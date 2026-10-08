<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * README.md and AGENTS.md from the package's source repository, pinned to the
 * reference Packagist reported. Raw GitHub content avoids the API's 60 requests
 * an hour for anonymous callers. A missing file is data (null), not a failure;
 * any other non-2xx throws so Messenger retries.
 */
final class PackageDocsFetcher
{
    private const README_NAMES = ['README.md', 'readme.md', 'Readme.md'];
    private const AGENTS_NAMES = ['AGENTS.md'];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function fetch(Package $package): void
    {
        $base = self::rawBaseUrl($package);
        $package->readme = $base ? $this->first($base, self::README_NAMES) : null;
        $package->agentsMd = $base ? $this->first($base, self::AGENTS_NAMES) : null;
        $package->docsFetchedAt = new \DateTimeImmutable();
    }

    /** https://raw.githubusercontent.com/{owner}/{repo}/{reference}, or null for non-GitHub sources. */
    public static function rawBaseUrl(Package $package): ?string
    {
        $url = $package->data['source']['url'] ?? $package->sourceUrl;
        $reference = $package->data['source']['reference'] ?? null;
        if (!is_string($url) || !preg_match('{^(?:https?://|git@)github\.com[/:]([^/]+)/([^/]+?)(?:\.git)?/?$}', $url, $m)) {
            return null;
        }

        return sprintf('https://raw.githubusercontent.com/%s/%s/%s', $m[1], $m[2], is_string($reference) && $reference !== '' ? $reference : 'HEAD');
    }

    /** @param list<string> $names */
    private function first(string $base, array $names): ?string
    {
        foreach ($names as $name) {
            $response = $this->httpClient->request('GET', $base.'/'.$name);
            if (404 === $response->getStatusCode()) {
                continue;
            }

            return $response->getContent();
        }

        return null;
    }
}
