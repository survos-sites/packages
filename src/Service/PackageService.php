<?php

declare(strict_types=1);

namespace App\Service;

use App\Workflow\PackagePlace;

use Adbar\Dot;
use App\Entity\Package;
use App\Entity\Package as SurvosPackage;
use App\Workflow\PackageWorkflow;
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
        $package->requirementsFailureReason = null;
        $package->phpVersionString = null;
        $package->symfonyVersionString = null;
        $package->phpUnitVersionString = null;
        $package->phpVersions = [];
        $package->symfonyVersions = [];
        $package->phpUnitVersions = [];
        $data = new Dot($package->data ?? []);
        $package->sourceUrl = $data->get('source.url');
        $package->sourceType = $data->get('source.type');

        if ($constraint = $data->get('require.php')) {
            $package->phpVersionString = $constraint;
            $package->phpVersions = $this->constraintComplies($constraint, ['8.3', '8.4', '8.5']);
        }

        // Check declared core constraints for libraries and bundles alike.
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

        $package->requirementsFailureReason = match (true) {
            $package->phpCompatibility === 'incompatible' => 'php_incompatible',
            $package->symfonyCompatibility === 'incompatible' => 'symfony_incompatible',
            default => null,
        };

        if ($constraint = $data->get('requireDev.phpunit/phpunit')) {
            $package->phpUnitVersionString = $constraint;
            $package->phpUnitVersions = $this->constraintComplies($constraint, ['9.4', '10.3', '11.4', '12.0'], 'phpunit/phpunit');
        }
    }

    private function getPackagistUrl($name): string
    {
        return sprintf("https://packagist.org/packages/$name");
    }

}
