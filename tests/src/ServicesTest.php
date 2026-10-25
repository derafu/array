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

use Derafu\Array\ArrayReader;
use Derafu\Array\ArrayTable;
use Derafu\Array\Exception\ArrayException;
use Derafu\Array\Exception\MissingValueException;
use Derafu\Array\Exception\TypeMismatchException;
use Derafu\Array\Translation\ArrayTranslationResourceProvider;
use Derafu\Translation\TranslatorFactory;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Imports the actual package configuration and checks its provider both as a
 * service and through the translator of a consuming application.
 */
#[CoversNothing]
final class ServicesTest extends TestCase
{
    private function loadContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2) . '/resources/config')))
            ->load('array-services.yaml');

        return $container;
    }

    private function compile(ContainerBuilder $container): void
    {
        // Keep package services reachable so unused-private-service removal
        // cannot hide an invalid definition from the construction checks.
        foreach ($container->getDefinitions() as $definition) {
            $definition->setPublic(true);
        }
        foreach ($container->getAliases() as $alias) {
            $alias->setPublic(true);
        }
        $container->compile();
    }

    private function consumerTranslator(): Translator
    {
        $container = $this->loadContainer();
        // The application chooses its own translator and locale. The package
        // only contributes its resource provider through the declared tag.
        $container->register('app.translator', Translator::class)
            ->setFactory([TranslatorFactory::class, 'create'])
            ->setArguments(['es', ['en'], new TaggedIteratorArgument('derafu_translation.resource_provider')]);
        $this->compile($container);
        $translator = $container->get('app.translator');
        self::assertInstanceOf(Translator::class, $translator);

        return $translator;
    }

    public function testEveryConfiguredServiceCanBeConstructed(): void
    {
        $container = $this->loadContainer();
        $this->compile($container);
        self::assertInstanceOf(ArrayTranslationResourceProvider::class, $container->get(ArrayTranslationResourceProvider::class));
    }

    public function testTheTaggedProviderExposesThePackageCatalogue(): void
    {
        $container = $this->loadContainer();
        self::assertArrayHasKey(
            ArrayTranslationResourceProvider::class,
            $container->findTaggedServiceIds('derafu_translation.resource_provider')
        );
        $this->compile($container);
        $provider = $container->get(ArrayTranslationResourceProvider::class);
        self::assertInstanceOf(ArrayTranslationResourceProvider::class, $provider);
        $directories = [...$provider->getDirectories()];
        self::assertCount(1, $directories);
        self::assertSame(realpath(dirname(__DIR__, 2) . '/resources/translations'), realpath($directories[0]));
        self::assertFileExists($directories[0] . '/errors+intl-icu.es.php');
    }

    public function testTaggedResourcesTranslateARealTypeMismatchWithItsPathAndSource(): void
    {
        $translator = $this->consumerTranslator();
        try {
            ArrayReader::fromArray(['order' => ['quantity' => '12']], source: 'request')
                ->requireInt('order.quantity');
            self::fail('A string was accepted as an integer.');
        } catch (TypeMismatchException $exception) {
            self::assertSame(
                'La clave "order.quantity" de request debe ser de tipo int, se entregó string.',
                $exception->trans($translator)
            );
        }
    }

    public function testTaggedResourcesTranslateARealMissingKeyError(): void
    {
        $translator = $this->consumerTranslator();
        try {
            ArrayReader::fromArray([], source: 'request')->requireString('customer.name');
            self::fail('A missing required key was accepted.');
        } catch (MissingValueException $exception) {
            self::assertSame('La clave "customer.name" de request es obligatoria.', $exception->trans($translator));
        }
    }

    public function testTaggedResourcesTranslateARealTableTransformationError(): void
    {
        $translator = $this->consumerTranslator();
        try {
            ArrayTable::pairsToMap([['a', 'b', 'c']]);
            self::fail('A row with three columns was accepted as a pair.');
        } catch (ArrayException $exception) {
            self::assertSame('La fila 0 debe tener exactamente 2 columnas.', $exception->trans($translator));
        }
    }
}
