<?php

declare(strict_types=1);
namespace App\Service;
use App\Entity\Package;
use App\Workflow\PackageWorkflow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Workflow\Registry;
final class PackageReadmeReviewService
{
    public function __construct(private EntityManagerInterface $em, private Registry $registry, private PackageDocsFetcher $docs) {}

    #[AsCommand('app:packages:review-readmes', 'Apply README policy to stored packages, fetching only absent documentation')]
    public function review(SymfonyStyle $io, #[Option('Number of disjoint review partitions')] int $shards = 1, #[Option('Zero-based partition')] int $shard = 0): int
    {
        if ($shards < 1 || $shard < 0 || $shard >= $shards) { throw new \InvalidArgumentException('Invalid shard.'); }
        $checked = $rejected = $inconclusive = 0;
        foreach ($this->em->createQuery("SELECT p FROM App\\Entity\\Package p WHERE p.state IN ('accepted', 'documented') AND MOD(LENGTH(p.name), :shards) = :shard ORDER BY p.id")->setParameter('shards', $shards)->setParameter('shard', $shard)->toIterable() as $p) {
            $workflow = $this->registry->get($p, WF::WORKFLOW_NAME);
            try {
                if ($p->state === 'accepted') {
                    if (!$workflow->can($p, WF::TRANSITION_FETCH_DOCS)) { ++$inconclusive; $this->em->detach($p); continue; }
                    $workflow->apply($p, WF::TRANSITION_FETCH_DOCS, ['cascade' => 'none']);
                } elseif (!$p->hasReadme) {
                    $this->docs->fetch($p);
                } else {
                    $p->readmeStatus = 'found';
                }
                if ($workflow->can($p, WF::TRANSITION_README_MISSING)) {
                    $workflow->apply($p, WF::TRANSITION_README_MISSING, ['cascade' => 'none']);
                    ++$rejected;
                }
                if ($p->readmeStatus === 'unsupported') { ++$inconclusive; }
                $this->em->flush();
                ++$checked;
            } catch (\RuntimeException $error) {
                ++$inconclusive;
                $io->writeln($p->name.': '.$error->getMessage());
            }
            $this->em->detach($p);
            if ($checked % 100 === 0) { $io->writeln(sprintf('Checked %d; rejected %d; inconclusive %d', $checked, $rejected, $inconclusive)); }
        }
        $io->success(sprintf('Checked %d; rejected %d; inconclusive %d', $checked, $rejected, $inconclusive));
        return 0;
    }
}
