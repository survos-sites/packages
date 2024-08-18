<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Package;
use App\Entity\Package as SurvosPackage;
use PharIo\Version\Version;
use PharIo\Version\VersionConstraintParser;
use Psr\Log\LoggerInterface;
use Zenstruck\Twig\AsTwigFunction;
use function Symfony\Component\String\u;

class PackageService
{
    public function __construct(
        private readonly LoggerInterface $logger
    )
    {
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
        {
            foreach ($data['require'] ?? [] as $dependency => $version) {
                switch ($dependency) {
                    case 'php':
                        $survosPackage->setPhpVersionString($version);
                        break;
                    default:
                        if (str_contains($dependency, 'symfony/') &&
                            !u($dependency)->startsWith('symfony/ux') &&
                            !in_array($dependency, ['symfony/flex'])) {
                            [$vendor, $shortName] = explode('/', $dependency);
                            if ($vendor == 'symfony') {
                                if (!array_key_exists($dependency, $distribution)) {
                                    $distribution[$dependency]=0;
                                }
                                $distribution[$dependency]++;
                                // too many false positives with "*" or ">2.0".
                                if (!preg_match("/\d/", $version)) {
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


    }


    #[AsTwigFunction] // will be available as "someMethod" in twig
    public function validPhpVersions(Package $survosPackage): array
    {
        $results = [];
        $okay = false; // unless we have a valid php version
        $survosPackage->setPhpVersions([]);
        $parser = new VersionConstraintParser();

        $allowed = ['8.1','8.2','8.3'];
        foreach ($allowed as $value) {
            $phpVersions[$value] = new Version($value);
        }

        if (!$survosPackage->getPhpVersionString()) {
            return $results;
        }

        $versionString = $survosPackage->getPhpVersionString();
        $versionString = str_replace('>=', '^', $versionString);

        try {
            $constraint = $parser->parse($versionString);
        } catch (\Exception $exception) {
            dd($exception, $survosPackage->getPhpVersionString());
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

}
