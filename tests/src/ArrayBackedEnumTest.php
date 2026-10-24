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

use BackedEnum;
use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayReader;
use Derafu\Array\Translation\ArrayTranslationResourceProvider;
use Derafu\TestsArray\Fixture\EmptyNumberStatus;
use Derafu\TestsArray\Fixture\EmptyTextStatus;
use Derafu\TestsArray\Fixture\NumberStatus;
use Derafu\TestsArray\Fixture\OtherNumberStatus;
use Derafu\TestsArray\Fixture\OtherTextStatus;
use Derafu\TestsArray\Fixture\PureStatus;
use Derafu\TestsArray\Fixture\TextStatus;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

require_once __DIR__ . '/../fixtures/backed-enums.php';

#[UsesClass(ArrayPath::class)]
#[CoversClass(ArrayReader::class)]
#[CoversClass(ArrayTranslationResourceProvider::class)]
final class ArrayBackedEnumTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<BackedEnum>, mixed, BackedEnum}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'string backing' => [TextStatus::class, 'approved', TextStatus::Approved];
        yield 'numeric string backing' => [TextStatus::class, '1', TextStatus::Numeric];
        yield 'empty string backing' => [TextStatus::class, '', TextStatus::Blank];
        yield 'integer backing' => [NumberStatus::class, 1, NumberStatus::Approved];
        yield 'zero backing' => [NumberStatus::class, 0, NumberStatus::Pending];
        yield 'negative backing' => [NumberStatus::class, -1, NumberStatus::Negative];
        yield 'string enum instance' => [TextStatus::class, TextStatus::Pending, TextStatus::Pending];
        yield 'integer enum instance' => [NumberStatus::class, NumberStatus::Approved, NumberStatus::Approved];
    }

    /**
     * @param class-string<BackedEnum> $class
     */
    #[DataProvider('acceptedValues')]
    public function testCasesAndExactBackingValuesAreResolved(string $class, mixed $value, BackedEnum $expected): void
    {
        $type = ValueType::backedEnum($class);
        $this->assertSame($class, $type->name);
        $this->assertSame($expected, $type->parse($value, 'status'));
        $this->assertSame($expected, ArrayReader::fromArray(['status' => $value])->require('status', $type));
    }

    /**
     * @return iterable<string, array{class-string<BackedEnum>, mixed}>
     */
    public static function rejectedValues(): iterable
    {
        yield 'unknown string' => [TextStatus::class, 'rejected'];
        yield 'unknown integer' => [NumberStatus::class, 99];
        yield 'numeric string is not integer' => [NumberStatus::class, '1'];
        yield 'integer is not string' => [TextStatus::class, 1];
        yield 'float is not integer' => [NumberStatus::class, 1.0];
        yield 'boolean is not zero' => [NumberStatus::class, false];
        yield 'boolean is not one' => [NumberStatus::class, true];
        yield 'null' => [TextStatus::class, null];
        yield 'array' => [TextStatus::class, ['approved']];
        yield 'object' => [TextStatus::class, new stdClass()];
        yield 'another string enum' => [TextStatus::class, OtherTextStatus::Approved];
        yield 'another integer enum' => [NumberStatus::class, OtherNumberStatus::Approved];
        yield 'pure enum value' => [TextStatus::class, PureStatus::Approved];
        yield 'empty string enum has no case' => [EmptyTextStatus::class, 'value'];
        yield 'empty integer enum has no case' => [EmptyNumberStatus::class, 0];
    }

    /**
     * @param class-string<BackedEnum> $class
     */
    #[DataProvider('rejectedValues')]
    public function testInvalidInputsUseTheValueExceptionWithContext(string $class, mixed $value): void
    {
        $type = ValueType::backedEnum($class);

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The value at "payload.status" of order.json must be of type ' . $class . ', ' . get_debug_type($value) . ' given.');

        $type->parse($value, 'payload.status', 'order.json');
    }

    public function testEnumsComposeWithEveryCollectionKind(): void
    {
        $type = ValueType::backedEnum(TextStatus::class);
        $reader = ArrayReader::fromArray([
            'list' => ['approved', 'pending'],
            'map' => ['first' => 'approved', 'second' => 'pending'],
            'array' => [3 => 'approved', 'other' => 'pending'],
        ]);

        $this->assertSame([TextStatus::Approved, TextStatus::Pending], $reader->requireListOf('list', $type));
        $this->assertSame(['first' => TextStatus::Approved, 'second' => TextStatus::Pending], $reader->requireMapOf('map', $type));
        $this->assertSame([3 => TextStatus::Approved, 'other' => TextStatus::Pending], $reader->requireArrayOf('array', $type));
    }

    public function testNestedCollectionErrorsKeepTheOriginalKeyAndSource(): void
    {
        $reader = ArrayReader::fromValue(['statuses' => [8 => 'invalid']], source: 'order.json', path: 'payload');

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "payload.statuses.8" of order.json must be of type ' . TextStatus::class . ', string given.');

        $reader->requireArrayOf('statuses', ValueType::backedEnum(TextStatus::class));
    }

    public function testNullableAndUnionTypesKeepTheirContracts(): void
    {
        $type = ValueType::backedEnum(TextStatus::class);
        $this->assertNull($type->nullable()->parse(null));
        $this->assertSame(TextStatus::Approved, $type->nullable()->parse('approved'));

        $union = ValueType::oneOf($type, ValueType::literal(false));
        $this->assertFalse($union->parse(false));
        $this->assertSame(TextStatus::Pending, $union->parse('pending'));
    }

    public function testOptionalReaderDefaultsAndZeroBackingValuesArePreserved(): void
    {
        $reader = ArrayReader::fromArray(['status' => 0, 'null' => null]);
        $type = ValueType::backedEnum(NumberStatus::class);

        $this->assertSame(NumberStatus::Pending, $reader->get('status', $type, NumberStatus::Approved));
        $this->assertSame(NumberStatus::Approved, $reader->get('missing', $type, NumberStatus::Approved));
        $this->assertSame(NumberStatus::Approved, $reader->get('null', $type, NumberStatus::Approved));
    }

    public function testRequiredReaderStillRejectsNullForANullableEnum(): void
    {
        $reader = ArrayReader::fromArray(['status' => null]);

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "status" is required.');

        $reader->require('status', ValueType::backedEnum(TextStatus::class)->nullable());
    }

    public function testTheConcreteEnumTypeIsPreservedForConsumers(): void
    {
        $type = ValueType::backedEnum(TextStatus::class);
        $reader = ArrayReader::fromArray(['status' => 'pending']);
        $readStatus = static fn (TextStatus $status): string => $status->value;

        $this->assertSame('approved', $readStatus($type->parse('approved')));
        $this->assertSame('pending', $readStatus($reader->require('status', $type)));
    }
}
