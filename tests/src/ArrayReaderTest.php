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
use DateTimeImmutable;
use DateTimeInterface;
use Derafu\Array\ArrayReader;
use Derafu\Array\ArrayType;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayReader::class)]
#[UsesClass(ArrayType::class)]
final class ArrayReaderTest extends TestCase
{
    #[Test]
    public function shouldReadEveryKindOfValue(): void
    {
        $reader = ArrayReader::fromArray([
            'string' => 'a',
            'int' => 3,
            'float' => 1.5,
            'number' => 2,
            'bool' => false,
            'array' => [1, 'a' => 2],
            'map' => ['a' => 1],
            'list' => ['a', 1],
            'stringList' => ['a', 'b'],
            'intList' => [1, 2],
            'scalarList' => ['a', 1, true, 1.5],
            'listOf' => [['a' => 1], ['b' => 2]],
            'mapOf' => ['x' => ['a'], 'y' => []],
        ]);

        $this->assertSame('a', $reader->getString('string'));
        $this->assertSame(3, $reader->getInt('int'));
        $this->assertSame(1.5, $reader->getFloat('float'));
        $this->assertSame(2, $reader->getNumber('number'));
        $this->assertFalse($reader->getBool('bool'));
        $this->assertSame([1, 'a' => 2], $reader->getArray('array'));
        $this->assertSame(['a' => 1], $reader->getMap('map'));
        $this->assertSame(['a', 1], $reader->getList('list'));
        $this->assertSame(['a', 'b'], $reader->getStringList('stringList'));
        $this->assertSame([1, 2], $reader->getIntList('intList'));
        $this->assertSame(['a', 1, true, 1.5], $reader->getScalarList('scalarList'));
        $this->assertSame([['a' => 1], ['b' => 2]], $reader->getListOf('listOf', ArrayType::map()));
        $this->assertSame(
            ['x' => ['a'], 'y' => []],
            $reader->getMapOf('mapOf', ArrayType::listOf(ArrayType::string()))
        );
    }

    /**
     * @return array<string, array{Closure(ArrayReader): mixed}>
     */
    public static function readersProvider(): array
    {
        return [
            'string' => [fn (ArrayReader $r) => $r->getString('k')],
            'int' => [fn (ArrayReader $r) => $r->getInt('k')],
            'float' => [fn (ArrayReader $r) => $r->getFloat('k')],
            'number' => [fn (ArrayReader $r) => $r->getNumber('k')],
            'bool' => [fn (ArrayReader $r) => $r->getBool('k')],
            'array' => [fn (ArrayReader $r) => $r->getArray('k')],
            'map' => [fn (ArrayReader $r) => $r->getMap('k')],
            'list' => [fn (ArrayReader $r) => $r->getList('k')],
            'stringList' => [fn (ArrayReader $r) => $r->getStringList('k')],
            'intList' => [fn (ArrayReader $r) => $r->getIntList('k')],
            'scalarList' => [fn (ArrayReader $r) => $r->getScalarList('k')],
            'listOf' => [fn (ArrayReader $r) => $r->getListOf('k', ArrayType::string())],
            'mapOf' => [fn (ArrayReader $r) => $r->getMapOf('k', ArrayType::string())],
            'reader' => [fn (ArrayReader $r) => $r->getReader('k')],
        ];
    }

    /**
     * @param Closure(ArrayReader): mixed $read
     */
    #[Test]
    #[DataProvider('readersProvider')]
    public function shouldReturnNullWhenTheKeyIsAbsentOrNull(Closure $read): void
    {
        $this->assertNull($read(ArrayReader::fromArray([])));
        $this->assertNull($read(ArrayReader::fromArray(['k' => null])));
    }

    /**
     * @param Closure(ArrayReader): mixed $read
     */
    #[Test]
    #[DataProvider('readersProvider')]
    public function shouldRejectAValueOfAnotherType(Closure $read): void
    {
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "k" must be of type ');
        $read(ArrayReader::fromArray(['k' => new \stdClass()]));
    }

    #[Test]
    public function shouldReturnTheDefaultWhenTheKeyIsAbsent(): void
    {
        $reader = ArrayReader::fromArray(['present' => 'x', 'null' => null]);

        $this->assertSame('d', $reader->getString('missing', 'd'));
        $this->assertSame('d', $reader->getString('null', 'd'));
        $this->assertSame('x', $reader->getString('present', 'd'));
        $this->assertSame(7, $reader->getInt('missing', 7));
        $this->assertSame(0.5, $reader->getFloat('missing', 0.5));
        $this->assertSame(2, $reader->getNumber('missing', 2));
        $this->assertTrue($reader->getBool('missing', true));
        $this->assertSame('fallback', $reader->get('missing', ArrayType::int(), 'fallback'));
    }

