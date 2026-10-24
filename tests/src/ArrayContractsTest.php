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
use Derafu\Array\ArrayTable;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayPath::class)]
#[CoversClass(ArrayReader::class)]
#[CoversClass(ArrayTable::class)]
final class ArrayContractsTest extends TestCase
{
    public function testTypedArraysPreserveMixedKeysAndNestedValues(): void
    {
        $values = [4 => ['a', 'b'], 'other' => [], 9 => ['c']];
        $reader = ArrayReader::fromArray(['values' => $values], 'settings.php');
        $item = ValueType::listOf(ValueType::string());

        $this->assertSame($values, $reader->getArrayOf('values', $item));
        $this->assertSame($values, $reader->requireArrayOf('values', $item));
        $this->assertSame('array<list<string>>', ValueType::arrayOf($item)->name);
    }

    public function testTypedArraysAcceptListsMapsAndEmptyArrays(): void
    {
        $type = ValueType::arrayOf(ValueType::int());
        foreach ([[], [1, 2], ['a' => 1], [8 => 1, 'a' => 2]] as $values) {
            $reader = ArrayReader::fromArray(['values' => $values]);
            $this->assertSame($values, $type->parse($values));
            $this->assertSame($values, $reader->getArrayOf('values', ValueType::int()));
            $this->assertSame($values, $reader->requireArrayOf('values', ValueType::int()));
        }
    }

    public function testOptionalTypedArraysTreatMissingAndNullAsAbsent(): void
    {
        foreach ([[], ['values' => null]] as $data) {
            $this->assertNull(ArrayReader::fromArray($data)->getArrayOf('values', ValueType::int()));
        }
    }

    public function testRequiredTypedArrayReportsTheMissingKeyAndSource(): void
    {
        $reader = ArrayReader::fromArray([], 'settings.php');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "values" of settings.php is required.');

        $reader->requireArrayOf('values', ValueType::int());
    }

    public function testRequiredTypedArrayRejectsNull(): void
    {
        $reader = ArrayReader::fromArray(['values' => null]);

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "values" is required.');

        $reader->requireArrayOf('values', ValueType::int());
    }

    public function testTypedArrayRejectsANonArray(): void
    {
        $reader = ArrayReader::fromArray(['values' => '1,2'], 'settings.php');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "values" of settings.php must be of type array<int>, string given.');

        $reader->getArrayOf('values', ValueType::int());
    }

    public function testNestedTypedArraysReportTheFullPathOfAnInvalidValue(): void
    {
        $reader = ArrayReader::fromArray(['groups' => ['first' => [3 => 1, 7 => '2']]], 'settings.php');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "groups.first.7" of settings.php must be of type int, string given.');

        $reader->requireMapOf('groups', ValueType::arrayOf(ValueType::int()));
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>}>
     */
    public static function invalidMapKeys(): iterable
    {
        yield 'list' => [[1, 2]];
        yield 'sparse integers' => [[3 => 1]];
        yield 'numeric string becomes integer' => [['123' => 1]];
        yield 'mixed keys' => [['name' => 1, 8 => 2]];
    }

    /**
     * @param array<array-key, mixed> $values
     */
    #[DataProvider('invalidMapKeys')]
    public function testMapsRejectEveryIntegerKey(array $values): void
    {
        foreach ([ValueType::map(), ValueType::mapOf(ValueType::int())] as $type) {
            try {
                $type->parse($values, 'values');
                $this->fail('A map must reject integer keys.');
            } catch (TranslatableInvalidArgumentException $exception) {
                $this->assertStringContainsString('The value at "values" must be of type ' . $type->name, $exception->getMessage());
            }
        }
    }

    public function testMapKeysThatRemainStringsArePreserved(): void
    {
        $values = ['01' => 1, 'name' => 2];
        $reader = ArrayReader::fromArray(['values' => $values]);

        $this->assertSame($values, $reader->requireMap('values'));
        $this->assertSame($values, $reader->requireMapOf('values', ValueType::int()));
        $this->assertSame([], ValueType::map()->parse([]));
    }

    public function testWrappingUsesAListCheckAndKeepsTheOriginalArray(): void
    {
        foreach ([[0 => 'a', 2 => 'b'], [0 => 'a', 'name' => 'b'], [2 => 'a']] as $value) {
            $original = ['items' => $value];
            $wrapped = ArrayPath::wrap($original, 'items');
            $this->assertSame(['items' => [$value]], $wrapped);
            $this->assertSame(['items' => $value], $original);
        }
        $this->assertSame(['items' => []], ArrayPath::wrap(['items' => []], 'items'));
        $this->assertSame(['items' => ['a', 'b']], ArrayPath::wrap(['items' => ['a', 'b']], 'items'));
        $this->assertSame(['items' => null], ArrayPath::wrap(['items' => null], 'items'));
    }

    public function testPairsRequireExactlyTwoColumns(): void
    {
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('Row 1 must have exactly 2 columns.');

        ArrayTable::pairsToMap([['valid', 1], ['invalid']]);
    }

    public function testPathsAndTypedReadersComposeWithoutCoercingValues(): void
    {
        $settings = ArrayPath::expand(['connection.host' => 'localhost', 'connection.port' => 5432]);
        $reader = ArrayReader::fromArray($settings, 'settings.php')->requireReader('connection');

        $this->assertSame('localhost', $reader->requireString('host'));
        $this->assertSame(5432, $reader->requireInt('port'));
        $this->assertSame(['connection.host' => 'localhost', 'connection.port' => 5432], ArrayPath::flatten($settings));
    }

    public function testReadersAreSnapshotsOfTheArrayAtCreation(): void
    {
        $data = ['port' => 5432];
        $reader = ArrayReader::fromArray($data);
        $data['port'] = 3306;

        $this->assertSame(5432, $reader->requireInt('port'));
        $this->assertSame(['port' => 5432], $reader->toArray());
        $this->assertSame(3306, $data['port']);
    }
}
