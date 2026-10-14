<?php

declare(strict_types=1);

// uses Survos Param Converter, from the UniqueIdentifiers method of the entity.

namespace App\Controller;

use App\Workflow\PackagePlace;

use App\Entity\Package;
use App\Schema\PackageSchema;
use App\Workflow\PackageWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use League\CommonMark\CommonMarkConverter;
use Nadar\PhpComposerReader\ComposerReader;
use Survos\WorkflowExtrasBundle\Contract\TransitionExecutorInterface;
use Survos\WorkflowExtrasBundle\Service\NextTransitionRunner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/package/{packageId}')]
class PackageController extends AbstractController
{


    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TransitionExecutorInterface $executor,
        private readonly NextTransitionRunner $next,
    ) {
    }

    #[Route('/transition/{transition}', name: 'package_transition', options: ['expose' => true], methods: [Request::METHOD_POST])]
    #[Route('/', name: 'package_show', options: ['expose' => true], methods: [Request::METHOD_GET])]
    public function show(
        string $packageId,
//        Package $package,
        Request $request,
        PackageSchema $packageSchema,
        #[Target(PackageWorkflow::WORKFLOW_NAME)] WorkflowInterface $workflow,
        ?string $transition = null,
    ): Response {
        $package = $this->entityManager->getRepository(Package::class)->find($packageId);
        assert($package instanceof Package, "wrong id $packageId");
        if ($transition !== null) {
            $this->denyAccessUnlessGranted('ROLE_ADMIN');
            if (!$this->isCsrfTokenValid('package_transition_'.$package->id, $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Invalid transition token.');
            }
            if ($transition === '_reset') {
                $package->marking = PackagePlace::Discovered;
                $this->entityManager->flush();
                $this->next->continue($workflow, $package);
            } else {
                $transition = str_ends_with($transition, '_async') ? substr($transition, 0, -6) : $transition;
                $this->executor->execute($workflow, $package, $transition);
            }
            $flashMessage = $transition.' requested.';
            // this could be done in a .leave listener too.
            $this->addFlash('info', $flashMessage);
            $this->entityManager->flush(); // to save the marking

            return $this->redirectToRoute('package_show', $package->getRP());
        }

        if ($package->data) {
            $reader = new ComposerReader($package->data);
        }

        // SoftwareSourceCode + WebPage + authors/vendor; base.html.twig renders it.
        $packageSchema->addPackage($package, $request->getSchemeAndHttpHost());

        //        dd($composer);
        return $this->render('package/show.html.twig', [
            'package' => $package,
            'composer' => $package->data,
        ]);
    }

    /** The bundle's AGENTS.md as an HTML fragment, for the "A" dialog on search results. */
    #[Route('/agents', name: 'package_agents', options: ['expose' => true], methods: [Request::METHOD_GET])]
    public function agents(string $packageId): Response
    {
        $package = $this->entityManager->getRepository(Package::class)->find($packageId);
        if (!$package instanceof Package || $package->agentsMd === null) {
            throw $this->createNotFoundException(sprintf('No AGENTS.md for %s', $packageId));
        }

        // Third-party Markdown: escape any raw HTML and drop javascript:/data: links.
        $converter = new CommonMarkConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]);

        return new Response((string) $converter->convert($package->agentsMd), headers: [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
