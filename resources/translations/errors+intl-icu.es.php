<?php

declare(strict_types=1);

/**
 * Derafu: Array - Typed Array Reading and Transformations.
 *
 * Copyright (c) 2026 Esteban De La Fuente Rubio / Derafu <https://www.derafu.dev>
 * Licensed under the MIT License.
 * See LICENSE file for more details.
 */

return [
    'The key "{key}" is required.' =>
        'La clave "{key}" es obligatoria.',
    'The key "{key}" must be of type {expected}, {given} given.' =>
        'La clave "{key}" debe ser de tipo {expected}, se entregó {given}.',
    'The key "{key}" is not allowed; the allowed keys are: {allowed}.' =>
        'La clave "{key}" no está permitida; las claves permitidas son: {allowed}.',
    'The key "{key}" of {source} is required.' =>
        'La clave "{key}" de {source} es obligatoria.',
    'The key "{key}" of {source} must be of type {expected}, {given} given.' =>
        'La clave "{key}" de {source} debe ser de tipo {expected}, se entregó {given}.',
    'The key "{key}" of {source} is not allowed; the allowed keys are: {allowed}.' =>
        'La clave "{key}" de {source} no está permitida; las claves permitidas son: {allowed}.',
    'The item {index} is the same as the item {first}.' =>
        'El elemento {index} es igual al elemento {first}.',
    'The item {index} has the same {paths} ({values}) as the item {first}.' =>
        'El elemento {index} tiene los mismos {paths} ({values}) que el elemento {first}.',
    'The path {position} must be a string, an integer or a list of them, {given} given.' =>
        'La ruta {position} debe ser un texto, un entero o una lista de ellos, se entregó {given}.',
    'The item {index} must be an array or an object to read {paths}, {given} given.' =>
        'El elemento {index} debe ser un arreglo o un objeto para leer {paths}, se entregó {given}.',
    'The values of the item {index} can not be compared: {reason}.' =>
        'Los valores del elemento {index} no se pueden comparar: {reason}.',
    'Row {index} must have exactly 2 columns.' =>
        'La fila {index} debe tener exactamente 2 columnas.',
    'The first column of row {index} must be a scalar or null to be a key.' =>
        'La primera columna de la fila {index} debe ser un escalar o null para ser una clave.',
];
