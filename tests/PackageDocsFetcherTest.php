<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Service\PackageDocsFetcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class PackageDocsFetcherTest extends TestCase
{
    public function testFetchesReadmeAndAgentsAtTheLoadedReference(): void
    {
        $requested = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$requested): MockResponse {
            $requested[] = $url;

            return match (basename($url)) {
                'README.md' => new MockResponse('', ['http_code' => 404]),
                'readme.md' => new MockResponse('# Acme'),
                'AGENTS.md' => new MockResponse('Run the tests.'),
            };
        });
        $package = $this->package('https://github.com/acme/demo-bundle.git', 'abc123');

        (new PackageDocsFetcher($client))->fetch($package);

        self::assertSame('# Acme', $package->readme);
        self::assertSame('Run the tests.', $package->agentsMd);
        self::assertTrue($package->hasAgentsMd);
        self::assertSame([
            'https://raw.githubusercontent.com/acme/demo-bundle/abc123/README.md',
            'https://raw.githubusercontent.com/acme/demo-bundle/abc123/readme.md',
            'https://raw.githubusercontent.com/acme/demo-bundle/abc123/AGENTS.md',
        ], $requested);
    }

    public function testMissingFilesAreRecordedAsAbsent(): void
    {
        $package = $this->package('https://github.com/acme/demo', 'abc123');
        (new PackageDocsFetcher(new MockHttpClient(static fn () => new MockResponse('', ['http_code' => 404]))))->fetch($package);

        self::assertNull($package->readme);
        self::assertFalse($package->hasAgentsMd);
        self::assertNotNull($package->docsFetchedAt);
    }

    public function testNonGithubSourceMakesNoRequests(): void
    {
        $client = new MockHttpClient(static fn () => self::fail('No request expected'));
        $package = $this->package('https://gitlab.com/acme/demo.git', 'abc123');
        (new PackageDocsFetcher($client))->fetch($package);

        self::assertNull($package->readme);
        self::assertFalse($package->hasAgentsMd);
    }

    public function testServerErrorsReachMessenger(): void
    {
        $package = $this->package('git@github.com:acme/demo.git', 'abc123');
        $this->expectException(\Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface::class);
        (new PackageDocsFetcher(new MockHttpClient(new MockResponse('', ['http_code' => 503]))))->fetch($package);
    }

    private function package(string $url, string $reference): Package
    {
        $package = new Package('acme/demo');
        $package->data = ['source' => ['type' => 'git', 'url' => $url, 'reference' => $reference]];

        return $package;
    }
}
