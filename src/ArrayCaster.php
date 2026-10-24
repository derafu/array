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

/**
 * Explicitly converts array values while preserving numeric precision.
 */
final class ArrayCaster
{
    /**
     * Recursively casts array values automatically based on their content.
     *
     * Rules applied:
     *
     *   - Trims strings.
     *   - Converts numeric strings to their appropriate type (int or float),
     *     when the number is the one that the string says: a string that does
     *     not fit in an int, that is not finite or that has more digits than its
     *     float can keep stays as the string (trimmed).
     *   - Can replace empty strings with a specified value.
     *
     * With `$preserveFormat` a numeric string is only converted if it is written
     * the way that its number is: `'42'`, `'-7'`, `'0.5'`, `'19.90'` (the zeros
     * at the end of the decimals do not count). The ones that the number would
     * not keep as they are written stay as the string: `'007'`, `'+5'`, `'-0'`,
     * `'.5'`, `'1.'` and the ones with an exponent (`'1e3'`).
     *
     * @param array<array-key, mixed> $array The array to process.
     * @param mixed $emptyValue Value to use for empty strings.
     * @param bool $preserveFormat Convert only the numeric strings that are
     * written like their number.
     * @return array<array-key, mixed> The processed array with cast values.
     */
    public static function cast(array $array, mixed $emptyValue = '', bool $preserveFormat = false): array
    {
        array_walk_recursive($array, function (&$value, $key, $emptyValue) use ($preserveFormat) {
            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    $value = $emptyValue;
                } elseif (is_numeric($value) && (!$preserveFormat || self::isWrittenAsNumber($value))) {
                    $value = self::toNumber($value);
                }
            }
        }, $emptyValue);

        return $array;
    }

    /**
     * Whether a numeric string is written the way that its number is: no sign
     * of plus, no zeros at the beginning, no point at the beginning nor at the
     * end, no exponent, and not `-0`. The zeros at the end of the decimals do
     * not count, because the number is the same.
     *
     * @param string $text A string that is numeric (see `is_numeric()`).
     */
    private static function isWrittenAsNumber(string $text): bool
    {
        return $text !== '-0' && preg_match('/^-?(0|[1-9]\d*)(\.\d+)?$/', $text) === 1;
    }

    /**
     * Gives the number that a numeric string says, or the string if the number
     * would not be the same.
     *
     * An integer is an int if it fits in one. A number with a decimal point is
     * a float, and an exponent gives an int when the number is a whole one that
     * an int can hold and a float if not. A float is only given when it keeps all
     * the digits of the string and the number is finite.
     *
     * @param string $text A string that is numeric (see `is_numeric()`).
     * @return int|float|string The number, or the string.
     */
    private static function toNumber(string $text): int|float|string
    {
        // A whole number, with no point nor exponent: an int if it fits.
        if (preg_match('/^([+-]?)(\d+)$/', $text, $parts) === 1) {
            $integer = filter_var($parts[1] . (ltrim($parts[2], '0') ?: '0'), FILTER_VALIDATE_INT);

            return $integer === false ? $text : $integer;
        }

        $float = (float) $text;
        if (!is_finite($float) || self::digits($text) !== self::digits(self::shortest($float))) {
            return $text;
        }

        // With an exponent and no point (`1e3`), a whole number is an int, if it
        // can hold it without losing anything (the floats are whole numbers up
        // to 2^53 exactly).
        if (!str_contains($text, '.') && floor($float) === $float && abs($float) < 9007199254740992.0) {
            return (int) $float;
        }

        return $float;
    }

    /**
     * The digits that a number says, with no sign, no point, no exponent and no
     * zeros at the beginning nor at the end: they are the ones that must be the
     * same in a number and in its float.
     *
     * @param string $number The number written as text.
     */
    private static function digits(string $number): string
    {
        $mantissa = explode('e', strtolower($number))[0];

        return trim(str_replace(['+', '-', '.'], '', $mantissa), '0');
    }

    /**
     * The number written with the least digits that give the same float back.
     */
    private static function shortest(float $float): string
    {
        for ($precision = 0; $precision < 17; $precision++) {
            $text = sprintf('%.' . $precision . 'e', $float);
            if ((float) $text === $float) {
                break;
            }
        }

        return $text;
    }
}
