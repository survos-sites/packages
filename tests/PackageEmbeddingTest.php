<?php

declare(strict_types=1);
namespace App\Tests;
use App\Entity\Package;
use App\Entity\PackageFile;
use App\Service\PackageEmbeddingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
final class PackageEmbeddingTest extends TestCase
{
    public function testFullReadmeIsChunkedCachedAndInvalidated(): void
    {
        $calls = 0;
        $input = [];
        $http = new MockHttpClient(function ($method, $url, $options) use (&$calls, &$input) {
            if (str_ends_with($url, '/api/tags')) { return new MockResponse('{"models":[{"name":"model","digest":"rev"}]}'); }
            ++$calls;
            $body = json_decode($options['body'], true);
            self::assertFalse($body['truncate']);
            $input = $body['input'];
            $vector = array_fill(0, 768, 0.0); $vector[0] = 1.0;
            return new MockResponse(json_encode(['embeddings' => array_fill(0, count($input), $vector)]));
        });
        $service = new PackageEmbeddingService($http, $this->createStub(EntityManagerInterface::class), 'http://localhost', 'model', 'rev');
        $p = new Package('acme/demo'); $p->state = 'documented'; $p->readme = str_repeat('Long documentation ', 400).'THE END';
        $service->vectorize($p);
        self::assertCount(768, $p->embedding);
        self::assertStringEndsWith('THE END', end($input));
        self::assertStringStartsWith('search_document: ', $input[0]);
        self::assertSame($p->embedding, $service->cachedVector($p));
        $service->vectorize($p); self::assertSame(1, $calls);
        $p->state = 'accepted'; self::assertTrue($service->isCurrent($p)); self::assertNull($service->cachedVector($p));
        $p->state = 'documented'; $p->readme .= ' Changed'; self::assertNull($service->cachedVector($p));
        $p->embedding = array_fill(0, 768, 0.0); self::assertFalse(PackageEmbeddingService::validVector($p->embedding));
        $p->embedding[0] = INF; self::assertFalse(PackageEmbeddingService::validVector($p->embedding));
    }

    public function testADocumentIsEmbeddedOnceAndKeepsItsOwnFingerprint(): void
    {
        $calls = 0;
        $http = new MockHttpClient(function ($method, $url) use (&$calls) {
            if (str_ends_with($url, '/api/tags')) { return new MockResponse('{"models":[{"name":"model","digest":"rev"}]}'); }
            ++$calls;
            $vector = array_fill(0, 768, 0.0); $vector[0] = 1.0;
            return new MockResponse(json_encode(['embeddings' => [$vector]]));
        });
        $service = new PackageEmbeddingService($http, $this->createStub(EntityManagerInterface::class), 'http://localhost', 'model', 'rev');
        $file = new PackageFile(new Package('acme/demo'), PackageFile::AGENTS, 'AGENTS.md', 'Run the tests.');
        self::assertFalse($service->isFileCurrent($file));
        $service->vectorizeFile($file);
        $service->vectorizeFile($file);
        self::assertSame(1, $calls);
        self::assertTrue($service->isFileCurrent($file));
        self::assertStringStartsWith('acme/demo', PackageEmbeddingService::fileText($file));
    }
}
