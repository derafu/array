<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2025 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsArray\Operations;

use Derafu\Array\ArrayPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayPath::class)]
class ArrayPathWrapTest extends TestCase
{
    public function testBasicConversion(): void
    {
        $data = [
            'Encabezado' => [
                'Detalle' => [
                    'Codigo' => '123',
                ],
            ],
        ];

        $data = ArrayPath::wrap($data, 'Encabezado.Detalle');

        $this->assertSame(['Encabezado' => ['Detalle' => [['Codigo' => '123']]]], $data);
    }

    public function testAlreadyArray(): void
    {
        $data = [
            'Encabezado' => [
                'Detalle' => [
                    ['Codigo' => '123'],
                ],
            ],
        ];

        $data = ArrayPath::wrap($data, 'Encabezado.Detalle');

        $this->assertSame(['Encabezado' => ['Detalle' => [['Codigo' => '123']]]], $data);
    }

    public function testNestedPath(): void
    {
        $data = [
            'Root' => [
                'Encabezado' => [
                    'Detalle' => [
                        'Codigo' => '456',
                    ],
                ],
            ],
        ];

        $data = ArrayPath::wrap($data, 'Root.Encabezado.Detalle');

        $this->assertSame(['Root' => ['Encabezado' => ['Detalle' => [['Codigo' => '456']]]]], $data);
    }

    public function testEmptyPath(): void
    {
        $data = [];

        $data = ArrayPath::wrap($data, 'Root.Encabezado.Detalle');

        $this->assertSame([], $data);
    }

    public function testEmptyArrayValue(): void
    {
        $data = [
            'Encabezado' => [
                'Detalle' => [],
            ],
        ];

        $data = ArrayPath::wrap($data, 'Encabezado.Detalle');

        $this->assertSame(['Encabezado' => ['Detalle' => []]], $data);
    }
}