    #[Test]
    public function shouldKeepAFalseValueInsteadOfTheDefault(): void
    {
        $reader = ArrayReader::fromArray(['bool' => false, 'int' => 0, 'string' => '']);

        $this->assertFalse($reader->getBool('bool', true));
        $this->assertSame(0, $reader->getInt('int', 7));
        $this->assertSame('', $reader->getString('string', 'd'));
    }

    #[Test]
    public function shouldTellIfAKeyIsThere(): void
    {
        $reader = ArrayReader::fromArray(['a' => 1, 'null' => null, 'false' => false]);

        $this->assertTrue($reader->hasValue('a'));
        $this->assertTrue($reader->hasValue('false'));
        $this->assertFalse($reader->hasValue('null'));
        $this->assertFalse($reader->hasValue('missing'));
    }

    #[Test]
    public function shouldRequireAKey(): void
    {
        $reader = ArrayReader::fromArray(['type' => 'string', 'bad' => 1]);

        $this->assertSame('string', $reader->require('type', ArrayType::string()));

        try {
            $reader->require('missing', ArrayType::string());
            $this->fail('A key that is not there should be an error.');
        } catch (TranslatableInvalidArgumentException $e) {
            $this->assertSame('The key "missing" is required.', $e->getMessage());
        }

        $this->expectExceptionMessage('The key "bad" must be of type string, int given.');
        $reader->require('bad', ArrayType::string());
    }

    #[Test]
    public function shouldReadANestedObjectReportingTheFullPath(): void
    {
        $reader = ArrayReader::fromArray(['options' => ['size' => 3, 'deep' => ['x' => 'a']]]);

        $options = $reader->getReader('options');
        $this->assertInstanceOf(ArrayReader::class, $options);
        $this->assertSame(3, $options->getInt('size'));

        $deep = $options->getReader('deep');
        $this->assertInstanceOf(ArrayReader::class, $deep);
        $this->assertSame(['x' => 'a'], $deep->toArray());

        try {
            $deep->getInt('x');
            $this->fail('The value is not an integer.');
        } catch (TranslatableInvalidArgumentException $e) {
            $this->assertSame('The key "options.deep.x" must be of type int, string given.', $e->getMessage());
        }

        try {
            $options->require('missing', ArrayType::int());
            $this->fail('A key that is not there should be an error.');
        } catch (TranslatableInvalidArgumentException $e) {
            $this->assertSame('The key "options.missing" is required.', $e->getMessage());
        }
    }

    #[Test]
    public function shouldReportThePathOfAnItemThatFails(): void
    {
        $reader = ArrayReader::fromArray(['dependentRequired' => ['a' => ['x', 2]]]);

        $this->expectExceptionMessage('The key "dependentRequired.a.1" must be of type string, int given.');
        $reader->getMapOf('dependentRequired', ArrayType::listOf(ArrayType::string()));
    }

    #[Test]
    public function shouldReturnTheWholeArray(): void
    {
        $this->assertSame(['a' => 1], ArrayReader::fromArray(['a' => 1])->toArray());
    }

    #[Test]
    public function shouldGiveTheDefaultThatIsNotNullAsTheTypeItHas(): void
    {
        $reader = ArrayReader::fromArray(['present' => 'a']);

        $this->assertSame('GET', $reader->getString('method', 'GET'));
        $this->assertSame('a', $reader->getString('present', 'GET'));
        $this->assertSame(80, $reader->getInt('port', 80));
        $this->assertSame(1.5, $reader->getFloat('ratio', 1.5));
        $this->assertSame(2, $reader->getNumber('size', 2));
        $this->assertTrue($reader->getBool('debug', true));
        $this->assertNull($reader->getString('method'));
    }

    #[Test]
    public function shouldReadANonEmptyStringAndADate(): void
    {
        $reader = ArrayReader::fromArray(['path' => '/api', 'blank' => ' ', 'date' => '2026-01-15', 'time' => 1768435200]);

        $this->assertSame('/api', $reader->getNonEmptyString('path'));
        $this->assertNull($reader->getNonEmptyString('missing'));
        $date = $reader->getDateTime('date');
        $this->assertInstanceOf(DateTimeInterface::class, $date);
        $this->assertSame('2026-01-15', $date->format('Y-m-d'));
        $this->assertSame(1768435200, $reader->getDateTime('time')?->getTimestamp());
        $this->assertNull($reader->getDateTime('missing'));

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "blank" must be of type non-empty-string, string given.');

        $reader->getNonEmptyString('blank');
    }

