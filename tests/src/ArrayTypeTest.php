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

use DateTimeImmutable;
use DateTimeInterface;
use Derafu\Array\ArrayType;
use Derafu\Translation\Contract\TranslatableInterface;
use Derafu\Translation\Exception\Logic\TranslatableInvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(ArrayType::class)]
final class ArrayTypeTest extends TestCase
{
    /**
     * @return array<string, array{ArrayType<mixed>, mixed}>
     */
    public static function acceptedProvider(): array
    {
        return [
            'mixed' => [ArrayType::mixed(), new stdClass()],
            'string' => [ArrayType::string(), 'a'],
            'int' => [ArrayType::int(), 3],
            'float' => [ArrayType::float(), 1.5],
            'number with an int' => [ArrayType::number(), 3],
            'number with a float' => [ArrayType::number(), 1.5],
            'bool' => [ArrayType::bool(), false],
            'scalar' => [ArrayType::scalar(), 'a'],
            'array' => [ArrayType::array(), [1, 'a' => 2]],
            'map' => [ArrayType::map(), ['a' => 1]],
            'empty map' => [ArrayType::map(), []],
            'list' => [ArrayType::list(), ['a', 1]],
            'empty list' => [ArrayType::list(), []],
            'list of strings' => [ArrayType::listOf(ArrayType::string()), ['a', 'b']],
            'empty list of strings' => [ArrayType::listOf(ArrayType::string()), []],
            'map of ints' => [ArrayType::mapOf(ArrayType::int()), ['a' => 1]],
            'empty map of ints' => [ArrayType::mapOf(ArrayType::int()), []],
            'one of, first' => [ArrayType::oneOf(ArrayType::bool(), ArrayType::map()), true],
            'one of, last' => [ArrayType::oneOf(ArrayType::bool(), ArrayType::map()), ['a' => 1]],
            'nullable with a value' => [ArrayType::string()->nullable(), 'a'],
            'nullable with null' => [ArrayType::string()->nullable(), null],
            'non-empty string' => [ArrayType::nonEmptyString(), 'a'],
            'literal text' => [ArrayType::literal('asc', 'desc'), 'desc'],
            'literal false' => [ArrayType::literal(false), false],
            'literal int' => [ArrayType::literal(1, 2), 2],
            'instance of a class' => [ArrayType::instanceOf(stdClass::class), new stdClass()],
            'date that is an object' => [ArrayType::dateTime(), new DateTimeImmutable('2026-01-02')],
        ];
    }

    /**
     * @param ArrayType<mixed> $type
     */
    #[Test]
    #[DataProvider('acceptedProvider')]
    public function shouldReturnTheValueThatIsOfTheType(ArrayType $type, mixed $value): void
    {
        $this->assertSame($value, $type->parse($value, 'key'));
    }

