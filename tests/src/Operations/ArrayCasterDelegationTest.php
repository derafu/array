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

use ArrayObject;
use Derafu\Array\ArrayCaster;
use Derafu\TestsArray\Fixture\StringableValue;
use Derafu\TestsArray\Fixture\ThrowingStringableValue;
use Derafu\Value\Exception\ValueConversionException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../fixtures/stringable-value.php';

#[CoversClass(ArrayCaster::class)]
final class ArrayCasterDelegationTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, bool, int|string, float|string, float|string, int|string}>
     */
    public static function options(): iterable
    {
        yield 'defaults' => [false, false, 7, 1.0, INF, 12];
        yield 'format only' => [true, false, '007', '1.0000000000000000001', '1e400', '12.0'];
        yield 'precision only' => [false, true, 7, '1.0000000000000000001', '1e400', 12];
        yield 'both' => [true, true, '007', '1.0000000000000000001', '1e400', '12.0'];
    }

    #[DataProvider('options')]
    public function testOptionsReachEveryLeafAndKeepTheOriginalArray(
        bool $format,
        bool $precision,
        int|string $formatted,
        float|string $lossy,
        float|string $overflow,
        int|string $decimal
    ): void {
        $input = [
            5 => ['format' => '007', 'lossy' => '1.0000000000000000001', 'overflow' => '1e400'],
            'other' => ['decimal' => '12.0', 'zero' => '0', 'boolean' => 'off', 'null' => '(null)', 'empty' => 'empty'],
            'text' => ' untouched ',
            'blank' => '   ',
            -2 => [],
        ];
        $expected = [
            5 => ['format' => $formatted, 'lossy' => $lossy, 'overflow' => $overflow],
            'other' => ['decimal' => $decimal, 'zero' => 0, 'boolean' => false, 'null' => null, 'empty' => ''],
            'text' => ' untouched ',
            'blank' => false,
            -2 => [],
        ];
        $original = $input;
        $this->assertSame($expected, ArrayCaster::cast(
            $input,
            preserveNumericFormat: $format,
            preserveNumericPrecision: $precision,
        ));
        $this->assertSame($original, $input);
    }

    public function testStringableLeavesAreInterpretedWithTheSameOptions(): void
    {
        $value = new StringableValue('12.0');
        $input = ['nested' => [3 => $value]];
        $this->assertSame(['nested' => [3 => 12]], ArrayCaster::cast($input));
        $this->assertSame(['nested' => [3 => '12.0']], ArrayCaster::cast($input, preserveNumericFormat: true));
        $this->assertSame($value, $input['nested'][3]);
    }

    public function testNativeValuesAndEmptyCollectionsStayUnchanged(): void
    {
        $object = new ArrayObject(['value' => '2']);
        $stream = fopen('php://memory', 'r+');
        $this->assertIsResource($stream);
        try {
            $input = ['object' => $object, 'stream' => $stream, 'empty' => [], 'native' => [12.0, false, null]];
            $this->assertSame($input, ArrayCaster::cast($input, preserveNumericFormat: true, preserveNumericPrecision: true));
            $this->assertSame(1, fwrite($stream, 'x'));
            $this->assertSame([], ArrayCaster::cast([]));
        } finally {
            fclose($stream);
        }
    }

    public function testStringableConversionFailuresKeepTheirOriginalCause(): void
    {
        $cause = new RuntimeException('Cannot produce text.');
        try {
            ArrayCaster::cast(['nested' => [new ThrowingStringableValue($cause)]]);
            $this->fail('String conversion must report its failure.');
        } catch (ValueConversionException $exception) {
            $this->assertSame($cause, $exception->getPrevious());
        }
    }
}
