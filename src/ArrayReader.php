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

use DateTimeInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;

/**
 * Reads the keys of an array (a decoded JSON or YAML, a configuration) as
 * typed values, instead of trusting its shape.
 *
 * A key that is not there, or is `null`, is absent: the reader returns the
 * default (`null` if none). A key that is there with another type is an error
 * that says where (`dependentRequired.name.0`) and what was expected. Nothing
 * is coerced (`getDateTime()` is the one type that converts, and its name says so).
 *
 * The array can say where it comes from (`source`: a file, a request, anything
 * that tells whoever reads the error which array it is), and the errors of the
 * readers that are made from it (`getReader()`, `getReaders()`) say it too.
 *
 *     $reader = ArrayReader::fromArray($definition);
 *
 *     $reader->getString('title');                   // ?string
 *     $reader->getInt('minLength', 0);               // int
 *     $reader->requireString('type');                // string, or an error
 *     $reader->getMapOf('dependentRequired', ArrayType::listOf(ArrayType::string()));
 *                                                    // ?array<string, list<string>>
 *
 *     $reader->getString('method', 'GET');           // string: it has a default
 *     $reader->assertOnlyKeys('title', 'minLength'); // an error for any other key
 *     $reader->getReaders('parameters');             // ?list<ArrayReader>
 *
 * Types are described by `ArrayType` and can be composed to any depth.
 */
