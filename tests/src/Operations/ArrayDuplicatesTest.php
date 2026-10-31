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

use ArrayObject;
use Derafu\Array\ArrayDuplicates;
use Derafu\Array\ArrayPath;
use Derafu\Array\Exception\ArrayException;
use Derafu\Array\Exception\DuplicateValueException;
use Derafu\Translation\Contract\TranslatableInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass(ArrayDuplicates::class)]
#[CoversClass(DuplicateValueException::class)]
#[UsesClass(ArrayPath::class)]
#[UsesClass(ArrayException::class)]
final class ArrayDuplicatesTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private static function documents(): array
    {
        return [
            ['TpoDoc' => 33, 'NroDoc' => 21, 'Rut' => '11111111-1'],
            ['TpoDoc' => 33, 'NroDoc' => 22, 'Rut' => '11111111-1'],
            ['TpoDoc' => 61, 'NroDoc' => 21, 'Rut' => '11111111-1'],
            ['TpoDoc' => 33, 'NroDoc' => 21, 'Rut' => '22222222-2'],
        ];
    }

    public function testTheWholeItemIsComparedWhenThereAreNoPaths(): void
    {
        $items = [['a' => 1], ['a' => 2], ['a' => 1], 'text', 'text', 5];

        $this->assertSame([
            ['index' => 2, 'first' => 0, 'value' => [['a' => 1]]],
            ['index' => 4, 'first' => 3, 'value' => ['text']],
        ], ArrayDuplicates::find($items));
    }

    public function testTheComparisonKeepsTheTypeOfTheValues(): void
    {
        $this->assertSame([], ArrayDuplicates::find(['1', 1, 1.0, true, '01']));
    }

    public function testTheItemsAreComparedByTheValuesAtOnePath(): void
    {
        $this->assertSame([
            ['index' => 3, 'first' => 0, 'value' => [21]],
            ['index' => 2, 'first' => 0, 'value' => [21]],
        ], array_reverse(ArrayDuplicates::find(self::documents(), ['NroDoc'])));
    }

    public function testTheItemsAreComparedByTheValuesAtSeveralPaths(): void
    {
        $this->assertSame(
            [['index' => 3, 'first' => 0, 'value' => [33, 21]]],
            ArrayDuplicates::find(self::documents(), ['TpoDoc', 'NroDoc'])
        );
    }

    public function testThePathsCanBeAtAnyLevel(): void
    {
        $items = [
            ['Encabezado' => ['IdDoc' => ['Folio' => 5]], 'x' => 1],
            ['Encabezado' => ['IdDoc' => ['Folio' => 6]], 'x' => 1],
            ['Encabezado' => ['IdDoc' => ['Folio' => 5]], 'x' => 2],
        ];

        $this->assertSame(
            [['index' => 2, 'first' => 0, 'value' => [5]]],
            ArrayDuplicates::find($items, ['Encabezado.IdDoc.Folio'])
        );
        $this->assertSame(
            [['index' => 2, 'first' => 0, 'value' => [5]]],
            ArrayDuplicates::find($items, [['Encabezado', 'IdDoc', 'Folio']])
        );
    }

    public function testALevelAndALevelBelowCanBeMixed(): void
    {
        $items = [
            ['a' => 1, 'b' => ['c' => 2]],
            ['a' => 1, 'b' => ['c' => 3]],
            ['a' => 1, 'b' => ['c' => 2]],
        ];

        $this->assertSame(
            [['index' => 2, 'first' => 0, 'value' => [1, 2]]],
            ArrayDuplicates::find($items, ['a', 'b.c'])
        );
    }

    public function testAPathThatIsNotInTheItemIsNull(): void
    {
        $items = [['a' => 1], ['b' => 2], ['a' => null], ['c' => 3]];

        $this->assertSame([
            ['index' => 3, 'first' => 1, 'value' => [null]],
            ['index' => 2, 'first' => 1, 'value' => [null]],
        ], array_reverse(ArrayDuplicates::find($items, ['a'])));
    }

    public function testTheKeysOfTheListAreTheOnesThatAreReported(): void
    {
        $items = [10 => ['a' => 1], 'x' => ['a' => 2], 'y' => ['a' => 1]];

        $this->assertSame(
            [['index' => 'y', 'first' => 10, 'value' => [1]]],
            ArrayDuplicates::find($items, ['a'])
        );
    }

    public function testEachRepetitionPointsToTheFirstItemThatHadTheValues(): void
    {
        $this->assertSame([
            ['index' => 1, 'first' => 0, 'value' => [7]],
            ['index' => 2, 'first' => 0, 'value' => [7]],
        ], ArrayDuplicates::find([['a' => 7], ['a' => 7], ['a' => 7]], ['a']));
    }

    public function testTheValuesAreNormalizedBeforeComparingThemAndTheOnesReportedAreTheOriginals(): void
    {
        $items = [['n' => 21], ['n' => '21'], ['n' => ' 21 '], ['n' => '0021'], ['n' => '22']];
        $normalize = static fn (mixed $value): string => is_scalar($value) ? (string) (int) trim((string) $value) : '';

        $this->assertSame([
            ['index' => 1, 'first' => 0, 'value' => ['21']],
            ['index' => 2, 'first' => 0, 'value' => [' 21 ']],
            ['index' => 3, 'first' => 0, 'value' => ['0021']],
        ], ArrayDuplicates::find($items, ['n'], $normalize));
    }

    public function testTheObjectsAreReadThroughTheirPublicProperties(): void
    {
        $first = new class () {
            public int $folio = 1;

            private int $secret = 1;

            public function secret(): int
            {
                return $this->secret;
            }
        };
        $second = new class () {
            public int $folio = 1;

            private int $secret = 2;

            public function secret(): int
            {
                return $this->secret;
            }
        };

        $this->assertSame(1, $first->secret());
        $this->assertSame(2, $second->secret());
        $this->assertSame(
            [['index' => 1, 'first' => 0, 'value' => [1]]],
            ArrayDuplicates::find([$first, $second], ['folio'])
        );
    }

    public function testTheWholeObjectsAreComparedByTheirValuesWhenThereAreNoPaths(): void
    {
        $duplicates = ArrayDuplicates::find([new ArrayObject([1]), new ArrayObject([1]), new ArrayObject([2])]);

        $this->assertCount(1, $duplicates);
        $this->assertSame(1, $duplicates[0]['index']);
        $this->assertSame(0, $duplicates[0]['first']);
    }

    public function testFirstGivesTheFirstRepeatedItemOrNull(): void
    {
        $this->assertSame(
            ['index' => 3, 'first' => 0, 'value' => [33, 21]],
            ArrayDuplicates::first(self::documents(), ['TpoDoc', 'NroDoc'])
        );
        $this->assertNull(ArrayDuplicates::first(self::documents(), ['TpoDoc', 'NroDoc', 'Rut']));
        $this->assertNull(ArrayDuplicates::first([]));
    }

    public function testAnEmptyListHasNoRepeatedItems(): void
    {
        $this->assertSame([], ArrayDuplicates::find([], ['a']));
    }

    public function testAListWithoutRepeatedItemsIsUnique(): void
    {
        ArrayDuplicates::assertUnique(self::documents(), ['TpoDoc', 'NroDoc', 'Rut']);

        $this->expectNotToPerformAssertions();
    }

    public function testAnItemThatIsRepeatedIsAnErrorThatSaysWhichOnesAndTheValues(): void
    {
        try {
            ArrayDuplicates::assertUnique(self::documents(), ['TpoDoc', 'NroDoc']);
            $this->fail('A repeated item was accepted.');
        } catch (DuplicateValueException $e) {
            $this->assertInstanceOf(TranslatableInterface::class, $e);
            $this->assertSame('The item 3 has the same TpoDoc, NroDoc (33, 21) as the item 0.', $e->getMessage());
            $this->assertSame(3, $e->getIndex());
            $this->assertSame(0, $e->getFirst());
            $this->assertSame([33, 21], $e->getValues());
        }
    }

    public function testTheMessageNamesThePathsOfTheLevelsAndTheValuesThatAreNotText(): void
    {
        $items = [['a' => ['b' => null, 'c' => true]], ['a' => ['b' => null, 'c' => true]]];

        try {
            ArrayDuplicates::assertUnique($items, ['a.b', ['a', 'c']]);
            $this->fail('A repeated item was accepted.');
        } catch (DuplicateValueException $e) {
            $this->assertSame('The item 1 has the same a.b, a.c (null, true) as the item 0.', $e->getMessage());
        }
    }

    public function testTheMessageWithoutPathsSaysTheItemsAreTheSame(): void
    {
        try {
            ArrayDuplicates::assertUnique(['x', 'y', 'x']);
            $this->fail('A repeated item was accepted.');
        } catch (DuplicateValueException $e) {
            $this->assertSame('The item 2 is the same as the item 0.', $e->getMessage());
            $this->assertSame(['x'], $e->getValues());
        }
    }

    public function testTheValuesOfTheMessageThatAreArraysAreWrittenAsJson(): void
    {
        $items = [['a' => [1, 2]], ['a' => [1, 2]]];

        try {
            ArrayDuplicates::assertUnique($items, ['a']);
            $this->fail('A repeated item was accepted.');
        } catch (DuplicateValueException $e) {
            $this->assertSame('The item 1 has the same a ([1,2]) as the item 0.', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidPaths(): array
    {
        return [
            'null' => [null],
            'a float' => [1.5],
            'an empty list' => [[]],
            'a list with a list' => [['a', ['b']]],
            'a list with a float' => [['a', 1.5]],
        ];
    }

    #[DataProvider('invalidPaths')]
    public function testAPathThatIsNotAPathIsAnError(mixed $path): void
    {
        // The type of the paths is a rule of the method, so it is called without the check of its
        // declared type to see that a path that is not one is refused.
        $find = new ReflectionMethod(ArrayDuplicates::class, 'find');

        try {
            $find->invoke(null, [['a' => 1]], [$path]);
            $this->fail('A path that is not valid was accepted.');
        } catch (ArrayException $e) {
            $this->assertInstanceOf(TranslatableInterface::class, $e);
            $this->assertStringStartsWith('The path 0 must be a string, an integer or a list of them, ', $e->getMessage());
        }
    }

    public function testAnItemThatIsNotAnArrayNorAnObjectCanNotBeReadByPaths(): void
    {
        try {
            ArrayDuplicates::find([['a' => 1], 5], ['a', 'b.c']);
            $this->fail('An item that can not be read was accepted.');
        } catch (ArrayException $e) {
            $this->assertInstanceOf(TranslatableInterface::class, $e);
            $this->assertSame('The item 1 must be an array or an object to read a, b.c, int given.', $e->getMessage());
        }
    }

    public function testValuesThatCanNotBeComparedAreAnError(): void
    {
        try {
            ArrayDuplicates::find([['a' => static fn (): int => 1]], ['a']);
            $this->fail('A value that can not be compared was accepted.');
        } catch (ArrayException $e) {
            $this->assertInstanceOf(TranslatableInterface::class, $e);
            $this->assertStringStartsWith('The values of the item 0 can not be compared: ', $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function testALargeListIsComparedFast(): void
    {
        $items = [];
        for ($i = 0; $i < 50000; $i++) {
            $items[] = ['TpoDoc' => 33, 'NroDoc' => $i];
        }
        $items[] = ['TpoDoc' => 33, 'NroDoc' => 49999];

        $start = microtime(true);
        $duplicate = ArrayDuplicates::first($items, ['TpoDoc', 'NroDoc']);

        $this->assertSame(['index' => 50000, 'first' => 49999, 'value' => [33, 49999]], $duplicate);
        $this->assertLessThan(2.0, microtime(true) - $start);
    }
}
