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

use Derafu\Array\Exception\ArrayException;

/**
 * Transforms columns, pairs and keyed rows.
 */
final class ArrayTable
{
    /**
     * Ensure an ID attribute in each array element using the array key.
     *
     * @param array<int|string, array<array-key, mixed>> $data Array of arrays.
     * @param string $idField Name of the field to store the ID.
     * @return array<int|string, array<array-key, mixed>> The modified array with IDs added.
     */
    public static function withKeyColumn(array $data, string $idField): array
    {
        return array_combine(
            array_keys($data),
            array_map(
                fn ($id, $item) => array_merge(
                    [$idField => $id],
                    $item
                ),
                array_keys($data),
                array_values($data)
            )
        );
    }

    /**
     * Transforms a grouped array into a table format.
     *
     * Converts an array like:
     *
     *   [
     *     'key1' => [1,2,3],
     *     'key2' => [4,5,6]
     *   ]
     *
     * into:
     *
     *   [
     *     ['key1' => 1, 'key2' => 4],
     *     ['key1' => 2, 'key2' => 5],
     *     ['key1' => 3, 'key2' => 6],
     *   ]
     *
     * A key that is requested and is not in the array is in every row with the
     * value `null`. The number of rows is the one of the longest group that is
     * there: if none of the keys is there, there are no rows.
     *
     * @param array<array-key, array<int, mixed>> $array The grouped array to transform.
     * @param list<array-key>|null $keys Specific keys to extract (null for all keys).
     * @return list<array<array-key, mixed>> The resulting table array.
     */
    public static function columnsToRows(array $array, ?array $keys = null): array
    {
        $keys = $keys ?? array_keys($array);
        if (empty($keys)) {
            return [];
        }

        // Find the maximum length among all groups.
        $lengths = array_map(
            'count',
            array_intersect_key($array, array_flip($keys))
        );
        $maxLength = $lengths === [] ? 0 : max($lengths);

        $result = [];
        for ($i = 0; $i < $maxLength; $i++) {
            $row = [];
            foreach ($keys as $key) {
                $row[$key] = $array[$key][$i] ?? null;
            }
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Converts a table of N rows and 2 columns into an associative array.
     *
     * The first column of each row is the key and the second one the value. The
     * key can be any scalar or `null`: an integer is kept, and any other one is
     * turned into a string (`null` is `""`, `true` is `"1"`, `2.7` is `"2.7"`),
     * which PHP then uses as a key as it does with any array (`"3"` is `3`). A
     * key that is an array or an object can not be a key and is an error.
     *
     * @param array<array-key, mixed> $table The table to convert, where each row has exactly 2 columns.
     * @return array<array-key, mixed> The resulting associative array.
     * @throws ArrayException If any row doesn't have exactly 2 columns
     * or its first column can not be a key.
     */
    public static function pairsToMap(array $table): array
    {
        $result = [];
        foreach ($table as $index => $row) {
            if (!is_array($row) || count($row) !== 2) {
                throw new ArrayException([
                    'Row {index} must have exactly 2 columns.',
                    'index' => $index,
                ]);
            }
            $key = array_shift($row);
            if (!is_scalar($key) && $key !== null) {
                throw new ArrayException([
                    'The first column of row {index} must be a scalar or null to be a key.',
                    'index' => $index,
                ]);
            }
            $result[is_int($key) ? $key : (string) $key] = array_shift($row);
        }

        return $result;
    }
}
