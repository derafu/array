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
use Derafu\Array\ArrayCombinations;
use Derafu\Array\ArrayDuplicates;
use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayReader;
use Derafu\Array\ArrayTable;
use Derafu\Array\ArrayTree;
use Derafu\Array\ArrayWriter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Consumer return contracts are checked by PHPStan; PHPUnit checks their values.
 */
#[CoversClass(ArrayCaster::class)]
#[CoversClass(ArrayCombinations::class)]
#[CoversClass(ArrayDuplicates::class)]
#[CoversClass(ArrayPath::class)]
#[CoversClass(ArrayReader::class)]
#[CoversClass(ArrayTable::class)]
#[CoversClass(ArrayTree::class)]
#[CoversClass(ArrayWriter::class)]
final class ArrayTypeContractsTest extends TestCase
{
    public function testCasterRetainsStringKeys(): void
    {
        $this->assertSame(['amount' => 12, 'flag' => false], $this->castStringKeys(['amount' => '12', 'flag' => false]));
        $this->assertSame(['amount' => '12', 'flag' => null], $this->nullifyStringKeys(['amount' => '12', 'flag' => false]));
        $this->assertSame(['amount' => '12', 'blank' => null], $this->blankStringKeys(['amount' => '12', 'blank' => '']));
    }

    public function testCasterRetainsDiscontinuousAndNegativeIntegerKeys(): void
    {
        $this->assertSame([7 => 12, -2 => false], $this->castIntegerKeys([7 => '12', -2 => false]));
        $this->assertSame([7 => '12', -2 => null], $this->nullifyIntegerKeys([7 => '12', -2 => false]));
        $this->assertSame([7 => '12', -2 => null], $this->blankIntegerKeys([7 => '12', -2 => '']));
    }

    public function testWrappingRetainsBothKeyKindsAndTheirOrder(): void
    {
        $this->assertSame(
            ['detail' => [['code' => 1]], 'keep' => true],
            $this->wrapStringKeys(['detail' => ['code' => 1], 'keep' => true])
        );
        $this->assertSame(
            [7 => [['code' => 1]], -2 => true],
            $this->wrapIntegerKeys([7 => ['code' => 1], -2 => true])
        );
    }

    public function testCasterAndWrappingContractsAcceptEmptyArrays(): void
    {
        foreach ([
            $this->castStringKeys([]), $this->castIntegerKeys([]),
            $this->nullifyStringKeys([]), $this->nullifyIntegerKeys([]),
            $this->blankStringKeys([]), $this->blankIntegerKeys([]),
            $this->wrapStringKeys([]), $this->wrapIntegerKeys([]),
        ] as $result) {
            $this->assertSame([], $result);
        }
    }

    public function testMixedKeyInputsKeepTheirKeysAndValues(): void
    {
        $this->assertSame(['amount' => 12, 7 => false], ArrayCaster::cast(['amount' => '12', 7 => false]));
        $this->assertSame(['amount' => '12', 7 => null], ArrayCaster::nullify(['amount' => '12', 7 => false], [false]));
        $this->assertSame(['amount' => '12', 7 => null], ArrayCaster::nullifyBlank(['amount' => '12', 7 => '']));
        $this->assertSame(['detail' => [['code' => 1]], 7 => true], ArrayPath::wrap(['detail' => ['code' => 1], 7 => true], 'detail'));
    }

    public function testReaderRoundTripsRetainTheRootKeyKinds(): void
    {
        $map = ['name' => 'Ana', 'nested' => [7 => 1]];
        $indexed = [7 => ['name' => 'Ana'], -2 => 'last'];
        $this->assertSame($map, $this->readerStringKeys($map));
        $this->assertSame($indexed, $this->readerIntegerKeys($indexed));
    }

    public function testAddingAnIdColumnRetainsTheRowKeyKinds(): void
    {
        $this->assertSame(
            ['second' => ['id' => 'second', 'name' => 'B'], 'first' => ['id' => 'first', 'name' => 'A']],
            $this->idColumnStringKeys(['second' => ['name' => 'B'], 'first' => ['name' => 'A']])
        );
        $this->assertSame(
            [7 => ['id' => 7, 'name' => 'B'], -2 => ['id' => -2, 'name' => 'A']],
            $this->idColumnIntegerKeys([7 => ['name' => 'B'], -2 => ['name' => 'A']])
        );
    }

