<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Array;

use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Value\ValueType;

/**
 * Writes the keys of an array, in place, as many times as needed. It is the
 * counterpart of `ArrayReader`: it takes the keys the same way (a literal key
 * takes precedence; otherwise, string keys navigate nested arrays using dot
 * notation) and reports the errors with the same messages and paths.
 *
 * The writer does not copy the array: it writes in the array it was created
 * from. It only writes; to read, `getReader()` gives a reader of the current
 * data. Values are not typed when written: the type is what the reader asks
 * for.
 *
 *     $writer = ArrayWriter::fromReference($data);
 *
 *     $writer->set('Encabezado.IdDoc.Folio', 10);   // creates the levels
 *     $writer->append('Detalle', ['NmbItem' => 'A']);
 *     $writer->remove('Encabezado.IdDoc.FchVenc');
 *
 *     $totales = $writer->getWriter('Encabezado.Totales'); // writes in $data
 *     $totales->set('MntTotal', 1190);
 *     $totales->mergeDefaults(['IVA' => 0, 'MntExe' => 0]);
 *
 *     $writer->getReader()->requireAsInt('Encabezado.Totales.MntTotal');
 *
 * The class is not `readonly`, as `ArrayReader` is, because the array it holds
 * changes; its path and source do not change.
 *
 * While a writer from `getWriter()` is alive, its level is shared by reference
 * with the array it writes in, so copies of that array made in the meantime
 * share it too.
 */
final class ArrayWriter
{
    /**
     * Array that is written, by reference to the one it was created from.
     *
     * @var array<array-key, mixed>
     */
    private array $data;

    /**
     * @param array<array-key, mixed> $data The array to write, by reference.
     * @param string $path Path of the array inside a bigger one, for the errors.
     * @param string|null $source Where the array comes from (a file, a request,
     * anything that says it), for the errors.
     */
    public function __construct(
        array &$data,
        private readonly string $path = '',
        private readonly ?string $source = null
    ) {
        $this->data = &$data;
    }

    /**
     * Creates a writer over an array, which is written in place.
     *
     * @param array<array-key, mixed> $data The array to write, by reference.
     * @param string|null $source Where the array comes from, for the errors.
     */
    public static function fromReference(array &$data, ?string $source = null): self
    {
        return new self($data, '', $source);
    }

    /**
     * Sets a value at a key, creating the levels that are missing.
     *
     * A level that is `null` or is not there is created as an array.
     *
     * @param int|string $key Literal key or dot notation path.
     * @param mixed $value Value to set; `null` is stored as `null`.
     * @return $this
     * @throws TypeMismatchException If a level of the path is not an array.
     */
    public function set(int|string $key, mixed $value): static
    {
        $keys = ArrayPath::keys($this->data, (string) $key);
        $last = array_pop($keys);

        $node = &$this->descend($keys);
        $node[$last] = $value;

        return $this;
    }

    /**
     * Removes a key. If the path does not exist, nothing happens.
     *
     * @param int|string $key Literal key or dot notation path.
     * @return $this
     */
    public function remove(int|string $key): static
    {
        $keys = ArrayPath::keys($this->data, (string) $key);
        $last = array_pop($keys);

        $node = &$this->data;
        foreach ($keys as $level) {
            if (!is_array($node[$level] ?? null)) {
                return $this;
            }
            $node = &$node[$level];
        }
        unset($node[$last]);

        return $this;
    }

    /**
     * Adds a value at the end of the list that is at a key. The list is created
     * if the key is not there or is `null`.
     *
     * @param int|string $key Literal key or dot notation path of the list.
     * @param mixed $value Value to add.
     * @return $this
     * @throws TypeMismatchException If a level of the path is not an array, or
     * the value at the key is not a list.
     */
    public function append(int|string $key, mixed $value): static
    {
        $keys = ArrayPath::keys($this->data, (string) $key);
        $last = array_pop($keys);

        $node = &$this->descend($keys);
        if (!array_key_exists($last, $node) || $node[$last] === null) {
            $node[$last] = [];
        } elseif (!is_array($node[$last]) || !array_is_list($node[$last])) {
            throw $this->typeMismatch([...$keys, $last], $node[$last], 'list');
        }
        $node[$last][] = $value;

        return $this;
    }

    /**
     * Merges recursively the values into this array: the values replace what
     * is already there.
     *
     * It has the semantics of `array_replace_recursive()`: nested arrays are
     * merged by key, lists are merged by index, and `null` replaces too.
     *
     * @param array<array-key, mixed> $values Values to merge.
     * @return $this
     */
    public function merge(array $values): static
    {
        $this->data = array_replace_recursive($this->data, $values);

        return $this;
    }

    /**
     * Merges recursively the defaults into this array: what is already there
     * is kept, and the defaults only fill what is missing.
     *
     * It has the semantics of `array_replace_recursive()`: nested arrays are
     * merged by key, lists are merged by index, and `null` counts as a value.
     *
     * @param array<array-key, mixed> $defaults Values for what is missing.
     * @return $this
     */
    public function mergeDefaults(array $defaults): static
    {
        $this->data = array_replace_recursive($defaults, $this->data);

        return $this;
    }

    /**
     * Gets a writer over the array that is at a key, which writes in this one.
     * The levels that are missing are created as arrays.
     *
     * Unlike `ArrayReader::getReader()`, it is never `null`: writing creates
     * the level.
     *
     * @param int|string $key Literal key or dot notation path of the array.
     * @throws TypeMismatchException If a level of the path is not an array.
     */
    public function getWriter(int|string $key): self
    {
        $keys = ArrayPath::keys($this->data, (string) $key);

        $node = &$this->descend($keys);

        return new self(
            $node,
            ValueType::path($this->path, implode('.', $keys)),
            $this->source
        );
    }

    /**
     * Gets a reader of the current data of this array.
     *
     * The reader is a snapshot: get a new one after writing to read what was
     * written.
     */
    public function getReader(): ArrayReader
    {
        return new ArrayReader($this->data, $this->path, $this->source);
    }

    /**
     * Gets the current data of this array.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * Goes down the levels of a path by reference, creating the ones that are
     * missing or `null`.
     *
     * @param list<string> $keys Keys of the levels, none for this array.
     * @return array<array-key, mixed> The array at the path, by reference.
     * @throws TypeMismatchException If a level of the path is not an array.
     */
    private function &descend(array $keys): array
    {
        $node = &$this->data;
        $walked = [];
        foreach ($keys as $level) {
            $walked[] = $level;
            if (!array_key_exists($level, $node) || $node[$level] === null) {
                $node[$level] = [];
            } elseif (!is_array($node[$level])) {
                throw $this->typeMismatch($walked, $node[$level], 'array');
            }
            $node = &$node[$level];
        }

        return $node;
    }

    /**
     * Creates the error of a value that is not of the type the path needs.
     *
     * @param list<string> $keys Keys of the path, from this array.
     */
    private function typeMismatch(array $keys, mixed $value, string $expected): TypeMismatchException
    {
        $key = ValueType::path($this->path, implode('.', $keys));

        return $this->source === null
            ? new TypeMismatchException([
                'The key "{key}" must be of type {expected}, {given} given.',
                'key' => $key,
                'expected' => $expected,
                'given' => get_debug_type($value),
            ])
            : new TypeMismatchException([
                'The key "{key}" of {source} must be of type {expected}, {given} given.',
                'key' => $key,
                'source' => $this->source,
                'expected' => $expected,
                'given' => get_debug_type($value),
            ]);
    }
}
