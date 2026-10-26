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
use Survos\WorkflowMessengerBundle\Attribute\Transition as AsyncTransition;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsCompletedListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\WorkflowType;

#[AsWorkflow(supports: [Package::class], name: self::WORKFLOW_NAME, type: WorkflowType::Workflow, markingProperty: 'marking', places: PackagePlace::class, description: 'Load package metadata, then check requirements and fetch documentation as independent tracks')]
final class PackageWorkflow
{
    // This name is used for injecting the workflow into a controller!
    public const WORKFLOW_NAME = 'PackageWorkflow';

    /** Docs are fetched only for compatible Symfony bundles. */
    private const string DOCS_WANTED = "subject.type == 'symfony-bundle' && subject.requirementsCompatible === true";

    #[AsyncTransition(PackagePlace::Discovered, [PackagePlace::MetadataLoaded, PackagePlace::RequirementsPending, PackagePlace::DocsPending],
        'Fetch package metadata from Packagist and start the requirements and documentation tracks',
        icon: 'tabler:download', iconClass: 'text-primary', async: true, transport: 'bundle.load')]
    public const TRANSITION_LOAD = 'load';

    // A refresh clears the derived tracks and forks again. Symfony requires transition names to be
    // unique per place, so there is one reload per settled combination; they are mutually exclusive.
    #[AsyncTransition([PackagePlace::MetadataLoaded, PackagePlace::RequirementsCompatible, PackagePlace::Documented],
        [PackagePlace::MetadataLoaded, PackagePlace::RequirementsPending, PackagePlace::DocsPending],
        'Refresh metadata; recheck requirements and re-fetch documentation',
        icon: 'tabler:refresh', iconClass: 'text-primary', async: true, transport: 'bundle.load')]
    public const TRANSITION_RELOAD_DOCUMENTED = 'reload_documented';

    #[AsyncTransition([PackagePlace::MetadataLoaded, PackagePlace::RequirementsCompatible, PackagePlace::DocsSkipped],
        [PackagePlace::MetadataLoaded, PackagePlace::RequirementsPending, PackagePlace::DocsPending],
        'Refresh metadata; recheck requirements (no documentation for this package)',
        icon: 'tabler:refresh', iconClass: 'text-primary', async: true, transport: 'bundle.load')]
    public const TRANSITION_RELOAD_COMPATIBLE = 'reload_compatible';

    #[AsyncTransition([PackagePlace::MetadataLoaded, PackagePlace::RequirementsUnsupported, PackagePlace::DocsSkipped],
        [PackagePlace::MetadataLoaded, PackagePlace::RequirementsPending, PackagePlace::DocsPending],
        'Refresh metadata; recheck requirements',
        icon: 'tabler:refresh', iconClass: 'text-primary', async: true, transport: 'bundle.load')]
    public const TRANSITION_RELOAD_UNSUPPORTED = 'reload_unsupported';

    /** Every way to (re)load; at most one is enabled for a package at a time. */
    public const array LOAD_TRANSITIONS = [
        self::TRANSITION_LOAD,
        self::TRANSITION_RELOAD_DOCUMENTED,
        self::TRANSITION_RELOAD_COMPATIBLE,
        self::TRANSITION_RELOAD_UNSUPPORTED,
    ];

    #[Transition(PackagePlace::RequirementsPending, PackagePlace::RequirementsCompatible,
        'Accept compatible PHP and applicable Symfony core constraints; absent constraints need no check',
        icon: 'tabler:circle-check', iconClass: 'text-success', guard: 'subject.requirementsCompatible === true')]
    public const TRANSITION_ACCEPT_REQUIREMENTS = 'accept_requirements';

    #[Transition(PackagePlace::RequirementsPending, PackagePlace::RequirementsUnsupported,
        'A declared PHP or Symfony core constraint is incompatible',
        icon: 'tabler:circle-x', iconClass: 'text-danger', guard: 'subject.requirementsCompatible === false')]
    public const TRANSITION_REJECT_REQUIREMENTS = 'reject_requirements';

    #[AsyncTransition(PackagePlace::DocsPending, PackagePlace::Documented,
        'Fetch README.md and AGENTS.md for compatible Symfony bundles',
        guard: self::DOCS_WANTED,
        icon: 'tabler:book-download', iconClass: 'text-info', async: true, transport: 'bundle.fetch.docs')]
    public const TRANSITION_FETCH_DOCS = 'fetch_docs';

    #[Transition(PackagePlace::DocsPending, PackagePlace::DocsSkipped,
        'Not a compatible Symfony bundle; there is no documentation to fetch',
        icon: 'tabler:book-off', iconClass: 'text-muted', guard: '!('.self::DOCS_WANTED.')')]
    public const TRANSITION_SKIP_DOCS = 'skip_docs';

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
    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_RELOAD_DOCUMENTED)]
    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_RELOAD_COMPATIBLE)]
    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_RELOAD_UNSUPPORTED)]
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
