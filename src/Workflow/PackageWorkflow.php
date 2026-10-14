<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use App\Service\PackageDocsFetcher;
use App\Service\PackageService;
use App\Service\RequiredPackageDiscovery;
use Packagist\Api\Client;
use Survos\WorkflowExtrasBundle\Attribute\AsWorkflow;
use Survos\WorkflowExtrasBundle\Attribute\Transition;
use Survos\WorkflowAsyncBundle\Attribute\Transition as AsyncTransition;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;

#[AsWorkflow(supports: [Package::class], name: self::WORKFLOW_NAME, places: PackagePlace::class, description: 'Load and validate package metadata, then fetch repository documentation')]
final class PackageWorkflow
{
    // This name is used for injecting the workflow into a controller!
    public const WORKFLOW_NAME = 'PackageWorkflow';

    #[AsyncTransition(PackagePlace::Discovered, PackagePlace::MetadataLoaded, 'Fetch package metadata from Packagist', async: true, transport: 'bundle.load')]
    #[AsyncTransition(PackagePlace::RequirementsCompatible, PackagePlace::MetadataLoaded, 'Fetch package metadata from Packagist', async: true, transport: 'bundle.load')]
    #[AsyncTransition(PackagePlace::RequirementsUnsupported, PackagePlace::MetadataLoaded, 'Fetch package metadata from Packagist', async: true, transport: 'bundle.load')]
    #[AsyncTransition(PackagePlace::Documented, PackagePlace::MetadataLoaded, 'Fetch package metadata from Packagist', async: true, transport: 'bundle.load')]
    public const TRANSITION_LOAD = 'load';

    #[Transition(PackagePlace::MetadataLoaded, PackagePlace::RequirementsCompatible,
        'Accept compatible PHP and applicable Symfony core constraints; absent constraints need no check',
        guard: 'subject.requirementsCompatible === true')]
    public const TRANSITION_ACCEPT_REQUIREMENTS = 'accept_requirements';

    #[Transition(PackagePlace::MetadataLoaded, PackagePlace::RequirementsUnsupported,
        'A declared PHP or Symfony core constraint is incompatible',
        guard: 'subject.requirementsCompatible === false')]
    public const TRANSITION_REJECT_REQUIREMENTS = 'reject_requirements';

    #[AsyncTransition(PackagePlace::RequirementsCompatible, PackagePlace::Documented,
        'Fetch README.md and AGENTS.md for Symfony bundles; dependencies stop at requirements compatible',
        guard: "subject.type == 'symfony-bundle'",
        async: true, transport: 'bundle.fetch.docs')]
    public const TRANSITION_FETCH_DOCS = 'fetch_docs';

    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly PackageService $packageService,
        private readonly RequiredPackageDiscovery $requiredPackageDiscovery,
        private readonly Client $packagistClient,
        private readonly PackageDocsFetcher $docsFetcher,
    ) {}

    #[AsCompletedListener(self::WORKFLOW_NAME, self::TRANSITION_ACCEPT_REQUIREMENTS)]
    public function onValidCompleted(CompletedEvent $event): void
    {
        $this->requiredPackageDiscovery->discover($event->getSubject());
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_FETCH_DOCS)]
    public function onFetchDocs(TransitionEvent $event): void
    {
        $this->docsFetcher->fetch($event->getSubject());
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_LOAD)]
    public function onLoadComposer(TransitionEvent $event): void
    {
        $package = $event->getSubject();
        // `load` always loads: whether a package needs reloading is decided before the
        // transition is dispatched (https://packagist.org/apidoc#track-package-updates),
        // not here. Repeat fetches are absorbed by the caching packagist.client.
        $this->loadLatestVersionData($package);
        $this->packageService->populateFromComposerData($package);
    }

    private function loadLatestVersionData(Package $package): void
    {
        // One detailed request provides everything we need. Let HTTP failures reach
        // Messenger so retries and the Doctrine failure transport can handle them.
        $result = $this->packagistClient->get($package->name);
        $package->abandoned = (bool) $result->isAbandoned();
        $package->replacement = $result->getReplacementPackage();
        $selected = null;
        $latestRelease = null;
        $latestBranch = null;
        foreach ($result->getVersions() as $version) {
            if ($version->getDefaultBranch()) {
                $selected = $version;
                break;
            }
            if (!str_contains(strtolower($version->getVersion()), 'dev')
                && ($latestRelease === null || version_compare($version->getVersion(), $latestRelease->getVersion(), '>'))) {
                $latestRelease = $version;
            }
            if ($latestBranch === null || $version->getTime() > $latestBranch->getTime()) {
                $latestBranch = $version;
            }
        }
        // Archived branches can disappear while published releases remain usable.
        $selected ??= $latestRelease ?? $latestBranch;
        if ($selected === null) {
            throw new \UnexpectedValueException(sprintf('No versions in Packagist metadata for %s.', $package->name));
        }

        $package->version = $selected->getVersion();
        $package->setLastUpdatedOnPackagist($selected->getTime());
        $package->lastUpdated = new \DateTimeImmutable();
        $package->stars = $result->getFavers();
        $package->downloads = $result->getDownloads()?->getTotal();
        $package->description = $selected->getDescription();
        $package->data = json_decode($this->serializer->serialize($selected, 'json'), true, flags: JSON_THROW_ON_ERROR);
    }

}
