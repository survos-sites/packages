<?php

declare(strict_types=1);

namespace App\Service;

use Adbar\Dot;
use App\Entity\Package;
use App\Entity\Package as SurvosPackage;
use App\Workflow\BundleWorkflowInterface;
use Composer\Semver\VersionParser;
use Composer\Semver\Intervals;
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
        $parser = new VersionParser();
        try {
            $constraint = $parser->parseConstraints($versionConstraintString);
        } catch (\Exception $exception) {
            $this->logger->error($versionConstraintString . "\n\n" . $exception->getMessage());
//            In VersionParser.php line 526:
//
//  [UnexpectedValueException]
//  Could not parse version constraint self.version: Invalid version string "self.version"
            return []; // for now.


        }
        $matches = [];
        foreach ($versions as $version) {
            [$major, $minor] = array_map('intval', explode('.', $version));
            $actualVersionConstraint = $parser->parseConstraints(sprintf('>=%d.%d.0 <%d.%d.0', $major, $minor, $major, $minor + 1));
            if (Intervals::haveIntersections($constraint, $actualVersionConstraint)) {
                $this->logger->info("$actualVersionConstraint matches $version!");
                $matches[] = $version;
            }
        }
        $this->logger->info("setting $dependency $versionConstraintString to ".join('||', $matches));
//        if ($dependency && str_contains($dependency, 'symfony')) dump($matches, $versionConstraintString, $versions);

        return $matches;
    }

    public function populateFromComposerData(Package $package): void
    {
        // Metadata extraction must never change the workflow marking.
        $package->phpVersionString = null;
        $package->symfonyVersionString = null;
        $package->phpUnitVersionString = null;
        $package->phpVersions = [];
        $package->symfonyVersions = [];
        $package->phpUnitVersions = [];
        $data = new Dot($package->data ?? []);
        $package->sourceUrl = $data->get('source.url');
        $package->sourceType = $data->get('source.type');
        if ($package->isAbandoned) {
            return;
        }

        if ($constraint = $data->get('require.php')) {
            $package->phpVersionString = $constraint;
            $package->phpVersions = $this->constraintComplies($constraint, ['8.3', '8.4', '8.5']);
        }

        if ($package->isSymfonyBundle) {
            // These dependencies share Symfony's release versions. Packages such
            // as contracts, polyfills, Flex and UX have independent versions.
            $requirements = [];
            $matches = ['8.0', '8.1', '8.2'];
            foreach (['runtime', 'config', 'http-kernel', 'dependency-injection',
                'framework-bundle', 'http-client', 'console', 'event-dispatcher',
                'routing', 'serializer', 'form', 'validator', 'workflow', 'messenger',
                'security-bundle', 'twig-bundle', 'cache', 'translation'] as $component) {
                $dependency = 'symfony/'.$component;
                if ($constraint = $data->get('require.'.$dependency)) {
                    $requirements[] = $constraint.' ('.$dependency.')';
                    $matches = array_values(array_intersect($matches,
                        $this->constraintComplies($constraint, ['8.0', '8.1', '8.2'], $dependency)));
                }
            }
            if ($requirements !== []) {
                $package->symfonyVersions = $matches;
                $package->symfonyVersionString = mb_strimwidth(implode('; ', $requirements), 0, 255, '…');
            }
        }

        if ($constraint = $data->get('requireDev.phpunit/phpunit')) {
            $package->phpUnitVersionString = $constraint;
            $package->phpUnitVersions = $this->constraintComplies($constraint, ['9.4', '10.3', '11.4', '12.0'], 'phpunit/phpunit');
        }
    }

    private function getPackagistUrl($name): string
    {
        return sprintf("https://packagist.org/packages/$name");
    }

    public function addPackage(SurvosPackage $survosPackage): void
    {
        // @todo: cache
        try {
            $this->logger->warning("Loading " . $survosPackage->name);
            $composer = $this->client->getComposer($survosPackage->name);
            assert($composer);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage()."\n".$survosPackage->name);
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

                $survosPackage->stars = $package->getFavers();
                $survosPackage->description = $version->getDescription();
                $survosPackage->data = json_decode($json, true);
                return;
                break; // we're getting the first one only, most recent.  hackish
            }
        }
    }
}
