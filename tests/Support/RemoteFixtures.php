<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Recorded remote responses for the test HttpClient; unknown requests fail loudly. */
final class RemoteFixtures
{
    public const PACKAGE = 'symfony/monolog-bundle';
    public const README = "# MonologBundle\n";

    public function __invoke(string $method, string $url): MockResponse
    {
        if ($url === 'https://packagist.org/packages/'.self::PACKAGE.'.json') {
            return new MockResponse(file_get_contents(__DIR__.'/../fixtures/packagist-symfony-monolog-bundle.json'), ['response_headers' => ['content-type' => 'application/json']]);
        }
        if (preg_match('{^https://packagist\.org/packages/([^/]+/[^/]+)\.json$}', $url, $m)) {
            // Discovered dependencies: plain libraries without constraints or docs.
            return new MockResponse(json_encode(['package' => ['name' => $m[1], 'versions' => ['dev-main' => [
                'name' => $m[1], 'version' => 'dev-main', 'default-branch' => true, 'type' => 'library',
                'time' => '2026-01-01T00:00:00+00:00', 'source' => null, 'require' => [],
            ]]]], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        }
        if (str_starts_with($url, 'https://raw.githubusercontent.com/symfony/monolog-bundle/')) {
            return str_ends_with($url, '/README.md') ? new MockResponse(self::README) : new MockResponse('', ['http_code' => 404]);
        }

        throw new \LogicException(sprintf('Unexpected test HTTP request: %s %s', $method, $url));
    }
}
