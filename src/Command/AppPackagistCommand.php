<?php

namespace App\Command;

use App\Entity\Package as SurvosPackage;
use App\Message\FetchComposer;
use App\Repository\PackageRepository;
use App\Service\PackageService;
use App\Workflow\BundleWorkflow;
use App\Workflow\BundleWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Packagist\Api\Client;
use Packagist\Api\Result\Package;
use Packagist\Api\Result\Result;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsCommand('app:load-data', 'Search and Load repos from packagist')]
final class AppPackagistCommand
{
    private SymfonyStyle $io;

    public function __construct(
        private PackageRepository          $packageRepository,
        private EntityManagerInterface     $entityManager,
        private SerializerInterface        $serializer,
        private LoggerInterface            $logger,
        private MessageBusInterface        $messageBus,
        private PackageService             $packageService,
        #[Target(BundleWorkflow::WORKFLOW_NAME)]
        private readonly WorkflowInterface $workflow,
        private CacheInterface             $cache,
        ?string                            $name = null,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'search query for packages, e.g.type=symfony-bundle')] string $q = 'type=symfony-bundle',
        //        #[Option(description: 'scrape the package details')] bool                             $details = false,
        #[Option(description: 'load the bundle names and vendors')] bool $setup = true,
        #[Option(description: 'refresh the data')] bool $refresh = true,
        #[Option(description: 'fetch the latest version json')] bool $fetch = false,
        #[Option(description: 'process the json in the database')] bool $process = false,
        #[Option(name: 'page-size', description: 'page size')] int $pageSize = 100000,
        #[Option(description: 'limit (for testing)')] int $limit = 0,
        #[Option(description: 'batch size (for flush)')] int $batch = 500,
        #[Option(description: 'filter by marking')] ?string $marking = '',
        #[Option(description: 'filter by vendor')] ?string $vendor = '',
        #[Option(description: 'filter by short name')] ?string $name = '',
