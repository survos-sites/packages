<?php

namespace App\MessageHandler;

use App\Entity\Package as SurvosPackage;
use App\Message\ProcessPackage;
use App\Repository\PackageRepository;
use App\Workflow\BundleWorkflow;
use App\Workflow\BundleWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;


final class ProcessPackageHandler implements BundleWorkflowInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PackageRepository $packageRepository,
        private readonly LoggerInterface $logger,
        #[Target(BundleWorkflow::WORKFLOW_NAME)] private WorkflowInterface $workflow
    )
    {

    }
    #[AsMessageHandler]
    public function __invoke(ProcessPackage $message): void
    {
        $package = $this->packageRepository->findOneBy(['name' => $message->packageName]);
        assert($package);

        // it's okay if we fail, or we can check ->can()
        try {
            $this->workflow->apply($package, self::TRANSITION_LOAD);
        } catch (\Exception $e) {
            $this->logger->info($e->getMessage());
        }

        $composer = $package->getData();


        // note: we are handling abandoned earlier
        $transitions = [
            BundleWorkflow::TRANSITION_LOAD,
            BundleWorkflow::TRANSITION_PHP_TOO_OLD,
            BundleWorkflow::TRANSITION_OUTDATED,
            BundleWorkflow::TRANSITION_VALID,
        ];

        foreach ($transitions as $transition) {

        }

        if (!in_array($survosPackage->getMarking(),
            [SurvosPackage::PLACE_OUTDATED_PHP,
                SurvosPackage::PLACE_ABANDONED])) {
            if (count($survosPackage->getSymfonyVersions()) == 0) {
                $this->logger->info("outdated " . $this->getPackagistUrl($name));
                $survosPackage->setMarking(SurvosPackage::PLACE_OUTDATED);
            } else {
                $survosPackage->setMarking(SurvosPackage::PLACE_VALID_REQUIREMENTS);
            }
        }


        // do something with your message
    }
}
