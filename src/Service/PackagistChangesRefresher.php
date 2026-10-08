<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FeedCursor;
use App\Entity\Package;
use App\Repository\PackageRepository;
use App\Workflow\BundleWorkflowInterface as WF;
use Doctrine\ORM\EntityManagerInterface;
use Survos\StateBundle\Message\TransitionMessage;
use Survos\StateBundle\Service\AsyncQueueLocator;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\MessageBusInterface;
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
        private HttpClientInterface $packagistClient,
        private EntityManagerInterface $entityManager,
        private PackageRepository $packages,
        private MessageBusInterface $bus,
        private AsyncQueueLocator $asyncQueueLocator,
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
                if ($package->marking === WF::PLACE_NEW || !$this->workflow->can($package, WF::TRANSITION_LOAD)) {
                    // Mid-flight: `new` already has its kickoff load queued, and composer_loaded /
                    // php_ok are inside a chain that started from fresh data.
                    $result->skipped[] = $name;
                    continue;
                }
                $message = new TransitionMessage($package->id, Package::class, WF::TRANSITION_LOAD, WF::WORKFLOW_NAME);
                $this->bus->dispatch($message, $this->asyncQueueLocator->stamps($message));
                $result->queued[] = $name;
            }
            $cursor->advance($data['timestamp']);
        }
        $this->entityManager->flush();

        return $result;
    }
}
