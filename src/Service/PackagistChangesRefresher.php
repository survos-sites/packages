<?php

declare(strict_types=1);

namespace App\Service;

use App\Workflow\PackagePlace;

use App\Entity\FeedCursor;
use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Workflow\PackageWorkflow as WF;
use Doctrine\ORM\EntityManagerInterface;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reads https://packagist.org/apidoc#track-package-updates from the stored cursor and
 * queues `load` for every package we track that changed. This is where "does this
 * package need reloading?" is decided; the load transition itself always loads.
 */
final class PackagistChangesRefresher
{
    public function __construct(
        #[Target('packagist.client')] private HttpClientInterface $packagistClient,
        private EntityManagerInterface $entityManager,
        private PackageRepository $packages,
        private TransitionExecutorInterface $executor,
        #[Target(WF::WORKFLOW_NAME)] private WorkflowInterface $workflow,
    ) {
    }

    public function refresh(): PackagistChanges
    {
        $cursor = $this->entityManager->find(FeedCursor::class, FeedCursor::PACKAGIST);
        // Without `since` Packagist answers with an error naming the current timestamp.
        $data = $this->packagistClient->request('GET', '/metadata/changes.json', [
            'query' => $cursor ? ['since' => $cursor->position] : [],
        ])->toArray(false);
        if (!isset($data['timestamp'])) {
            throw new \UnexpectedValueException('Packagist changes feed returned no timestamp.');
        }

        $result = new PackagistChanges(initialized: $cursor === null);
        if ($cursor === null) {
            $this->entityManager->persist(new FeedCursor(FeedCursor::PACKAGIST, (string) $data['timestamp']));
        } else {
            if (isset($data['error'])) {
                // The cursor is older than Packagist keeps; changes in the gap are lost.
                $result->resync = true;
            }
            $updated = [];
            foreach ($data['actions'] ?? [] as $action) {
                match ($action['type'] ?? null) {
                    'update' => $updated[preg_replace('/~dev$/', '', $action['package'])] = true,
                    'delete' => $result->deleted[] = preg_replace('/~dev$/', '', $action['package']),
                    'resync' => $result->resync = true,
                    default => null,
                };
            }
            $result->changed = count($updated);
            foreach (array_keys($updated) as $name) {
                $package = $this->packages->find(Package::idFromName($name));
                if (!$package) {
                    continue;
                }
                $load = $package->isIn(PackagePlace::Discovered) ? null : $this->enabledLoad($package);
                if (null === $load) {
                    // Mid-flight: a new package already has its kickoff load queued, and a package
                    // with a pending track is inside a chain that started from fresh data.
                    $result->skipped[] = $name;
                    continue;
                }
                $this->executor->execute($this->workflow, $package, $load);
                $result->queued[] = $name;
            }
            $cursor->advance($data['timestamp']);
        }
        $this->entityManager->flush();

        return $result;
    }

    /** The reload that applies to the package's settled places, or null while any track is pending. */
    private function enabledLoad(Package $package): ?string
    {
        foreach (WF::LOAD_TRANSITIONS as $name) {
            if ($this->workflow->can($package, $name)) {
                return $name;
            }
        }

        return null;
    }
}
