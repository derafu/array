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
use Derafu\Value\ValueParser;

/**
 * Delegates recursive array value interpretation to ValueParser.
 */
final class ArrayCaster
{
    /**
     * Parses array leaves using ValueParser's options and defaults.
     *
     * @param array<array-key, mixed> $array The array to process.
     * @param bool $preserveNumericFormat Preserve numeric spelling as defined by ValueParser.
     * @param bool $preserveNumericPrecision Preserve numeric precision as defined by ValueParser.
     * @return array<array-key, mixed> Parsed values with their original keys.
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
}
