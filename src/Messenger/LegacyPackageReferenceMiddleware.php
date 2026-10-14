<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Entity\Package;
use Survos\WorkflowMessengerBundle\Message\SubjectReference;
use Survos\WorkflowMessengerBundle\Message\TransitionRequest;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

/**
 * Upgrades transition requests queued before the move to DoctrineSubjectStore, whose
 * subject type was 'package' rather than the entity class. Rewriting the message
 * before the handler keeps its per-subject lock on the same resource as new requests,
 * and a retried message is re-sent in the new form.
 *
 * Remove once no queue (including `failed`) holds a request with type 'package'.
 */
final class LegacyPackageReferenceMiddleware implements MiddlewareInterface
{
    private const LEGACY_TYPE = 'package';

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if ($message instanceof TransitionRequest && self::LEGACY_TYPE === $message->subject->type) {
            $upgraded = new TransitionRequest(
                $message->workflow,
                new SubjectReference(Package::class, $message->subject->id),
                $message->transition,
                $message->context,
                $message->requestId,
                $message->version,
            );
            $envelope = new Envelope($upgraded, array_merge(...array_values($envelope->all())));
        }

        return $stack->next()->handle($envelope, $stack);
    }
}
