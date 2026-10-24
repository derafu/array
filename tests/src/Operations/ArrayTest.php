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
use Derafu\Array\ArrayCombinations;
use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayTable;
use Derafu\Array\ArrayTree;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayCaster::class)]
#[CoversClass(ArrayCombinations::class)]
#[CoversClass(ArrayPath::class)]
#[CoversClass(ArrayTable::class)]
#[CoversClass(ArrayTree::class)]
class ArrayTest extends TestCase
{
    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $input
     */
    #[Test]
    #[DataProvider('provideAutoCastData')]
    public function shouldAutoCastArrayValues(
        array $input,
        array $expected
    ): void {
        $result = ArrayCaster::cast($input);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideAutoCastData(): array
    {
        return [
            'simple types' => [
                ['1', '2.5', 'text', ''],
                [1, 2.5, 'text', false],
            ],
            'nested arrays' => [
                ['a' => ['1', '2.5'], 'b' => ['text', '']],
                ['a' => [1, 2.5], 'b' => ['text', false]],
            ],
            'special literals and empty text' => [
                ['1', '', '2', 'empty', 'null'],
                [1, false, 2, '', null],
            ],
            'whitespace retained unless interpreted' => [
                [' 1 ', ' text '],
                [1, ' text '],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $table
     */
    #[Test]
    #[DataProvider('provideTableToAssociativeData')]
    public function shouldConvertTableToAssociativeArray(
        array $table,
        array $expected
    ): void {
        $result = ArrayTable::pairsToMap($table);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideTableToAssociativeData(): array
    {
        return [
            'simple table' => [
                [['key1', 'value1'], ['key2', 'value2']],
                ['key1' => 'value1', 'key2' => 'value2'],
            ],
            'mixed types' => [
                [['key1', 1], ['key2', true]],
                ['key1' => 1, 'key2' => true],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, array<int, mixed>> $input
     * @param list<array-key>|null $keys
     */
    #[Test]
    #[DataProvider('provideGroupToTableData')]
    public function shouldConvertGroupedArrayToTable(
        array $input,
        ?array $keys,
        array $expected
    ): void {
        $result = ArrayTable::columnsToRows($input, $keys);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideGroupToTableData(): array
    {
        return [
            'simple groups' => [
                [
                    'key1' => [1, 2, 3],
                    'key2' => [4, 5, 6],
                ],
                null,
                [
                    ['key1' => 1, 'key2' => 4],
                    ['key1' => 2, 'key2' => 5],
                    ['key1' => 3, 'key2' => 6],
                ],
            ],
            'specific keys' => [
                [
                    'key1' => [1, 2],
                    'key2' => [3, 4],
                    'key3' => [5, 6],
                ],
                ['key1', 'key3'],
                [
                    ['key1' => 1, 'key3' => 5],
                    ['key1' => 2, 'key3' => 6],
                ],
            ],
            'uneven groups' => [
                [
                    'key1' => [1, 2],
                    'key2' => [3],
                ],
                null,
                [
                    ['key1' => 1, 'key2' => 3],
                    ['key1' => 2, 'key2' => null],
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, array<array-key, mixed>> $items
     */
    #[Test]
    #[DataProvider('provideTreeData')]
    public function shouldConvertArrayToTree(
        array $items,
        string $parentField,
        string $childrenField,
        mixed $parentId,
        array $expected
    ): void {
        $result = ArrayTree::fromRows($items, $parentField, $childrenField, $parentId);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideTreeData(): array
    {
        return [
            'simple tree' => [
                [
                    1 => ['name' => 'Parent', 'parent_id' => null],
                    2 => ['name' => 'Child', 'parent_id' => 1],
                ],
                'parent_id',
                'children',
                null,
                [
                    1 => [
                        'name' => 'Parent',
                        'children' => [
                            2 => ['name' => 'Child', 'children' => []],
                        ],
                    ],
                ],
            ],
            'multiple levels' => [
                [
                    1 => ['name' => 'Root', 'parent_id' => null],
                    2 => ['name' => 'Child1', 'parent_id' => 1],
                    3 => ['name' => 'Child2', 'parent_id' => 1],
                    4 => ['name' => 'Grandchild', 'parent_id' => 2],
                ],
                'parent_id',
                'children',
                null,
                [
                    1 => [
                        'name' => 'Root',
                        'children' => [
                            2 => [
                                'name' => 'Child1',
                                'children' => [
                                    4 => ['name' => 'Grandchild', 'children' => []],
                                ],
                            ],
                            3 => ['name' => 'Child2', 'children' => []],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $tree
     */
    #[Test]
    #[DataProvider('provideTreeToListData')]
    public function shouldConvertTreeToList(
        array $tree,
        string $nameField,
        string $childrenField,
        array $expected
    ): void {
        $result = ArrayTree::toOutline($tree, $nameField, $childrenField);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideTreeToListData(): array
    {
        return [
            'simple tree' => [
                [
                    1 => [
                        'name' => 'Parent',
                        'children' => [
                            2 => ['name' => 'Child', 'children' => []],
                        ],
                    ],
                ],
                'name',
                'children',
                [
                    1 => ['name' => 'Parent', 'level' => 0],
                    2 => ['name' => 'Child', 'level' => 1],
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<int|string, array<array-key, mixed>> $input
     */
    #[Test]
    #[DataProvider('provideIdAttributeData')]
    public function shouldAddIdAttribute(
        array $input,
        string $idField,
        array $expected
    ): void {
        $result = ArrayTable::withKeyColumn($input, $idField);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideIdAttributeData(): array
    {
        return [
            'numeric keys' => [
                [
                    1 => ['name' => 'Item 1'],
                    2 => ['name' => 'Item 2'],
                ],
                'id',
                [
                    1 => ['id' => 1, 'name' => 'Item 1'],
                    2 => ['id' => 2, 'name' => 'Item 2'],
                ],
            ],
            'string keys' => [
                [
                    'a' => ['name' => 'Item A'],
                    'b' => ['name' => 'Item B'],
                ],
                'key',
                [
                    'a' => ['key' => 'a', 'name' => 'Item A'],
                    'b' => ['key' => 'b', 'name' => 'Item B'],
                ],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $input
     */
    #[Test]
    #[DataProvider('provideArrayPathData')]
    public function shouldEnsureArrayAtPath(
        array $input,
        string $path,
        array $expected
    ): void {
        $input = ArrayPath::wrap($input, $path);
        $this->assertSame($expected, $input);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideArrayPathData(): array
    {
        return [
            'simple path' => [
                ['key' => 'value'],
                'key',
                ['key' => ['value']],
            ],
            'nested path' => [
                ['a' => ['b' => 'value']],
                'a.b',
                ['a' => ['b' => ['value']]],
            ],
            'nested path associative array' => [
                ['a' => ['b' => ['key' => 'value']]],
                'a.b',
                ['a' => ['b' => [['key' => 'value']]]],
            ],
            'already array' => [
                ['key' => ['value']],
                'key',
                ['key' => ['value']],
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param list<mixed> $input
     */
    #[Test]
    #[DataProvider('provideSubsetsData')]
    public function shouldGenerateSubsets(
        array $input,
        int $minLength,
        array $expected
    ): void {
        $result = ArrayCombinations::subsets($input, $minLength);
        sort($result); // Normalize order for comparison
        sort($expected);
        $this->assertSame($expected, $result);
    }

    /**
     * @return array<array-key, array<int, mixed>>
     */
    public static function provideSubsetsData(): array
    {
        return [
            'simple array' => [
                [1, 2],
                1,
                [[1], [2], [1, 2]],
            ],
            'with min length' => [
                [1, 2, 3],
                2,
                [[1, 2], [1, 3], [2, 3], [1, 2, 3]],
            ],
        ];
    }
}
