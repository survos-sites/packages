<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

/** Records evidence only; workflow guards decide what follows. */
final class CanonicalProbe
{
    private HttpClientInterface $http;
    /** Providers returning 429 are skipped for the rest of this pass. */
    private array $rateLimitedHosts = [];

    public function __construct(HttpClientInterface $httpClient, #[Autowire(env: 'default::GITHUB_TOKEN')] private ?string $githubToken = null)
    {
        $this->http = new NoPrivateNetworkHttpClient($httpClient);
    }

    public function probe(Package $package): void
    {
        $this->probeBatch([$package]);
    }

    /** @param list<Package> $packages Requests overlap; callers bound the batch size. */
    public function probeBatch(array $packages, bool $force = false): void
    {
        $pending = [];
        $github = [];
        foreach ($packages as $package) {
            $url = $package->canonicalUrl ?? $package->data['source']['url'] ?? $package->sourceUrl;
            $url = is_string($url) ? preg_replace('{^(?:git@|ssh://git@)([^/:]+)[:/]}', 'https://$1/', $url) : null;
            $url = $url ? preg_replace('{\.git/?$}', '', $url) : null;
            if (!$force && ($package->canonicalProbe['url'] ?? null) === $url
                && in_array($package->canonicalProbe['outcome'] ?? null, ['available', 'unavailable'], true)
                && new \DateTimeImmutable($package->canonicalProbe['checkedAt']) > new \DateTimeImmutable('-24 hours')) {
                continue;
            }
            $package->canonicalProbe = ['url' => $url, 'checkedAt' => (new \DateTimeImmutable())->format(DATE_ATOM), 'status' => null, 'outcome' => 'inconclusive'];
            if (!$url || !preg_match('{^https?://}i', $url)) {
                $package->canonicalProbe['detail'] = 'No HTTP repository URL';
                continue;
            }
            if ($this->githubToken && preg_match('{^https?://github\.com/([^/]+)/([^/]+)/?$}i', $url, $parts)) {
                $github[] = [$package, $parts[1], $parts[2]];
                continue;
            }
            // Bitbucket's web UI can return a login page; use its repository API.
            // GitHub's public repository page avoids the anonymous API's 60/hour cap.
            $target = preg_replace('{^https?://bitbucket\.org/([^/]+/[^/]+)/?$}i', 'https://api.bitbucket.org/2.0/repositories/$1', $url);
            if (isset($this->rateLimitedHosts[parse_url($target, PHP_URL_HOST)])) {
                $package->canonicalProbe['status'] = 429;
                $package->canonicalProbe['detail'] = 'Provider rate-limited earlier in this pass';
                continue;
            }
            try {
                $pending[] = [$package, $this->http->request('GET', $target, ['timeout' => 8, 'max_duration' => 12, 'max_redirects' => 5, 'buffer' => false])];
            } catch (ExceptionInterface $e) {
                $package->canonicalProbe['detail'] = $e->getMessage();
            }
        }
        $this->probeGithub($github);
        foreach ($pending as [$package, $response]) {
            try {
                $status = $response->getStatusCode();
                if ($status === 429) {
                    $this->rateLimitedHosts[parse_url($response->getInfo('url'), PHP_URL_HOST)] = true;
                }
                $package->canonicalProbe['status'] = $status;
                $package->canonicalProbe['resolvedUrl'] = $response->getInfo('url');
                $login = preg_match('{/(?:login|signin|sign_in)(?:[/?#]|$)}i', $response->getInfo('url'));
                $package->canonicalProbe['outcome'] = in_array($status, [404, 410], true) ? 'unavailable'
                    : ($status >= 200 && $status < 300 && !$login ? 'available' : 'inconclusive');
            } catch (ExceptionInterface $e) {
                $package->canonicalProbe['detail'] = $e->getMessage();
            } finally {
                $response->cancel();
            }
        }
    }

    /** One authenticated API request per batch, rather than one page per repository. */
    private function probeGithub(array $repositories): void
    {
        if ($repositories === []) {
            return;
        }
        if (isset($this->rateLimitedHosts['api.github.com'])) {
            foreach ($repositories as [$package]) {
                $package->canonicalProbe['status'] = 429;
                $package->canonicalProbe['detail'] = 'Provider rate-limited earlier in this pass';
            }
            return;
        }
        $fields = [];
        foreach ($repositories as $i => [$package, $owner, $name]) {
            $fields[] = sprintf('r%d: repository(owner: %s, name: %s) { url }', $i, json_encode($owner, JSON_THROW_ON_ERROR), json_encode($name, JSON_THROW_ON_ERROR));
        }
        try {
            $response = $this->http->request('POST', 'https://api.github.com/graphql', [
                'auth_bearer' => $this->githubToken, 'json' => ['query' => '{ '.implode(' ', $fields).' }'],
                'timeout' => 10, 'max_duration' => 20, 'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
            foreach ($repositories as $i => [$package]) {
                $package->canonicalProbe['status'] = $status;
                $package->canonicalProbe['provider'] = 'github';
                if (array_any($body['errors'] ?? [], static fn (array $error): bool => ($error['type'] ?? null) === 'RATE_LIMITED')) {
                    $package->canonicalProbe['status'] = 429;
                    $this->rateLimitedHosts['api.github.com'] = true;
                    continue;
                }
                if ($status !== 200) {
                    if ($status === 429) {
                        $this->rateLimitedHosts['api.github.com'] = true;
                    }
                    continue;
                }
                if (isset($body['data']['r'.$i]['url'])) {
                    $package->canonicalProbe['outcome'] = 'available';
                    $package->canonicalProbe['resolvedUrl'] = $body['data']['r'.$i]['url'];
                } else {
                    foreach ($body['errors'] ?? [] as $error) {
                        if (($error['path'][0] ?? null) === 'r'.$i && ($error['type'] ?? null) === 'NOT_FOUND') {
                            $package->canonicalProbe['outcome'] = 'unavailable';
                            $package->canonicalProbe['detail'] = 'GitHub repository NOT_FOUND';
                        }
                    }
                }
            }
        } catch (ExceptionInterface $e) {
            foreach ($repositories as [$package]) {
                $package->canonicalProbe['detail'] = $e->getMessage();
            }
        }
    }

}
