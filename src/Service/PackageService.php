<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use App\Entity\Package as SurvosPackage;
use App\Workflow\BundleWorkflowInterface;
use Composer\Semver\VersionParser;
use Packagist\Api\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Zenstruck\Twig\AsTwigFunction;

class PackageService
{
    private VersionParser $parser;
    private Client $client;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SerializerInterface $serializer,
    ) {
        $this->parser = new VersionParser();
        $this->client = new Client();
    }

    public function constraintComplies(string $versionConstraintString, array $versions, ?string $dependency = null): array
    {
        // skip php, since >= 8.1 is so common
        if ($dependency && str_contains($dependency, '/')) {
            //            assert(str_contains($dependency, '/'), $dependency);
            [$vendor, $shortName] = explode('/', $dependency);
            if ('symfony' === $vendor) {
                // don't allow >=, too many >= 2.1
                if (str_starts_with($versionConstraintString, '>')) {
                    // @todo: replace with ^<version>
                    $versionConstraintString = preg_replace('/>=?/', '', $versionConstraintString);
                    //                    dd($versionConstraintString);
                }
                if ('*' === $versionConstraintString) {
                    $this->logger->warning($dependency." $versionConstraintString does not comply with packagist.org");

                    return [];
                }
            }
        }
        $parser = new VersionParser();
        $constraint = $parser->parseConstraints($versionConstraintString);
        $matches = [];
        foreach ($versions as $version) {
            $actualVersionConstraint = $parser->parseConstraints($version);
            if ($constraint->matches($actualVersionConstraint)) {
                $this->logger->info("$actualVersionConstraint matches $version!");
                $matches[] = $version;
            }
        }
        $this->logger->info("setting $dependency $versionConstraintString to ".join('||', $matches));

        return $matches;
    }

    public function populateFromComposerData(Package $survosPackage)
    {
        // given the composer data, populate the php and other relevant values
        $survosPackage
            ->setMarking(BundleWorkflowInterface::PLACE_NEW)
            ->setSymfonyVersionString(null)
            ->setSymfonyVersions([])
            ->setPhpVersionString(null)
            ->setPhpVersions([])
            ->setPhpUnitVersions([])
            ->setPhpUnitVersionString(null)
        ;
        $data = $survosPackage->getData();
        if (!$data) {
            return;
        }

        if ($data['abandoned'] || (0 == count($data['require'] ?? []))) {
            $survosPackage->setMarking(BundleWorkflowInterface::PLACE_ABANDONED);

            return;
        }

        if ($phpVersionStr = $data['require']['php'] ?? false) {
            $matches = $this->constraintComplies($phpVersionStr, ['8.2', '8.3', '8.4']);
            $survosPackage
                ->setPhpVersionString($phpVersionStr)
                ->setPhpVersions($matches)
                ->setMarking(count($matches) ? BundleWorkflowInterface::PLACE_PHP_OKAY : BundleWorkflowInterface::PLACE_OUTDATED_PHP);
        } else {
            // missing PHP, this is usually bad.
            $survosPackage
                ->setMarking(BundleWorkflowInterface::PLACE_OUTDATED_PHP);
        }

        if (0 === count($survosPackage->getPhpVersions())) {
            return;
        }

        $distribution = []; // for tracking bundle counts ??? should be elsewhere.
//        dd($data['keywords']);
//        $survosPackage->setKeywords($data['keywords']); // could also get this from the json directly!
        //        dd($data['keywords'], $survosPackage->getKeywords());

        // find the first package that matches and use it for the symfony version.  This isn't very good.
        foreach (['symfony/runtime', 'symfony/config', 'symfony/http-kernel', 'symfony/dependency-injection',
                     'symfony/framework-bundle', 'symfony/http-client', 'symfony/console'] as $dependency) {
            if ($symfonyVersionStr = $data['require'][$dependency] ?? false) {
                break;
            }
        }
        if ($symfonyVersionStr) {
            $symfonyVersions = $this->constraintComplies($symfonyVersionStr, ['5.4', '6.4', '7.0'], $dependency);
            if (count($symfonyVersions)) {
//                dd($symfonyVersions);
            }
            $survosPackage
                ->setSymfonyVersions($symfonyVersions)
                ->setSymfonyVersionString($symfonyVersionStr." ($dependency)")
                ->setMarking(count($symfonyVersions) ? BundleWorkflowInterface::PLACE_SYMFONY_OKAY : BundleWorkflowInterface::PLACE_SYMFONY_OUTDATED);
        } else {
            // no valid symfony, warn?

            // no! Do this in the workflow
//            $survosPackage->setMarking(SurvosPackage::PLACE_SYMFONY_OUTDATED);

            return;
            dd($survosPackage->getName(), $data ?? []);
        }

        if ($phpUnitVersionStr = $data['requireDev']['phpunit/phpunit'] ?? null) {
            $matches = $this->constraintComplies($phpUnitVersionStr, ['8.4', '9.4', '10.3', '11.4'], 'phpunit/phpunit');
            $survosPackage
                ->setPhpUnitVersions($matches)
                ->setPhpUnitVersionString($phpUnitVersionStr);
        }
//        $survosPackage->setKeywords($data['keywords']);
//        assert(count($survosPackage->getKeywords()), "no keywords");
    }

    private function getPackagistUrl($name): string
    {
        return sprintf("https://packagist.org/packages/$name");
    }

    public function addPackage(SurvosPackage $survosPackage): void
    {
        // @todo: cache
        try {
            $composer = $this->client->getComposer($survosPackage->getName());
            assert($composer);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage()."\n".$survosPackage->getName());
            $survosPackage->setMarking(Package::PLACE_NOT_FOUND);

            return;
        }
        assert(1 == count($composer), 'multiple packages: '.join("\n", array_keys($composer)));

        /**
         * @var string                        $packageName
         * @var \Packagist\Api\Result\Package $package
         */
        foreach ($composer as $packageName => $package) {
            // if it's abandoned, don't even add it. Actually, we've already added it. :-(
            if ($package->isAbandoned()) {
                $survosPackage->setMarking($survosPackage::PLACE_ABANDONED);
                continue;
            }
            /** @var \Packagist\Api\Result\Package\Version $version */
            foreach ($package->getVersions() as $versionCode => $version) {
                if ($version->isAbandoned()) {
                    $survosPackage->setMarking($survosPackage::PLACE_ABANDONED);

                    return; // is this true?
                    continue;
                }
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
//                    ->setMarking($survosPackage::PLACE_COMPOSER_LOADED)
//                    ->setPhpVersionString($versionCode)
                    ->setDescription($version->getDescription())
                    ->setData(json_decode($json, true));

                //                        dd($result, $package, $composer, $versionCode, json_decode($json, false));
                foreach ($version->getRequire() as $key => $require) {
                    //                            dump($key, $require);
                }

                //                dd($survosPackage, $package, $version);
                return;
                break; // we're getting the first one only, most recent.  hackish
            }
        }
    }
}
