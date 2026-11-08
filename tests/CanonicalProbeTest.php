<?php

declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Service\CanonicalProbe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CanonicalProbeTest extends TestCase
{
    public static function statuses(): iterable
    {
        yield [200, 'available'];
        yield [404, 'unavailable'];
        yield [410, 'unavailable'];
        foreach ([401, 403, 429, 500, 503] as $status) {
            yield [$status, 'inconclusive'];
        }
    }

    #[DataProvider('statuses')]
    public function testProbeRecordsEvidenceWithoutChangingState(int $status, string $outcome): void
    {
        $p = new Package('yaboroda/ybsy-file-bundle');
        $p->state = 'accepted';
        $p->data = ['source' => ['url' => 'https://bitbucket.org/yaboroda/ybsy-file-bundle.git']];
        $probe = new CanonicalProbe(new MockHttpClient(function ($method, $url) use ($status) {
            self::assertSame('https://api.bitbucket.org/2.0/repositories/yaboroda/ybsy-file-bundle', $url);
            return new MockResponse('', ['http_code' => $status]);
        }));
        $probe->probe($p);
        self::assertSame($outcome, $p->canonicalProbe['outcome']);
        self::assertSame($status, $p->canonicalProbe['status']);
        self::assertSame('accepted', $p->state);
        self::assertNull($p->rejectionReason);
    }

    public function testRateLimitedProviderIsSkippedForRestOfPass(): void
    {
        $calls = 0;
        $probe = new CanonicalProbe(new MockHttpClient(function () use (&$calls) {
            ++$calls;
            return new MockResponse('', ['http_code' => 429]);
        }));
        foreach (['first', 'second'] as $name) {
            $p = new Package('example/'.$name);
            $p->canonicalUrl = 'https://bitbucket.org/example/'.$name;
            $probe->probe($p);
            self::assertSame('inconclusive', $p->canonicalProbe['outcome']);
            self::assertFalse($p->canonicalUnavailable);
        }
        self::assertSame(1, $calls);
    }

    public function testGithubBatchDistinguishesMissingFromAccessErrors(): void
    {
        $packages = array_map(static function (string $name): Package {
            $p = new Package('example/'.$name);
            $p->canonicalUrl = 'https://github.com/example/'.$name;
            return $p;
        }, ['present', 'missing', 'denied']);
        $calls = 0;
        $probe = new CanonicalProbe(new MockHttpClient(function ($method, $url) use (&$calls) {
            ++$calls;
            self::assertSame('https://api.github.com/graphql', $url);
            return new MockResponse(json_encode([
                'data' => ['r0' => ['url' => 'https://github.com/example/moved'], 'r1' => null, 'r2' => null],
                'errors' => [['path' => ['r1'], 'type' => 'NOT_FOUND'], ['path' => ['r2'], 'type' => 'FORBIDDEN']],
            ], JSON_THROW_ON_ERROR));
        }), 'test-token');
        $probe->probeBatch($packages);
        self::assertSame(1, $calls);
        self::assertTrue($packages[0]->canonicalAvailable);
        self::assertSame('https://github.com/example/moved', $packages[0]->canonicalProbe['resolvedUrl']);
        self::assertTrue($packages[1]->canonicalUnavailable);
        self::assertSame('inconclusive', $packages[2]->canonicalProbe['outcome']);
    }

    public function testFreshResultIsCachedButInconclusiveIsRetried(): void
    {
        $calls = 0;
        $probe = new CanonicalProbe(new MockHttpClient(function () use (&$calls) {
            return new MockResponse('', ['http_code' => ++$calls === 1 ? 503 : 200]);
        }));
        $p = new Package('example/test');
        $p->canonicalUrl = 'https://github.com/example/test';
        $probe->probe($p);
        $probe->probe($p);
        $probe->probe($p);
        self::assertSame(2, $calls);
        self::assertTrue($p->canonicalAvailable);
        $p->canonicalUrl = 'https://github.com/example/moved';
        $probe->probe($p);
        self::assertSame(3, $calls);
    }
}
