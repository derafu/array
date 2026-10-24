<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\TestsArray\Fixture;

use RuntimeException;
use Stringable;

final class StringableValue implements Stringable
{
    public function __construct(private string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

final class ThrowingStringableValue implements Stringable
{
    public function __construct(private RuntimeException $cause)
    {
    }

    public function __toString(): string
    {
        throw $this->cause;
    }
}