    /**
     * @return array<string, array{ArrayType<mixed>, mixed, string}>
     */
    public static function rejectedProvider(): array
    {
        return [
            'string' => [ArrayType::string(), 1, 'string'],
            'int with a numeric string' => [ArrayType::int(), '3', 'int'],
            'int with a float' => [ArrayType::int(), 3.0, 'int'],
            'float with an int' => [ArrayType::float(), 3, 'float'],
            'number' => [ArrayType::number(), '3', 'float|int'],
            'bool' => [ArrayType::bool(), 0, 'bool'],
            'scalar' => [ArrayType::scalar(), [], 'bool|float|int|string'],
            'array' => [ArrayType::array(), 'a', 'array'],
            'map with integer keys' => [ArrayType::map(), [1 => 'a'], 'object'],
            'typed map with integer keys' => [ArrayType::mapOf(ArrayType::string()), [1 => 'a'], 'object<string>'],
            'map with a string' => [ArrayType::map(), 'a', 'object'],
            'map with a list' => [ArrayType::map(), ['a', 'b'], 'object'],
            'list with a string' => [ArrayType::list(), 'a', 'list'],
            'list with a map' => [ArrayType::list(), ['a' => 1], 'list'],
            'list of strings with a string' => [ArrayType::listOf(ArrayType::string()), 'a', 'list<string>'],
            'list of strings with a map' => [ArrayType::listOf(ArrayType::string()), ['a' => 'b'], 'list<string>'],
            'map of ints with a string' => [ArrayType::mapOf(ArrayType::int()), 'a', 'object<int>'],
            'map of ints with a list' => [ArrayType::mapOf(ArrayType::int()), [1, 2], 'object<int>'],
            'one of' => [ArrayType::oneOf(ArrayType::bool(), ArrayType::map()), 'a', 'bool|object'],
            'nullable' => [ArrayType::string()->nullable(), 1, 'string'],
            'non-empty string with an empty one' => [ArrayType::nonEmptyString(), '', 'non-empty-string'],
            'non-empty string with spaces' => [ArrayType::nonEmptyString(), '  ', 'non-empty-string'],
            'non-empty string with an int' => [ArrayType::nonEmptyString(), 1, 'non-empty-string'],
            'literal text with another text' => [ArrayType::literal('asc', 'desc'), 'up', "'asc'|'desc'"],
            'literal false with 0' => [ArrayType::literal(false), 0, 'false'],
            'literal int with a numeric string' => [ArrayType::literal(1, 2), '1', '1|2'],
            'instance of a class with a string' => [ArrayType::instanceOf(stdClass::class), 'a', 'stdClass'],
            'instance of a class with another object' => [ArrayType::instanceOf(stdClass::class), new DateTimeImmutable(), 'stdClass'],
            'date with a text that is not a date' => [ArrayType::dateTime(), 'not a date', 'date'],
            'date with an empty text' => [ArrayType::dateTime(), '  ', 'date'],
            'date with a float' => [ArrayType::dateTime(), 1.5, 'date'],
            'date with an array' => [ArrayType::dateTime(), [], 'date'],
        ];
    }

    /**
     * @param ArrayType<mixed> $type
     */
    #[Test]
    #[DataProvider('rejectedProvider')]
    public function shouldRejectTheValueThatIsNotOfTheType(ArrayType $type, mixed $value, string $expected): void
    {
        try {
            $type->parse($value, 'key');
            $this->fail('The value should be rejected.');
        } catch (TranslatableInvalidArgumentException $e) {
            $this->assertStringContainsString(
                'The key "key" must be of type ' . $expected . ', ',
                $e->getMessage()
            );
        }
    }

    #[Test]
    public function shouldCreateEveryType(): void
    {
        $this->assertSame(
            ['mixed', 'string', 'int', 'float', 'float|int', 'bool', 'bool|float|int|string', 'array', 'object', 'list'],
            array_map(static fn (ArrayType $t): string => $t->name, [
                ArrayType::mixed(),
                ArrayType::string(),
                ArrayType::int(),
                ArrayType::float(),
                ArrayType::number(),
                ArrayType::bool(),
                ArrayType::scalar(),
                ArrayType::array(),
                ArrayType::map(),
                ArrayType::list(),
            ])
        );
    }

    #[Test]
    public function shouldConvertADateFromATimestampOrAText(): void
    {
        $fromInt = ArrayType::dateTime()->parse(1768435200, 'key');
        $fromText = ArrayType::dateTime()->parse('2026-01-15', 'key');

        $this->assertInstanceOf(DateTimeInterface::class, $fromInt);
        $this->assertSame(1768435200, $fromInt->getTimestamp());
        $this->assertSame('2026-01-15', $fromText->format('Y-m-d'));
    }

    #[Test]
    public function shouldKeepTheLiteralFalseApartFromTheOtherTypes(): void
    {
        $type = ArrayType::oneOf(ArrayType::string(), ArrayType::literal(false));

        $this->assertSame('a.png', $type->parse('a.png', 'image'));
        $this->assertFalse($type->parse(false, 'image'));

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "image" must be of type string|false, bool given.');

        $type->parse(true, 'image');
    }

