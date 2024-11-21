<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use App\Entity\Package as SurvosPackage;
use Packagist\Api\Client;
use PharIo\Version\Version;
use PharIo\Version\VersionConstraintParser;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Zenstruck\Twig\AsTwigFunction;
use function Symfony\Component\String\u;

class PackageService
{
    private VersionConstraintParser $parser;
    private Client $client;
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly SerializerInterface $serializer,
    )
    {
        $this->parser = new VersionConstraintParser();
        $this->client = new Client();


    }

    public function populateFromComposerData(Package $survosPackage)
    {
        // given the composer data, populate the php and other relevant values
        $name = $survosPackage->getName();
        $survosPackage->setSymfonyVersions([]);
        $survosPackage->setPhpVersions([]);
        $data = $survosPackage->getData();
        if (!$data) {
            return;
        }
        $distribution = []; // for tracking bundle counts
        $survosPackage->setKeywords($data['keywords'] ?? []); // could also get this from the json directly!

        if ($data['abandoned'] || (count($data['require'] ?? []) == 0)) {
            $survosPackage->setMarking(SurvosPackage::PLACE_ABANDONED);
            return;
        }

        foreach ($data['requireDev'] ?? [] as $dependency => $version) {
            switch ($dependency) {
                case 'phpunit/phpunit':
                    $survosPackage->setPhpUnitVersion($version);
                    try {
                        $constraint = $this->parser->parse($version);
                    } catch (\Exception $exception) {
                        $this->logger->warning(sprintf("%s %s\n%s\n%s",
                            $dependency, $version,
                            $this->getPackagistUrl($name),
                            $exception->getMessage()));
                        break;
//                                    dd($dependency, $version, $exception);
                    }

                    foreach (['6.0', '7.0','8.0', '9.5','10.1','11.1'] as $versionConstraint) {
                        if ($constraint->complies(new Version($versionConstraint))) {
                            $this->logger->warning("$dependency $version");
                            $survosPackage->addPhpUnitVersion($versionConstraint);
                        }
                    }
                    break;
            }
        }

        if (0)
            foreach ($data['require'] ?? [] as $dependency => $version) {
                switch ($dependency) {
                    case 'php':
                        $survosPackage->setPhpVersionString($version);
                        break;
                    default:
                        if (str_contains($dependency, 'phpunit/')) {
                            $survosPackage->setPhpVersionString($version);
                        }
                        if (str_contains($dependency, 'symfony/') &&
                            !u($dependency)->startsWith('symfony/ux') &&
                            !in_array($dependency, ['symfony/flex'])) {
                            [$vendor, $shortName] = explode('/', $dependency);
                            if ($vendor == 'symfony') {
                                if (!array_key_exists($dependency, $distribution)) {
                                    $distribution[$dependency]=0;
                                }
                                $distribution[$dependency]++;
                                if (u($version)->endsWith('^7')) {
                                    $version .= ".0";
                                }
                                // too many false positives with "*" or ">2.0".
                                if (!preg_match("/\d/", $version)) {
                                    $okay = false;
                                    break;
                                }
                                try {
                                    $constraint = $this->parser->parse($version);
                                } catch (\Exception $exception) {
                                    $this->logger->warning(sprintf("%s %s\n%s\n%s",
                                        $dependency, $version,
                                        $this->getPackagistUrl($name),
                                        $exception->getMessage()));
                                    break;
//                                    dd($dependency, $version, $exception);
                                }
                                foreach (['5.4', '6.4', '7.1'] as $x) {
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


    #[AsTwigFunction] // will be available as "fnValidPhpVersions" in twig
    public function validPhpVersions(Package $survosPackage): array
    {
        $results = [];
        $okay = false; // unless we have a valid php version
        $survosPackage->setPhpVersions([]);

        $allowed = ['8.1','8.2','8.3'];
        foreach ($allowed as $value) {
            $phpVersions[$value] = new Version($value);
        }

        if (!$survosPackage->getPhpVersionString()) {
            return $results;
        }

        $versionString = $survosPackage->getPhpVersionString();
        $versionString = str_replace('>=', '^', $versionString);
        $versionString = str_replace(' ', '', $versionString);
        if (preg_match('/^\^\d$/', $versionString, $m)) {
            $versionString .= '.0';
        }

        try {
            $constraint = $this->parser->parse($versionString);
        } catch (\Exception $exception) {
            $this->logger->error($survosPackage->getPhpVersionString() . " ($versionString) " . $exception->getMessage());
            return [];
//            dd($exception, $survosPackage->getPhpVersionString());
        }
        foreach ($allowed as $value) {
            if ($complies = $constraint->complies($phpVersion = $phpVersions[$value])) {
                $results[] = $value;
            }
        }
        return $results;


    }

    private function getPackagistUrl($name): string
    {
        return sprintf("https://packagist.org/packages/$name");
    }

    /**
     * @param Client $client
     * @param string $name
     * @param bool $persist
     * @param SerializerInterface $serializer
     * @return void
     */
    public function addPackage(SurvosPackage $survosPackage): void
    {
        // @todo: cache
        try {
            $composer = $this->client->getComposer($survosPackage->getName());
            assert($composer);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage() . "\n" . $survosPackage->getName());
            $survosPackage->setMarking(Package::PLACE_NOT_FOUND);
            return;
        }
        assert(count($composer) == 1, "multiple packages: " . join("\n", array_keys($composer)));

        /**
         * @var string $packageName
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
                    ->setMarking($survosPackage::PLACE_COMPOSER_LOADED)
                    ->setVersion($versionCode)
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
