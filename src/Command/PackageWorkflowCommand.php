<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Workflow\PackageWorkflow as WF;
use App\Workflow\PackagePipeline;
use App\Workflow\PackagePlace;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Style\SymfonyStyle;

final readonly class PackageWorkflowCommand
{
    public function __construct(private PackageRepository $packages, private PackagePipeline $pipeline, private WorkflowCatalog $catalog, private EntityManagerInterface $em) {}

    #[AsCommand('state:iterate', 'Apply or queue a transition for matching packages.')]
    public function iterate(
        SymfonyStyle $io,
        #[Argument('Entity name')] string $className = 'Package',
        #[Option('Transition to dispatch', shortcut: 't')] string $transition = 'load',
        #[Option('Comma-separated places', shortcut: 'm')] ?string $marking = null,
        #[Option('Workflow name', shortcut: 'w')] string $workflowName = WF::WORKFLOW_NAME,
        #[Option('Run transitions synchronously')] bool $sync = false,
        #[Option('Continuation mode: async, sync or none')] ?string $cascade = null,
        #[Option('Maximum number to dispatch; zero means all')] int $limit = 0,
        #[Option('Property filters as a query string')] string $filter = '',
    ): int {
        if (!in_array($className, ['Package', Package::class], true) || !in_array($workflowName, $this->catalog->forClass(Package::class), true)) {
            throw new \InvalidArgumentException('This application iterates Package workflows only.');
        }
        $cascade ??= $sync ? 'sync' : 'async';
        if (!in_array($cascade, ['async', 'sync', 'none'], true) || $limit < 0) {
            throw new \InvalidArgumentException('Invalid cascade mode or limit.');
        }
        $workflow = $this->catalog->get($workflowName);
        if (!in_array($transition, array_map(static fn ($t) => $t->getName(), $workflow->getDefinition()->getTransitions()), true)) {
            throw new \InvalidArgumentException('Unknown transition: '.$transition);
        }
        $query = $this->packages->createQueryBuilder('p')->orderBy('p.id', 'ASC');
        if ($marking !== null) {
            $places = array_map(static fn ($value) => PackagePlace::from(trim($value))->value, explode(',', $marking));
            $query->andWhere('p.marking IN (:places)')->setParameter('places', $places);
        }
        parse_str($filter, $filters);
        foreach ($filters as $field => $value) {
            if (!in_array($field, ['id', 'name', 'vendor', 'abandoned'], true) || !is_scalar($value)) {
                throw new \InvalidArgumentException('Unsupported package filter: '.$field);
            }
            $query->andWhere('p.'.$field.' = :'.$field)->setParameter($field, $field === 'abandoned' ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : $value);
        }
        $count = $skipped = 0;
        $previousSync = $this->pipeline->sync;
        $this->pipeline->sync = $sync;
        try {
            foreach ($query->getQuery()->toIterable() as $package) {
                if (!$workflow->can($package, $transition)) {
                    ++$skipped;
                } else {
                    $this->pipeline->dispatch($package, $transition, ['cascade' => $cascade]);
                    ++$count;
                }
                $this->em->clear();
                if ($limit && $count >= $limit) {
                    break;
                }
            }
        } finally {
            $this->pipeline->sync = $previousSync;
        }
        $io->success(sprintf('%d transitions %s; %d packages skipped.', $count, $sync ? 'applied' : 'requested', $skipped));
        return 0;
    }

    #[AsCommand('state:stats', 'Count packages by workflow place.')]
    public function stats(SymfonyStyle $io, #[Argument('Entity name')] string $className = 'Package'): int
    {
        if (!in_array($className, ['Package', Package::class], true)) {
            throw new \InvalidArgumentException('This application counts Package workflows only.');
        }
        $counts = $this->packages->countByMarking();
        $io->table(['Place', 'Packages'], array_map(static fn (PackagePlace $place) => [$place->value, $counts[$place->value] ?? 0], PackagePlace::cases()));
        return 0;
    }
}
