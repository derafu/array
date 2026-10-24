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
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayCaster::class)]
class ArrayAutoCastRecursiveTest extends TestCase
{
    public function testAutoCastRecursive(): void
    {
        $array = [
            'integerString' => '42',
            'floatString' => '42.42',
            'negativeIntegerString' => '-42',
            'negativeFloatString' => '-42.42',
            'emptyString' => '',
            'stringWithSpaces' => '   123   ',
            'nonNumericString' => 'hello',
            'arrayWithMixedValues' => [
                'nestedIntegerString' => '10',
                'nestedFloatString' => '10.10',
                'nestedEmptyString' => '',
                'nestedNonNumericString' => 'world',
            ],
            'nonStringValue' => true, // Should remain unchanged.
        ];

        $expected = [
            'integerString' => 42,
            'floatString' => 42.42,
            'negativeIntegerString' => -42,
            'negativeFloatString' => -42.42,
            'emptyString' => false,
            'stringWithSpaces' => 123, // Trimmed and casted to int.
            'nonNumericString' => 'hello', // Remains unchanged.
            'arrayWithMixedValues' => [
                'nestedIntegerString' => 10,
                'nestedFloatString' => 10.10,
                'nestedEmptyString' => false,
                'nestedNonNumericString' => 'world', // Remains unchanged.
            ],
            'nonStringValue' => true, // Remains unchanged.
        ];

        $result = ArrayCaster::cast($array);

        $this->assertSame($expected, $result, 'The array was not transformed as expected.');
    }

    public function testAutoCastRecursiveUsesParserLiteralsAndBlankText(): void
    {
        $array = [
            'emptyString' => '',
            'emptyLiteral' => 'empty',
            'nullLiteral' => '(null)',
            'nestedArray' => ['whitespace' => '   ', 'falseLiteral' => 'false'],
        ];
        $this->assertSame([
            'emptyString' => false,
            'emptyLiteral' => '',
            'nullLiteral' => null,
            'nestedArray' => ['whitespace' => false, 'falseLiteral' => false],
        ], ArrayCaster::cast($array));
    }

    public function testAutoCastRecursiveNoCastsUnnecessaryValues(): void
    {
        $array = [
            'booleanTrue' => true,
            'booleanFalse' => false,
            'nullValue' => null,
            'integer' => 123,
            'float' => 123.45,
        ];

        $expected = $array; // Should remain unchanged.

        $result = ArrayCaster::cast($array);

        $this->assertSame($expected, $result, 'The non-string values were altered when they should not have been.');
    }
}
