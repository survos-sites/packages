<?php

namespace App\Command;

use App\Repository\PackageRepository;
use App\Workflow\BundleWorkflow;
use Composer\Semver\Comparator;
use Doctrine\ORM\EntityManagerInterface;
use Packagist\Api\Result\Package;
use App\Entity\Package as SurvosPackage;
use Packagist\Api\Result\Result;
use PharIo\Version\Version;
use PharIo\Version\VersionConstraintParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Zenstruck\Console\Attribute\Argument;
use Zenstruck\Console\Attribute\Option;
use Zenstruck\Console\ConfigureWithAttributes;
use Zenstruck\Console\InvokableServiceCommand;
use Zenstruck\Console\IO;
use Zenstruck\Console\RunsCommands;
use Zenstruck\Console\RunsProcesses;
use Packagist\Api\Client;
use function Symfony\Component\String\u;

#[AsCommand('app:load-data', 'Search and Load repos from packagist')]
final class AppPackagistCommand extends InvokableServiceCommand
{
    use RunsCommands;
    use RunsProcesses;


    public function __construct(
        private PackageRepository                                  $packageRepository,
        private EntityManagerInterface                             $entityManager,
        private SerializerInterface                                $serializer,
        private LoggerInterface                                    $logger,
        #[Target(BundleWorkflow::WORKFLOW_NAME)]
        private readonly WorkflowInterface $workflow,
        string                                                     $name = null
    )
    {
        parent::__construct($name);
    }

    public function __invoke(
        IO                                                                                    $io,
        #[Argument(description: 'search query for packages, e.g.type=symfony-bundle')] string $q = 'type=symfony-bundle',
        //        #[Option(description: 'scrape the package details')] bool                             $details = false,
        #[Option(description: 'load the bundle names and vendors')] bool                      $setup = false,
        #[Option(description: 'fetch the latest version json')] bool                          $fetch = false,
        #[Option(description: 'process the json in the database')] bool                       $process = false,
        #[Option(name: 'page-size', description: 'page size')] int                                               $pageSize = 100000,
        #[Option(description: 'limit (for testing)')] int                                               $limit = 0
    ): void
    {
        // note: we are handling abandoned earlier
        $transitions = [
            BundleWorkflow::TRANSITION_LOAD,
            BundleWorkflow::TRANSITION_PHP_TOO_OLD,
            BundleWorkflow::TRANSITION_OUTDATED,
            BundleWorkflow::TRANSITION_VALID,
            ];

        $client = new Client();
//        'fields' => ['abandoned','repository','type'],

        if ($setup) {
            foreach (
                $client->all([
                    'type' => 'symfony-bundle']) as $idx => $packageName
            ) {
                [$vendor, $shortName] = explode('/', $packageName);

                if (!$survosPackage = $this->packageRepository->findOneBy(['name' => $packageName])) {
                    $survosPackage = new SurvosPackage();
                    $survosPackage
                        ->setName($packageName);
                    $this->entityManager->persist($survosPackage);
                }
                $survosPackage
                    ->setVendor($vendor)
                    ->setShortName($shortName);
                if (($idx % 100) == 1) {
                    $this->entityManager->flush();
                    $io->writeln("flushing $idx");
                }
            }
            $this->entityManager->flush();
            $this->io()->writeln("bundled loaded: " . $this->packageRepository->count([]));
        }

        foreach ($this->packageRepository->findBy([], limit: $limit) as $package) {
            foreach ($transitions as $transition) {
                if ($this->workflow->can($package, $transition)) {
                    $this->workflow->apply($package, $transition);
                    $this->entityManager->flush();
                    dd($package, $transition);
                } else {
                    $reasons = $this->workflow->buildTransitionBlockerList($package, $transition);
//                    dd($reasons);
                }

            }

        }


        if ($fetch) {
            $this->fetch($pageSize, $client);
        }
        if ($process) {
            $this->process($pageSize);
        }

        $io->success('app:packagist success. ' . $this->packageRepository->count([]));
    }

    /**
     * @param Client $client
     * @param string $name
     * @param bool $persist
     * @param SerializerInterface $serializer
     * @return void
     */
    public function addPackage(Client $client, SurvosPackage $survosPackage): void
    {
        // @todo: cache
        try {
            $composer = $client->getComposer($survosPackage->getName());
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage() . "\n" .$survosPackage->getName());
            return;
        }
        assert(count($composer) == 1, "multiple packages: " . join("\n", array_keys($composer)));
        /**
         * @var string $packageName
         * @var Package $package
         */
        foreach ($composer as $packageName => $package) {
            // if it's abandoned, don't even add it. Actually, we've already added it. :-(
            if ($package->isAbandoned()) {
                $survosPackage->setMarking($survosPackage::PLACE_ABANDONED);
                continue;
            }
//            dd($packageName, $package);
            /** @var Package\Version $version */
            foreach ($package->getVersions() as $versionCode => $version) {
                // need a different API call for github stars.
//                if ($package->getFavers() || $package->getGithubStars()) {
//                    dd($package->getFavers(), $package);
//                }
//                dd($composer, $package);
//                $package->getDescription(); //
//                assert($package->getDescription() == $version->getDescription(), $package->getDescription() . '<>' . $version->getDescription());
                $json = $this->serializer->serialize($version, 'json');
                $survosPackage
                    ->setStars($package->getFavers())
                    ->setMarking($survosPackage::PLACE_COMPOSER_LOADED)
                    ->setVersion($versionCode)
                    ->setDescription($version->getDescription())
                    ->setData(json_decode($json, true));

//                        dd($result, $package, $composer, $versionCode, json_decode($json, false));
                foreach ($version->getRequire() as $key => $require) {
//                            dump($key, $require);
                }
                return;
                break; // we're getting the first one only, most recent.  hackish
            }
        }
    }

    /**
     * @param int $pageSize
     * @param IO $io
     * @param Client $client
     * @return void
     */
    public function process(int $pageSize): void
    {
        $parser = new VersionConstraintParser();
        $allowed = ['8.1', '8.2', '8.3'];
        foreach ($allowed as $value) {
            $phpVersions[$value] = new Version($value);
        }
        $packages = $this->packageRepository->findBy(
            [
                            'marking' =>
                                [SurvosPackage::PLACE_NEW,  SurvosPackage::PLACE_COMPOSER_LOADED]
            ],
            limit: $pageSize
        );
        /** @var Result $result */
        $progressBar = new ProgressBar($this->io()->output(), count($packages));
        $progressBar->start();
        $distribution = [];
        foreach ($packages as $survosPackage) {


            if ((($progressBar->getProgress() % 500) == 0)) {
//                $this->logger->warning("Flushing");
                $this->entityManager->flush();
//                $this->logger->warning("Flushed!");
            }
        }
        $progressBar->finish();
        $table = new Table($this->io());
        $table->setHeaders(['bundle','count']);
        asort($distribution);
        foreach ($distribution as $bundle=>$count) {
            $table->addRow([$bundle, $count]);
        }
        $table->render();

        $this->io()->writeln("total " . count($packages));
    }

    public function fetch(int $pageSize, Client $client): void
    {
//        $packages = $this->packageRepository->findBy(['vendor' => 'symfony'], limit: $pageSize);
        $packages = $this->packageRepository->findBy(
            ['marking' => [SurvosPackage::PLACE_NEW]],
            ['name' => 'ASC']
        );
        /** @var Result $result */
        foreach ($packages as $survosPackage) {
            $name = $survosPackage->getName();
            $this->io()->writeln($name);
            $this->addPackage($client, $survosPackage);
            $this->entityManager->flush();
        }
    }

}