//        #[Option(description: 'Dispatch a load transition for new packages')] ?bool $dispatch = null,
    ): int {
        //        // note: we are handling abandoned earlier
        $transitions = [
            BundleWorkflowInterface::TRANSITION_LOAD,
            BundleWorkflow::TRANSITION_PHP_TOO_OLD,
            BundleWorkflow::TRANSITION_PHP_OKAY,
            BundleWorkflow::TRANSITION_OUTDATED,
            BundleWorkflow::TRANSITION_VALID,
        ];

        $this->io = $io;
        $client = new Client();
        //        'fields' => ['abandoned','repository','type'],

        if ($setup) {
            $idx = 0;
            $packages = $this->cache->get('json', function (CacheItem $cacheItem) {
                $cacheItem->expiresAfter(3600);
                $json = file_get_contents('https://packagist.org/packages/list.json?type=symfony-bundle&fields[]=abandoned&fields[]=type&fields[]=repository');
                return json_decode($json)->packages;
            });

            foreach ($packages as $packageName => $package) {
                if (true === $package->abandoned) {
                    continue; // no replacement
                }
                // at this point, we don't care that much to replace it.
                if (is_string($package->abandoned)) {
                    continue; // no replacement
                }

                [$vendor, $shortName] = explode('/', $packageName);

                if (!$survosPackage = $this->packageRepository->findOneBy(['name' => $packageName])) {
                    $survosPackage = new SurvosPackage();
                    $survosPackage
                        ->setName($packageName);
                    $this->entityManager->persist($survosPackage);
                }
//                https://repo.packagist.org/p/[vendor]/[package].json


                $survosPackage
                    ->setRepo($package->repository)
                    ->setVendor($vendor)
                    ->setShortName($shortName);
                if (($idx++ % 100) == 1) {
                    $this->entityManager->flush();
                    $this->io->writeln("flushing $idx");
                }

                if ($limit && ($idx >= $limit)) {
                    break;
                }
            }
            $this->entityManager->flush();
            $io->writeln('bundled loaded: '.$this->packageRepository->count([]));
        }

        $where = [];
        if ($marking) {
            $where['marking'] = $marking;
        }

        if ($fetch) {
            $this->fetch($pageSize, $client, $refresh);
        }

        // interesting, but not working as expected. :-(
        if (false && $process) {
            $progressBar = new ProgressBar($io, count($packages));
            foreach ($packages as $package) {
                $progressBar->advance();
                //                    $transitions = $this->workflow->getEnabledTransitions($package);
                foreach ($transitions as $transitionName) {
                    //                        $transitionName = $t->getName();
                    $original = $package->getMarking();
                    if ($this->workflow->can($package, $transitionName)) {
                        $this->workflow->apply($package, $transitionName);
                        $this->entityManager->flush();
                        $this->logger->info($package->getName()." @$original ==>".$transitionName.'->'.$package->getMarking());
                    //                        dd($package, $transition);
                    } else {
                        //                            $this->logger->info("Skipping " . $package->getName() . " @$original ==>" . $transitionName);
                        //                            $reasons = $this->workflow->buildTransitionBlockerList($package, $transitionName);
                        //                    dd($reasons);
                    }
                }
                //                    if (count($transitions) === 0) {
                //                        $this->logger->info(sprintf("Skipping %s at %s no transitions", $package->getName(), $package->getMarking()));
                //                    }
            }
            $progressBar->finish();
        }

        if ($process)
        {
            if ($vendorFilter = $io->getOption('vendor')) {
                $where['vendor'] = $vendorFilter;
            }
            if ($nameFilter = $io->getOption('name')) {
                $where['shortName'] = $nameFilter;
            }
            $packages = $this->packageRepository->findBy($where, ['id' => 'desc'], limit: $limit ?: 100000);
            $progressBar = new ProgressBar($io, count($packages));
            $progressBar->start();
            $distribution = [];
            foreach ($packages as $survosPackage) {
                $progressBar->advance();
                if (!$survosPackage->getData()) {
                    $this->logger->error('Missing data in '.$survosPackage->getName());
                    //                    assert(false, $survosPackage->getName());
                    continue;
                }
                assert($survosPackage->getData(), 'missing data! for '.$survosPackage->getId().' '.$survosPackage->getMarking());
                $this->packageService->populateFromComposerData($survosPackage);
                assert(BundleWorkflowInterface::PLACE_COMPOSER_LOADED != $survosPackage->getMarking());

//                dd($survosPackage->getSymfonyVersions(), $survosPackage->getSymfonyVersionString(),  $survosPackage->getPhpVersionString());
                $this->logger->info($survosPackage->getName()." {$survosPackage->getMarking()} ".join('|', $survosPackage->getSymfonyVersions()));
                if (($progressBar->getProgress() % $this->io()->getOption('batch')) == 1) {
                    $this->logger->info('Flushing');
                    $this->entityManager->flush();
                }
            }
            $this->entityManager->flush();
            $io->success(__CLASS__ . '.process '.count($packages));
        }
        return Command::SUCCESS;
    }

    public function fetch(int $pageSize, Client $client, bool $refresh): void
    {
        //        $packages = $this->packageRepository->findBy(['vendor' => 'symfony'], limit: $pageSize);
        $packages = $this->packageRepository->findBy(
            [
                //                'marking' => [SurvosPackage::PLACE_NEW]
            ],
            ['name' => 'ASC']
        );

        $progressBar = new ProgressBar($this->io, count($packages));
        /* @var Result $result */
        foreach ($packages as $survosPackage) {
            $progressBar->advance();

            if (!$survosPackage->getPackagistData() || $refresh) {
                $this->messageBus->dispatch(new FetchComposer($survosPackage->getName(), 'packagist'));
            }

            if (!$survosPackage->getData() || $refresh) {
                $this->messageBus->dispatch(new FetchComposer($survosPackage->getName(), 'composer'));
            }
        }
        $progressBar->finish();
    }
}
