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

    public function testUnsupportedSourceMakesNoRequests(): void
    {
        $client = new MockHttpClient(static fn () => self::fail('No request expected'));
        $package = $this->package('https://unknown.example/acme/demo.git', 'abc123');
        (new PackageDocsFetcher($client))->fetch($package);

        self::assertNull($package->readme);
        self::assertFalse($package->hasAgentsMd);
    }

    public function testServerErrorsReachMessenger(): void
    {
        $package = $this->package('git@github.com:acme/demo.git', 'abc123');
        // Match DispatchTask::run(): parallel children omit trace arguments.
        $previous = ini_set('zend.exception_ignore_args', '1');
        try {
            (new PackageDocsFetcher(new MockHttpClient(new MockResponse('', ['http_code' => 503]))))->fetch($package);
            self::fail('Expected an HTTP failure');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('503', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertSame($error->getMessage(), unserialize(serialize($error))->getMessage());
            self::assertNull($package->docsFetchedAt);
        } finally {
            ini_set('zend.exception_ignore_args', $previous);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('encodedDocuments')]
    public function testNormalizesLegacyDocuments(string $raw, string $expected): void
    {
        $package = $this->package('https://github.com/acme/demo', 'abc123');
        $client = new MockHttpClient(static fn () => new MockResponse($raw));
        (new PackageDocsFetcher($client))->fetch($package);
        self::assertSame($expected, $package->readme);
        self::assertSame($expected, $package->agentsMd);
        self::assertNotNull($package->docsFetchedAt);
    }

    public static function encodedDocuments(): iterable
    {
        yield 'UTF-8 stays unchanged' => ['Éléphant 🐘', 'Éléphant 🐘'];
        yield 'legacy Latin accents' => ["Asociaci\xf3n \xc9l\xe9phant", 'Asociación Éléphant'];
        yield 'Windows punctuation' => ["\x93Hello\x94", '“Hello”'];
        yield 'UTF-16LE appended line' => ["# UTF-8\n#\0 \0A\0\r\0\n\0", "# UTF-8\n# A\r\n"];
        yield 'UTF-16LE BOM' => ["\xff\xfe".mb_convert_encoding('Éléphant', 'UTF-16LE', 'UTF-8'), 'Éléphant'];
        yield 'UTF-16BE BOM' => ["\xfe\xff".mb_convert_encoding('Éléphant', 'UTF-16BE', 'UTF-8'), 'Éléphant'];
    }

    public function testRejectsNullBytesBeforePersistence(): void
    {
        $package = $this->package('https://github.com/acme/demo', 'abc123');
        $fetcher = new PackageDocsFetcher(new MockHttpClient(new MockResponse("null\0byte")));

        $this->expectException(\UnexpectedValueException::class);
        $fetcher->fetch($package);
    }

    private function package(string $url, string $reference): Package
    {
        $package = new Package('acme/demo');
        $package->data = ['source' => ['type' => 'git', 'url' => $url, 'reference' => $reference]];

        return $package;
    }
}