    #[Test]
    public function shouldReadAListOfObjectsAsReadersThatReportTheirPosition(): void
    {
        $reader = ArrayReader::fromArray([
            'parameters' => [['name' => 'id', 'in' => 'path'], ['name' => 5]],
        ]);

        $readers = $reader->getReaders('parameters');

        $this->assertNotNull($readers);
        $this->assertCount(2, $readers);
        $this->assertSame('id', $readers[0]->getString('name'));
        $this->assertSame('path', $readers[0]->getString('in'));

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "parameters.1.name" must be of type string, int given.');

        $readers[1]->getString('name');
    }

    #[Test]
    public function shouldReturnNullAndRejectWhatIsNotAListOfArraysInReaders(): void
    {
        $reader = ArrayReader::fromArray(['one' => ['a' => 1], 'items' => ['a', 'b']]);

        $this->assertNull($reader->getReaders('missing'));
        $this->assertSame([], ArrayReader::fromArray(['empty' => []])->getReaders('empty'));

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "items.0" must be of type array, string given.');

        $reader->getReaders('items');
    }

    #[Test]
    public function shouldAllowOnlyTheKeysThatAreSaid(): void
    {
        $reader = ArrayReader::fromArray(['path' => '/', 'maxAge' => 10]);

        $this->assertSame($reader, $reader->assertOnlyKeys('path', 'maxAge', 'other'));
        $this->assertSame('/', $reader->assertOnlyKeys('path', 'maxAge')->getString('path'));
    }

    #[Test]
    public function shouldRejectAKeyThatIsNotAllowed(): void
    {
        $reader = ArrayReader::fromArray(['path' => '/', 'allowedOrgins' => ['*']]);

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "allowedOrgins" is not allowed; the allowed keys are: path, allowedOrigins.');

        $reader->assertOnlyKeys('path', 'allowedOrigins');
    }

    #[Test]
    public function shouldRejectAKeyThatIsNotAllowedWithItsPathAndItsSource(): void
    {
        $rule = ArrayReader::fromArray(['rules' => [['path' => '/', 'typo' => 1]]], 'config/cors.yaml')->getReaders('rules');

        $this->assertNotNull($rule);
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "rules.0.typo" of config/cors.yaml is not allowed; the allowed keys are: path.');

        $rule[0]->assertOnlyKeys('path');
    }

    #[Test]
    public function shouldSayWhereTheArrayComesFromInTheErrorsOfTheReaderAndOfTheOnesItCreates(): void
    {
        $reader = ArrayReader::fromArray(['title' => 5, 'meta' => ['count' => 'x'], 'items' => [['id' => 'y']]], 'docs/guide.md');
        $items = $reader->getReaders('items');
        $this->assertNotNull($items);

        $checks = [
            fn () => $reader->getString('title'),
            fn () => $reader->require('missing', ArrayType::string()),
            fn () => $reader->getReader('meta')?->getInt('count'),
            fn () => $items[0]->getInt('id'),
        ];
        $messages = [
            'The key "title" of docs/guide.md must be of type string, int given.',
            'The key "missing" of docs/guide.md is required.',
            'The key "meta.count" of docs/guide.md must be of type int, string given.',
            'The key "items.0.id" of docs/guide.md must be of type int, string given.',
        ];

        foreach ($checks as $i => $check) {
            try {
                $check();
                $this->fail('The check should fail.');
            } catch (TranslatableInvalidArgumentException $e) {
                $this->assertSame($messages[$i], $e->getMessage());
            }
        }
    }

