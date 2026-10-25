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
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayReader::class)]
#[UsesClass(ArrayPath::class)]
final class ArrayReaderTypedReadersTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed, string, bool}>
     */
    public static function readerMethods(): iterable
    {
        yield 'getReader' => [fn (ArrayReader $reader, ValueType $type) => $reader->getReader('payload', $type), 'single', false];
        yield 'requireReader' => [fn (ArrayReader $reader, ValueType $type) => $reader->requireReader('payload', $type), 'single', true];
        yield 'getReaders' => [fn (ArrayReader $reader, ValueType $type) => $reader->getReaders('payload', $type), 'list', false];
        yield 'requireReaders' => [fn (ArrayReader $reader, ValueType $type) => $reader->requireReaders('payload', $type), 'list', true];
        yield 'getArrayReaders' => [fn (ArrayReader $reader, ValueType $type) => $reader->getArrayReaders('payload', $type), 'keyed', false];
        yield 'requireArrayReaders' => [fn (ArrayReader $reader, ValueType $type) => $reader->requireArrayReaders('payload', $type), 'keyed', true];
    }

    /**
     * @param array<array-key, mixed> $record
     * @return array<array-key, mixed>
     */
    private function payload(string $kind, array $record): array
    {
        return match ($kind) {
            'single' => $record,
            'list' => [$record],
            default => [7 => $record, 'last' => $record],
        };
    }

    private function path(string $kind): string
    {
        return 'response.payload' . match ($kind) {
            'single' => '',
            'list' => '.0',
            default => '.7',
        };
    }

    /** @param Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed $read */
    #[DataProvider('readerMethods')]
    public function testMapsKeepTheirValuesAndCollectionKeys(Closure $read, string $kind, bool $required): void
    {
        $record = ['name' => 'Ana', 'roles' => [7 => 'editor'], 'optional' => null];
        $payload = $this->payload($kind, $record);
        $reader = ArrayReader::fromArray(['payload' => $payload]);
        $result = $read($reader, ValueType::map());

        if ($kind === 'single') {
            $this->assertInstanceOf(ArrayReader::class, $result);
            $this->assertSame($record, $result->toArray());
        } else {
            $this->assertIsArray($result);
            $this->assertSame(array_keys($payload), array_keys($result));
            foreach ($result as $item) {
                $this->assertInstanceOf(ArrayReader::class, $item);
                $this->assertSame($record, $item->toArray());
            }
        }

        $this->assertSame(['payload' => $payload], $reader->toArray());
    }

    /** @param Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed $read */
    #[DataProvider('readerMethods')]
    public function testPositionalListsAreRejectedWhenMapsAreRequested(Closure $read, string $kind, bool $required): void
    {
        $reader = new ArrayReader(['payload' => $this->payload($kind, ['Ana'])], 'response', 'input.yaml');
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "' . $this->path($kind) . '" of input.yaml must be of type map, array given.');

        $read($reader, ValueType::map());
    }

    /** @param Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed $read */
    #[DataProvider('readerMethods')]
    public function testPositionalListsCanBeRequestedExplicitly(Closure $read, string $kind, bool $required): void
    {
        $reader = ArrayReader::fromArray(['payload' => $this->payload($kind, ['Ana', 'Chile'])]);
        $result = $read($reader, ValueType::listOf(ValueType::string()));
        if ($kind !== 'single') {
            $this->assertIsArray($result);
            $result = $result[$kind === 'list' ? 0 : 7];
        }
        $this->assertInstanceOf(ArrayReader::class, $result);
        $this->assertSame('Ana', $result->requireString(0));
        $this->assertSame('Chile', $result->requireString(1));
    }

    /** @param Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed $read */
    #[DataProvider('readerMethods')]
    public function testMissingAndNullValuesAreHandledBeforeParsing(Closure $read, string $kind, bool $required): void
    {
        $calls = 0;
        $type = new ValueType('map', static function (mixed $value, string $path, ?string $source) use (&$calls): array {
            ++$calls;
            return ValueType::map()->parse($value, $path, $source);
        });

        foreach ([[], ['payload' => null]] as $data) {
            $reader = new ArrayReader($data, 'response', 'input.yaml');
            if ($required) {
                try {
                    $read($reader, $type);
                    $this->fail('A required payload must not be absent or null.');
                } catch (MissingValueException $exception) {
                    $this->assertSame('The key "response.payload" of input.yaml is required.', $exception->getMessage());
                }
            } else {
                $this->assertNull($read($reader, $type));
            }
        }

        $this->assertSame(0, $calls);
    }

    /** @param Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed $read */
    #[DataProvider('readerMethods')]
    public function testTheParsedResultIsUsedAndEachRecordIsParsedOnce(Closure $read, string $kind, bool $required): void
    {
        $calls = 0;
        $type = new ValueType('integer map', static function (mixed $value, string $path, ?string $source) use (&$calls): array {
            ++$calls;
            return ValueType::mapOf(ValueType::toInt())->parse($value, $path, $source);
        });
        $payload = $this->payload($kind, ['amount' => '0012']);
        $reader = ArrayReader::fromArray(['payload' => $payload]);
        $result = $read($reader, $type);
        $records = $kind === 'single' ? [$result] : $result;
        $this->assertIsArray($records);
        foreach ($records as $record) {
            $this->assertInstanceOf(ArrayReader::class, $record);
            $this->assertSame(12, $record->requireInt('amount'));
        }
        $this->assertSame($kind === 'keyed' ? 2 : 1, $calls);
        $this->assertSame(['payload' => $payload], $reader->toArray());
    }

    /** @param Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed $read */
    #[DataProvider('readerMethods')]
    public function testCreatedReadersKeepTheirSourceAndPath(Closure $read, string $kind, bool $required): void
    {
        $reader = new ArrayReader(['payload' => $this->payload($kind, ['name' => 123])], 'response', 'input.yaml');
        $result = $read($reader, ValueType::map());
        if ($kind !== 'single') {
            $this->assertIsArray($result);
            $result = $result[$kind === 'list' ? 0 : 7];
        }
        $this->assertInstanceOf(ArrayReader::class, $result);
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "' . $this->path($kind) . '.name" of input.yaml must be of type string, int given.');

        $result->requireString('name');
    }

    public function testAnItemTypeDoesNotRelaxTheOuterListRequirement(): void
    {
        $reader = ArrayReader::fromArray(['rows' => [7 => ['name' => 'Ana']]]);
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "rows" must be of type list<map>, array given.');

        $reader->requireReaders('rows', ValueType::map());
    }

    /** @param Closure(ArrayReader, ValueType<array<array-key, mixed>>): mixed $read */
    #[DataProvider('readerMethods')]
    public function testEmptyDataRetainsItsMeaningWithAnExplicitType(Closure $read, string $kind, bool $required): void
    {
        $result = $read(ArrayReader::fromArray(['payload' => []]), ValueType::map());
        if ($kind === 'single') {
            $this->assertInstanceOf(ArrayReader::class, $result);
            $this->assertSame([], $result->toArray());
        } else {
            $this->assertSame([], $result);
        }
    }
}
