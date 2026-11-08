<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use App\Workflow\PackageWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Workflow\Registry;

final class CanonicalRecheckService
{
    public function __construct(private EntityManagerInterface $em, private CanonicalProbe $probe, private Registry $workflows) {}

    #[AsCommand('app:canonical:recheck', 'Probe stored repository URLs without reloading metadata or documents')]
    public function recheck(SymfonyStyle $io, #[Option('Only this package')] ?string $name = null,
        #[Option('Ignore the 24-hour probe cache')] bool $force = false): int
    {
        $last = 0;
        $counts = ['available' => 0, 'unavailable' => 0, 'inconclusive' => 0];
        do {
            $qb = $this->em->getRepository(Package::class)->createQueryBuilder('p')
                ->where('p.state IN (:states)')->andWhere('p.id > :last')
                ->setParameter('states', ['accepted', 'documented'])->setParameter('last', $last)
                ->orderBy('p.id')->setMaxResults(16);
            if ($name !== null) {
                $qb->andWhere('p.name = :name')->setParameter('name', $name);
            }
            $packages = $qb->getQuery()->getResult();
            $this->probe->probeBatch($packages, $force);
            foreach ($packages as $package) {
                $last = $package->id;
                ++$counts[$package->canonicalProbe['outcome']];
                $workflow = $this->workflows->get($package, PackageWorkflow::WORKFLOW_NAME);
                if ($workflow->can($package, PackageWorkflow::TRANSITION_CANONICAL_FAILED)) {
                    $workflow->apply($package, PackageWorkflow::TRANSITION_CANONICAL_FAILED, ['cascade' => 'none']);
                }
            }
            $this->em->flush();
            $this->em->clear();
            if ($packages !== []) {
                $io->writeln(json_encode($counts, JSON_THROW_ON_ERROR));
            }
        } while (count($packages) === 16);
        return 0;
    }
}
