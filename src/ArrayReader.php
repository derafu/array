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

use BackedEnum;
use DateTimeInterface;
use Derafu\Array\Exception\ArrayException;
use Derafu\Array\Exception\MissingValueException;
use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Array\Exception\UnexpectedKeyException;
use Derafu\Value\Exception\TypeMismatchException as ValueTypeMismatchException;
use Derafu\Value\ValueType;
use stdClass;

/**
 * Reads the keys of an array (a decoded JSON or YAML, a configuration) as
 * typed values, instead of trusting its shape. Literal keys take precedence;
 * otherwise, string keys navigate nested arrays using dot notation.
 *
 * A key that is not there, or is `null`, is absent: the reader returns the
 * default (`null` if none). A key that is there with another type is an error
 * that says where (`dependentRequired.name.0`) and what was expected. Nothing
 * is implicitly coerced. Explicit date and backed-enum types interpret their
 * supported input forms; primitive reads remain strict. The getAsXXX() and
 * requireAsXXX() methods explicitly convert scalars through derafu/value.
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
 *     $reader->getMapOf('dependentRequired', ValueType::listOf(ValueType::string()));
 *                                                    // ?array<string, list<string>>
 *
 *     $reader->getString('method', 'GET');           // string: it has a default
 *     $reader->assertOnlyKeys('title', 'minLength'); // an error for any other key
 *     $reader->getReaders('parameters');             // ?list<ArrayReader>
 *
 * Types are described by `ValueType` and can be composed to any depth.
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
     * @throws ArrayException If the value is not an array.
     */
    public static function fromValue(mixed $data, ?string $source = null, string $path = ''): self
    {
        return new self(self::parseValue($data, ValueType::array(), $path, $source), $path, $source);
    }

    /**
     * Tells if the key exists, including when its value is `null`.
     */
    public function hasKey(int|string $key): bool
    {
        $missing = new stdClass();

        return ArrayPath::get($this->data, (string) $key, $missing) !== $missing;
    }

    /**
     * Tells if the key is there and is not `null`.
     */
    public function hasValue(int|string $key): bool
    {
        return ArrayPath::get($this->data, (string) $key) !== null;
    }

    /**
     * Reads a key as a type.
     *
     * @template T
     * @template D
     * @param int|string $key
     * @param ValueType<T> $type
     * @param D $default What is returned when the key is absent.
     * @return T|D
     * @throws ArrayException If the value is not of the type.
     */
    public function get(int|string $key, ValueType $type, mixed $default = null): mixed
    {
        $value = ArrayPath::get($this->data, (string) $key);
        if ($value === null) {
            return $default;
        }

        return self::parseValue($value, $type, ValueType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a key as a type, that must be there.
     *
     * @template T
     * @param int|string $key
     * @param ValueType<T> $type
     * @return T
     * @throws ArrayException If the key is absent or not of the type.
     */
    public function require(int|string $key, ValueType $type): mixed
    {
        $value = ArrayPath::get($this->data, (string) $key);
        if ($value === null) {
            throw $this->source === null
                ? new MissingValueException([
                    'The key "{key}" is required.',
                    'key' => ValueType::path($this->path, $key),
                ])
                : new MissingValueException([
                    'The key "{key}" of {source} is required.',
                    'key' => ValueType::path($this->path, $key),
                    'source' => $this->source,
                ]);
        }

        return self::parseValue($value, $type, ValueType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a key that is an array as another reader, which reports its
     * errors with the full path.
     *
     * @param ValueType<array<array-key, mixed>>|null $type Type of the selected array.
     * @throws ArrayException If the value is not an array.
     */
    public function getReader(int|string $key, ?ValueType $type = null): ?self
    {
        $value = $this->get($key, $type ?? ValueType::array());

        return $value === null
            ? null
            : new self($value, ValueType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a key that is a list of arrays as a list of readers, each one
     * reporting its errors with the path of its position (`parameters.2.name`).
     *
     * @param ValueType<array<array-key, mixed>>|null $type Type of each array in the list.
     * @return list<self>|null
     * @throws ArrayException If the value is not a list of arrays.
     */
    public function getReaders(int|string $key, ?ValueType $type = null): ?array
    {
        $items = $this->get($key, ValueType::listOf($type ?? ValueType::array()));

        if ($items === null) {
            return null;
        }

        $path = ValueType::path($this->path, $key);

        return array_map(
            fn (array $item, int $index): self => new self($item, ValueType::path($path, $index), $this->source),
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
     * @throws ArrayException If the array has another key.
     */
    public function assertOnlyKeys(int|string ...$allowed): self
    {
        $allowed = array_map(static fn (int|string $key): string => (string) $key, $allowed);
        foreach (array_keys($this->data) as $key) {
            if (in_array((string) $key, $allowed, true)) {
                continue;
            }

            throw $this->source === null
                ? new UnexpectedKeyException([
                    'The key "{key}" is not allowed; the allowed keys are: {allowed}.',
                    'key' => ValueType::path($this->path, $key),
                    'allowed' => implode(', ', $allowed),
                ])
                : new UnexpectedKeyException([
                    'The key "{key}" of {source} is not allowed; the allowed keys are: {allowed}.',
                    'key' => ValueType::path($this->path, $key),
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
     * Checks the whole array against a type, as `get()` does with a key.
     *
     * The errors say `$` for the array itself, or the path of the reader when
     * it was made from a bigger array.
     *
     * @template T
     * @param ValueType<T> $type
     * @return T
     * @throws ArrayException If the array is not of the type.
     */
    public function to(ValueType $type): mixed
    {
        return self::parseValue($this->data, $type, $this->path, $this->source);
    }

    /**
     * Returns the whole array, that must have only string keys (see `getMap()`).
     *
     * @return array<string, mixed>
     * @throws ArrayException If the array is not a map.
     */
    public function toMap(): array
    {
        return $this->to(ValueType::map());
    }

    /**
     * Returns the whole array, that must be a list (see `getList()`).
     *
     * @return list<mixed>
     * @throws ArrayException If the array is not a list.
     */
    public function toList(): array
    {
        return $this->to(ValueType::list());
    }

    /**
     * Reads and converts a value using ValueType::toInt().
     *
     * @param int|null $default Returned when the key is absent or null.
     * @return ($default is null ? int|null : int)
     * @throws ArrayException If the present value cannot be converted.
     */
    public function getAsInt(int|string $key, ?int $default = null): ?int
    {
        return $this->get($key, ValueType::toInt(), $default);
    }

    /**
     * Reads and converts a required value using ValueType::toInt().
     *
     * @throws ArrayException If the key is absent, null or cannot be converted.
     */
    public function requireAsInt(int|string $key): int
    {
        return $this->require($key, ValueType::toInt());
    }

    /**
     * Reads and converts a value using ValueType::toFloat().
     *
     * @param float|null $default Returned when the key is absent or null.
     * @return ($default is null ? float|null : float)
     * @throws ArrayException If the present value cannot be converted.
     */
    public function getAsFloat(int|string $key, ?float $default = null): ?float
    {
        return $this->get($key, ValueType::toFloat(), $default);
    }

    /**
     * Reads and converts a required value using ValueType::toFloat().
     *
     * @throws ArrayException If the key is absent, null or cannot be converted.
     */
    public function requireAsFloat(int|string $key): float
    {
        return $this->require($key, ValueType::toFloat());
    }

    /**
     * Reads and converts a value using ValueType::toNumber().
     *
     * @param float|int|null $default Returned when the key is absent or null.
     * @return ($default is null ? float|int|null : float|int)
     * @throws ArrayException If the present value cannot be converted.
     */
    public function getAsNumber(int|string $key, float|int|null $default = null): float|int|null
    {
        return $this->get($key, ValueType::toNumber(), $default);
    }

    /**
     * Reads and converts a required value using ValueType::toNumber().
     *
     * @throws ArrayException If the key is absent, null or cannot be converted.
     */
    public function requireAsNumber(int|string $key): float|int
    {
        return $this->require($key, ValueType::toNumber());
    }

    /**
     * Reads and converts a value using ValueType::toBool().
     *
     * @param bool|null $default Returned when the key is absent or null.
     * @return ($default is null ? bool|null : bool)
     * @throws ArrayException If the present value cannot be converted.
     */
    public function getAsBool(int|string $key, ?bool $default = null): ?bool
    {
        return $this->get($key, ValueType::toBool(), $default);
    }

    /**
     * Reads and converts a required value using ValueType::toBool().
     *
     * @throws ArrayException If the key is absent, null or cannot be converted.
     */
    public function requireAsBool(int|string $key): bool
    {
        return $this->require($key, ValueType::toBool());
    }

    /**
     * Reads and converts a value using ValueType::toString().
     *
     * @param string|null $default Returned when the key is absent or null.
     * @return ($default is null ? string|null : string)
     * @throws ArrayException If the present value cannot be converted.
     */
    public function getAsString(int|string $key, ?string $default = null): ?string
    {
        return $this->get($key, ValueType::toString(), $default);
    }

    /**
     * Reads and converts a required value using ValueType::toString().
     *
     * @throws ArrayException If the key is absent, null or cannot be converted.
     */
    public function requireAsString(int|string $key): string
    {
        return $this->require($key, ValueType::toString());
    }

    /**
     * @param string|null $default What is returned when the key is absent.
     * @return ($default is null ? string|null : string)
     * @throws ArrayException If the value is not a string.
     */
    public function getString(int|string $key, ?string $default = null): ?string
    {
        return $this->get($key, ValueType::string(), $default);
    }

    /**
     * @param int|null $default What is returned when the key is absent.
     * @return ($default is null ? int|null : int)
     * @throws ArrayException If the value is not an integer.
     */
    public function getInt(int|string $key, ?int $default = null): ?int
    {
        return $this->get($key, ValueType::int(), $default);
    }

    /**
     * @param float|null $default What is returned when the key is absent.
     * @return ($default is null ? float|null : float)
     * @throws ArrayException If the value is not a float.
     */
    public function getFloat(int|string $key, ?float $default = null): ?float
    {
        return $this->get($key, ValueType::float(), $default);
    }

    /**
     * Reads an integer or a float.
     *
     * @param float|int|null $default What is returned when the key is absent.
     * @return ($default is null ? float|int|null : float|int)
     * @throws ArrayException If the value is not a number.
     */
    public function getNumber(int|string $key, float|int|null $default = null): float|int|null
    {
        return $this->get($key, ValueType::number(), $default);
    }

    /**
     * @param bool|null $default What is returned when the key is absent.
     * @return ($default is null ? bool|null : bool)
     * @throws ArrayException If the value is not a boolean.
     */
    public function getBool(int|string $key, ?bool $default = null): ?bool
    {
        return $this->get($key, ValueType::bool(), $default);
    }

    /**
     * Reads an instance of a class or interface.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T|null
     * @throws ArrayException If the value is not an instance of the class.
     */
    public function getObject(int|string $key, string $class): ?object
    {
        return $this->get($key, ValueType::instanceOf($class));
    }

    /**
     * Reads a string that is not empty, nor only spaces.
     *
     * @return non-empty-string|null
     * @throws ArrayException If the value is not that string.
     */
    public function getNonEmptyString(int|string $key): ?string
    {
        return $this->get($key, ValueType::nonEmptyString());
    }

    /**
     * Reads a date (see `ValueType::dateTime()`).
     *
     * @throws ArrayException If the value is not a date.
     */
    public function getDateTime(int|string $key): ?DateTimeInterface
    {
        return $this->get($key, ValueType::dateTime());
    }

    /**
     * Reads a case of a backed enum (see `ValueType::backedEnum()`).
     *
     * @template E of BackedEnum
     * @param class-string<E> $class
     * @return E|null
     * @throws ArrayException If the value is not a case of the enum.
     */
    public function getEnum(int|string $key, string $class): ?BackedEnum
    {
        return $this->get($key, ValueType::backedEnum($class));
    }

    /**
     * @return array<array-key, mixed>|null
     * @throws ArrayException If the value is not an array.
     */
    public function getArray(int|string $key): ?array
    {
        return $this->get($key, ValueType::array());
    }

    /**
     * @return array<string, mixed>|null
     * @throws ArrayException If the value is not a map.
     */
    public function getMap(int|string $key): ?array
    {
        return $this->get($key, ValueType::map());
    }

    /**
     * @return list<mixed>|null
     * @throws ArrayException If the value is not a list.
     */
    public function getList(int|string $key): ?array
    {
        return $this->get($key, ValueType::list());
    }

    /**
     * @return list<string>|null
     * @throws ArrayException If the value is not a list of strings.
     */
    public function getStringList(int|string $key): ?array
    {
        return $this->get($key, ValueType::listOf(ValueType::string()));
    }

    /**
     * @return list<int>|null
     * @throws ArrayException If the value is not a list of integers.
     */
    public function getIntList(int|string $key): ?array
    {
        return $this->get($key, ValueType::listOf(ValueType::int()));
    }

    /**
     * @return list<bool|float|int|string>|null
     * @throws ArrayException If the value is not a list of scalars.
     */
    public function getScalarList(int|string $key): ?array
    {
        return $this->get($key, ValueType::listOf(ValueType::scalar()));
    }

    /**
     * Reads a list whose items are all of a type.
     *
     * @template T
     * @param ValueType<T> $item
     * @return list<T>|null
     * @throws ArrayException If the value is not that list.
     */
    public function getListOf(int|string $key, ValueType $item): ?array
    {
        return $this->get($key, ValueType::listOf($item));
    }

    /**
     * Reads a map whose values are all of a type.
     *
     * @template T
     * @param ValueType<T> $item
     * @return array<string, T>|null
     * @throws ArrayException If the value is not that map.
     */
    public function getMapOf(int|string $key, ValueType $item): ?array
    {
        return $this->get($key, ValueType::mapOf($item));
    }

    /**
     * Reads a required value (see `getString()`).
     *
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireString(int|string $key): string
    {
        return $this->require($key, ValueType::string());
    }

    /**
     * Reads a required value (see `getInt()`).
     *
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireInt(int|string $key): int
    {
        return $this->require($key, ValueType::int());
    }

    /**
     * Reads a required value (see `getFloat()`).
     *
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireFloat(int|string $key): float
    {
        return $this->require($key, ValueType::float());
    }

    /**
     * Reads a required value (see `getNumber()`).
     *
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireNumber(int|string $key): float|int
    {
        return $this->require($key, ValueType::number());
    }

    /**
     * Reads a required value (see `getBool()`).
     *
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireBool(int|string $key): bool
    {
        return $this->require($key, ValueType::bool());
    }

    /**
     * Reads a required instance of a class or interface (see `getObject()`).
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     * @throws ArrayException If the key is absent, null, or is not an instance of the class.
     */
    public function requireObject(int|string $key, string $class): object
    {
        return $this->require($key, ValueType::instanceOf($class));
    }

    /**
     * Reads a required value (see `getNonEmptyString()`).
     *
     * @return non-empty-string
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireNonEmptyString(int|string $key): string
    {
        return $this->require($key, ValueType::nonEmptyString());
    }

    /**
     * Reads a required value (see `getDateTime()`).
     *
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireDateTime(int|string $key): DateTimeInterface
    {
        return $this->require($key, ValueType::dateTime());
    }

    /**
     * Reads a required case of a backed enum (see `getEnum()`).
     *
     * @template E of BackedEnum
     * @param class-string<E> $class
     * @return E
     * @throws ArrayException If the key is absent, null, or is not a case of the enum.
     */
    public function requireEnum(int|string $key, string $class): BackedEnum
    {
        return $this->require($key, ValueType::backedEnum($class));
    }

    /**
     * Reads a required value (see `getArray()`).
     *
     * @return array<array-key, mixed>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireArray(int|string $key): array
    {
        return $this->require($key, ValueType::array());
    }

    /**
     * Reads a required value (see `getMap()`).
     *
     * @return array<string, mixed>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireMap(int|string $key): array
    {
        return $this->require($key, ValueType::map());
    }

    /**
     * Reads a required value (see `getList()`).
     *
     * @return list<mixed>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireList(int|string $key): array
    {
        return $this->require($key, ValueType::list());
    }

    /**
     * Reads a required value (see `getStringList()`).
     *
     * @return list<string>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireStringList(int|string $key): array
    {
        return $this->require($key, ValueType::listOf(ValueType::string()));
    }

    /**
     * Reads a required value (see `getIntList()`).
     *
     * @return list<int>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireIntList(int|string $key): array
    {
        return $this->require($key, ValueType::listOf(ValueType::int()));
    }

    /**
     * Reads a required value (see `getScalarList()`).
     *
     * @return list<bool|float|int|string>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireScalarList(int|string $key): array
    {
        return $this->require($key, ValueType::listOf(ValueType::scalar()));
    }

    /**
     * Reads a required value (see `getListOf()`).
     *
     * @template T
     * @param ValueType<T> $item
     * @return list<T>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireListOf(int|string $key, ValueType $item): array
    {
        return $this->require($key, ValueType::listOf($item));
    }

    /**
     * Reads a required value (see `getMapOf()`).
     *
     * @template T
     * @param ValueType<T> $item
     * @return array<string, T>
     * @throws ArrayException If the key is absent, null, or has an invalid type.
     */
    public function requireMapOf(int|string $key, ValueType $item): array
    {
        return $this->require($key, ValueType::mapOf($item));
    }

    /**
     * Reads a required array as a reader, preserving its path and source.
     *
     * @param ValueType<array<array-key, mixed>>|null $type Type of the selected array.
     * @throws ArrayException If the key is absent, null, or is not an array.
     */
    public function requireReader(int|string $key, ?ValueType $type = null): self
    {
        $value = $this->require($key, $type ?? ValueType::array());

        return new self($value, ValueType::path($this->path, $key), $this->source);
    }

    /**
     * Reads a required list of arrays as readers, preserving their paths and source.
     *
     * @param ValueType<array<array-key, mixed>>|null $type Type of each array in the list.
     * @return list<self>
     * @throws ArrayException If the key is absent, null, or is not a list of arrays.
     */
    public function requireReaders(int|string $key, ?ValueType $type = null): array
    {
        $items = $this->require($key, ValueType::listOf($type ?? ValueType::array()));
        $path = ValueType::path($this->path, $key);

        return array_map(
            fn (array $item, int $index): self => new self($item, ValueType::path($path, $index), $this->source),
            $items,
            array_keys($items)
        );
    }

    /**
     * Reads an array with arbitrary keys whose values match the given type.
     *
     * @template T
     * @param ValueType<T> $item
     * @return array<array-key, T>|null
     * @throws ArrayException If the value is not that array.
     */
    public function getArrayOf(int|string $key, ValueType $item): ?array
    {
        return $this->get($key, ValueType::arrayOf($item));
    }

    /**
     * Reads an array with arbitrary keys whose values match the given type.
     *
     * @template T
     * @param ValueType<T> $item
     * @return array<array-key, T>
     * @throws ArrayException If the value is not that array, or is absent or null.
     */
    public function requireArrayOf(int|string $key, ValueType $item): array
    {
        return $this->require($key, ValueType::arrayOf($item));
    }

    /**
     * Reads an array of arrays as readers, preserving every key and error path.
     *
     * @param ValueType<array<array-key, mixed>>|null $type Type of each array in the collection.
     * @return array<array-key, self>|null
     * @throws ArrayException If the value is not an array of arrays.
     */
    public function getArrayReaders(int|string $key, ?ValueType $type = null): ?array
    {
        $items = $this->getArrayOf($key, $type ?? ValueType::array());

        return $items === null ? null : $this->createArrayReaders($items, $key);
    }

    /**
     * Reads a required array of arrays as readers, preserving keys and paths.
     *
     * @param ValueType<array<array-key, mixed>>|null $type Type of each array in the collection.
     * @return array<array-key, self>
     * @throws ArrayException If the key is absent, null, or is not an array of arrays.
     */
    public function requireArrayReaders(int|string $key, ?ValueType $type = null): array
    {
        return $this->createArrayReaders($this->requireArrayOf($key, $type ?? ValueType::array()), $key);
    }

    /**
     * Creates readers for a collection already validated by ValueType.
     *
     * @param array<array-key, array<array-key, mixed>> $items
     * @return array<array-key, self>
     */
    private function createArrayReaders(array $items, int|string $key): array
    {
        $path = ValueType::path($this->path, $key);
        $readers = [];
        foreach ($items as $index => $item) {
            $readers[$index] = new self($item, ValueType::path($path, $index), $this->source);
        }

        return $readers;
    }

    /**
     * Delegates typing to Value while retaining Array's read error contract.
     *
     * @template T
     * @param ValueType<T> $type
     * @return T
     * @throws TypeMismatchException If the value does not satisfy the type.
     */
    private static function parseValue(mixed $value, ValueType $type, string $path, ?string $source): mixed
    {
        try {
            return $type->parse($value, $path, $source);
        } catch (ValueTypeMismatchException $exception) {
            $parameters = $exception->getTranslatableMessage()->getParameters();
            throw $source === null
                ? new TypeMismatchException([
                    'The key "{key}" must be of type {expected}, {given} given.',
                    'key' => $parameters['path'] ?? $path,
                    'expected' => $parameters['expected'] ?? $type->name,
                    'given' => $parameters['given'] ?? get_debug_type($value),
                ], previous: $exception)
                : new TypeMismatchException([
                    'The key "{key}" of {source} must be of type {expected}, {given} given.',
                    'key' => $parameters['path'] ?? $path,
                    'source' => $source,
                    'expected' => $parameters['expected'] ?? $type->name,
                    'given' => $parameters['given'] ?? get_debug_type($value),
                ], previous: $exception);
        }
    }
}