    #[Test]
    public function shouldSayWhereTheArrayComesFromInEveryError(): void
    {
        $types = [
            ArrayType::string(),
            ArrayType::listOf(ArrayType::string()),
            ArrayType::mapOf(ArrayType::int()),
            ArrayType::oneOf(ArrayType::bool(), ArrayType::map()),
            ArrayType::string()->nullable(),
            ArrayType::literal('a'),
            ArrayType::dateTime(),
        ];

        foreach ($types as $type) {
            try {
                $type->parse(1.5, 'key', 'docs/guide.md');
                $this->fail('The value should be rejected.');
            } catch (TranslatableInvalidArgumentException $e) {
                $this->assertStringStartsWith('The key "key" of docs/guide.md must be of type ', $e->getMessage());
            }
        }
    }

    #[Test]
    public function shouldSayWhereTheArrayComesFromInTheItemThatFails(): void
    {
        $this->expectExceptionMessage('The key "tags.1" of docs/guide.md must be of type string, int given.');

        ArrayType::listOf(ArrayType::string())->parse(['a', 1], 'tags', 'docs/guide.md');
    }

    #[Test]
    public function shouldAcceptACustomTypeThatDoesNotKnowTheSource(): void
    {
        $even = new ArrayType('even', static fn (mixed $value, string $path): int
            => is_int($value) && $value % 2 === 0 ? $value : throw ArrayType::mismatch($path, 'even', $value));

        $this->assertSame(4, $even->parse(4, 'key', 'docs/guide.md'));

        $this->expectExceptionMessage('The key "key" must be of type even, int given.');

        $even->parse(3, 'key', 'docs/guide.md');
    }

    #[Test]
    public function shouldNameTheTypes(): void
    {
        $this->assertSame('non-empty-string', ArrayType::nonEmptyString()->name);
        $this->assertSame("'asc'|'desc'", ArrayType::literal('asc', 'desc')->name);
        $this->assertSame('false', ArrayType::literal(false)->name);
        $this->assertSame('stdClass', ArrayType::instanceOf(stdClass::class)->name);
        $this->assertSame('date', ArrayType::dateTime()->name);
        $this->assertSame('list<object<int>>', ArrayType::listOf(ArrayType::mapOf(ArrayType::int()))->name);
        $this->assertSame('bool|object', ArrayType::oneOf(ArrayType::bool(), ArrayType::map())->name);
        $this->assertSame('string|null', ArrayType::string()->nullable()->name);
    }

    #[Test]
    public function shouldReportThePathOfTheItemThatFails(): void
    {
        $type = ArrayType::mapOf(ArrayType::listOf(ArrayType::string()));

        try {
            $type->parse(['a' => ['x', 2]], 'dependentRequired');
            $this->fail('The value should be rejected.');
        } catch (TranslatableInvalidArgumentException $e) {
            $this->assertSame(
                'The key "dependentRequired.a.1" must be of type string, int given.',
                $e->getMessage()
            );
        }
    }

    #[Test]
    public function shouldReportTheTypeOfTheGivenValue(): void
    {
        $error = ArrayType::mismatch('k', 'string', new stdClass());

        $this->assertInstanceOf(TranslatableInterface::class, $error);
        $this->assertSame('The key "k" must be of type string, stdClass given.', $error->getMessage());
    }

    #[Test]
    public function shouldJoinPaths(): void
    {
        $this->assertSame('a', ArrayType::path('', 'a'));
        $this->assertSame('0', ArrayType::path('', 0));
        $this->assertSame('a.b', ArrayType::path('a', 'b'));
        $this->assertSame('a.0', ArrayType::path('a', 0));
    }

    #[Test]
    public function shouldAcceptACustomType(): void
    {
        $even = new ArrayType('even', static fn (mixed $v, string $p): int
            => is_int($v) && $v % 2 === 0 ? $v : throw ArrayType::mismatch($p, 'even', $v));

        $this->assertSame(4, $even->parse(4));
        $this->assertSame([2, 4], ArrayType::listOf($even)->parse([2, 4]));

        $this->expectException(TranslatableInvalidArgumentException::class);
        $this->expectExceptionMessage('The key "0" must be of type even, int given.');
        ArrayType::listOf($even)->parse([3]);
    }
}
