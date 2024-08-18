<?php

namespace App\Workflow;

use App\Entity\Package;
use App\Service\PackageService;
use Packagist\Api\Result\Package as PackagistPackage;
use App\Message\ProcessPackage;
use Packagist\Api\Client;
use Psr\Log\LoggerInterface;
use Survos\WorkflowBundle\Attribute\Transition;
use Survos\WorkflowBundle\Attribute\Workflow;
use Symfony\Component\HttpKernel\Attribute\Cache;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Workflow\Attribute\AsGuardListener;
use Symfony\Component\Workflow\Attribute\AsTransitionListener;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Event\TransitionEvent;

#[Workflow(supports: [Package::class], name: self::WORKFLOW_NAME)]
final class BundleWorkflow implements BundleWorkflowInterface
{
    public function __construct(
        private MessageBusInterface   $bus,
        private UrlGeneratorInterface $urlGenerator,
        private SerializerInterface $serializer,
        private LoggerInterface $logger,
        private PackageService $packageService,
        private Client $packagistClient
    )
    {

    }

    // This name is used for injecting the workflow into a controller!
    const WORKFLOW_NAME = 'BundleWorkflow';

    #[AsGuardListener(self::WORKFLOW_NAME)]
    public function onGuard(GuardEvent $event): void
    {
        $composer = $this->getComposer($package = $this->getPackage($event));
        $transition = $event->getTransition();
        $transitionName = $event->getTransition()->getName();
//        if (empty($composer)) {
//        }
//        $package = $this->getPackage($event);
//        dd($composer, $package);
        if (empty($composer)) {
            switch ($transitionName) {
                case self::TRANSITION_ABANDON:
                case self::TRANSITION_LOAD:
                    // okay
                    break;
                default:
                    $event->setBlocked(true, 'Composer data is not yet loaded.');
            }
        }

        $validPhpVersion = count($this->packageService->validPhpVersions($package));
        switch ($event->getTransition()->getName()) {
            case self::TRANSITION_PHP_TOO_OLD:
                if ($validPhpVersion) {
                    $event->setBlocked(true, 'Valid PHP versions.');
                }
                break;
            case self::TRANSITION_PHP_OKAY:
                if (!$validPhpVersion) {
                    $event->setBlocked(true, 'No Valid PHP versions.');
                }
        }
    }

    private function getPackage(TransitionEvent|GuardEvent $event): Package
    {
        /** @var Package */
        return $event->getSubject();
    }

    private function getComposer(Package $package): ?array
    {
        return $package->getData();
    }

    #[AsTransitionListener(self::WORKFLOW_NAME, self::TRANSITION_LOAD)]
    public function onLoadComposer(TransitionEvent $event): void
    {
        $package = $this->getPackage($event);
        // @todo: check updatedAt
        if (true || !$data = $package->getData()) {
            $this->loadLatestVersionData($package);
        }
        $this->packageService->populateFromComposerData($package);
//        dd($package);
    }

//    #[Cache('1 day')]
    private function loadLatestVersionData(Package $package)
    {
        $packageName = $package->getName();
        try {
            $composer = $this->packagistClient->getComposer($packageName);
        } catch (\Exception $exception) {
            $this->logger->error($exception->getMessage() . "\n" . $packageName);
            throw $exception;
            dd($exception);
        }

        /**
         * @var string $packageName
         * @var \Packagist\Api\Result\Package $package
         */
        foreach ($composer as $packageName => $packagistPackage) {

            //            dd($packageName, $package);
        /** @var PackagistPackage\Version $version */
        foreach ($packagistPackage->getVersions() as $versionCode => $version) {
            // need a different API call for github stars.
//                if ($package->getFavers() || $package->getGithubStars()) {
//                    dd($package->getFavers(), $package);
//                }
//                dd($composer, $package);
//                $package->getDescription(); //
//                assert($package->getDescription() == $version->getDescription(), $package->getDescription() . '<>' . $version->getDescription());
            $json = $this->serializer->serialize($version, 'json');
            $package
                ->setStars($packagistPackage->getFavers())
                ->setVersion($versionCode)
                ->setDescription($version->getDescription())
                ->setData(json_decode($json, true));
            break; // we're getting the first one only, most recent.  hackish
        }
        }

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

    #[AsMessageHandler]
    public function processPackageMessageHandler(ProcessPackage $message): void
    {
        $package = $this->getPackage($message);
        // do something with your message
    }

    public function processPackage(Package $package)
    {


    }



}
