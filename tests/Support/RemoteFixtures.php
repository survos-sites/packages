<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;

/** Recorded remote responses for the test HttpClient; unknown requests fail loudly. */
final class RemoteFixtures
{
    public const PACKAGE = 'symfony/monolog-bundle';
    public const README = "# MonologBundle\n";

    public function __invoke(string $method, string $url, array $options = []): MockResponse
    {
        if (str_ends_with($url, '/api/tags')) {
            return new MockResponse(json_encode(['models' => [['name' => 'nomic-embed-text:latest', 'digest' => '0a109f422b47e3a30ba2b10eca18548e944e8a23073ee3f3e947efcf3c45e59f']]]));
        }
        if (str_ends_with($url, '/api/embed')) {
            $inputs = json_decode($options['body'], true)['input'];
            $vector = array_fill(0, 768, 0.0);
            $vector[0] = 1.0;
            return new MockResponse(json_encode(['embeddings' => array_fill(0, count($inputs), $vector)]));
        }
        if ($url === 'https://api.bitbucket.org/2.0/repositories/yaboroda/ybsy-file-bundle') {
            return new MockResponse('', ['http_code' => 404]);
        }
        if (preg_match('{^https://github\.com/(?:symfony|monolog|psr)/[^/]+$}', $url)) {
            return new MockResponse('Repository');
        }
        if ($url === 'https://packagist.org/packages/'.self::PACKAGE.'.json') {
            return new MockResponse(file_get_contents(__DIR__.'/../fixtures/packagist-symfony-monolog-bundle.json'), ['response_headers' => ['content-type' => 'application/json']]);
        }
        if (preg_match('{^https://packagist\.org/packages/([^/]+/[^/]+)\.json$}', $url, $m)) {
            // Discovered dependencies: plain libraries without constraints or docs.
            return new MockResponse(json_encode(['package' => ['name' => $m[1], 'versions' => ['dev-main' => [
                'name' => $m[1], 'version' => 'dev-main', 'default-branch' => true, 'type' => 'library',
                'time' => '2026-01-01T00:00:00+00:00', 'source' => ['url' => 'https://github.com/'.$m[1].'.git'], 'require' => [],
            ]]]], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
        }
        if (str_starts_with($url, 'https://raw.githubusercontent.com/')) {
            return str_ends_with($url, '/README.md') ? new MockResponse(self::README) : new MockResponse('', ['http_code' => 404]);
        }

        throw new \LogicException(sprintf('Unexpected test HTTP request: %s %s', $method, $url));
    }
}
