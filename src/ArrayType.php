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

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException as InvalidArgumentException;
use Exception;

/**
 * A type that a value read from an array can be checked against.
 *
 * Types do not coerce: `"3"` is not an `int`. The only one that converts is
 * `dateTime()`, which says so with its name: a date is written as a text or a
 * timestamp in YAML and JSON, and what is wanted is a date. They are built from
 * the simple ones (`string()`, `int()`, `map()`, ...), the exact ones
 * (`literal()`, `instanceOf()`) and composed with `listOf()`, `mapOf()`,
 * `oneOf()` and `nullable()`, and every composition keeps the generic type
 * that `ArrayReader` (and PHPStan) will know:
 *
 *     ArrayType::mapOf(ArrayType::listOf(ArrayType::string()));
 *     // ArrayType<array<string, list<string>>>
 *
 * A custom type is built with the constructor, throwing `mismatch()` when the
 * value is not valid.
 *
 * @template-covariant T The type of the values it accepts.
 */
final readonly class ArrayType
{
    /**
     * @param string $name Name of the type, as reported in the errors.
     * @param Closure(mixed, string, string|null): T $parse Receives the value,
     * its path and the source of the array (if it has one), returns the value as
     * `T` or throws what `mismatch()` creates. A closure that does not need the
     * source can leave it out of its parameters.
     */
    public function __construct(
        public string $name,
        private Closure $parse
    ) {
    }

    /**
     * Checks a value against the type.
     *
     * @param mixed $value The value to check.
     * @param string $path Path of the value, for the error ("a.b.0").
     * @param string|null $source Where the array comes from (a file, a request,
     * anything that says it), for the error.
     * @return T The value.
     * @throws InvalidArgumentException If the value is not of the type.
     */
    public function parse(mixed $value, string $path = '', ?string $source = null): mixed
    {
        return ($this->parse)($value, $path, $source);
    }

    /**
     * Creates the error of a value that is not of the expected type.
     *
     * @param string $path Path of the value.
     * @param string $expected Name of the expected type.
     * @param mixed $given The value that was found.
     * @param string|null $source Where the array comes from, if it is known.
     */
    public static function mismatch(
        string $path,
        string $expected,
        mixed $given,
        ?string $source = null
    ): InvalidArgumentException {
        if ($source !== null) {
            return new InvalidArgumentException([
                'The key "{key}" of {source} must be of type {expected}, {given} given.',
                'key' => $path,
                'source' => $source,
                'expected' => $expected,
                'given' => get_debug_type($given),
            ]);
        }

        return new InvalidArgumentException([
            'The key "{key}" must be of type {expected}, {given} given.',
            'key' => $path,
            'expected' => $expected,
            'given' => get_debug_type($given),
        ]);
    }

    /**
     * Joins a path with the key or index below it.
     *
     * @param string $parent Path of the parent, empty at the root.
     * @param string|int $key Key or index.
     */
    public static function path(string $parent, string|int $key): string
    {
        return $parent === '' ? (string) $key : $parent . '.' . $key;
    }

    /**
     * Any value.
     *
     * @return self<mixed>
     */
    public static function mixed(): self
    {
        return new self('mixed', static fn (mixed $value): mixed => $value);
    }

    /**
     * @return self<string>
     */
    public static function string(): self
    {
        return new self('string', static fn (mixed $value, string $path, ?string $source): string
            => is_string($value) ? $value : throw self::mismatch($path, 'string', $value, $source));
    }

    /**
     * @return self<int>
     */
    public static function int(): self
    {
        return new self('int', static fn (mixed $value, string $path, ?string $source): int
            => is_int($value) ? $value : throw self::mismatch($path, 'int', $value, $source));
    }

    /**
     * @return self<float>
     */
    public static function float(): self
    {
        return new self('float', static fn (mixed $value, string $path, ?string $source): float
            => is_float($value) ? $value : throw self::mismatch($path, 'float', $value, $source));
    }

    /**
     * An integer or a float.
     *
     * @return self<float|int>
     */
    public static function number(): self
    {
        return new self('float|int', static fn (mixed $value, string $path, ?string $source): float|int
            => is_int($value) || is_float($value)
                ? $value
                : throw self::mismatch($path, 'float|int', $value, $source));
    }

    /**
     * @return self<bool>
     */
    public static function bool(): self
    {
        return new self('bool', static fn (mixed $value, string $path, ?string $source): bool
            => is_bool($value) ? $value : throw self::mismatch($path, 'bool', $value, $source));
    }

    /**
     * A boolean, a number or a string.
     *
     * @return self<bool|float|int|string>
     */
    public static function scalar(): self
    {
        return new self('bool|float|int|string', static fn (mixed $value, string $path, ?string $source): bool|float|int|string
            => is_scalar($value)
                ? $value
                : throw self::mismatch($path, 'bool|float|int|string', $value, $source));
    }

    /**
     * Any array, with whatever it has inside.
     *
     * @return self<array<array-key, mixed>>
     */
    public static function array(): self
    {
        return new self('array', static fn (mixed $value, string $path, ?string $source): array
            => is_array($value) ? $value : throw self::mismatch($path, 'array', $value, $source));
    }

    /**
     * An array with string keys, empty or not. Numeric strings that PHP turns
     * into integer keys are not map keys; use array() or arrayOf() for them.
     *
     * @return self<array<string, mixed>>
     */
    public static function map(): self
    {
        return new self('object', static fn (mixed $value, string $path, ?string $source): array
            => self::parseMap($value, $path, $source, 'object'));
    }

    /**
     * Validates every key, keeping the error name of the requested map type.
     *
     * @return array<string, mixed>
     */
    private static function parseMap(mixed $value, string $path, ?string $source, string $expected): array
    {
        if (!is_array($value)) {
            throw self::mismatch($path, $expected, $value, $source);
        }

        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw self::mismatch($path, $expected, $value, $source);
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /**
     * An array that is a list, empty or not.
     *
     * @return self<list<mixed>>
     */
    public static function list(): self
    {
        return new self('list', static function (mixed $value, string $path, ?string $source): array {
            if (!is_array($value) || !array_is_list($value)) {
                throw self::mismatch($path, 'list', $value, $source);
            }

            return $value;
        });
    }

    /**
     * A list whose items are all of a type.
     *
     * @template I
     * @param self<I> $item The type of each item.
     * @return self<list<I>>
     */
    public static function listOf(self $item): self
    {
        return new self(
            'list<' . $item->name . '>',
            static function (mixed $value, string $path, ?string $source) use ($item): array {
                if (!is_array($value) || !array_is_list($value)) {
                    throw self::mismatch($path, 'list<' . $item->name . '>', $value, $source);
                }

                return array_map(
                    static fn (mixed $v, int $i): mixed => $item->parse($v, self::path($path, $i), $source),
                    $value,
                    array_keys($value)
                );
            }
        );
    }

    /**
     * An array with arbitrary keys whose values match a type.
     *
     * @template I
     * @param self<I> $item The type of each value.
     * @return self<array<array-key, I>>
     */
    public static function arrayOf(self $item): self
    {
        return new self(
            'array<' . $item->name . '>',
            static function (mixed $value, string $path, ?string $source) use ($item): array {
                if (!is_array($value)) {
                    throw self::mismatch($path, 'array<' . $item->name . '>', $value, $source);
                }

                $result = [];
                foreach ($value as $key => $v) {
                    $result[$key] = $item->parse($v, self::path($path, $key), $source);
                }

                return $result;
            }
        );
    }

    /**
     * A map whose values are all of a type.
     *
     * @template I
     * @param self<I> $item The type of each value.
     * @return self<array<string, I>>
     */
    public static function mapOf(self $item): self
    {
        return new self(
            'object<' . $item->name . '>',
            static function (mixed $value, string $path, ?string $source) use ($item): array {
                $map = self::parseMap($value, $path, $source, 'object<' . $item->name . '>');
                $result = [];
                foreach ($map as $key => $v) {
                    $result[$key] = $item->parse($v, self::path($path, $key), $source);
                }

                return $result;
            }
        );
    }

    /**
     * A string that is not empty, nor only spaces.
     *
     * @return self<non-empty-string>
     */
    public static function nonEmptyString(): self
    {
        return new self('non-empty-string', static fn (mixed $value, string $path, ?string $source): string
            => is_string($value) && $value !== '' && trim($value) !== ''
                ? $value
                : throw self::mismatch($path, 'non-empty-string', $value, $source));
    }

    /**
     * Exactly one of some values: `literal('asc', 'desc')`, `literal(false)`.
     *
     * The comparison is strict: `0` is not `false`.
     *
     * @template L of bool|int|string
     * @param L $first
     * @param L ...$others
     * @return self<L>
     */
    public static function literal(bool|int|string $first, bool|int|string ...$others): self
    {
        $values = [$first, ...$others];
        $name = implode('|', array_map(static fn (bool|int|string $v): string => var_export($v, true), $values));

        return new self($name, static function (mixed $value, string $path, ?string $source) use ($values, $name): mixed {
            if (!in_array($value, $values, true)) {
                throw self::mismatch($path, $name, $value, $source);
            }

            return $value;
        });
    }

    /**
     * An object of a class or an interface.
     *
     * @template O of object
     * @param class-string<O> $class
     * @return self<O>
     */
    public static function instanceOf(string $class): self
    {
        return new self($class, static function (mixed $value, string $path, ?string $source) use ($class): object {
            if (!$value instanceof $class) {
                throw self::mismatch($path, $class, $value, $source);
            }

            return $value;
        });
    }

    /**
     * A date: a `DateTimeInterface` (it is given as it is), a timestamp (an
     * `int`) or a text that PHP reads as a date.
     *
     * It is the only type that converts, and a date that was not an object comes
     * as a `DateTimeImmutable`. A text that PHP can not read as a date is an
     * error, as is anything else.
     *
     * @return self<DateTimeInterface>
     */
    public static function dateTime(): self
    {
        return new self('date', static function (mixed $value, string $path, ?string $source): DateTimeInterface {
            if ($value instanceof DateTimeInterface) {
                return $value;
            }

            if (is_int($value)) {
                return new DateTimeImmutable('@' . $value);
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    return new DateTimeImmutable($value);
                } catch (Exception) {
                    // It is not a date: the error is the one of any other type.
                }
            }

            throw self::mismatch($path, 'date', $value, $source);
        });
    }

    /**
     * A value that is of any of the types. The first one that accepts it wins.
     *
     * @template I
     * @param self<I> $first
     * @param self<I> ...$others
     * @return self<I>
     */
    public static function oneOf(self $first, self ...$others): self
    {
        $types = [$first, ...$others];
        $name = implode('|', array_map(static fn (self $t): string => $t->name, $types));

        return new self(
            $name,
            static function (mixed $value, string $path, ?string $source) use ($types, $name): mixed {
                foreach ($types as $type) {
                    try {
                        return $type->parse($value, $path, $source);
                    } catch (InvalidArgumentException) {
                        // Next type.
                    }
                }

                throw self::mismatch($path, $name, $value, $source);
            }
        );
    }

    /**
     * The same type, that also accepts `null`.
     *
     * @return self<T|null>
     */
    public function nullable(): self
    {
        $type = $this;

        return new self(
            $this->name . '|null',
            static fn (mixed $value, string $path, ?string $source): mixed
                => $value === null ? null : $type->parse($value, $path, $source)
        );
    }
}
