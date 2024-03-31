<?php

namespace App\Command;

use App\Repository\PackageRepository;
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
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Symfony\Component\Serializer\SerializerInterface;
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
        private PackageRepository      $packageRepository,
        private EntityManagerInterface $entityManager,
        private SerializerInterface    $serializer,
        private LoggerInterface        $logger,
        string                         $name = null
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
        #[Option(name: 'page-size', description: 'page size')] int                                               $pageSize = 100000
    ): void
    {

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
        $composer = $client->getComposer($survosPackage->getName());
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
                //            'marking' =>
                //                [SurvosPackage::PLACE_VALID_REQUIREMENTS, SurvosPackage::PLACE_OUTDATED_PHP, SurvosPackage::PLACE_COMPOSER_LOADED]
            ],
            limit: $pageSize
        );
        /** @var Result $result */
        $progressBar = new ProgressBar($this->io()->output(), count($packages));
        $progressBar->start();
        foreach ($packages as $survosPackage) {
            $progressBar->advance();
            $name = $survosPackage->getName();
            $survosPackage->setSymfonyVersions([]);
            $survosPackage->setPhpVersions([]);
            $data = $survosPackage->getData();
            if (!$data) {
                continue;
            }
            $survosPackage->setKeywords($data['keywords'] ?? []); // could also get this from the json directly!

            if ($data['abandoned'] || (count($data['require'] ?? []) == 0)) {
                $survosPackage->setMarking(SurvosPackage::PLACE_ABANDONED);
            } else {
                foreach ($data['require'] ?? [] as $dependency => $version) {
                    switch ($dependency) {
                        case 'php':
                            $okay = false; // unless we have a valid php version
                            $survosPackage->setPhpVersions([]);
                            try {
                                $constraint = $parser->parse($version);
                            } catch (\Exception $exception) {
                                break; // probably >= 8.2 or something like that.
                            }
                            foreach ($allowed as $value) {
                                if (!$complies = $constraint->complies($phpVersion = $phpVersions[$value])) {
//                                dd(actual: $version, minimum: $phpVersion->getVersionString());
                                } else {
                                    $okay = true;
                                    $survosPackage->addPhpVersion($value);
                                }
                            }
                            if (!$okay) {
                                $survosPackage->setMarking(SurvosPackage::PLACE_OUTDATED_PHP);
                            }
                            break;
                        default:
                            if (str_contains($dependency, 'symfony/') &&
                                !u($dependency)->startsWith('symfony/ux') &&
                                !in_array($dependency, ['symfony/flex'])) {
                                [$vendor, $shortName] = explode('/', $dependency);
                                if ($vendor == 'symfony') {
                                    // too many false positives with "*" or ">2.0".
                                    if (!preg_match("/\d/", $version)) {
                                        dump($version);
                                        $okay = false;
                                        break;
                                    }
                                    try {
                                        $constraint = $parser->parse($version);
                                    } catch (\Exception $exception) {
                                        $this->logger->info(sprintf("%s %s\n%s\n%s",
                                            $dependency, $version,
                                            $this->getPackagistUrl($name),
                                            $exception->getMessage()));
                                        break;
//                                    dd($dependency, $version, $exception);
                                    }
                                    foreach (['5.4', '6.4', '7.0'] as $x) {
                                        $complies = $constraint->complies(new Version($x)); // true
//                                    $complies = Comparator::greaterThan($version, $x); // 1.25.0 > 1.24.0
//                                    if (!$complies) dd($complies, $x, $version);
                                        if ($complies) {
                                            $survosPackage->addSymfonyVersion($x);
                                            $okay = true;
                                        }
                                    }
                                }
                            } else {
                                $okay = true;
                            }
                            break;
                    }
                }
            }

            if (!in_array($survosPackage->getMarking(),
                [SurvosPackage::PLACE_OUTDATED_PHP,
                    SurvosPackage::PLACE_ABANDONED])) {
                if (count($survosPackage->getSymfonyVersions()) == 0) {
                    $this->logger->info("outdated " . $this->getPackagistUrl($name));
                    $survosPackage->setMarking(SurvosPackage::PLACE_OUTDATED);
                } else {
                    $survosPackage->setMarking(SurvosPackage::PLACE_VALID_REQUIREMENTS);
                }
            }

            if ((($progressBar->getProgress() % 500) == 0)) {
//                $this->logger->warning("Flushing");
                $this->entityManager->flush();
//                $this->logger->warning("Flushed!");
            }
        }
        $progressBar->finish();
    }

    public function fetch(int $pageSize, Client $client): void
    {
//        $packages = $this->packageRepository->findBy(['vendor' => 'symfony'], limit: $pageSize);
        $packages = $this->packageRepository->findAll();
        /** @var Result $result */
        foreach ($packages as $survosPackage) {
            $name = $survosPackage->getName();
            $this->io()->writeln($name);
            $this->addPackage($client, $survosPackage);
            $this->entityManager->flush();
        }
    }

    private function getPackagistUrl($name): string
    {
        return sprintf("https://packagist.org/packages/$name");

    }
}
