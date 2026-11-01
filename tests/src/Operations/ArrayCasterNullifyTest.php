<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsArray\Operations;

use Derafu\Array\ArrayCaster;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayCaster::class)]
class ArrayCasterNullifyTest extends TestCase
{
    public function testReplacesTheGivenValuesByNull(): void
    {
        $result = ArrayCaster::nullify(
            ['a' => false, 'b' => '', 'c' => 5, 'd' => 'text'],
            [false, '']
        );

        $this->assertSame(['a' => null, 'b' => null, 'c' => 5, 'd' => 'text'], $result);
    }

    public function testTraversesNestedArraysKeepingKeys(): void
    {
        $result = ArrayCaster::nullify(
            ['a' => [3 => ['x' => false, 'y' => 1], 'z' => ''], 'b' => []],
            [false, '']
        );

        $this->assertSame(['a' => [3 => ['x' => null, 'y' => 1], 'z' => null], 'b' => []], $result);
    }

    public function testComparisonIsStrict(): void
    {
        $input = ['zero' => 0, 'zeroText' => '0', 'zeroFloat' => 0.0, 'null' => null, 'true' => true];

        $this->assertSame($input, ArrayCaster::nullify($input, [false, '']));
    }

    public function testAcceptsIntegersFloatsAndStrings(): void
    {
        $result = ArrayCaster::nullify(
            ['a' => 0, 'b' => 1.5, 'c' => 'n/a', 'd' => 1, 'e' => '0'],
            [0, 1.5, 'n/a']
        );

        $this->assertSame(['a' => null, 'b' => null, 'c' => null, 'd' => 1, 'e' => '0'], $result);
    }

    public function testTheGivenArrayIsNotModified(): void
    {
        $input = ['a' => ['b' => false]];

        ArrayCaster::nullify($input, [false]);

        $this->assertSame(['a' => ['b' => false]], $input);
    }

    public function testNullifyBlankReplacesEmptyStringsAndEmptyArrays(): void
    {
        $result = ArrayCaster::nullifyBlank(['a' => '', 'b' => [], 'c' => null, 'd' => 'text', 'e' => 5]);

        $this->assertSame(['a' => null, 'b' => null, 'c' => null, 'd' => 'text', 'e' => 5], $result);
    }

    public function testNullifyBlankKeepsFalseZeroAndWhitespace(): void
    {
        $input = ['false' => false, 'zero' => 0, 'zeroText' => '0', 'zeroFloat' => 0.0, 'space' => ' '];

        $this->assertSame($input, ArrayCaster::nullifyBlank($input));
    }

    public function testNullifyBlankTraversesNestedArrays(): void
    {
        $result = ArrayCaster::nullifyBlank(['a' => [3 => ['x' => '', 'y' => 1], 'z' => []]]);

        $this->assertSame(['a' => [3 => ['x' => null, 'y' => 1], 'z' => null]], $result);
    }

    public function testNullifyBlankKeepsAnArrayWithOnlyBlankValues(): void
    {
        $this->assertSame(['a' => [null, null]], ArrayCaster::nullifyBlank(['a' => ['', []]]));
    }

    public function testNullifyBlankDoesNotModifyTheGivenArray(): void
    {
        $input = ['a' => ['b' => '']];

        ArrayCaster::nullifyBlank($input);

        $this->assertSame(['a' => ['b' => '']], $input);
    }
}
