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

use Derafu\Array\ArrayPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayPath::class)]
class ArrayDotNestedTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $input
     */
    #[Test]
    #[DataProvider('provideDotData')]
    public function shouldFlattenArrayWithDotNotation(
        array $input,
        string $prefix,
        array $expected
    ): void {
        $result = ArrayPath::flatten($input, $prefix);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideDotData(): array
    {
        return [
            'simple nested array' => [
                ['a' => ['b' => 'c']],
                '',
                ['a.b' => 'c'],
            ],
            'with prefix' => [
                ['a' => ['b' => 'c']],
                'x',
                ['x.a.b' => 'c'],
            ],
            'flat array' => [
                ['a' => 1, 'b' => 2],
                '',
                ['a' => 1, 'b' => 2],
            ],
            'empty array' => [
                [],
                '',
                [],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $input
     */
    #[Test]
    #[DataProvider('provideNestedData')]
    public function shouldConvertDotNotationToNestedArray(
        array $input,
        array $expected
    ): void {
        $result = ArrayPath::expand($input);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideNestedData(): array
    {
        return [
            'simple dot notation' => [
                ['a.b' => 'c'],
                ['a' => ['b' => 'c']],
            ],
            'flat array' => [
                ['a' => 1, 'b' => 2],
                ['a' => 1, 'b' => 2],
            ],
            'empty array' => [
                [],
                [],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $original
     */
    #[Test]
    #[DataProvider('provideCycleData')]
    public function shouldCompleteCycleDotToNestedAndBack(
        array $original
    ): void {
        $dot = ArrayPath::flatten($original);
        $nested = ArrayPath::expand($dot);
        $this->assertSame($original, $nested);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideCycleData(): array
    {
        return [
            'simple nested structure' => [
                ['a' => ['b' => 'c']],
            ],
            'flat array' => [
                ['a' => 1, 'b' => 2],
            ],
            'empty array' => [
                [],
            ],
        ];
    }
}
