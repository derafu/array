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

use Derafu\Array\Exception\ArrayException;
use Derafu\Array\Exception\DuplicateValueException;
use Generator;
use Throwable;

/**
 * Finds the items of a list that repeat the values of another one.
 */
final class ArrayDuplicates
{
    /**
     * Finds every item that repeats the values of an earlier one.
     *
     * What identifies an item are the values at the given paths (see
     * {@see ArrayPath::get()}, so they can be at any level). A path that is not
     * in the item gives `null`. Without paths, the whole item is what is
     * compared. Two items are the same when their values have the same type and
     * the same value, unless `$normalize` makes them the same.
     *
     * An item that is an object is read through its public properties, and the
     * values that are compared must be serializable.
     *
     * @template K of array-key
     * @param array<K, mixed> $items The list. Its keys are the ones that
     * are reported.
     * @param array<int, int|string|array<int, int|string>> $paths The paths of
     * the values that identify an item.
     * @param (callable(mixed): mixed)|null $normalize Applied to each value
     * that is read, before comparing it.
     * @return list<array{index: K, first: K, value: list<mixed>}>
     * The repeated items in the order of the list: the key of the item, the key
     * of the first item that has those values and the values that were read from
     * the repeated one (before normalizing them).
     * @throws ArrayException If a path is not valid, if an item is not an array
     * or an object and there are paths to read from it, or if its values can not
     * be compared.
     */
    public static function find(array $items, array $paths = [], ?callable $normalize = null): array
    {
        return iterator_to_array(self::scan($items, $paths, $normalize), false);
    }

    /**
     * Finds the first item that repeats the values of an earlier one.
     *
     * @template K of array-key
     * @param array<K, mixed> $items
     * @param array<int, int|string|array<int, int|string>> $paths
     * @param (callable(mixed): mixed)|null $normalize
     * @return array{index: K, first: K, value: list<mixed>}|null
     * `null` if there is none.
     * @throws ArrayException As {@see find()}.
     */
    public static function first(array $items, array $paths = [], ?callable $normalize = null): ?array
    {
        foreach (self::scan($items, $paths, $normalize) as $duplicate) {
            return $duplicate;
        }

        return null;
    }

    /**
     * Requires that no item of the list repeats the values of an earlier one.
     *
     * @param array<array-key, mixed> $items
     * @param array<int, int|string|array<int, int|string>> $paths
     * @param (callable(mixed): mixed)|null $normalize
     * @throws DuplicateValueException If an item is repeated; it is the first one.
     * @throws ArrayException As {@see find()}.
     */
    public static function assertUnique(array $items, array $paths = [], ?callable $normalize = null): void
    {
        $duplicate = self::first($items, $paths, $normalize);
        if ($duplicate === null) {
            return;
        }

        throw $paths === []
            ? new DuplicateValueException(
                [
                    'The item with key {index} is the same as the item with key {first}.',
                    'index' => $duplicate['index'],
                    'first' => $duplicate['first'],
                ],
                $duplicate['index'],
                $duplicate['first'],
                $duplicate['value']
            )
            : new DuplicateValueException(
                [
                    'The item with key {index} repeats the item with key {first} ({values}).',
                    'index' => $duplicate['index'],
                    'first' => $duplicate['first'],
                    'values' => implode(', ', array_map(
                        static fn (int|string|array $path, mixed $value): string => self::pathName($path) . ' = ' . self::describe($value),
                        array_values($paths),
                        $duplicate['value']
                    )),
                ],
                $duplicate['index'],
                $duplicate['first'],
                $duplicate['value']
            );
    }

    /**
     * Goes through the list and gives each item that repeats an earlier one.
     *
     * @template K of array-key
     * @param array<K, mixed> $items
     * @param array<array-key, mixed> $paths
     * @param (callable(mixed): mixed)|null $normalize
     * @return Generator<int, array{index: K, first: K, value: list<mixed>}>
     */
    private static function scan(array $items, array $paths, ?callable $normalize): Generator
    {
        $readPaths = [];
        foreach ($paths as $position => $path) {
            $readPaths[] = self::toPath($path) ?? throw new ArrayException([
                'The path {position} must be a string, an integer or a list of them, {given} given.',
                'position' => $position,
                'given' => get_debug_type($path),
            ]);
        }

        /** @var array<string, K> $seen */
        $seen = [];
        foreach ($items as $index => $item) {
            $values = $readPaths === [] ? [$item] : self::read($index, $item, $readPaths);
            $compared = $normalize === null ? $values : array_map($normalize, $values);

            try {
                $signature = serialize($compared);
            } catch (Throwable $e) {
                throw new ArrayException([
                    'The values of the item with key {index} can not be compared: {reason}.',
                    'index' => $index,
                    'reason' => $e->getMessage(),
                ], previous: $e);
            }

            if (isset($seen[$signature])) {
                yield ['index' => $index, 'first' => $seen[$signature], 'value' => $values];

                continue;
            }

            $seen[$signature] = $index;
        }
    }

    /**
     * Reads the values of an item at the paths.
     *
     * @param list<string|non-empty-list<string>> $paths
     * @return list<mixed>
     */
    private static function read(int|string $index, mixed $item, array $paths): array
    {
        if (is_object($item)) {
            $item = get_object_vars($item);
        }

        if (!is_array($item)) {
            throw new ArrayException([
                'The item with key {index} must be an array or an object to read {paths}, {given} given.',
                'index' => $index,
                'paths' => implode(', ', array_map(self::pathName(...), $paths)),
                'given' => get_debug_type($item),
            ]);
        }

        return array_map(static fn (string|array $path): mixed => ArrayPath::get($item, $path), $paths);
    }

    /**
     * Gives the path in the form that `ArrayPath::get()` reads: a string, or a
     * list of strings when it is given by levels.
     *
     * @return string|non-empty-list<string>|null `null` if it is not a path: a
     * string, an integer or a non-empty list of them.
     */
    private static function toPath(mixed $path): string|array|null
    {
        if (is_string($path) || is_int($path)) {
            return (string) $path;
        }

        if (!is_array($path) || $path === []) {
            return null;
        }

        $levels = [];
        foreach ($path as $level) {
            if (!is_string($level) && !is_int($level)) {
                return null;
            }

            $levels[] = (string) $level;
        }

        return $levels;
    }

    /**
     * Gives the name of a path to be said in a message.
     *
     * @param int|string|array<int, int|string> $path
     */
    private static function pathName(int|string|array $path): string
    {
        return is_array($path) ? implode('.', $path) : (string) $path;
    }

    /**
     * Gives a value to be said in a message.
     */
    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: get_debug_type($value),
        };
    }
}
