<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * README and AGENTS.md from the package's source repository, pinned to the
 * reference Packagist reported. Raw GitHub content avoids the API's 60 requests
 * an hour for anonymous callers. A missing file is data (null), not a failure;
 * any other non-2xx throws so Messenger retries.
 */
final class PackageDocsFetcher
{
    private const README_NAMES = ['README.md', 'readme.md', 'Readme.md', 'README.rst', 'README.txt', 'README.markdown', 'README'];
    private const AGENTS_NAMES = ['AGENTS.md'];
    private array $blockedUntil = [];

    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
        $this->httpClient = new NoPrivateNetworkHttpClient($httpClient);
    }

    public function fetch(Package $package): void
    {
        $base = self::rawBaseUrl($package);
        $package->readme = $base ? $this->first($base, self::README_NAMES) : null;
        $package->agentsMd = $base ? $this->first($base, self::AGENTS_NAMES) : null;
        $package->readmeStatus = $base === null ? 'unsupported' : ($package->hasReadme ? 'found' : 'missing');
        $package->docsFetchedAt = new \DateTimeImmutable();
    }

    /** Provider-specific pinned document URL; null means unsupported, not missing. */
    public static function rawBaseUrl(Package $package): ?string
    {
        $url = $package->data['source']['url'] ?? $package->sourceUrl;
        $reference = $package->data['source']['reference'] ?? null;
        if (!is_string($url)) {
            return null;
        }
        $url = preg_replace('{^(?:git@|ssh://git@)([^/:]+)[:/]}', 'https://$1/', $url);
        $reference = is_string($reference) && $reference !== '' ? rawurlencode($reference) : 'HEAD';
        if (preg_match('{^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?/?$}', $url, $m)) {
            return sprintf('https://raw.githubusercontent.com/%s/%s/%s', $m[1], $m[2], $reference);
        }
        if (preg_match('{^https?://bitbucket\.org/([^/]+)/([^/]+?)(?:\.git)?/?$}', $url, $m)) {
            return sprintf('https://api.bitbucket.org/2.0/repositories/%s/%s/src/%s', $m[1], $m[2], $reference);
        }
        if (preg_match('{^(https?://[^/]*gitlab[^/]*)/(.+?)(?:\.git)?/?$}', $url, $m)) {
            return sprintf('%s/api/v4/projects/%s/repository/files/{file}/raw?ref=%s', $m[1], rawurlencode($m[2]), $reference);
        }
        return null;
    }

    /** @param list<string> $names */
    private function first(string $base, array $names): ?string
    {
        foreach ($names as $name) {
            try {
                $url = str_contains($base, '{file}') ? str_replace('{file}', rawurlencode($name), $base) : $base.'/'.$name;
                $host = parse_url($url, PHP_URL_HOST);
                if (($this->blockedUntil[$host] ?? 0) > time()) {
                    throw new \RuntimeException('Documentation provider is rate-limited: '.$host);
                }
                $response = $this->httpClient->request('GET', $url, ['timeout' => 10, 'max_duration' => 20]);
                if ($response->getStatusCode() === 429) { $this->blockedUntil[$host] = time() + 60; }
                if (404 === $response->getStatusCode()) {
                    continue;
                }

                if (preg_match('{/(?:login|sign_in|signin)(?:[/?#]|$)}i', $response->getInfo('url'))) {
                    throw new \RuntimeException('Document request redirected to login: '.$url);
                }
                $content = self::normalizeText($response->getContent(), $url);
                if (trim($content) !== '') { return $content; }
            } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $error) {
                // Parallel Messenger sends errors across processes. Live HTTP responses
                // cannot be serialized; keep the diagnostic, but not the response/previous.
                throw new \RuntimeException($error->getMessage(), (int) $error->getCode());
            }
        }

        return null;
    }

    private static function normalizeText(string $content, string $url): string
    {
        // Some repositories contain a UTF-16 file, or an ASCII UTF-16LE line
        // appended to an otherwise UTF-8 README by a different editor.
        if (str_starts_with($content, "\xff\xfe")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        } elseif (str_starts_with($content, "\xfe\xff")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        } else {
            $content = preg_replace_callback('/(?:[\x09\x0A\x0D\x20-\x7E]\x00){2,}/',
                static fn (array $match): string => mb_convert_encoding($match[0], 'UTF-8', 'UTF-16LE'), $content);
        }
        // Legacy Western-European README files commonly use Windows-1252
        // (including Latin-1 accented letters). Preserve valid UTF-8 verbatim.
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $content)) {
            throw new \UnexpectedValueException(sprintf('Document contains binary control bytes: %s', $url));
        }

        return $content;
    }

}
