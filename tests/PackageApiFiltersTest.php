<?php

declare(strict_types=1);

namespace App\Tests;

use ApiPlatform\Doctrine\Orm\Extension\ParameterExtension;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGenerator;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PackageApiFiltersTest extends KernelTestCase
{
    public function testCollectionParametersPreserveFilteringAndOrdering(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $metadata = $container->get(ResourceMetadataCollectionFactoryInterface::class)->create(Package::class);
        $operation = $metadata->getOperation('doctrine-packages');
        $parameters = $operation->getParameters();
        foreach ([
            'name' => 'Acme',
            'description' => 'Symfony',
            'order[stars]' => 'desc',
            'order[name]' => 'asc',
        ] as $key => $value) {
            self::assertTrue($parameters->has($key), $key);
            $parameters->get($key)->setValue($value);
        }
        // Legacy OrderFilter ignored this unmapped field; do not generate invalid DQL for it.
        self::assertFalse($parameters->has('order[favers]'));
        self::assertFalse($parameters->has('order[data]'));

        $query = $container->get(EntityManagerInterface::class)->createQueryBuilder()
            ->select('p')->from(Package::class, 'p');
        $container->get(ParameterExtension::class)->applyToCollection(
            $query, new QueryNameGenerator(), Package::class, $operation,
        );

        $dql = $query->getDQL();
        self::assertStringNotContainsString('p.places', $dql, 'places is a list; search facets it, the API does not filter by exact match');
        self::assertStringContainsString('p.name LIKE :', $dql);
        self::assertStringContainsString('p.description LIKE :', $dql);
        self::assertStringNotContainsString('LOWER(', $dql);
        self::assertStringContainsString('p.stars DESC', $dql);
        self::assertStringContainsString('p.name ASC', $dql);
        self::assertSame(['%Acme%', '%Symfony%'], array_map(
            static fn ($parameter) => $parameter->getValue(), $query->getParameters()->toArray(),
        ));
    }
}