final readonly class ArrayReader
{
    /**
     * @param array<array-key, mixed> $data The array to read.
     * @param string $path Path of the array inside a bigger one, for the errors.
     * @param string|null $source Where the array comes from (a file, a request,
     * anything that says it), for the errors.
     */
    public function __construct(
        private array $data,
        private string $path = '',
        private ?string $source = null
    ) {
    }

    /**
     * Creates a reader of an array.
     *
     * @param array<array-key, mixed> $data
     * @param string|null $source Where the array comes from, for the errors.
     */
    public static function fromArray(array $data, ?string $source = null): self
    {
        return new self($data, '', $source);
    }

    /**
     * Creates a reader from an untrusted value without converting it.
     *
     * @param mixed $data Value that must be an array.
     * @param string|null $source Where the array comes from.
     * @param string $path Path of the value inside its source.
     * @throws InvalidArgumentException If the value is not an array.
     */
    public static function fromValue(mixed $data, ?string $source = null, string $path = ''): self
    {
        return new self(ArrayType::array()->parse($data, $path, $source), $path, $source);
    }

    /**
     * Tells if the key exists, including when its value is `null`.
     */
    public function hasKey(int|string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    /**
     * Tells if the key is there and is not `null`.
     */
    public function hasValue(int|string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * Reads a key as a type.
     *
     * @template T
     * @template D
     * @param int|string $key
     * @param ArrayType<T> $type
     * @param D $default What is returned when the key is absent.
     * @return T|D
     * @throws InvalidArgumentException If the value is not of the type.
     */
    public function get(int|string $key, ArrayType $type, mixed $default = null): mixed
    {
        if (!isset($this->data[$key])) {
            return $default;
        }

        return $type->parse($this->data[$key], ArrayType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a key as a type, that must be there.
     *
     * @template T
     * @param int|string $key
     * @param ArrayType<T> $type
     * @return T
     * @throws InvalidArgumentException If the key is absent or not of the type.
     */
    public function require(int|string $key, ArrayType $type): mixed
    {
        if (!isset($this->data[$key])) {
            throw $this->source === null
                ? new InvalidArgumentException([
                    'The key "{key}" is required.',
                    'key' => ArrayType::path($this->path, $key),
                ])
                : new InvalidArgumentException([
                    'The key "{key}" of {source} is required.',
                    'key' => ArrayType::path($this->path, $key),
                    'source' => $this->source,
                ]);
        }

        return $type->parse($this->data[$key], ArrayType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a key that is an array as another reader, which reports its
     * errors with the full path.
     *
     * @throws InvalidArgumentException If the value is not an array.
     */
    public function getReader(int|string $key): ?self
    {
        $value = $this->get($key, ArrayType::array());

        return $value === null
            ? null
            : new self($value, ArrayType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a key that is a list of arrays as a list of readers, each one
     * reporting its errors with the path of its position (`parameters.2.name`).
     *
     * @return list<self>|null
     * @throws InvalidArgumentException If the value is not a list of arrays.
     */
    public function getReaders(int|string $key): ?array
    {
        $items = $this->get($key, ArrayType::listOf(ArrayType::array()));

        if ($items === null) {
            return null;
        }

        $path = ArrayType::path($this->path, $key);

        return array_map(
            fn (array $item, int $index): self => new self($item, ArrayType::path($path, $index), $this->source),
            $items,
            array_keys($items)
        );
    }

    /**
     * Says that the array can only have some keys: a key that is not one of
     * them is an error, as a typo in a configuration would be ignored otherwise.
     *
     * @param int|string ...$allowed The keys that the array can have.
     * @return self The same reader, to go on reading.
     * @throws InvalidArgumentException If the array has another key.
     */
    public function assertOnlyKeys(int|string ...$allowed): self
    {
        $allowed = array_map(static fn (int|string $key): string => (string) $key, $allowed);
        foreach (array_keys($this->data) as $key) {
            if (in_array((string) $key, $allowed, true)) {
                continue;
            }

            throw $this->source === null
                ? new InvalidArgumentException([
                    'The key "{key}" is not allowed; the allowed keys are: {allowed}.',
                    'key' => ArrayType::path($this->path, $key),
                    'allowed' => implode(', ', $allowed),
                ])
                : new InvalidArgumentException([
                    'The key "{key}" of {source} is not allowed; the allowed keys are: {allowed}.',
                    'key' => ArrayType::path($this->path, $key),
                    'source' => $this->source,
                    'allowed' => implode(', ', $allowed),
                ]);
        }

        return $this;
    }

    /**
     * Returns the whole array.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @param string|null $default What is returned when the key is absent.
     * @return ($default is null ? string|null : string)
     * @throws InvalidArgumentException If the value is not a string.
     */
    public function getString(int|string $key, ?string $default = null): ?string
    {
        return $this->get($key, ArrayType::string(), $default);
    }

    /**
     * @param int|null $default What is returned when the key is absent.
     * @return ($default is null ? int|null : int)
     * @throws InvalidArgumentException If the value is not an integer.
     */
    public function getInt(int|string $key, ?int $default = null): ?int
    {
        return $this->get($key, ArrayType::int(), $default);
    }

    /**
     * @param float|null $default What is returned when the key is absent.
     * @return ($default is null ? float|null : float)
     * @throws InvalidArgumentException If the value is not a float.
     */
    public function getFloat(int|string $key, ?float $default = null): ?float
    {
        return $this->get($key, ArrayType::float(), $default);
    }

    /**
     * Reads an integer or a float.
     *
     * @param float|int|null $default What is returned when the key is absent.
     * @return ($default is null ? float|int|null : float|int)
     * @throws InvalidArgumentException If the value is not a number.
     */
    public function getNumber(int|string $key, float|int|null $default = null): float|int|null
    {
        return $this->get($key, ArrayType::number(), $default);
    }

    /**
     * @param bool|null $default What is returned when the key is absent.
     * @return ($default is null ? bool|null : bool)
     * @throws InvalidArgumentException If the value is not a boolean.
     */
    public function getBool(int|string $key, ?bool $default = null): ?bool
    {
        return $this->get($key, ArrayType::bool(), $default);
    }

    /**
     * Reads a string that is not empty, nor only spaces.
     *
     * @return non-empty-string|null
     * @throws InvalidArgumentException If the value is not that string.
     */
    public function getNonEmptyString(int|string $key): ?string
    {
        return $this->get($key, ArrayType::nonEmptyString());
    }

    /**
     * Reads a date (see `ArrayType::dateTime()`).
     *
     * @throws InvalidArgumentException If the value is not a date.
     */
    public function getDateTime(int|string $key): ?DateTimeInterface
    {
        return $this->get($key, ArrayType::dateTime());
    }

    /**
     * @return array<array-key, mixed>|null
     * @throws InvalidArgumentException If the value is not an array.
     */
    public function getArray(int|string $key): ?array
    {
        return $this->get($key, ArrayType::array());
    }

    /**
     * @return array<string, mixed>|null
     * @throws InvalidArgumentException If the value is not an object.
     */
    public function getMap(int|string $key): ?array
    {
        return $this->get($key, ArrayType::map());
    }

    /**
     * @return list<mixed>|null
     * @throws InvalidArgumentException If the value is not a list.
     */
    public function getList(int|string $key): ?array
    {
        return $this->get($key, ArrayType::list());
    }

    /**
     * @return list<string>|null
     * @throws InvalidArgumentException If the value is not a list of strings.
     */
    public function getStringList(int|string $key): ?array
    {
        return $this->get($key, ArrayType::listOf(ArrayType::string()));
    }

    /**
     * @return list<int>|null
     * @throws InvalidArgumentException If the value is not a list of integers.
     */
    public function getIntList(int|string $key): ?array
    {
        return $this->get($key, ArrayType::listOf(ArrayType::int()));
    }

    /**
     * @return list<bool|float|int|string>|null
     * @throws InvalidArgumentException If the value is not a list of scalars.
     */
    public function getScalarList(int|string $key): ?array
    {
        return $this->get($key, ArrayType::listOf(ArrayType::scalar()));
    }

    /**
     * Reads a list whose items are all of a type.
     *
     * @template T
     * @param ArrayType<T> $item
     * @return list<T>|null
     * @throws InvalidArgumentException If the value is not that list.
     */
    public function getListOf(int|string $key, ArrayType $item): ?array
    {
        return $this->get($key, ArrayType::listOf($item));
    }

    /**
     * Reads an object whose values are all of a type.
     *
     * @template T
     * @param ArrayType<T> $item
     * @return array<string, T>|null
     * @throws InvalidArgumentException If the value is not that object.
     */
    public function getMapOf(int|string $key, ArrayType $item): ?array
    {
        return $this->get($key, ArrayType::mapOf($item));
    }

    /**
     * Reads a required value (see `getString()`).
     *
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireString(int|string $key): string
    {
        return $this->require($key, ArrayType::string());
    }

    /**
     * Reads a required value (see `getInt()`).
     *
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireInt(int|string $key): int
    {
        return $this->require($key, ArrayType::int());
    }

    /**
     * Reads a required value (see `getFloat()`).
     *
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireFloat(int|string $key): float
    {
        return $this->require($key, ArrayType::float());
    }

    /**
     * Reads a required value (see `getNumber()`).
     *
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireNumber(int|string $key): float|int
    {
        return $this->require($key, ArrayType::number());
    }

    /**
     * Reads a required value (see `getBool()`).
     *
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireBool(int|string $key): bool
    {
        return $this->require($key, ArrayType::bool());
    }

    /**
     * Reads a required value (see `getNonEmptyString()`).
     *
     * @return non-empty-string
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireNonEmptyString(int|string $key): string
    {
        return $this->require($key, ArrayType::nonEmptyString());
    }

    /**
     * Reads a required value (see `getDateTime()`).
     *
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireDateTime(int|string $key): DateTimeInterface
    {
        return $this->require($key, ArrayType::dateTime());
    }

    /**
     * Reads a required value (see `getArray()`).
     *
     * @return array<array-key, mixed>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireArray(int|string $key): array
    {
        return $this->require($key, ArrayType::array());
    }

    /**
     * Reads a required value (see `getMap()`).
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireMap(int|string $key): array
    {
        return $this->require($key, ArrayType::map());
    }

    /**
     * Reads a required value (see `getList()`).
     *
     * @return list<mixed>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireList(int|string $key): array
    {
        return $this->require($key, ArrayType::list());
    }

    /**
     * Reads a required value (see `getStringList()`).
     *
     * @return list<string>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireStringList(int|string $key): array
    {
        return $this->require($key, ArrayType::listOf(ArrayType::string()));
    }

    /**
     * Reads a required value (see `getIntList()`).
     *
     * @return list<int>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireIntList(int|string $key): array
    {
        return $this->require($key, ArrayType::listOf(ArrayType::int()));
    }

    /**
     * Reads a required value (see `getScalarList()`).
     *
     * @return list<bool|float|int|string>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireScalarList(int|string $key): array
    {
        return $this->require($key, ArrayType::listOf(ArrayType::scalar()));
    }

    /**
     * Reads a required value (see `getListOf()`).
     *
     * @template T
     * @param ArrayType<T> $item
     * @return list<T>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireListOf(int|string $key, ArrayType $item): array
    {
        return $this->require($key, ArrayType::listOf($item));
    }

    /**
     * Reads a required value (see `getMapOf()`).
     *
     * @template T
     * @param ArrayType<T> $item
     * @return array<string, T>
     * @throws InvalidArgumentException If the key is absent, null, or has an invalid type.
     */
    public function requireMapOf(int|string $key, ArrayType $item): array
    {
        return $this->require($key, ArrayType::mapOf($item));
    }

    /**
     * Reads a required array as a reader, preserving its path and source.
     *
     * @throws InvalidArgumentException If the key is absent, null, or is not an array.
     */
    public function requireReader(int|string $key): self
    {
        $value = $this->require($key, ArrayType::array());

        return new self($value, ArrayType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a required list of arrays as readers, preserving their paths and source.
     *
     * @return list<self>
     * @throws InvalidArgumentException If the key is absent, null, or is not a list of arrays.
     */
    public function requireReaders(int|string $key): array
    {
        $items = $this->require($key, ArrayType::listOf(ArrayType::array()));
        $path = ArrayType::path($this->path, $key);

        return array_map(
            fn (array $item, int $index): self => new self($item, ArrayType::path($path, $index), $this->source),
            $items,
            array_keys($items)
        );
    }

    /**
     * Reads an array with arbitrary keys whose values match the given type.
     *
     * @template T
     * @param ArrayType<T> $item
     * @return array<array-key, T>|null
     * @throws InvalidArgumentException If the value is not that array.
     */
    public function getArrayOf(int|string $key, ArrayType $item): ?array
    {
        return $this->get($key, ArrayType::arrayOf($item));
    }

    /**
     * Reads an array with arbitrary keys whose values match the given type.
     *
     * @template T
     * @param ArrayType<T> $item
     * @return array<array-key, T>
     * @throws InvalidArgumentException If the value is not that array, or is absent or null.
     */
    public function requireArrayOf(int|string $key, ArrayType $item): array
    {
        return $this->require($key, ArrayType::arrayOf($item));
    }

    /**
     * Reads an array of arrays as readers, preserving every key and error path.
     *
     * @return array<array-key, self>|null
     * @throws InvalidArgumentException If the value is not an array of arrays.
     */
    public function getArrayReaders(int|string $key): ?array
    {
        $items = $this->getArrayOf($key, ArrayType::array());

        return $items === null ? null : $this->createArrayReaders($items, $key);
    }

    /**
     * Reads a required array of arrays as readers, preserving keys and paths.
     *
     * @return array<array-key, self>
     * @throws InvalidArgumentException If the key is absent, null, or is not an array of arrays.
     */
    public function requireArrayReaders(int|string $key): array
    {
        return $this->createArrayReaders($this->requireArrayOf($key, ArrayType::array()), $key);
    }

    /**
     * Creates readers for a collection already validated by ArrayType.
     *
     * @param array<array-key, array<array-key, mixed>> $items
     * @return array<array-key, self>
     */
    private function createArrayReaders(array $items, int|string $key): array
    {
        $path = ArrayType::path($this->path, $key);
        $readers = [];
        foreach ($items as $index => $item) {
            $readers[$index] = new self($item, ArrayType::path($path, $index), $this->source);
        }

        return $readers;
    }
}
