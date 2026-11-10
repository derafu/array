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

use Closure;
use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayReader;
use Derafu\Array\Exception\MissingValueException;
use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Array\Translation\ArrayTranslationResourceProvider;
use Derafu\Translation\TranslatorFactory;
use Derafu\Value\Exception\TypeMismatchException as ValueTypeMismatchException;
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayReader::class)]
#[CoversClass(ArrayTranslationResourceProvider::class)]
#[UsesClass(ArrayPath::class)]
final class ArrayReaderIndexedTest extends TestCase
{
    /** @return iterable<string, array{Closure(ArrayReader): mixed, bool, bool}> */
    public static function methods(): iterable
    {
        yield 'getIndexed' => [fn (ArrayReader $r) => $r->getIndexed('rows'), false, false];
        yield 'requireIndexed' => [fn (ArrayReader $r) => $r->requireIndexed('rows'), true, false];
        yield 'getIndexedOf' => [fn (ArrayReader $r) => $r->getIndexedOf('rows', ValueType::array()), false, false];
        yield 'requireIndexedOf' => [fn (ArrayReader $r) => $r->requireIndexedOf('rows', ValueType::array()), true, false];
        yield 'getIndexedReaders' => [fn (ArrayReader $r) => $r->getIndexedReaders('rows'), false, true];
        yield 'requireIndexedReaders' => [fn (ArrayReader $r) => $r->requireIndexedReaders('rows'), true, true];
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('methods')]
    public function testIntegerKeysAndOrderArePreserved(Closure $read, bool $required, bool $readers): void
    {
        $rows = [8 => ['name' => 'first'], -2 => ['last', null, false]];
        $reader = ArrayReader::fromArray(['rows' => $rows]);
        $result = $read($reader);

        $this->assertIsArray($result);
        $this->assertSame([8, -2], array_keys($result));
        if ($readers) {
            foreach ($result as $index => $item) {
                $this->assertInstanceOf(ArrayReader::class, $item);
                $this->assertSame($rows[$index], $item->toArray());
            }
        } else {
            $this->assertSame($rows, $result);
        }
        $this->assertSame(['rows' => $rows], $reader->toArray());
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('methods')]
    public function testEmptyArraysStayEmpty(Closure $read, bool $required, bool $readers): void
    {
        $this->assertSame([], $read(ArrayReader::fromArray(['rows' => []])));
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('methods')]
    public function testMissingAndNullKeepOptionalAndRequiredSemantics(Closure $read, bool $required, bool $readers): void
    {
        foreach ([[], ['rows' => null]] as $data) {
            $reader = new ArrayReader($data, 'payload', 'report.json');
            if (!$required) {
                $this->assertNull($read($reader));
                continue;
            }

            try {
                $read($reader);
                $this->fail('A required collection must not be absent or null.');
            } catch (MissingValueException $exception) {
                $this->assertSame('The key "payload.rows" of report.json is required.', $exception->getMessage());
            }
        }
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('methods')]
    public function testNonArraysAreRejectedWithoutFallback(Closure $read, bool $required, bool $readers): void
    {
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "rows" of report.json must be of type indexed');

        $read(ArrayReader::fromArray(['rows' => false], 'report.json'));
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('methods')]
    public function testStringKeysReportTheirPathAndSource(Closure $read, bool $required, bool $readers): void
    {
        try {
            $read(new ArrayReader(['rows' => [8 => [], 'extra' => []]], 'payload', 'report.json'));
            $this->fail('String row keys must be rejected.');
        } catch (TypeMismatchException $exception) {
            $this->assertInstanceOf(ValueTypeMismatchException::class, $exception->getPrevious());
            $this->assertSame(
                'The key "payload.rows.extra" of report.json must be of type int key, string given.',
                $exception->getMessage()
            );
        }
    }

    public function testStrictAndConvertingItemTypesDelegateToValue(): void
    {
        $reader = ArrayReader::fromArray(['rows' => [8 => '12.0', 2 => '1e3']]);
        $this->assertSame([8 => 12, 2 => 1000], $reader->getIndexedOf('rows', ValueType::toInt()));
        $this->assertSame([8 => 12, 2 => 1000], $reader->requireIndexedOf('rows', ValueType::toInt()));
        $this->assertSame(['rows' => [8 => '12.0', 2 => '1e3']], $reader->toArray());

        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "rows.8" must be of type int, string given.');

        $reader->requireIndexedOf('rows', ValueType::int());
    }

    public function testDefaultRowReadersRequireArrays(): void
    {
        $reader = ArrayReader::fromArray(['rows' => [8 => 'invalid']], 'report.json');
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "rows.8" of report.json must be of type array, string given.');

        $reader->requireIndexedReaders('rows');
    }

    public function testChildErrorsKeepTheirFullPathAndAreTranslated(): void
    {
        $reader = ArrayReader::fromArray(['payload' => ['rows' => [8 => ['quantity' => 'invalid']]]], 'report.json');
        $rows = $reader->requireReader('payload')->requireIndexedReaders('rows');

        try {
            $rows[8]->requireInt('quantity');
            $this->fail('The cell must have the requested type.');
        } catch (TypeMismatchException $exception) {
            $this->assertSame(
                'The key "payload.rows.8.quantity" of report.json must be of type int, string given.',
                $exception->getMessage()
            );
            $translator = TranslatorFactory::create('es', resourceProviders: [new ArrayTranslationResourceProvider()]);
            $this->assertSame(
                'La clave "payload.rows.8.quantity" de report.json debe ser de tipo int, se entregó string.',
                $exception->trans($translator)
            );
        }
    }

    public function testRootIndexedArraysPreserveValuesAndHaveInferredIntegerKeys(): void
    {
        $rows = [8 => null, -2 => false, 1 => ['name' => 'first']];
        $this->assertSame($rows, $this->indexedRoot(ArrayReader::fromArray($rows)));
        $this->assertSame([], ArrayReader::fromArray([])->toIndexed());
        $this->assertSame($rows, ArrayReader::fromArray(['rows' => $rows])->requireReader('rows')->toIndexed());
    }

    public function testRootValidationKeepsTheNestedPathAndSource(): void
    {
        $reader = ArrayReader::fromArray(['rows' => ['extra' => 1]], 'report.json')->requireReader('rows');
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "rows.extra" of report.json must be of type int key, string given.');

        $reader->toIndexed();
    }

    public function testTypedReadsHaveInferredIntegerKeysAndValues(): void
    {
        $reader = ArrayReader::fromArray(['rows' => [8 => ['first'], 2 => ['last']]]);

        $this->assertSame([8 => ['first'], 2 => ['last']], $this->typedRows($reader));
        $this->assertSame([8, 2], array_keys($this->rowReaders($reader)));
    }

    /** @return array<int, mixed> */
    private function indexedRoot(ArrayReader $reader): array
    {
        return $reader->toIndexed();
    }

    /** @return array<int, array<array-key, mixed>> */
    private function typedRows(ArrayReader $reader): array
    {
        return $reader->requireIndexedOf('rows', ValueType::array());
    }

    /** @return array<int, ArrayReader> */
    private function rowReaders(ArrayReader $reader): array
    {
        return $reader->requireIndexedReaders('rows');
    }
}
