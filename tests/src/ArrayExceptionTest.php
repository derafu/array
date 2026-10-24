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
use Derafu\Array\ArrayTable;
use Derafu\Array\Exception\ArrayException;
use Derafu\Array\Exception\MissingValueException;
use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Array\Exception\UnexpectedKeyException;
use Derafu\Array\Translation\ArrayTranslationResourceProvider;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use Derafu\Translation\TranslatorFactory;
use Derafu\Value\ValueType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[UsesClass(ArrayPath::class)]
#[CoversClass(ArrayReader::class)]
#[CoversClass(ArrayTable::class)]
#[CoversClass(ArrayException::class)]
#[CoversClass(MissingValueException::class)]
#[CoversClass(TypeMismatchException::class)]
#[CoversClass(UnexpectedKeyException::class)]
#[CoversClass(ArrayTranslationResourceProvider::class)]
final class ArrayExceptionTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): mixed, class-string<ArrayException>, string}>
     */
    public static function invalidReads(): iterable
    {
        yield 'missing value' => [
            static fn () => ArrayReader::fromArray([])->requireString('name'),
            MissingValueException::class,
            'The key "name" is required.',
        ];
        yield 'null value with source' => [
            static fn () => ArrayReader::fromValue(['name' => null], source: 'profile.json', path: 'user')->requireString('name'),
            MissingValueException::class,
            'The key "user.name" of profile.json is required.',
        ];
        yield 'wrong type' => [
            static fn () => ArrayReader::fromArray(['port' => '5432'])->requireInt('port'),
            TypeMismatchException::class,
            'The key "port" must be of type int, string given.',
        ];
        yield 'nested wrong type with source' => [
            static fn () => ArrayReader::fromValue(['rows' => [3 => ['port' => '5432']]], source: 'settings.json', path: 'config')
                ->requireArrayReaders('rows')[3]->requireInt('port'),
            TypeMismatchException::class,
            'The key "config.rows.3.port" of settings.json must be of type int, string given.',
        ];
        yield 'invalid root' => [
            static fn () => ArrayReader::fromValue(false, source: 'request', path: 'body'),
            TypeMismatchException::class,
            'The key "body" of request must be of type array, bool given.',
        ];
        yield 'unexpected key' => [
            static fn () => ArrayReader::fromArray(['typo' => 1])->assertOnlyKeys('name'),
            UnexpectedKeyException::class,
            'The key "typo" is not allowed; the allowed keys are: name.',
        ];
        yield 'unexpected key with source' => [
            static fn () => ArrayReader::fromValue([3 => 'name'], source: 'request', path: 'body')->assertOnlyKeys(0, 1),
            UnexpectedKeyException::class,
            'The key "body.3" of request is not allowed; the allowed keys are: 0, 1.',
        ];
        yield 'invalid pair row' => [
            static fn () => ArrayTable::pairsToMap([['name']]),
            ArrayException::class,
            'Row 0 must have exactly 2 columns.',
        ];
        yield 'invalid pair key' => [
            static fn () => ArrayTable::pairsToMap([[[], 'name']]),
            ArrayException::class,
            'The first column of row 0 must be a scalar or null to be a key.',
        ];
    }

    /**
     * @param Closure(): mixed $read
     * @param class-string<ArrayException> $expected
     */
    #[DataProvider('invalidReads')]
    public function testEveryCategoryCanBeCaughtThroughThePackageBase(Closure $read, string $expected, string $message): void
    {
        try {
            $read();
            $this->fail('The invalid input must be rejected.');
        } catch (ArrayException $exception) {
            $this->assertSame($expected, $exception::class);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param Closure(): mixed $read
     * @param class-string<ArrayException> $expected
     */
    #[DataProvider('invalidReads')]
    public function testExistingPhpExceptionCatchesStillWork(Closure $read, string $expected, string $message): void
    {
        try {
            $read();
            $this->fail('The invalid input must be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame($expected, $exception::class);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * @param Closure(): mixed $read
     * @param class-string<ArrayException> $expected
     */
    #[DataProvider('invalidReads')]
    public function testExistingTranslationExceptionCatchesStillWork(Closure $read, string $expected, string $message): void
    {
        try {
            $read();
            $this->fail('The invalid input must be rejected.');
        } catch (TranslatableInvalidArgumentException $exception) {
            $this->assertSame($expected, $exception::class);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    public function testSpecificMissingValueCatchWorksWhenTheKeyExistsButIsNull(): void
    {
        $reader = ArrayReader::fromArray(['name' => null]);
        $this->assertTrue($reader->hasKey('name'));
        $this->assertFalse($reader->hasValue('name'));

        $this->expectException(MissingValueException::class);
        $reader->requireString('name');
    }

    public function testUnionTypesTryTheNextCandidateAfterATypeMismatch(): void
    {
        $type = ValueType::oneOf(ValueType::int(), ValueType::string());

        $this->assertSame('text', $type->parse('text'));
        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "value" must be of type int|string, bool given.');

        ArrayReader::fromArray(['value' => false])->require('value', $type);
    }

    public function testCustomTypesUseTheSameMismatchException(): void
    {
        $positive = new ValueType('positive-int', static fn (mixed $value, string $path, ?string $source): int
            => is_int($value) && $value > 0
                ? $value
                : throw ValueType::mismatch($path, 'positive-int', $value, $source));

        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "quantity" of order.json must be of type positive-int, int given.');

        ArrayReader::fromArray(['quantity' => -1], 'order.json')->require('quantity', $positive);
    }

    public function testSpecializedExceptionsKeepTheirTranslationsAndParameters(): void
    {
        $translator = TranslatorFactory::create(
            defaultLocale: 'es',
            fallbackLocales: ['es', 'en'],
            resourceProviders: [new ArrayTranslationResourceProvider()],
        );
        $cases = [
            [static fn () => ArrayReader::fromArray([], 'form')->requireString('name'), 'La clave "name" de form es obligatoria.'],
            [static fn () => ArrayReader::fromArray(['name' => 42], 'form')->requireString('name'), 'La clave "name" de form debe ser de tipo string, se entregó int.'],
            [static fn () => ArrayReader::fromArray(['typo' => 42], 'form')->assertOnlyKeys('name'), 'La clave "typo" de form no está permitida; las claves permitidas son: name.'],
            [static fn () => ArrayTable::pairsToMap([['name']]), 'La fila 0 debe tener exactamente 2 columnas.'],
        ];
        foreach ($cases as [$read, $message]) {
            try {
                $read();
                $this->fail('The invalid input must be rejected.');
            } catch (ArrayException $exception) {
                $this->assertSame($message, $exception->trans($translator));
            }
        }
    }
}
