<?php

namespace App\Command;

use App\Entity\Package as SurvosPackage;
use App\Message\FetchComposer;
use App\Repository\PackageRepository;
use App\Service\PackageService;
use App\Workflow\BundleWorkflow;
use App\Workflow\BundleWorkflowInterface;
use Castor\Attribute\AsSymfonyTask;
use Doctrine\ORM\EntityManagerInterface;
use Packagist\Api\Client;
use Packagist\Api\Result\Package;
use Packagist\Api\Result\Result;
use Psr\Cache\InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
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
//#[AsSymfonyTask(name: 'app:load')]
final class LoadDataCommand
{
    const BASE_URL = 'https://packagist.org/packages/list.json';
    private SymfonyStyle $io;

    public function __construct(
        private PackageRepository          $packageRepository,
        private EntityManagerInterface     $entityManager,
        private CacheInterface             $cache,
        private AsyncQueueLocator $asyncQueueLocator,

        private SerializerInterface        $serializer,
        private LoggerInterface            $logger,
        private MessageBusInterface        $messageBus,
        private PackageService             $packageService,
        #[Target(BundleWorkflowInterface::WORKFLOW_NAME)]
        private readonly WorkflowInterface $workflow,
    )
    {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __invoke(
        SymfonyStyle                                                             $io,
        #[Argument('search query for packages, e.g.type=symfony-bundle')] string $q = 'type=symfony-bundle',
        #[Option('load the bundle names and vendors')] bool                      $setup = true,
        #[Option('Dispatch the load request')] bool                              $dispatch = false,
        #[Option('Dispatch sync')] bool                              $sync = false,


        #[Option('refresh the data')] bool                                       $refresh = true,
        #[Option('fetch the latest version json')] bool                          $fetch = false,
        #[Option('process the json in the database')] bool                       $process = false,
        #[Option('reset (purge) the database first')] bool                       $reset = false,
        #[Option('page size')] int                                               $pageSize = 100000,
        #[Option('limit (for testing)')] int                                     $limit = 0,
        #[Option('batch size (for flush)')] int                                  $batch = 500,
        #[Option('filter by marking')] ?string                                   $marking = null,
        #[Option('filter by vendor', name: 'vendor')] ?string                    $vendorFilter = null,
        #[Option('filter by short name')] ?string                                $name = null,
//        #[Option('Dispatch a load transition for new packages')] ?bool $dispatch = null,
    ): int
    {
        //        // note: we are handling abandoned earlier

        if ($reset) {
            $this->entityManager->createQuery('DELETE FROM App\Entity\Package p')->execute();
            $this->entityManager->flush();
        }
        $this->io = $io;
        $client = new Client();
        //        'fields' => ['abandoned','repository','type'],

        if ($setup)
        {
            $idx = 0;
            // alternative:

            $packages = $this->cache->get('json', function (CacheItem $cacheItem) {
                $cacheItem->expiresAfter(3600);
                $json = file_get_contents(self::BASE_URL . '?type=symfony-bundle&fields[]=abandoned&fields[]=type&fields[]=repository');
//                file_put_contents('packages.json', $json);
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
                if ($vendorFilter && ($vendor !== $vendorFilter)) {
                    continue;
                }
                if ($name && ($shortName !== $name)) {
                    continue;
                }
                if (!$survosPackage = $this->packageRepository->findOneBy(['name' => $packageName])) {
                    $survosPackage = new SurvosPackage($packageName);

                    $this->entityManager->persist($survosPackage);
                }
//                https://repo.packagist.org/p/[vendor]/[package].json

                $survosPackage->repo = $package->repository;
                if ((++$idx % $batch) == 0) {
                    $this->entityManager->flush();
                    $this->io->writeln("flushing $idx");
                }

                if ($limit && ($idx >= $limit)) {
                    break;
                }
            }
            $this->entityManager->flush();
            $io->writeln('total bundles in database: ' . $this->packageRepository->count([]));
        }
        if ($dispatch) {
            if ($sync) {
                $this->asyncQueueLocator->sync = true;
            }
            foreach ($this->packageRepository->findBy(['marking' => BundleWorkflowInterface::PLACE_NEW], ['id' => 'ASC'], $limit ?: null) as $package) {
                $msg = new TransitionMessage($package->id, $package::class,
                    BundleWorkflowInterface::TRANSITION_LOAD,
                BundleWorkflowInterface::WORKFLOW_NAME
                );
                $stamps = $this->asyncQueueLocator->stamps($msg);
                $this->messageBus->dispatch($msg, $stamps);
            }
        }

        $where = [];
        if ($marking) {
            $where['marking'] = $marking;
        }

        return Command::SUCCESS;
    }

}