    public function testTreesRetainStringAndIntegerIdentifiers(): void
    {
        $this->assertSame(
            ['root' => ['name' => 'Root', 'children' => ['child' => ['name' => 'Child', 'children' => []]]]],
            $this->treeStringKeys([
                'root' => ['parent' => null, 'name' => 'Root'],
                'child' => ['parent' => 'root', 'name' => 'Child'],
            ])
        );
        $this->assertSame(
            [7 => ['name' => 'Root', 'children' => [-2 => ['name' => 'Child', 'children' => []]]]],
            $this->treeIntegerKeys([
                7 => ['parent' => null, 'name' => 'Root'],
                -2 => ['parent' => 7, 'name' => 'Child'],
            ])
        );
    }

    public function testDuplicateReportsRetainTheTypesOfBothIdentifiers(): void
    {
        $map = ['first' => ['id' => 1], 'second' => ['id' => 1]];
        $indexed = [7 => ['id' => 1], -2 => ['id' => 1]];
        $stringDuplicate = ['index' => 'second', 'first' => 'first', 'value' => [1]];
        $integerDuplicate = ['index' => -2, 'first' => 7, 'value' => [1]];
        $this->assertSame([$stringDuplicate], $this->duplicatesStringKeys($map));
        $this->assertSame([$integerDuplicate], $this->duplicatesIntegerKeys($indexed));
        $this->assertSame($stringDuplicate, $this->firstDuplicateStringKeys($map));
        $this->assertSame($integerDuplicate, $this->firstDuplicateIntegerKeys($indexed));
        $this->assertNull($this->firstDuplicateStringKeys(['only' => ['id' => 1]]));
        $this->assertNull($this->firstDuplicateIntegerKeys([7 => ['id' => 1]]));
    }

    public function testColumnConversionRetainsColumnKeyAndCellTypes(): void
    {
        $this->assertSame(
            [['first' => 10, 'second' => 30], ['first' => 20, 'second' => null]],
            $this->columnsStringKeys(['first' => [10, 20], 'second' => [30]])
        );
        $this->assertSame(
            [[7 => 10, -2 => 30], [7 => 20, -2 => null]],
            $this->columnsIntegerKeys([7 => [10, 20], -2 => [30]])
        );
    }

    public function testExplicitColumnsRetainCellTypesAndUsePhpKeyNormalization(): void
    {
        $this->assertSame(
            [['amount' => 10, 123 => null], ['amount' => 20, 123 => null]],
            $this->selectedColumns(['amount' => [10, 20]], ['amount', '123'])
        );
    }

    public function testSubsetsRetainTheirElementTypes(): void
    {
        $this->assertSame([[1], [2], [1, 2]], $this->integerSubsets([1, 2]));
        $this->assertSame([['a'], ['b'], ['a', 'b']], $this->stringSubsets(['a', 'b']));
    }

    public function testWriterCanAddAKeyKindThatWasAbsentFromItsInput(): void
    {
        $data = ['name' => 'Ana'];
        $writer = ArrayWriter::fromReference($data);
        $writer->set('123', 'value');
        $this->assertSame(['name' => 'Ana', 123 => 'value'], $writer->toArray());
        $this->assertSame($data, $writer->toArray());
    }

    public function testFlatteningCanProduceIntegerKeysFromStringRootKeys(): void
    {
        $this->assertSame([0 => 'value'], ArrayPath::flatten(['' => [7 => 'value']]));
    }

