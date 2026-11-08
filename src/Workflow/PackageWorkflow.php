<?php

declare(strict_types=1);

namespace App\Workflow;

use App\Entity\Package;
use App\Service\PackageDocsFetcher;
use App\Service\CanonicalProbe;
use App\Service\PackageEmbeddingService;
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

#[AsWorkflow(supports: [Package::class], name: self::WORKFLOW_NAME, type: WorkflowType::StateMachine, markingProperty: 'state', places: PackagePlace::class, description: 'Load metadata, accept or reject the package, then fetch documentation for accepted bundles')]
final class PackageWorkflow
{
    // This name is used for injecting the workflow into a controller!
    public const WORKFLOW_NAME = 'PackageWorkflow';

    #[AsyncTransition(PackagePlace::Discovered, PackagePlace::MetadataLoaded, 'Load Packagist metadata', async: true, transport: 'bundle.load')]
    #[AsyncTransition(PackagePlace::Accepted, PackagePlace::MetadataLoaded, 'Refresh an accepted package', async: true, transport: 'bundle.load')]
    #[AsyncTransition(PackagePlace::Rejected, PackagePlace::MetadataLoaded, 'Recheck a rejected package', async: true, transport: 'bundle.load')]
    #[AsyncTransition(PackagePlace::Documented, PackagePlace::MetadataLoaded, 'Refresh metadata and documentation', async: true, transport: 'bundle.load')]
    public const TRANSITION_LOAD = 'load';

    public const array LOAD_TRANSITIONS = [self::TRANSITION_LOAD];

    #[Transition(PackagePlace::MetadataLoaded, PackagePlace::Accepted,
        'Accept supported PHP and Symfony criteria, then discover dependencies',
        guard: 'subject.rejectionReason === null && subject.requirementsCompatible === true')]
    public const TRANSITION_ACCEPT = 'accept';

    #[Transition(PackagePlace::MetadataLoaded, PackagePlace::Rejected,
        'Reject unavailable metadata or unsupported PHP/Symfony criteria; retain the reason',
        guard: 'subject.rejectionReason !== null || subject.requirementsCompatible === false')]
    public const TRANSITION_REJECT = 'reject';

    #[Transition(PackagePlace::Accepted, PackagePlace::Rejected,
        'Reject a confirmed unavailable canonical repository', guard: 'subject.canonicalUnavailable')]
    #[Transition(PackagePlace::Documented, PackagePlace::Rejected,
        'Reject a previously documented repository confirmed unavailable on recheck', guard: 'subject.canonicalUnavailable')]
    public const TRANSITION_CANONICAL_FAILED = 'canonical_failed';

    #[AsyncTransition(PackagePlace::Accepted, PackagePlace::Documented,
        'Fetch README and AGENTS for an accepted package',
        guard: "subject.canonicalAvailable", async: true, transport: 'bundle.fetch.docs')]
    public const TRANSITION_FETCH_DOCS = 'fetch_docs';

    #[Transition(PackagePlace::Documented, PackagePlace::Rejected,
        'Reject a completed documentation check with no README',
        guard: "subject.readmeStatus == 'missing' && !subject.hasReadme")]
    public const TRANSITION_README_MISSING = 'readme_missing';

    #[AsyncTransition(PackagePlace::Documented, PackagePlace::Documented,
        'Persist an embedding of the README', guard: 'subject.hasReadme && subject.embedding === null',
        async: true, transport: 'bundle.embed')]
    public const TRANSITION_EMBED_README = 'embed_readme';

    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly PackageService $packageService,
        private readonly RequiredPackageDiscovery $requiredPackageDiscovery,
        private readonly Client $packagistClient,
        private readonly PackageDocsFetcher $docsFetcher,
        private readonly CanonicalProbe $canonicalProbe,
        private readonly PackageEmbeddingService $embeddings,
    ) {}

    #[AsCompletedListener(self::WORKFLOW_NAME, self::TRANSITION_ACCEPT)]
    public function onAcceptedCompleted(CompletedEvent $event): void
    {
        if ($event->getSubject()->canonicalAvailable) {
            $this->requiredPackageDiscovery->discover($event->getSubject());
        }
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_ACCEPT)]
    public function onAccept(TransitionEvent $event): void
    {
        // The probe is the last acceptance step. Ordered next guards use its result.
        $package = $event->getSubject();
        $this->canonicalProbe->probe($package);
        if (($package->canonicalProbe['outcome'] ?? null) === 'inconclusive') {
            // Keep live HTTP responses out of parallel Messenger exception payloads.
            throw new \RuntimeException(sprintf('Canonical probe inconclusive for %s (HTTP %s)', $package->name, $package->canonicalProbe['status'] ?? 'unknown'));
        }
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_CANONICAL_FAILED)]
    public function onCanonicalFailed(TransitionEvent $event): void
    {
        $event->getSubject()->rejectionReason = 'canonical_unavailable';
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_FETCH_DOCS)]
    public function onFetchDocs(TransitionEvent $event): void
    {
        $package = $event->getSubject();
        $this->docsFetcher->fetch($package);
        if (!$this->embeddings->isCurrent($package)) {
            $package->embedding = null;
            $package->embeddingFingerprint = null;
        }
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_README_MISSING)]
    public function onReadmeMissing(TransitionEvent $event): void
    {
        $package = $event->getSubject();
        $package->rejectionReason = 'readme_missing';
        $package->embedding = null;
        $package->embeddingFingerprint = null;
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_EMBED_README)]
    public function onEmbedReadme(TransitionEvent $event): void
    {
        try {
            $this->embeddings->vectorize($event->getSubject());
        } catch (\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $error) {
            throw new \RuntimeException($error->getMessage(), (int) $error->getCode());
        }
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_LOAD)]
    public function onLoadComposer(TransitionEvent $event): void
    {
        $package = $event->getSubject();
        // `load` always loads: whether a package needs reloading is decided before the
        // transition is dispatched (https://packagist.org/apidoc#track-package-updates),
        // not here. Repeat fetches are absorbed by the caching packagist.client.
        $package->rejectionReason = null;
        if ($this->loadLatestVersionData($package)) {
            $this->packageService->populateFromComposerData($package);
        }
    }

    private function loadLatestVersionData(Package $package): bool
    {
        // One detailed request provides everything we need. Let HTTP failures reach
        // Messenger so retries and the Doctrine failure transport can handle them.
        try {
            $result = $this->packagistClient->get($package->name);
        } catch (\Packagist\Api\PackageNotFoundException) {
            $package->rejectionReason = 'package_not_found';

            return false;
        } catch (\Psr\Http\Client\ClientExceptionInterface|\Symfony\Contracts\HttpClient\Exception\ExceptionInterface $error) {
            // Do not send a live HTTP response through the parallel worker channel.
            throw new \RuntimeException($error->getMessage(), (int) $error->getCode());
        }
        $package->canonicalUrl = $result->getRepository() ?: null;
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
            $package->rejectionReason = 'no_versions';

            return false;
        }

        $package->version = $selected->getVersion();
        $package->setLastUpdatedOnPackagist($selected->getTime());
        $package->lastUpdated = new \DateTimeImmutable();
        $package->stars = $result->getFavers();
        $package->downloads = $result->getDownloads()?->getTotal();
        $package->description = $selected->getDescription();
        $package->data = json_decode($this->serializer->serialize($selected, 'json'), true, flags: JSON_THROW_ON_ERROR);

        return true;
    }

}
