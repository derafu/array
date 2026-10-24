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
use Derafu\Array\Exception\MissingValueException;
use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Value\ValueType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayPath::class)]
#[CoversClass(ArrayReader::class)]
final class ArrayReaderPathsTest extends TestCase
{
    public function testLiteralKeysTakePrecedenceIncludingNullAndWrongTypes(): void
    {
        $data = [
            'database.host' => 'literal',
            'database.port' => null,
            'database.enabled' => 'wrong',
            'database' => ['host' => 'nested', 'port' => 5432, 'enabled' => true],
        ];
        $reader = ArrayReader::fromArray($data);

        self::assertSame('literal', ArrayPath::get($data, 'database.host'));
        self::assertSame('nested', ArrayPath::get($data, ['database', 'host']));
        self::assertNull(ArrayPath::get($data, 'database.port', 3306));
        self::assertSame('literal', $reader->requireString('database.host'));
        self::assertTrue($reader->hasKey('database.port'));
        self::assertFalse($reader->hasValue('database.port'));
        self::assertSame(3306, $reader->getInt('database.port', 3306));

        $this->expectException(TypeMismatchException::class);
        $reader->requireBool('database.enabled');
    }

    public function testNestedReadsShareStrictTypesConversionsAndCollectionValidation(): void
    {
        $reader = ArrayReader::fromArray([
            'database' => ['host' => 'localhost', 'port' => '5432', 'enabled' => 'yes'],
            'servers' => [['port' => 8080]],
            'limits' => ['values' => [2, 4]],
        ]);

        self::assertSame('localhost', $reader->getString('database.host'));
        self::assertSame(5432, $reader->requireAsInt('database.port'));
        self::assertTrue($reader->getAsBool('database.enabled'));
        self::assertSame(8080, $reader->requireInt('servers.0.port'));
        self::assertSame([2, 4], $reader->requireListOf('limits.values', ValueType::int()));
        self::assertSame(8080, $reader->get('servers.0.port', ValueType::int()));

        $this->expectException(TypeMismatchException::class);
        $reader->getInt('database.port');
    }

    public function testPresenceDefaultsAndNullKeepTheirMeaningAlongPaths(): void
    {
        $reader = ArrayReader::fromArray([
            'settings' => ['null' => null, 'off' => false, 'zero' => 0, 'empty' => ''],
            'blocked' => 'text',
            7 => 'numeric key',
            '' => ['value' => 'empty segment'],
        ]);

        self::assertTrue($reader->hasKey('settings.null'));
        self::assertFalse($reader->hasValue('settings.null'));
        foreach (['settings.off', 'settings.zero', 'settings.empty'] as $path) {
            self::assertTrue($reader->hasKey($path));
            self::assertTrue($reader->hasValue($path));
        }
        foreach (['settings.missing', 'blocked.child', 'settings.null.child'] as $path) {
            self::assertFalse($reader->hasKey($path));
            self::assertFalse($reader->hasValue($path));
            self::assertSame('fallback', $reader->getString($path, 'fallback'));
        }
        self::assertSame('fallback', $reader->getString('settings.null', 'fallback'));
        self::assertSame('numeric key', $reader->requireString(7));
        self::assertSame('empty segment', $reader->requireString('.value'));
    }

    public function testRequiredNullReportsTheRequestedPathAndSource(): void
    {
        $reader = ArrayReader::fromArray(['database' => ['host' => null]], 'config.php');

        $this->expectException(MissingValueException::class);
        $this->expectExceptionMessage('The key "database.host" of config.php is required.');
        $reader->requireString('database.host');
    }

    public function testNestedReadersAndCollectionsPreserveFullDiagnostics(): void
    {
        $reader = ArrayReader::fromArray([
            'deployment' => [
                'database' => ['host' => 'localhost'],
                'servers' => [['port' => 8080], ['port' => 'wrong']],
                'named' => ['primary' => ['port' => 5432]],
            ],
        ], 'config.php');

        self::assertSame('localhost', $reader->requireReader('deployment.database')->requireString('host'));
        self::assertSame('localhost', $reader->getReader('deployment.database')?->getString('host'));
        self::assertSame(5432, $reader->requireArrayReaders('deployment.named')['primary']->requireInt('port'));
        $servers = $reader->getReaders('deployment.servers');
        self::assertNotNull($servers);
        self::assertArrayHasKey(0, $servers);
        self::assertSame(8080, $servers[0]->requireInt('port'));
        self::assertSame(8080, $reader->requireReaders('deployment.servers')[0]->requireInt('port'));

        $this->expectException(TypeMismatchException::class);
        $this->expectExceptionMessage('The key "deployment.servers.1.port" of config.php must be of type int, string given.');
        $reader->requireReaders('deployment.servers')[1]->requireInt('port');
    }

    public function testLiteralPrecedenceAppliesToTheWholeRequestedPath(): void
    {
        $data = ['section' => ['a.b' => 'literal child', 'a' => ['b' => 'nested child']]];
        $reader = ArrayReader::fromArray($data);

        self::assertSame('nested child', ArrayPath::get($data, 'section.a.b'));
        self::assertSame('nested child', $reader->requireString('section.a.b'));
        self::assertSame('literal child', $reader->requireReader('section')->requireString('a.b'));
        self::assertSame('literal child', ArrayPath::get($data, ['section', 'a.b']));
    }
}