    public function testOutlinesCanIncludeAKeyKindFromDescendants(): void
    {
        $tree = ['root' => ['name' => 'Root', 'children' => [7 => ['name' => 'Child']]]];
        $this->assertSame([
            'root' => ['name' => 'Root', 'level' => 0],
            7 => ['name' => 'Child', 'level' => 1],
        ], ArrayTree::toOutline($tree, 'name', 'children'));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function castStringKeys(array $data): array
    {
        return ArrayCaster::cast($data);
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function castIntegerKeys(array $data): array
    {
        return ArrayCaster::cast($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function nullifyStringKeys(array $data): array
    {
        return ArrayCaster::nullify($data, [false]);
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function nullifyIntegerKeys(array $data): array
    {
        return ArrayCaster::nullify($data, [false]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function blankStringKeys(array $data): array
    {
        return ArrayCaster::nullifyBlank($data);
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function blankIntegerKeys(array $data): array
    {
        return ArrayCaster::nullifyBlank($data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function wrapStringKeys(array $data): array
    {
        return ArrayPath::wrap($data, 'detail');
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function wrapIntegerKeys(array $data): array
    {
        return ArrayPath::wrap($data, '7');
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function readerStringKeys(array $data): array
    {
        return ArrayReader::fromArray($data)->toArray();
    }

    /**
     * @param array<string, array<array-key, mixed>> $data
     * @return array<string, array<array-key, mixed>>
     */
    private function idColumnStringKeys(array $data): array
    {
        return ArrayTable::withKeyColumn($data, 'id');
    }

    /**
     * @param array<string, array<array-key, mixed>> $data
     * @return array<string, array<array-key, mixed>>
     */
    private function treeStringKeys(array $data): array
    {
        return ArrayTree::fromRows($data, 'parent', 'children');
    }

    /**
     * @param array<string, array<array-key, mixed>> $data
     * @return list<array{index: string, first: string, value: list<mixed>}>
     */
    private function duplicatesStringKeys(array $data): array
    {
        return ArrayDuplicates::find($data, ['id']);
    }

    /**
     * @param array<string, array<array-key, mixed>> $data
     * @return array{index: string, first: string, value: list<mixed>}|null
     */
    private function firstDuplicateStringKeys(array $data): ?array
    {
        return ArrayDuplicates::first($data, ['id']);
    }

    /**
     * @param array<string, array<int, int>> $data
     * @return list<array<string, int|null>>
     */
    private function columnsStringKeys(array $data): array
    {
        return ArrayTable::columnsToRows($data);
    }

    /**
     * @param array<int, mixed> $data
     * @return array<int, mixed>
     */
    private function readerIntegerKeys(array $data): array
    {
        return ArrayReader::fromArray($data)->toArray();
    }

    /**
     * @param array<int, array<array-key, mixed>> $data
     * @return array<int, array<array-key, mixed>>
     */
    private function idColumnIntegerKeys(array $data): array
    {
        return ArrayTable::withKeyColumn($data, 'id');
    }

    /**
     * @param array<int, array<array-key, mixed>> $data
     * @return array<int, array<array-key, mixed>>
     */
    private function treeIntegerKeys(array $data): array
    {
        return ArrayTree::fromRows($data, 'parent', 'children');
    }

    /**
     * @param array<int, array<array-key, mixed>> $data
     * @return list<array{index: int, first: int, value: list<mixed>}>
     */
    private function duplicatesIntegerKeys(array $data): array
    {
        return ArrayDuplicates::find($data, ['id']);
    }

    /**
     * @param array<int, array<array-key, mixed>> $data
     * @return array{index: int, first: int, value: list<mixed>}|null
     */
    private function firstDuplicateIntegerKeys(array $data): ?array
    {
        return ArrayDuplicates::first($data, ['id']);
    }

    /**
     * @param array<int, array<int, int>> $data
     * @return list<array<int, int|null>>
     */
    private function columnsIntegerKeys(array $data): array
    {
        return ArrayTable::columnsToRows($data);
    }

    /**
     * @param array<array-key, array<int, int>> $data
     * @param list<array-key> $keys
     * @return list<array<array-key, int|null>>
     */
    private function selectedColumns(array $data, array $keys): array
    {
        return ArrayTable::columnsToRows($data, $keys);
    }

    /**
     * @param list<int> $data
     * @return list<list<int>>
     */
    private function integerSubsets(array $data): array
    {
        return ArrayCombinations::subsets($data);
    }

    /**
     * @param list<string> $data
     * @return list<list<string>>
     */
    private function stringSubsets(array $data): array
    {
        return ArrayCombinations::subsets($data);
    }
}
