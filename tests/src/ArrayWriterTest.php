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

use Derafu\Array\ArrayPath;
use Derafu\Array\ArrayReader;
use Derafu\Array\ArrayWriter;
use Derafu\Array\Exception\MissingValueException;
use Derafu\Array\Exception\TypeMismatchException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayWriter::class)]
#[CoversClass(ArrayPath::class)]
#[UsesClass(ArrayReader::class)]
final class ArrayWriterTest extends TestCase
{
    public function testSetWritesInPlaceAndCreatesTheLevels(): void
    {
        $data = ['a' => ['x' => 1]];
        $writer = ArrayWriter::fromReference($data);

        $result = $writer->set('a.y', 2)->set('b.c.d', 3)->set('e', null);

        self::assertSame($writer, $result);
        self::assertSame(
            ['a' => ['x' => 1, 'y' => 2], 'b' => ['c' => ['d' => 3]], 'e' => null],
            $data
        );
        self::assertSame($data, $writer->toArray());
    }

    public function testLiteralKeysTakePrecedenceAndANullLevelIsCreatedAsAnArray(): void
    {
        $data = ['a.b' => 1, 'n' => null];
        $writer = ArrayWriter::fromReference($data);

        $writer->set('a.b', 2)->set('n.x', 3);

        self::assertSame(['a.b' => 2, 'n' => ['x' => 3]], $data);
        self::assertSame(['a.b'], ArrayPath::keys($data, 'a.b'));
        self::assertSame(['a', 'b', 'missing'], ArrayPath::keys($data, 'a.b.missing'));
        self::assertSame(['x', 'y'], ArrayPath::keys([], ['x', 'y']));
    }

    public function testSetThroughAValueThatIsNotAnArrayReportsThePathAndTheSource(): void
    {
        $data = ['a' => ['b' => 33]];

        try {
            ArrayWriter::fromReference($data)->set('a.b.c', 1);
            self::fail('A TypeMismatchException was expected.');
        } catch (TypeMismatchException $exception) {
            $parameters = $exception->getTranslatableMessage()->getParameters();
            self::assertSame('a.b', $parameters['key']);
            self::assertSame('array', $parameters['expected']);
            self::assertSame('int', $parameters['given']);
            self::assertArrayNotHasKey('source', $parameters);
        }

        try {
            ArrayWriter::fromReference($data, 'document')->set('a.b.c', 1);
            self::fail('A TypeMismatchException was expected.');
        } catch (TypeMismatchException $exception) {
            self::assertSame(
                'document',
                $exception->getTranslatableMessage()->getParameters()['source']
            );
        }
    }

    public function testRemoveDeletesTheKeyAndIgnoresWhatIsNotThere(): void
    {
        $data = ['a' => ['b' => 1, 'c' => 2], 'd' => 3, 'scalar' => 4];
        $writer = ArrayWriter::fromReference($data);

        $writer->remove('a.b')->remove('d')->remove('x.y')->remove('scalar.z');

        self::assertSame(['a' => ['c' => 2], 'scalar' => 4], $data);
    }

    public function testAppendCreatesTheListAndAddsToIt(): void
    {
        $data = ['items' => ['a']];
        $writer = ArrayWriter::fromReference($data);

        $writer->append('items', 'b')->append('other.list', 1);

        self::assertSame(['items' => ['a', 'b'], 'other' => ['list' => [1]]], $data);
    }

    public function testAppendToAValueThatIsNotAListFails(): void
    {
        $data = ['map' => ['k' => 1]];

        $this->expectException(TypeMismatchException::class);
        ArrayWriter::fromReference($data)->append('map', 2);
    }

    public function testGetWriterWritesInTheParentAndCreatesTheLevel(): void
    {
        $data = ['Encabezado' => ['Totales' => ['MntNeto' => 1000]]];
        $writer = ArrayWriter::fromReference($data);

        $totales = $writer->getWriter('Encabezado.Totales');
        $totales->set('MntTotal', 1190);
        $writer->getWriter('Encabezado.IdDoc')->set('Folio', 10);

        self::assertSame(
            [
                'Encabezado' => [
                    'Totales' => ['MntNeto' => 1000, 'MntTotal' => 1190],
                    'IdDoc' => ['Folio' => 10],
                ],
            ],
            $data
        );
    }

    public function testGetWriterErrorsSayThePathFromTheRoot(): void
    {
        $data = ['a' => ['b' => ['c' => 5]]];
        $child = ArrayWriter::fromReference($data)->getWriter('a.b');

        try {
            $child->set('c.d', 1);
            self::fail('A TypeMismatchException was expected.');
        } catch (TypeMismatchException $exception) {
            self::assertSame(
                'a.b.c',
                $exception->getTranslatableMessage()->getParameters()['key']
            );
        }
    }

    public function testMergeReplacesAndMergeDefaultsOnlyFillsWhatIsMissing(): void
    {
        $data = ['a' => ['x' => 1, 'y' => 2], 'k' => 'keep'];
        $writer = ArrayWriter::fromReference($data);

        $writer->merge(['a' => ['y' => 20, 'z' => 30]]);
        self::assertSame(['a' => ['x' => 1, 'y' => 20, 'z' => 30], 'k' => 'keep'], $data);

        $writer->mergeDefaults(['a' => ['x' => 0, 'w' => 4], 'k' => 'default', 'n' => 5]);
        self::assertSame(
            ['a' => ['x' => 1, 'w' => 4, 'y' => 20, 'z' => 30], 'k' => 'keep', 'n' => 5],
            $data
        );
    }

    public function testMergeInAChildWriterOnlyTouchesItsLevel(): void
    {
        $data = ['a' => ['x' => 1], 'b' => ['x' => 1]];

        ArrayWriter::fromReference($data)->getWriter('a')->mergeDefaults(['x' => 0, 'y' => 2]);

        self::assertSame(['a' => ['x' => 1, 'y' => 2], 'b' => ['x' => 1]], $data);
    }

    public function testGetReaderReadsTheCurrentDataWithItsPathAndSource(): void
    {
        $data = ['a' => ['n' => '21']];
        $writer = ArrayWriter::fromReference($data, 'document');

        $writer->getWriter('a')->set('m', 5);
        $reader = $writer->getReader();

        self::assertInstanceOf(ArrayReader::class, $reader);
        self::assertSame(21, $reader->requireAsInt('a.n'));
        self::assertSame(5, $reader->requireInt('a.m'));

        try {
            $writer->getWriter('a')->getReader()->requireString('missing');
            self::fail('A MissingValueException was expected.');
        } catch (MissingValueException $exception) {
            self::assertSame(
                'a.missing',
                $exception->getTranslatableMessage()->getParameters()['key']
            );
        }
    }
}
