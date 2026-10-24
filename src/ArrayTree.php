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
 * Builds trees from rows and extracts outlines from trees.
 */
final class ArrayTree
{
    /**
     * Creates a hierarchical tree from a flat array using parent references.
     *
     * The **keys** of the array are the identifiers of the items: the field
     * `$parentField` of an item has the key of its parent. The keys are kept in
     * the tree. The field of the parent is removed from each item and the
     * children go in `$childrenField` (an empty array if there are none). An
     * item that does not have the field of the parent is skipped, with
     * everything below it.
     *
     * @param array<array-key, array<array-key, mixed>> $items Array of items, by their identifier.
     * @param string $parentField Name of the field containing the key of the parent.
     * @param string $childrenField Name of the field to store children.
     * @param mixed $parentId Value of the parent field for root level items.
     * @return array<array-key, array<array-key, mixed>> The hierarchical tree.
     */
    public static function fromRows(
        array $items,
        string $parentField,
        string $childrenField,
        mixed $parentId = null
    ): array {
        $tree = [];

        foreach ($items as $key => $item) {
            if (!array_key_exists($parentField, $item)) {
                continue;
            }

            if ($item[$parentField] === $parentId) {
                $current = $item;
                unset($current[$parentField]);
                $current[$childrenField] = self::fromRows(
                    $items,
                    $parentField,
                    $childrenField,
                    $key
                );
                $tree[$key] = $current;
            }
        }

        return $tree;
    }

    /**
     * Converts a hierarchical tree to a flat list with level indicators.
     *
     * The elements go in depth order, with the keys that they had in the tree.
     * Each one has only its name (`name`, from `$nameField`) and its `level`
     * (0 for the root).
     *
     * An item that is not an array, or that does not have the name field (or has
     * it as `null`), is skipped, with everything below it. If `$childrenField` of an item is
     * not an array (a string, for example) the item is listed but its
     * children are silently omitted.
     *
     * @param array<array-key, mixed> $tree The tree structure.
     * @param string $nameField Field containing the item name.
     * @param string $childrenField Field containing child items.
     * @param int $level Current level (used internally).
     * @param array<array-key, array{name: mixed, level: int}> $result Accumulated result (used internally).
     * @return array<array-key, array{name: mixed, level: int}> The flattened list with level information.
     */
    public static function toOutline(
        array $tree,
        string $nameField,
        string $childrenField,
        int $level = 0,
        array &$result = []
    ): array {
        foreach ($tree as $key => $item) {
            if (!is_array($item) || !isset($item[$nameField])) {
                continue;
            }

            $result[$key] = [
                'name' => $item[$nameField],
                'level' => $level,
            ];

            $children = $item[$childrenField] ?? null;
            if (is_array($children) && $children !== []) {
                self::toOutline(
                    $children,
                    $nameField,
                    $childrenField,
                    $level + 1,
                    $result
                );
            }
        }

        return $result;
    }
}
