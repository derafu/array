<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

namespace Derafu\Array;

/**
 * Generates subsets of a list.
 */
final class ArrayCombinations
{
    /**
     * Generates all possible subsets of an array with a minimum length.
     *
     * @param list<mixed> $array Input array.
     * @param int $minLength Minimum length for subsets.
     * @return list<list<mixed>> Array of all possible subsets.
     */
    public static function subsets(array $array, int $minLength = 1): array
    {
        $count = count($array);
        $members = pow(2, $count);
        $subsets = [];

        for ($i = 0; $i < $members; $i++) {
            $subset = [];
            for ($j = 0; $j < $count; $j++) {
                if ($i & (1 << $j)) {
                    $subset[] = $array[$j];
                }
            }
            if (count($subset) >= $minLength) {
                $subsets[] = $subset;
            }
        }

        return $subsets;
    }
}
