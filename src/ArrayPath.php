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
 * Accesses nested arrays and transforms dot notation paths.
 */
final class ArrayPath
{
    /**
     * Flattens a multi-dimensional array into a single level array with dot
     * notation keys.
     *
     * @param array<array-key, mixed> $array The array to flatten.
     * @param string $prefix The prefix to use for flattened keys.
     * @return array<array-key, mixed> The flattened array.
     */
    public static function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;
            if (is_array($value)) {
                $result = array_merge($result, self::flatten($value, $newKey));
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    /**
     * Converts a flat array with dot notation keys back to a multi-dimensional
     * array.
     *
     * Keys that are integers have no dots, so they are kept as they are. If a
     * key needs a level that already has a value that is not an array, that
     * value is replaced by the new array.
     *
     * @param array<array-key, mixed> $array The flat array with dot notation keys.
     * @return array<array-key, mixed> The multi-dimensional array.
     */
    public static function expand(array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            if (str_contains((string) $key, '.')) {
                $result = self::set($result, (string) $key, $value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Gets the value that is in an array at a path of keys.
     *
     * A key whose value is `null` is a value that is there, and `null` is what
     * is returned. The default is returned when the path does not exist: a key
     * that is not there, or a level of the path that is not an array.
     *
     * @param array<array-key, mixed> $array The array.
     * @param string|non-empty-list<string> $path Dot notation path ("a.b.c") or
     * the list of keys. A string matching a literal key takes precedence over
     * dot notation; a list of keys always follows the explicit path.
     * @param mixed $default What is returned if the path does not exist.
     * @return mixed The value at the path, or the default.
     */
    public static function get(array $array, string|array $path, mixed $default = null): mixed
    {
        if (is_string($path) && array_key_exists($path, $array)) {
            return $array[$path];
        }

        $keys = is_string($path) ? explode('.', $path) : $path;
        $current = $array;

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return $default;
            }

            $current = $current[$key];
        }

        return $current;
    }

    /**
     * Sets a value in an array, at a path of keys, creating the levels.
     * An existing literal string key takes precedence over dot notation;
     * otherwise, dot notation creates the nested path.
     *
     * The array is not modified: the array with the value set is returned. A
     * level of the path that exists and is not an array is replaced by an
     * array.
     *
     * @param array<array-key, mixed> $array The array.
     * @param string|non-empty-list<string> $path Dot notation path ("a.b.c") or
     * the list of keys.
     * @param mixed $value The value to set at the end of the path.
     * @return array<array-key, mixed> The array with the value set.
     */
    public static function set(array $array, string|array $path, mixed $value): array
    {
        if (is_string($path) && array_key_exists($path, $array)) {
            $path = [$path];
        }

        $keys = is_string($path) ? explode('.', $path) : $path;
        $key = $keys[0];
        $rest = array_slice($keys, 1);

        if ($rest === []) {
            $array[$key] = $value;

            return $array;
        }

        $child = isset($array[$key]) && is_array($array[$key]) ? $array[$key] : [];
        $array[$key] = self::set($child, $rest, $value);

        return $array;
    }

    /**
     * Wraps in an array the value at a path of keys, if it is not a list.
     * An existing literal string key takes precedence over dot notation.
     *
     * The array is not modified: the array with the value wrapped is returned.
     * If any key of the path does not exist, or a level of the path is not an
     * array, the array is returned as it is.
     *
     * @param array<array-key, mixed> $array The array.
     * @param string|non-empty-list<string> $path Dot notation path ("a.b.c") or
     * the list of keys.
     * @return array<array-key, mixed> The array.
     */
    public static function wrap(array $array, string|array $path): array
    {
        if (is_string($path) && array_key_exists($path, $array)) {
            $path = [$path];
        }

        $keys = is_string($path) ? explode('.', $path) : $path;
        $key = $keys[0];
        if (!isset($array[$key])) {
            return $array;
        }

        $rest = array_slice($keys, 1);
        $value = $array[$key];

        if ($rest !== []) {
            if (is_array($value)) {
                $array[$key] = self::wrap($value, $rest);
            }

            return $array;
        }

        if (!is_array($value) || !array_is_list($value)) {
            $array[$key] = [$value];
        }

        return $array;
    }
}
