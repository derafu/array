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

use ArrayObject;
use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayReader;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[UsesClass(ArrayPath::class)]
#[CoversClass(ArrayReader::class)]
final class ArrayReaderCollectionsTest extends TestCase
{
    public function testFromValuePreservesArrayKeysTypesAndSnapshots(): void
    {
        $data = [8 => 'name', 'options' => ['enabled' => false], 'nullable' => null];
        $reader = ArrayReader::fromValue($data);
        $data[8] = 'changed';

        $this->assertSame([8 => 'name', 'options' => ['enabled' => false], 'nullable' => null], $reader->toArray());
        $this->assertSame('name', $reader->requireString(8));
        $this->assertFalse($reader->requireReader('options')->requireBool('enabled'));
        $this->assertSame([], ArrayReader::fromValue([])->toArray());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonArrays(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'true' => [true];
        yield 'integer' => [1];
        yield 'float' => [1.5];
        yield 'empty text' => [''];
        yield 'JSON text' => ['{"name":"Alice"}'];
        yield 'object' => [new stdClass()];
        yield 'array object' => [new ArrayObject(['name' => 'Alice'])];
    }

    #[DataProvider('nonArrays')]
    public function testFromValueRejectsNonArraysWithoutConvertingThem(mixed $value): void
    {
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage(
            'The key "processed_data" of form data must be of type array, ' . get_debug_type($value) . ' given.'
        );

        ArrayReader::fromValue($value, source: 'form data', path: 'processed_data');
    }

    public function testFromValueCarriesItsSourceAndPathIntoNestedReaders(): void
    {
        $reader = ArrayReader::fromValue(
            ['rows' => [7 => ['amount' => '100']]],
            source: 'account API',
            path: 'response'
        );
        $records = $reader->requireArrayReaders('rows');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "response.rows.7.amount" of account API must be of type int, string given.');

        $records[7]->requireInt('amount');
    }

    public function testIntegerKeysWorkForGenericAndTypedReads(): void
    {
        $reader = ArrayReader::fromArray([
            0 => 'Alice', 2 => 42, 3 => 1.5, 4 => false, 5 => ['admin'], 6 => ['id' => 9],
        ]);

        $this->assertSame('Alice', $reader->getString(0));
        $this->assertSame('Alice', $reader->requireString(0));
        $this->assertSame(42, $reader->getInt(2));
        $this->assertSame(42, $reader->requireInt(2));
        $this->assertSame(1.5, $reader->getFloat(3));
        $this->assertSame(1.5, $reader->requireFloat(3));
        $this->assertFalse($reader->getBool(4));
        $this->assertFalse($reader->requireBool(4));
        $this->assertSame(['admin'], $reader->requireStringList(5));
        $this->assertSame(['id' => 9], $reader->requireMapOf(6, ValueType::int()));
        $this->assertSame(42, $reader->get(2, ValueType::int()));
        $this->assertSame(42, $reader->require(2, ValueType::int()));
        $this->assertSame('default', $reader->getString(99, 'default'));
    }

    public function testHasKeyDistinguishesNullFromMissingAndKeepsFalsyValues(): void
    {
        $reader = ArrayReader::fromArray([0 => null, 3 => false, 'empty' => '', 'zero' => 0, 'items' => []]);

        $this->assertTrue($reader->hasKey(0));
        $this->assertTrue($reader->hasKey('0'));
        $this->assertFalse($reader->hasValue(0));
        $this->assertFalse($reader->hasKey('missing'));
        $this->assertFalse($reader->hasValue('missing'));
        foreach ([3, 'empty', 'zero', 'items'] as $key) {
            $this->assertTrue($reader->hasKey($key));
            $this->assertTrue($reader->hasValue($key));
        }
    }

    public function testOptionalReadsStillUseTheirDefaultForNull(): void
    {
        $reader = ArrayReader::fromArray([0 => null]);

        $this->assertSame(12, $reader->getInt(0, 12));
        $this->assertNull($reader->getReader(0));
    }

    public function testAllowedKeysAcceptIntegerAndEquivalentStringKeys(): void
    {
        $reader = ArrayReader::fromArray([0 => 'name', 2 => null, '01' => 'code']);

        $this->assertSame($reader, $reader->assertOnlyKeys(0, 2, '01'));
        $this->assertSame($reader, $reader->assertOnlyKeys('0', '2', '01'));
    }

    public function testUnknownIntegerKeysKeepTheirDiagnosticPath(): void
    {
        $reader = ArrayReader::fromValue([2 => 'name'], source: 'form data', path: 'row');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "row.2" of form data is not allowed; the allowed keys are: 0, 1.');

        $reader->assertOnlyKeys(0, 1);
    }

    public function testNestedReadersAcceptListsMapsAndSparseArrays(): void
    {
        $reader = ArrayReader::fromArray(['authors' => [['Alice', 'alice'], ['name' => 'Bob'], [8 => 'Carol']]]);
        $authors = $reader->requireReader('authors');

        $this->assertSame('Alice', $authors->requireReader(0)->requireString(0));
        $this->assertSame('alice', $authors->requireReader(0)->getString(1));
        $this->assertSame('Bob', $authors->getReader(1)?->requireString('name'));
        $this->assertSame('Carol', $authors->getReader(2)?->requireString(8));
        $this->assertNull($authors->getReader(9));
    }

    public function testReaderListsKeepTheirOrderAndAcceptAllArrayKinds(): void
    {
        $values = [['Alice', 'alice'], ['name' => 'Bob'], [8 => 'Carol'], []];
        $reader = ArrayReader::fromArray(['authors' => $values]);
        $optional = $reader->getReaders('authors');
        $this->assertNotNull($optional);
        $required = $reader->requireReaders('authors');

        $this->assertSame([0, 1, 2, 3], array_keys($required));
        $this->assertSame($values, array_map(static fn (ArrayReader $row): array => $row->toArray(), $required));
        $this->assertSame($values, array_map(static fn (ArrayReader $row): array => $row->toArray(), $optional));
    }

    public function testListReadersStillRejectNonConsecutiveOuterKeys(): void
    {
        $reader = ArrayReader::fromArray(['rows' => [3 => ['name' => 'Alice']]], 'account API');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "rows" of account API must be of type list<array>, array given.');

        $reader->getReaders('rows');
    }

    public function testArrayReadersPreserveIntegerStringAndMixedKeys(): void
    {
        foreach ([
            [3 => ['name' => 'Alice'], 8 => ['name' => 'Bob']],
            ['alice' => ['name' => 'Alice'], 'bob' => ['name' => 'Bob']],
            [3 => ['Alice', 'alice'], 'bob' => ['name' => 'Bob']],
            [['name' => 'Alice'], ['name' => 'Bob']],
            [],
        ] as $records) {
            $reader = ArrayReader::fromArray([0 => $records]);
            $optional = $reader->getArrayReaders(0);
            $this->assertNotNull($optional);
            $required = $reader->requireArrayReaders(0);
            $this->assertSame(array_keys($records), array_keys($optional));
            $this->assertSame(array_keys($records), array_keys($required));
            $this->assertSame($records, array_map(static fn (ArrayReader $row): array => $row->toArray(), $optional));
            $this->assertSame($records, array_map(static fn (ArrayReader $row): array => $row->toArray(), $required));
        }
    }

    public function testOptionalArrayReadersTreatMissingAndNullAsAbsent(): void
    {
        $reader = ArrayReader::fromArray(['rows' => null]);

        $this->assertNull($reader->getArrayReaders('missing'));
        $this->assertNull($reader->getArrayReaders('rows'));
    }

    public function testRequiredArrayReadersRejectMissingAndNull(): void
    {
        foreach ([[], ['rows' => null]] as $values) {
            try {
                ArrayReader::fromValue($values, source: 'account API', path: 'response')->requireArrayReaders('rows');
                $this->fail('A required collection cannot be absent or null.');
            } catch (TranslatableInvalidArgumentException $exception) {
                $this->assertSame('The key "response.rows" of account API is required.', $exception->getMessage());
            }
        }
    }

    public function testArrayReadersRejectNonArrayCollections(): void
    {
        $reader = ArrayReader::fromArray(['rows' => 'Alice']);

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "rows" must be of type array<array>, string given.');

        $reader->getArrayReaders('rows');
    }

    public function testArrayReadersRejectNonArrayRecordsAtTheirOriginalKey(): void
    {
        $reader = ArrayReader::fromValue(['rows' => [3 => ['name' => 'Alice'], 'bob' => false]], source: 'account API', path: 'response');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "response.rows.bob" of account API must be of type array, bool given.');

        $reader->requireArrayReaders('rows');
    }

    public function testOptionalArrayReadersPreserveNestedErrorPaths(): void
    {
        $reader = ArrayReader::fromValue(['rows' => ['bob' => [9 => 123]]], source: 'account API', path: 'response');
        $rows = $reader->getArrayReaders('rows');
        $this->assertNotNull($rows);

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "response.rows.bob.9" of account API must be of type string, int given.');

        $rows['bob']->requireString(9);
    }

    public function testStrictMapReadsStillRejectLists(): void
    {
        $reader = ArrayReader::fromArray(['row' => ['Alice', 'alice']]);
        $this->assertSame('Alice', $reader->requireReader('row')->requireString(0));

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "row" must be of type map, array given.');

        $reader->requireMap('row');
    }
}
