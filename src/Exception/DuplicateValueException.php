<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Array\Exception;

use Derafu\Translation\Contract\TranslatableInterface;
use Throwable;

/**
 * A list has an item that repeats the values of another one.
 *
 * It tells which item is the repeated one, which one it repeats and the values
 * that are the same, so whoever catches it can say it in its own words.
 */
class DuplicateValueException extends ArrayException
{
    /**
     * @param string|array<int|string, mixed>|TranslatableInterface $message
     * @param int|string $index Key of the item that is repeated.
     * @param int|string $first Key of the first item that has those values.
     * @param list<mixed> $values The values read from the item that is repeated,
     * in the order of the paths.
     */
    public function __construct(
        string|array|TranslatableInterface $message,
        private readonly int|string $index,
        private readonly int|string $first,
        private readonly array $values,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Key of the item that is repeated.
     */
    public function getIndex(): int|string
    {
        return $this->index;
    }

    /**
     * Key of the first item that has those values.
     */
    public function getFirst(): int|string
    {
        return $this->first;
    }

    /**
     * The values read from the item that is repeated, in the order of the paths.
     *
     * @return list<mixed>
     */
    public function getValues(): array
    {
        return $this->values;
    }
}