    /** @return array<string, array{Closure(ArrayReader): mixed, mixed, mixed, mixed}> */
    public static function requiredValues(): array
    {
        return [
            'string' => [fn (ArrayReader $r) => $r->requireString('k'), 'a', 'a', 1],
            'int' => [fn (ArrayReader $r) => $r->requireInt('k'), 0, 0, '0'],
            'float' => [fn (ArrayReader $r) => $r->requireFloat('k'), 1.5, 1.5, '1.5'],
            'number' => [fn (ArrayReader $r) => $r->requireNumber('k'), 2, 2, '2'],
            'bool' => [fn (ArrayReader $r) => $r->requireBool('k'), false, false, 0],
            'nonEmptyString' => [fn (ArrayReader $r) => $r->requireNonEmptyString('k'), ' a ', ' a ', ' '],
            'dateTime' => [fn (ArrayReader $r) => $r->requireDateTime('k')->format('Y-m-d'), '2026-01-15', '2026-01-15', []],
            'array' => [fn (ArrayReader $r) => $r->requireArray('k'), [1, 'a' => 2], [1, 'a' => 2], false],
            'map' => [fn (ArrayReader $r) => $r->requireMap('k'), ['a' => 1], ['a' => 1], ['a']],
            'list' => [fn (ArrayReader $r) => $r->requireList('k'), ['a', 1], ['a', 1], ['a' => 1]],
            'stringList' => [fn (ArrayReader $r) => $r->requireStringList('k'), ['a', 'b'], ['a', 'b'], ['a', 1]],
            'intList' => [fn (ArrayReader $r) => $r->requireIntList('k'), [0, 1], [0, 1], ['0']],
            'scalarList' => [fn (ArrayReader $r) => $r->requireScalarList('k'), [false, 0, 1.5, 'a'], [false, 0, 1.5, 'a'], [null]],
            'listOf' => [
                fn (ArrayReader $r) => $r->requireListOf('k', ArrayType::mapOf(ArrayType::string())),
                [['name' => 'a']], [['name' => 'a']], [['name' => 1]],
            ],
            'mapOf' => [
                fn (ArrayReader $r) => $r->requireMapOf('k', ArrayType::listOf(ArrayType::int())),
                ['ids' => [1, 2]], ['ids' => [1, 2]], ['ids' => ['1']],
            ],
            'reader' => [
                fn (ArrayReader $r) => $r->requireReader('k')->toArray(),
                ['name' => 'a'], ['name' => 'a'], 'a',
            ],
            'readers' => [
                fn (ArrayReader $r) => array_map(
                    static fn (ArrayReader $item): array => $item->toArray(),
                    $r->requireReaders('k')
                ),
                [['name' => 'a'], ['name' => 'b']], [['name' => 'a'], ['name' => 'b']], [['name' => 'a'], 'b'],
            ],
        ];
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('requiredValues')]
    public function testRequiredReadersReturnTheirTypedValues(
        Closure $read,
        mixed $value,
        mixed $expected,
        mixed $invalid
    ): void {
        $reader = ArrayReader::fromArray(['k' => $value]);

        $this->assertSame($expected, $read($reader));
        $this->assertSame(['k' => $value], $reader->toArray());
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('requiredValues')]
    public function testRequiredReadersRejectMissingAndNullValues(
        Closure $read,
        mixed $value,
        mixed $expected,
        mixed $invalid
    ): void {
        foreach ([[], ['k' => null]] as $data) {
            try {
                $read(ArrayReader::fromArray($data, 'request data'));
                $this->fail('A required key cannot be absent or null.');
            } catch (TranslatableInvalidArgumentException $e) {
                $this->assertSame('The key "k" of request data is required.', $e->getMessage());
            }
        }
    }

    /** @param Closure(ArrayReader): mixed $read */
    #[DataProvider('requiredValues')]
    public function testRequiredReadersRejectWrongTypesAndKeepTheSourceAndPath(
        Closure $read,
        mixed $value,
        mixed $expected,
        mixed $invalid
    ): void {
        $reader = ArrayReader::fromArray(['profile' => ['k' => $invalid]], 'request data')->requireReader('profile');
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "profile.k');
        $this->expectExceptionMessage('of request data must be of type');

        $read($reader);
    }

    public function testRequiredReadersKeepFalsyValuesAndAllowEmptyCollections(): void
    {
        $reader = ArrayReader::fromArray(['text' => '', 'integer' => 0, 'number' => 0.0, 'enabled' => false, 'empty' => []]);

        $this->assertSame('', $reader->requireString('text'));
        $this->assertSame(0, $reader->requireInt('integer'));
        $this->assertSame(0.0, $reader->requireNumber('number'));
        $this->assertFalse($reader->requireBool('enabled'));
        $this->assertSame([], $reader->requireArray('empty'));
        $this->assertSame([], $reader->requireMap('empty'));
        $this->assertSame([], $reader->requireList('empty'));
        $this->assertSame([], $reader->requireStringList('empty'));
        $this->assertSame([], $reader->requireIntList('empty'));
        $this->assertSame([], $reader->requireScalarList('empty'));
        $this->assertSame([], $reader->requireListOf('empty', ArrayType::string()));
        $this->assertSame([], $reader->requireMapOf('empty', ArrayType::string()));
        $this->assertSame([], $reader->requireReader('empty')->toArray());
        $this->assertSame([], $reader->requireReaders('empty'));
    }

    public function testRequiredNestedReadersPreserveTheirPathsAndSource(): void
    {
        $reader = ArrayReader::fromArray([
            'settings' => [
                'rules' => [['name' => 'first'], ['name' => 2]],
            ],
        ], 'config/rules.yaml')->requireReader('settings');
        $rules = $reader->requireReaders('rules');
        $this->assertSame('first', $rules[0]->requireString('name'));
        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "settings.rules.1.name" of config/rules.yaml must be of type string, int given.');

        $rules[1]->requireString('name');
    }

    public function testRequiredDateTimeKeepsTheExistingDateConversionPolicy(): void
    {
        $date = new DateTimeImmutable('@1768435200');
        $reader = ArrayReader::fromArray(['date' => $date, 'timestamp' => 1768435200]);

        $this->assertSame($date, $reader->requireDateTime('date'));
        $this->assertSame(1768435200, $reader->requireDateTime('timestamp')->getTimestamp());
    }
}
