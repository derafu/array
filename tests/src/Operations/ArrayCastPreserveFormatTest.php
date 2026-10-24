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
 * With `preserveFormat` a numeric text becomes a number only if it is written
 * the way that the number is (the zeros at the end of the decimals do not count):
 * the others stay as the text, because the number would not keep how it was
 * written.
 */
#[CoversClass(ArrayCaster::class)]
final class ArrayCastPreserveFormatTest extends TestCase
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
            'decimal' => ['42.42', 42.42],
            'negative decimal' => ['-42.42', -42.42],
            'decimal that is less than one' => ['0.5', 0.5],
            'decimal with a zero at the end' => ['19.90', 19.9],
            'decimal with zeros at the end' => ['1.500', 1.5],
            'whole number with decimals' => ['1.0', 1.0],
            'zero with decimals' => ['0.00', 0.0],
        ];
    }

    #[DataProvider('numbersProvider')]
    public function testATextThatIsWrittenLikeItsNumberBecomesIt(string $text, mixed $expected): void
    {
        $this->assertSame($expected, ArrayCaster::cast(['value' => $text], preserveFormat: true)['value']);
        $this->assertSame($expected, ArrayCaster::cast(['value' => '  ' . $text . ' '], preserveFormat: true)['value'], 'It is trimmed first.');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textsProvider(): array
    {
        return [
            'zeros at the beginning' => ['007'],
            'zeros at the beginning of a decimal' => ['007.5'],
            'only zeros' => ['00'],
            'negative with zeros at the beginning' => ['-007'],
            'sign of plus' => ['+5'],
            'negative zero' => ['-0'],
            'point at the beginning' => ['.5'],
            'point at the end' => ['1.'],
            'exponent' => ['1e3'],
            'negative exponent' => ['1e-2'],
            'exponent with a decimal' => ['1.5E3'],
            'one more than the greatest integer' => ['9223372036854775808'],
            'decimal that loses digits' => ['3.141592653589793238462643383279'],
        ];
    }

    #[DataProvider('textsProvider')]
    public function testATextThatIsNotWrittenLikeItsNumberStaysAsTheText(string $text): void
    {
        $this->assertSame($text, ArrayCaster::cast(['value' => $text], preserveFormat: true)['value']);
        $this->assertSame($text, ArrayCaster::cast(['value' => ' ' . $text . "\n"], preserveFormat: true)['value'], 'It is trimmed.');
    }

    public function testWhatIsNotANumericTextIsAsWithoutTheOption(): void
    {
        $array = ['a' => ' x ', 'b' => '', 'c' => ['d' => true, 'e' => 5, 'f' => null, 'g' => '12abc']];

        $this->assertSame(
            ['a' => 'x', 'b' => null, 'c' => ['d' => true, 'e' => 5, 'f' => null, 'g' => '12abc']],
            ArrayCaster::cast($array, null, preserveFormat: true)
        );
    }

    public function testWithoutTheOptionTheTextsAreNumbersAsAlways(): void
    {
        $this->assertSame(
            ['a' => 7, 'b' => 5, 'c' => 1000, 'd' => 1.5],
            ArrayCaster::cast(['a' => '007', 'b' => '+5', 'c' => '1e3', 'd' => '1.50'])
        );
    }
}
