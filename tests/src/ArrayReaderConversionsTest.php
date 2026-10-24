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
use Derafu\Value\Exception\TypeMismatchException as ValueTypeMismatchException;
use Derafu\Value\Exception\ValueConversionException;
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[UsesClass(ArrayPath::class)]
#[CoversClass(ArrayReader::class)]
#[CoversClass(TypeMismatchException::class)]
#[CoversClass(MissingValueException::class)]
final class ArrayReaderConversionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, mixed, mixed, mixed}>
     */
    public static function conversions(): iterable
    {
        yield 'integer' => ['Int', '0012', 12, 3];
        yield 'float' => ['Float', '1e3', 1000.0, 2.5];
        yield 'number as int' => ['Number', '12.0', 12, 0];
        yield 'number as float' => ['Number', '12.5', 12.5, 0.0];
        yield 'boolean' => ['Bool', ' Off ', false, true];
        yield 'string' => ['String', false, '0', 'fallback'];
    }

    #[DataProvider('conversions')]
    public function testPublicGettersConvertAndHandleDefaults(string $suffix, mixed $input, mixed $expected, mixed $default): void
    {
        $reader = ArrayReader::fromArray(['present' => $input, 'null' => null, 3 => $input]);
        $get = 'getAs' . $suffix;
        $require = 'requireAs' . $suffix;
        $this->assertSame($expected, $reader->$get('present'));
        $this->assertSame($expected, $reader->$require('present'));
        $this->assertSame($expected, $reader->$get(3));
        $this->assertSame($expected, $reader->$require(3));
        foreach (['missing', 'null'] as $key) {
            $this->assertNull($reader->$get($key));
            $this->assertSame($default, $reader->$get($key, $default));
            try {
                $reader->$require($key);
                $this->fail('Required reads must reject missing and null values.');
            } catch (MissingValueException $exception) {
                $this->assertStringContainsString($key, $exception->getMessage());
            }
        }
        $this->assertSame($input, $reader->toArray()['present']);
    }

    #[DataProvider('conversions')]
    public function testInvalidValuesNeverUseTheDefault(string $suffix, mixed $input, mixed $expected, mixed $default): void
    {
        $reader = ArrayReader::fromArray(['value' => []]);
        $get = 'getAs' . $suffix;
        $this->expectException(TypeMismatchException::class);
        $reader->$get('value', $default);
    }

    public function testReportsNestedContextAndPreservesTheConversionCause(): void
    {
        $reader = ArrayReader::fromArray(['items' => [['quantity' => '12.5']]], 'order.json');
        try {
            $reader->requireReaders('items')[0]->requireAsInt('quantity');
            $this->fail('Fractions are not integer quantities.');
        } catch (TypeMismatchException $exception) {
            $this->assertSame('The key "items.0.quantity" of order.json must be of type int, string given.', $exception->getMessage());
            $cause = $exception->getPrevious();
            $this->assertInstanceOf(ValueTypeMismatchException::class, $cause);
            $this->assertInstanceOf(ValueConversionException::class, $cause->getPrevious());
        }
    }

    public function testConvertingCollectionsPreserveKeysAndInferConcreteTypes(): void
    {
        $reader = ArrayReader::fromArray([
            'quantities' => ['first' => '12.0', 5 => '1e3'],
            'amounts' => ['12.5', 2],
        ]);
        $this->assertSame(['first' => 12, 5 => 1000], $reader->requireArrayOf('quantities', ValueType::toInt()));
        $this->assertSame([12.5, 2.0], $reader->requireListOf('amounts', ValueType::toFloat()));

        // Typed consumers also verify that generic conversion results and
        // non-null defaults remain useful to PHPStan at level 9.
        $consumeInt = static fn (int $value): int => $value;
        $consumeFloat = static fn (float $value): float => $value;
        $consumeBool = static fn (bool $value): bool => $value;
        $consumeString = static fn (string $value): string => $value;
        $consumeNumber = static fn (float|int $value): float|int => $value;
        $this->assertSame(12, $consumeInt(ValueType::toInt()->parse('12.0')));
        $this->assertSame(2.5, $consumeFloat(ValueType::toFloat()->parse('2.5')));
        $this->assertTrue($consumeBool(ValueType::toBool()->parse('on')));
        $this->assertSame('0', $consumeString(ValueType::toString()->parse(false)));
        $this->assertSame(0, $consumeInt($reader->getAsInt('missing', 0)));
        $this->assertSame(0.0, $consumeFloat($reader->getAsFloat('missing', 0.0)));
        $this->assertFalse($consumeBool($reader->getAsBool('missing', false)));
        $this->assertSame('', $consumeString($reader->getAsString('missing', '')));
        $this->assertSame(0, $consumeNumber($reader->getAsNumber('missing', 0)));
        $this->assertSame(0.0, $consumeNumber($reader->getAsNumber('missing', 0.0)));
    }

    public function testUnionCanRecoverFromAConversionFailure(): void
    {
        $type = ValueType::oneOf(ValueType::toInt(), ValueType::string());
        $this->assertSame(12, $type->parse('12.0'));
        $this->assertSame('text', $type->parse('text'));
    }

    /**
     * @return iterable<string, array{Closure(ArrayReader): mixed}>
     */
    public static function strictReads(): iterable
    {
        yield 'integer' => [static fn (ArrayReader $reader) => $reader->requireInt('value')];
        yield 'float' => [static fn (ArrayReader $reader) => $reader->requireFloat('value')];
        yield 'boolean' => [static fn (ArrayReader $reader) => $reader->requireBool('value')];
    }

    /**
     * @param Closure(ArrayReader): mixed $read
     */
    #[DataProvider('strictReads')]
    public function testExistingPrimitiveReadsRemainStrict(Closure $read): void
    {
        $this->expectException(TypeMismatchException::class);
        $read(ArrayReader::fromArray(['value' => '1']));
    }
}
