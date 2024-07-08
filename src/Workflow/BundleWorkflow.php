<?php

namespace App\Workflow;

use App\Entity\Package;
use App\Entity\Package as SurvosPackage;
use Packagist\Api\Client;
use Survos\WorkflowBundle\Attribute\Workflow;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;

#[Workflow(supports: ['stdClass', Package::class], name: self::WORKFLOW_NAME)]
final class BundleWorkflow implements BundleWorkflowInterface
{
    public function __construct(
        private MessageBusInterface       $bus,
        private UrlGeneratorInterface $urlGenerator
    )
    {

    }
    // This name is used for injecting the workflow into a controller!
    const WORKFLOW_NAME='BundleWorkflow';

    #[AsGuardListener(self::WORKFLOW_NAME, transition: self::TRANSITION_LOAD)]
    public function onGuard(GuardEvent $event): void
    {
        // admin or user owns the submission
        // if the file no longer exists, we can't approve it.
//        dd($event, __);
    }

    private function getPackage(TransitionEvent|GuardEvent $event): Package
    {
        /** @var Package */ return $event->getSubject();
    }
    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_VALID)]
    public function onSubmitPhoto(TransitionEvent $event): void
    {

    }
    #[AsTransitionListener(self::WORKFLOW_NAME)]
    public function onTransition(TransitionEvent $event): void
    {
        switch ($event->getTransition()->getName()) {
            case self::TRANSITION_PHP_TOO_OLD:

                break;
        }
//        dd($event, $event->getTransition(), $event->getSubject());
        // ...
    }

    private function loadComposer(Client $client, SurvosPackage $survosPackage): void
    {
        // @todo: cache
        try {
            $composer = $client->getComposer($survosPackage->getName());
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage() . "\n" .$survosPackage->getName());
            return;
        }
        assert(count($composer) == 1, "multiple packages: " . join("\n", array_keys($composer)));


    }
