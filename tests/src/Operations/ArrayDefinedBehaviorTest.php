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

use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayTable;
use Derafu\Array\ArrayTree;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * What the methods of Arr do with the values that they can not process, which
 * is what their documentation says.
 */
#[CoversClass(ArrayPath::class)]
#[CoversClass(ArrayTable::class)]
#[CoversClass(ArrayTree::class)]
final class ArrayDefinedBehaviorTest extends TestCase
{
    #[Test]
    public function shouldKeepTheKeysThatAreIntegersInNested(): void
    {
        $this->assertSame(
            [0 => 'a', 1 => 'b', 'x' => ['y' => 1]],
            ArrayPath::expand([0 => 'a', 1 => 'b', 'x.y' => 1])
        );
    }

    #[Test]
    public function shouldReplaceAValueThatIsNotAnArrayInNested(): void
    {
        $this->assertSame(
            ['a' => ['b' => 2]],
            ArrayPath::expand(['a' => 1, 'a.b' => 2])
        );
        $this->assertSame(
            ['a' => ['b' => 1, 'c' => 2]],
            ArrayPath::expand(['a.b' => 1, 'a.c' => 2])
        );
    }

    #[Test]
    public function shouldSetAValueAtAPathWithoutModifyingTheArray(): void
    {
        $array = ['a' => ['b' => 1]];

        $this->assertSame(['a' => ['b' => 1, 'c' => 2]], ArrayPath::set($array, 'a.c', 2));
        $this->assertSame(['a' => ['b' => 1, 'c' => ['d' => 2]]], ArrayPath::set($array, ['a', 'c', 'd'], 2));
        $this->assertSame(['a' => ['b' => 1], 'x' => 3], ArrayPath::set($array, 'x', 3));
        $this->assertSame(['a' => ['b' => 1]], $array);
    }

    #[Test]
    public function shouldWrapAValueAtAPathWithoutModifyingTheArray(): void
    {
        $array = ['a' => ['b' => 'x'], 'c' => ['d', 'e']];

        $this->assertSame(['a' => ['b' => ['x']], 'c' => ['d', 'e']], ArrayPath::wrap($array, 'a.b'));
        $this->assertSame(['a' => ['b' => ['x']], 'c' => ['d', 'e']], ArrayPath::wrap($array, ['a', 'b']));
        $this->assertSame($array, ArrayPath::wrap($array, 'c'));
        $this->assertSame($array, ArrayPath::wrap($array, 'x.y'));
        $this->assertSame(['a' => ['b' => 'x'], 'c' => ['d', 'e']], $array);
    }

    #[Test]
    public function shouldLeaveTheArrayAsItIsWhenALevelIsNotAnArray(): void
    {
        $data = ['a' => 'text'];
        $data = ArrayPath::wrap($data, 'a.b');
        $this->assertSame(['a' => 'text'], $data);

        $data = ['a' => ['b' => 1]];
        $data = ArrayPath::wrap($data, 'a.x');
        $this->assertSame(['a' => ['b' => 1]], $data);
    }

    #[Test]
    public function shouldReturnTheKeyThatDoesNotExistAsNullInGroupToTable(): void
    {
        $this->assertSame(
            [
                ['key1' => 1, 'key2' => null],
                ['key1' => 2, 'key2' => null],
                ['key1' => 3, 'key2' => null],
            ],
            ArrayTable::columnsToRows(['key1' => [1, 2, 3]], ['key1', 'key2'])
        );
    }

    #[Test]
    public function shouldReturnNoRowsWhenNoneOfTheKeysExists(): void
    {
        $this->assertSame([], ArrayTable::columnsToRows(['a' => [1]], ['x', 'y']));
        $this->assertSame([], ArrayTable::columnsToRows([]));
    }

    #[Test]
    public function shouldTurnTheKeyOfATableIntoAnArrayKey(): void
    {
        $this->assertSame(
            ['' => 'null', 1 => 'true', '2.7' => 'float', 3 => 'int', 'a' => 'string'],
            ArrayTable::pairsToMap([
                [null, 'null'],
                [true, 'true'],
                [2.7, 'float'],
                [3, 'int'],
                ['a', 'string'],
            ])
        );
    }

    #[Test]
    public function shouldRejectAKeyThatIsNotScalar(): void
    {
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The first column of row 1 must be a scalar or null to be a key.');

        ArrayTable::pairsToMap([['a', 1], [new stdClass(), 2]]);
    }

    #[Test]
    public function shouldSkipTheItemsWithoutTheFieldOfTheParentInToTree(): void
    {
        $items = [
            1 => ['name' => 'root', 'parent' => null],
            2 => ['name' => 'no parent field'],
            3 => ['name' => 'child of the one that is skipped', 'parent' => 2],
        ];

        $this->assertSame(
            [1 => ['name' => 'root', 'children' => []]],
            ArrayTree::fromRows($items, 'parent', 'children')
        );
    }

    #[Test]
    public function shouldOmitTheChildrenThatAreNotAnArray(): void
    {
        $tree = [
            1 => ['name' => 'root', 'children' => [
                2 => ['name' => 'child'],
                3 => 'not an item',
            ]],
            4 => ['name' => 'text', 'children' => 'x'],
            5 => 'not an item',
            6 => ['other' => 'no name'],
        ];

        $this->assertSame(
            [
                1 => ['name' => 'root', 'level' => 0],
                2 => ['name' => 'child', 'level' => 1],
                4 => ['name' => 'text', 'level' => 0],
            ],
            ArrayTree::toOutline($tree, 'name', 'children')
        );
    }

    #[Test]
    public function shouldGetTheValueAtAPath(): void
    {
        $array = ['a' => ['b' => ['c' => 1, 'null' => null], 'list' => [['x' => 'y']]], 'z' => 0];

        $this->assertSame(1, ArrayPath::get($array, 'a.b.c'));
        $this->assertSame(1, ArrayPath::get($array, ['a', 'b', 'c']));
        $this->assertSame(['c' => 1, 'null' => null], ArrayPath::get($array, 'a.b'));
        $this->assertSame('y', ArrayPath::get($array, 'a.list.0.x'));
        $this->assertSame(0, ArrayPath::get($array, 'z'));
        $this->assertNull(ArrayPath::get($array, 'a.b.null', 'default'), 'A key whose value is null is there.');
    }

    #[Test]
    public function shouldGetTheDefaultWhenThePathDoesNotExist(): void
    {
        $array = ['a' => ['b' => 1], 'text' => 'abc'];

        $this->assertNull(ArrayPath::get($array, 'a.x'));
        $this->assertSame('d', ArrayPath::get($array, 'a.x', 'd'));
        $this->assertSame('d', ArrayPath::get($array, 'a.b.c', 'd'), 'A level that is not an array has no keys.');
        $this->assertSame('d', ArrayPath::get($array, 'text.0', 'd'), 'A string is not an array.');
        $this->assertSame('d', ArrayPath::get($array, 'x.y.z', 'd'));
    }
}
