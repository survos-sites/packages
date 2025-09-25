<?php

// uses Survos Param Converter, from the UniqueIdentifiers method of the entity.

namespace App\Controller;

use App\Entity\Package;
use App\Workflow\BundleWorkflow;
use App\Workflow\BundleWorkflowInterface;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Nadar\PhpComposerReader\ComposerReader;
use Survos\StateBundle\Controller\HandleTransitionsInterface;
use Survos\StateBundle\Traits\HandleTransitionsTrait;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/package/{packageId}')]
class PackageController extends AbstractController implements HandleTransitionsInterface
{
    use HandleTransitionsTrait;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/', name: 'package_show', options: ['expose' => true], methods: [Request::METHOD_GET])]
    #[Route('/transition/{transition}', name: 'package_transition', options: ['expose' => true], methods: [Request::METHOD_GET])]
    #[AdminRoute('/bundle/{packageId}', name: 'bundle_show')]
    public function show(
        string $packageId,
//        Package $package,
        #[Target(BundleWorkflowInterface::WORKFLOW_NAME)] ?WorkflowInterface $workflow = null,
        ?string $transition = null,
    ): Response {
        $package = $this->entityManager->getRepository(Package::class)->find($packageId);
        assert($package instanceof Package, "wrong id $packageId");
        if ($flashMessage = $this->handleTransitionButtons($workflow, $transition, $package)) {
            // this could be done in a .leave listener too.
            $this->addFlash('info', $flashMessage);
            $this->entityManager->flush(); // to save the marking

            return $this->redirectToRoute('package_show', $package->getRP());
        }

        if ($package->data) {
            $reader = new ComposerReader($package->data);
        }

        //        dd($composer);
        return $this->render('package/show.html.twig', [
            'package' => $package,
            'composer' => $package->data,
        ]);
    }
}
