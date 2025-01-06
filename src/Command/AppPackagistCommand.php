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
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;

#[AsCommand('app:load-data', 'Search and Load repos from packagist')]
final class AppPackagistCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;

    public function __construct(
        private PackageRepository $packageRepository,
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private LoggerInterface $logger,
        private MessageBusInterface $messageBus,
        private PackageService $packageService,
        #[Target(BundleWorkflow::WORKFLOW_NAME)]
        private readonly WorkflowInterface $workflow,
        ?string $name = null,
    ) {
        parent::__construct($name);
    }

    public function __invoke(
        IO $io,
        #[Argument(description: 'search query for packages, e.g.type=symfony-bundle')] string $q = 'type=symfony-bundle',
        //        #[Option(description: 'scrape the package details')] bool                             $details = false,
        #[Option(description: 'load the bundle names and vendors')] bool $setup = true,
        #[Option(description: 'fetch the latest version json')] bool $fetch = true,
        #[Option(description: 'process the json in the database')] bool $process = false,
        #[Option(name: 'page-size', description: 'page size')] int $pageSize = 100000,
        #[Option(description: 'limit (for testing)')] int $limit = 0,
        #[Option(description: 'batch size (for flush)')] int $batch = 500,
        #[Option(description: 'filter by marking')] ?string $marking = null,
        #[Option(description: 'filter by vendor')] ?string $vendor = null,
        #[Option(description: 'filter by short name')] ?string $name = null,
    ): void {
        //        // note: we are handling abandoned earlier
        $transitions = [
            BundleWorkflow::TRANSITION_LOAD,
            BundleWorkflow::TRANSITION_PHP_TOO_OLD,
            BundleWorkflow::TRANSITION_PHP_OKAY,
            BundleWorkflow::TRANSITION_OUTDATED,
            BundleWorkflow::TRANSITION_VALID,
        ];

        $client = new Client();
        //        'fields' => ['abandoned','repository','type'],

        if ($setup) {
            $idx = 0;
            $json = file_get_contents('https://packagist.org/packages/list.json?type=symfony-bundle&fields[]=abandoned&fields[]=type&fields[]=repository');
            $packages = json_decode($json)->packages;
            foreach ($packages as $packageName => $package) {
                if (true === $package->abandoned) {
                    continue; // no replacement
                }
                // at this point, we don't care that much to replace it.
                if (is_string($package->abandoned)) {
                    continue; // no replacement
                }

                //                dd($packageName, $package);
                //            // packagist api is buggy, e.g.
                //            // https://packagist.org/packages/list.json?vendor=zenstruck&type=symfony-bundle doesn't work
                //                $all = $client->all([
                // //                    'vendor' => 'zenstruck',
                //                    'fields'=> ['type','repository','abandoned'],
                //                    'type' => 'symfony-bundle']);
                // //                $all = $client->search('zenstruck/cache-bundle', [
                // //                    'per_page' => 3,
                // //                    'fields' => ['abandoned'],
                // //                    'type' => 'symfony-bundle'
                // //                ], 4) as $idx => $packageName
                //            foreach ($packages as $idx => $package) {
                //                dd($idx, $package);
                //

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
                    $io->writeln("flushing $idx");
                }

                if ($limit && ($idx >= $limit)) {
                    break;
                }
            }
            $this->entityManager->flush();
            $this->io()->writeln('bundled loaded: '.$this->packageRepository->count([]));
        }

        $where = [];
        if ($marking) {
            $where['marking'] = $marking;
        }

        if ($fetch) {
            $this->fetch($pageSize, $client);
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

        if ($process) {
            if ($vendorFilter = $io->getOption('vendor')) {
                $where['vendor'] = $vendorFilter;
            }
            if ($nameFilter = $io->getOption('name')) {
                $where['shortName'] = $nameFilter;
            }
            $packages = $this->packageRepository->findBy($where, ['id' => 'desc'], limit: $limit ?: 100000);
            $progressBar = new ProgressBar($this->io()->output(), count($packages));
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
            $io->success($this->getName().' process '.count($packages));
        }
    }

    public function fetch(int $pageSize, Client $client): void
    {
        //        $packages = $this->packageRepository->findBy(['vendor' => 'symfony'], limit: $pageSize);
        $packages = $this->packageRepository->findBy(
            [
                //                'marking' => [SurvosPackage::PLACE_NEW]
            ],
            ['name' => 'ASC']
        );

        $progressBar = new ProgressBar($this->io()->output(), count($packages));
        /* @var Result $result */
        foreach ($packages as $survosPackage) {
            $progressBar->advance();

            if (!$survosPackage->getPackagistData()) {
                $this->messageBus->dispatch(new FetchComposer($survosPackage->getName(), 'packagist'));
            }

            if (!$survosPackage->getData()) {
                $this->messageBus->dispatch(new FetchComposer($survosPackage->getName(), 'composer'));
            }
        }
        $progressBar->finish();
    }
}
