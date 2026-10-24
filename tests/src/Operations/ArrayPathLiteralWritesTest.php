<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsArray\Operations;

use Derafu\Array\ArrayPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayPath::class)]
final class ArrayPathLiteralWritesTest extends TestCase
{
    public function testSettingAnExistingLiteralKeyUpdatesTheValueThatGetReads(): void
    {
        $data = ['profile.name' => 'Ana', 'profile' => ['name' => 'nested']];
        $updated = ArrayPath::set($data, 'profile.name', 'Luis');

        self::assertSame(['profile.name' => 'Luis', 'profile' => ['name' => 'nested']], $updated);
        self::assertSame('Luis', ArrayPath::get($updated, 'profile.name'));
        self::assertSame('Ana', $data['profile.name']);
    }

    public function testSettingAnExistingNullLiteralKeyDoesNotCreateANestedPath(): void
    {
        $updated = ArrayPath::set(['profile.name' => null], 'profile.name', 'Luis');

        self::assertSame(['profile.name' => 'Luis'], $updated);
        self::assertSame('Luis', ArrayPath::get($updated, 'profile.name'));
    }

    public function testSettingAnAbsentLiteralKeyCreatesOrUpdatesTheNestedPath(): void
    {
        $created = ArrayPath::set([], 'profile.name', 'Luis');
        self::assertSame(['profile' => ['name' => 'Luis']], $created);
        self::assertSame('Luis', ArrayPath::get($created, 'profile.name'));

        $updated = ArrayPath::set($created, 'profile.name', 'Ana');
        self::assertSame(['profile' => ['name' => 'Ana']], $updated);
        self::assertSame('Ana', ArrayPath::get($updated, 'profile.name'));
    }

    #[DataProvider('literalValues')]
    public function testWrappingUsesTheLiteralKeyAndPreservesTheOtherData(mixed $value, mixed $expected): void
    {
        $data = ['document.reference' => $value, 'document' => ['reference' => ['nested']]];
        $wrapped = ArrayPath::wrap($data, 'document.reference');

        self::assertSame([
            'document.reference' => $expected,
            'document' => ['reference' => ['nested']],
        ], $wrapped);
        self::assertSame($expected, ArrayPath::get($wrapped, 'document.reference'));
        self::assertSame($value, $data['document.reference']);
        self::assertSame($wrapped, ArrayPath::wrap($wrapped, 'document.reference'));
    }

    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function literalValues(): iterable
    {
        yield 'record' => [['id' => 123], [['id' => 123]]];
        yield 'sparse array' => [[2 => 'reference'], [[2 => 'reference']]];
        yield 'string' => ['reference', ['reference']];
        yield 'false' => [false, [false]];
        yield 'zero' => [0, [0]];
        yield 'list' => [[['id' => 123]], [['id' => 123]]];
        yield 'empty list' => [[], []];
        yield 'null remains untouched' => [null, null];
    }

    public function testWrappingAnAbsentPathDoesNotCreateIt(): void
    {
        self::assertSame([], ArrayPath::wrap([], 'document.reference'));
        self::assertSame(['document' => []], ArrayPath::wrap(['document' => []], 'document.reference'));
    }
}
