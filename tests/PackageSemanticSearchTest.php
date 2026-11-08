<?php

declare(strict_types=1);
namespace App\Tests;
use App\Entity\Package;
use App\Service\PackageEmbeddingService;
use Survos\SearchBundle\Search\SearchProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class PackageSemanticSearchTest extends KernelTestCase
{
    public function testProjectionNeverLeaksVectorsOrPublishesStaleEmbeddings(): void
    {
        self::bootKernel();
        $provider = self::getContainer()->get(SearchProvider::class);
        $params = $provider->getSearch('package_semantic')->create()->getAdapterParameters();
        self::assertSame('lexical', $params['retrievalMode']);
        self::assertSame(768, $params['mappings']['embedding']['dims']);
        self::assertNotContains('embedding', $params['sourceFields']);
        self::assertNotContains('embeddingFingerprint', $params['sourceFields']);
        $automatic = $provider->getSearch('app_package')->create()->getAdapterParameters();
        self::assertNotContains('embeddingFingerprint', $automatic['sourceFields']);
        self::assertNotContains('embedding', $automatic['sourceFields']);
        $p = new Package('example/library'); $p->state = 'documented'; $p->readme = 'Useful README';
        $p->embedding = array_fill(0, 768, 1.0);
        $embeddings = self::getContainer()->get(PackageEmbeddingService::class);
        $p->embeddingFingerprint = $embeddings->profile->fingerprint(PackageEmbeddingService::text($p));
        self::assertSame($p->embedding, ($params['documentMapper'])($p)['embedding']);
        $p->readme .= ' changed'; self::assertNull(($params['documentMapper'])($p)['embedding']);
        $p->readme = 'Useful README'; $p->state = 'rejected'; self::assertNull(($params['documentMapper'])($p)['embedding']);
    }
}
