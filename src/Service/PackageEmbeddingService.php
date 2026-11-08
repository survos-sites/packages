<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use Doctrine\ORM\EntityManagerInterface;
use Survos\SearchBundle\Contract\EmbeddingProviderInterface;
use Survos\SearchBundle\Search\EmbeddingProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PackageEmbeddingService implements EmbeddingProviderInterface
{
    public const int DIMENSIONS = 768;
    public readonly EmbeddingProfile $profile;
    private bool $verified = false;

    public function __construct(private HttpClientInterface $http, private EntityManagerInterface $em,
        #[Autowire(env: 'PACKAGE_OLLAMA_URL')] private string $url,
        #[Autowire(env: 'PACKAGE_EMBED_MODEL')] string $model,
        #[Autowire(env: 'PACKAGE_EMBED_REVISION')] string $revision)
    {
        $this->profile = new EmbeddingProfile($model, self::DIMENSIONS, $revision, 'readme-v1-chunks-1500-mean');
    }

    public static function text(Package $package): string
    {
        return trim($package->name."\n".$package->description."\n".preg_replace('/\s+/u', ' ', $package->readme ?? ''));
    }

    public static function validVector(?array $vector): bool
    {
        return $vector !== null && array_is_list($vector) && count($vector) === self::DIMENSIONS
            && array_all($vector, static fn ($v): bool => (is_int($v) || is_float($v)) && is_finite((float) $v))
            && array_any($vector, static fn ($v): bool => $v != 0);
    }

    public function cachedVector(Package $package): ?array
    {
        return $package->state === 'documented' && $this->isCurrent($package) ? $package->embedding : null;
    }

    public function isCurrent(Package $package): bool
    {
        return $package->hasReadme
            && $package->embeddingFingerprint === $this->profile->fingerprint(self::text($package))
            && self::validVector($package->embedding);
    }

    public function embed(string $text): array
    {
        return $this->generate($text, 'search_query: ');
    }

    public function vectorize(Package $package): void
    {
        if (!$package->hasReadme) {
            $package->embedding = null;
            $package->embeddingFingerprint = null;
            return;
        }
        if ($this->isCurrent($package)) {
            return;
        }
        $text = self::text($package);
        $package->embedding = $this->generate($text, 'search_document: ');
        $package->embeddingFingerprint = $this->profile->fingerprint($text);
    }

    private function generate(string $text, string $prefix): array
    {
        if (!$this->verified) {
            $models = $this->http->request('GET', rtrim($this->url, '/').'/api/tags')->toArray()['models'] ?? [];
            if (!array_any($models, fn (array $m): bool => $m['name'] === $this->profile->model && $m['digest'] === $this->profile->revision)) {
                throw new \RuntimeException('Embedding model revision mismatch; update the profile and regenerate vectors.');
            }
            $this->verified = true;
        }
        // Include the entire README, in bounded chunks. No silent model truncation.
        $chunks = mb_str_split($text, 1500, 'UTF-8');
        $sum = array_fill(0, self::DIMENSIONS, 0.0);
        foreach (array_chunk($chunks, 32) as $batch) {
            $result = $this->http->request('POST', rtrim($this->url, '/').'/api/embed', ['json' => [
                'model' => $this->profile->model, 'input' => array_map(static fn ($chunk) => $prefix.$chunk, $batch),
                'truncate' => false, 'keep_alive' => '30m',
            ], 'timeout' => 120, 'max_duration' => 180])->toArray();
            $vectors = $result['embeddings'] ?? [];
            if (count($vectors) !== count($batch)) {
                throw new \UnexpectedValueException('Embedding response did not cover every chunk.');
            }
            foreach ($vectors as $i => $vector) {
                if (!self::validVector($vector)) {
                    throw new \UnexpectedValueException('Invalid embedding vector.');
                }
                $weight = mb_strlen($batch[$i]);
                foreach ($vector as $j => $value) {
                    $sum[$j] += $value * $weight;
                }
            }
        }
        $norm = sqrt(array_sum(array_map(static fn ($v) => $v * $v, $sum)));
        if ($norm == 0 || !is_finite($norm)) {
            throw new \UnexpectedValueException('Invalid pooled embedding.');
        }
        return array_map(static fn ($v) => $v / $norm, $sum);
    }

    #[AsCommand('app:packages:embed', 'Generate missing/stale README vectors from stored content; safely resumable')]
    public function backfill(SymfonyStyle $io, #[Option('Generate at most this many vectors; zero means all')] int $limit = 0): int
    {
        if ($limit < 0) { throw new \InvalidArgumentException('Limit must be nonnegative.'); }
        $generated = $reused = 0;
        foreach ($this->em->createQuery("SELECT p FROM App\\Entity\\Package p WHERE p.state = 'documented' ORDER BY p.id")->toIterable() as $package) {
            if (!$package->hasReadme) { $this->em->detach($package); continue; }
            if ($this->cachedVector($package) !== null) {
                ++$reused;
            } else {
                $this->vectorize($package);
                $this->em->getConnection()->update('package', [
                    'embedding' => json_encode($package->embedding, JSON_THROW_ON_ERROR),
                    'embedding_fingerprint' => json_encode($package->embeddingFingerprint, JSON_THROW_ON_ERROR),
                ], ['id' => $package->id]);
                ++$generated;
                if ($generated % 100 === 0) { $io->writeln('Generated '.$generated); }
            }
            $this->em->detach($package);
            if ($limit && $generated >= $limit) { break; }
        }
        $io->success(sprintf('Generated %d; reused %d. Rebuild the semantic index to publish these vectors.', $generated, $reused));
        return 0;
    }
}
