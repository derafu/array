<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsArray\Operations;

use Derafu\Array\ArrayCaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Recursive numeric interpretation follows ValueParser's defaults and options.
 */
#[CoversClass(ArrayCaster::class)]
final class ArrayCastNumericTest extends TestCase
{
    /**
     * @return array<string, array{string, mixed}>
     */
    public static function numbersProvider(): array
    {
        return [
            'integer' => ['42', 42],
            'negative integer' => ['-42', -42],
            'zero' => ['0', 0],
            'the greatest integer' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'the least integer' => [(string) PHP_INT_MIN, PHP_INT_MIN],
            'decimal' => ['42.42', 42.42],
            'decimal with a zero at the end' => ['10.10', 10.1],
            'decimal that has the digits of its float' => ['0.30000000000000004', 0.30000000000000004],
            'exponent that is an integer' => ['1e3', 1000],
            'exponent with a capital' => ['1E3', 1000],
            'exponent with a decimal' => ['1.5e3', 1500],
            'exponent that is negative' => ['1e-2', 0.01],
            'exponent that is negative with a decimal' => ['2.5e-3', 0.0025],
            'exponent that is greater than the integers' => ['1e25', 1.0E+25],
            'sign of plus' => ['+5', 5],
            'point at the beginning' => ['.5', 0.5],
            'point at the end' => ['1.', 1],
            'zero with decimals' => ['0.0', 0],
            'negative zero with decimals' => ['-0.0', 0],
            'whole number with decimals' => ['1.0', 1],
        ];
    }

    #[DataProvider('numbersProvider')]
    public function testANumericTextBecomesItsNumber(string $text, mixed $expected): void
    {
        $this->assertSame($expected, ArrayCaster::cast(['value' => $text])['value']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textsProvider(): array
    {
        return [
            'one more than the greatest integer' => ['9223372036854775808'],
            'one less than the least integer' => ['-9223372036854775809'],
            'integer of twenty digits' => ['12345678901234567890'],
            'exponent that is not finite' => ['1e400'],
            'negative exponent that is not finite' => ['-1e400'],
            'decimal that loses digits' => ['3.141592653589793238462643383279'],
            'decimal that loses digits at the end' => ['123456789.123456789'],
            'exponent that loses digits' => ['1.234567890123456789e5'],
            'exponent that is too small for a float' => ['1e-400'],
            'text that is not numeric' => ['12abc'],
            'hexadecimal' => ['0x1A'],
            'with a comma' => ['1,5'],
        ];
    }

    #[DataProvider('textsProvider')]
    public function testPrecisionOptionRetainsUnsafeNumericText(string $text): void
    {
        $this->assertSame($text, ArrayCaster::cast(['value' => $text], preserveNumericPrecision: true)['value']);
        $padded = '  ' . $text . "\t";
        $this->assertSame($padded, ArrayCaster::cast(['value' => $padded], preserveNumericPrecision: true)['value']);
    }
}
