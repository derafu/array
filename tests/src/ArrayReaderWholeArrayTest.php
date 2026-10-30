<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsArray;

use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayReader;
use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayReader::class)]
#[UsesClass(ArrayPath::class)]
final class ArrayReaderWholeArrayTest extends TestCase
{
    public function testAMapIsReturnedWithItsKeysAndValues(): void
    {
        $reader = ArrayReader::fromArray(['name' => 'Ana', 'age' => 30, 'nested' => ['a' => 1]]);

        $this->assertSame(['name' => 'Ana', 'age' => 30, 'nested' => ['a' => 1]], $reader->toMap());
    }

    public function testAnEmptyArrayIsAMapAndAList(): void
    {
        $reader = ArrayReader::fromArray([]);

        $this->assertSame([], $reader->toMap());
        $this->assertSame([], $reader->toList());
    }

    public function testAListIsReturnedInOrder(): void
    {
        $this->assertSame(['a', 'b'], ArrayReader::fromArray(['a', 'b'])->toList());
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function arraysThatAreNotMaps(): iterable
    {
        yield 'list' => [['a', 'b']];
        yield 'integer key' => [['a' => 1, 5 => 2]];
        yield 'numeric string key' => [['a' => 1, '5' => 2]];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    #[DataProvider('arraysThatAreNotMaps')]
    public function testArraysWithNonStringKeysAreNotMaps(array $data): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "$" must be of type map, array given.');

        ArrayReader::fromArray($data)->toMap();
    }

    public function testAMapWithGapsIsNotAList(): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "$" must be of type list, array given.');

        ArrayReader::fromArray(['a' => 1])->toList();
    }

    public function testErrorsKeepThePathAndTheSourceOfTheReader(): void
    {
        $reader = ArrayReader::fromArray(['rows' => [['a', 'b']]], 'order.json')->requireReaders('rows')[0];

        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "rows.0" of order.json must be of type map, array given.');

        $reader->toMap();
    }

    public function testAnyTypeCanBeAppliedToTheWholeArray(): void
    {
        $reader = ArrayReader::fromArray(['a' => 1, 'b' => 2]);

        $this->assertSame(['a' => 1, 'b' => 2], $reader->to(ValueType::mapOf(ValueType::int())));
        $this->assertSame([1, 2], ArrayReader::fromArray([1, 2])->to(ValueType::listOf(ValueType::int())));
    }

    public function testTheItemsOfTheWholeArrayAreChecked(): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "b" must be of type int, string given.');

        ArrayReader::fromArray(['a' => 1, 'b' => 'x'])->to(ValueType::mapOf(ValueType::int()));
    }
}
