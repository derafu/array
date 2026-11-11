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

use Derafu\Value\Exception\ValueConversionException;
use Derafu\Value\ValueInspector;
use Derafu\Value\ValueParser;

/**
 * Delegates recursive array value interpretation to ValueParser and replaces
 * given or blank values by `null`.
 */
final class ArrayCaster
{
    /**
     * Parses array leaves using ValueParser's options and defaults.
     *
     * @template K of array-key
     * @param array<K, mixed> $array The array to process.
     * @param bool $preserveNumericFormat Preserve numeric spelling as defined by ValueParser.
     * @param bool $preserveNumericPrecision Preserve numeric precision as defined by ValueParser.
     * @return array<K, mixed> Parsed values with their original keys.
     * @throws ValueConversionException If a Stringable value cannot be converted to text.
     */
    public static function cast(
        array $array,
        bool $preserveNumericFormat = false,
        bool $preserveNumericPrecision = false
    ): array {
        array_walk_recursive($array, static function (mixed &$value) use ($preserveNumericFormat, $preserveNumericPrecision): void {
            $value = ValueParser::parse($value, $preserveNumericFormat, $preserveNumericPrecision);
        });

        return $array;
    }

    /**
     * Replaces by `null` the leaves of the array that are one of the values.
     *
     * The comparison is strict: `0` is not `false` and `'0'` is not `''`.
     *
     * @template K of array-key
     * @param array<K, mixed> $array The array to process.
     * @param non-empty-list<bool|int|float|string> $values The values that become `null`.
     * @return array<K, mixed> The array, with its original keys.
     */
    public static function nullify(array $array, array $values): array
    {
        array_walk_recursive($array, static function (mixed &$value) use ($values): void {
            if (in_array($value, $values, true)) {
                $value = null;
            }
        });

        return $array;
    }

    /**
     * Replaces by `null` the values of the array that are blank, as
     * ValueInspector::isBlank() defines it: an empty string or an empty array.
     *
     * False, zero and whitespace-only strings are not blank, so they are kept.
     * The arrays are traversed, but an array is blank only when it is empty: one
     * that has only blank values is kept, with those values replaced by `null`.
     *
     * @template K of array-key
     * @param array<K, mixed> $array The array to process.
     * @return array<K, mixed> The array, with its original keys.
     */
    public static function nullifyBlank(array $array): array
    {
        foreach ($array as $key => $value) {
            if (ValueInspector::isBlank($value)) {
                $array[$key] = null;
            } elseif (is_array($value)) {
                $array[$key] = self::nullifyBlank($value);
            }
        }

        return $array;
    }
}
