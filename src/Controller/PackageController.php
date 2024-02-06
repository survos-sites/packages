<?php


// uses Survos Param Converter, from the UniqueIdentifiers method of the entity.

namespace App\Controller;

use App\Entity\Package;
use App\Form\PackageType;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\PackageRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Workflow\WorkflowInterface;

#[Route('/package/{packageId}')]
class PackageController extends AbstractController
{

    public function __construct(private EntityManagerInterface $entityManager)
    {

    }

    #[Route(path: '/transition/{transition}', name: 'package_transition')]
    public function transition(Request $request, WorkflowInterface $packageStateMachine, string $transition, Package $package): Response
    {
        if ($transition === '_') {
            $transition = $request->request->get('transition'); // the _ is a hack to display the form, @todo: cleanup
        }

        $this->handleTransitionButtons($packageStateMachine, $transition, $package);
        $this->entityManager->flush(); // to save the marking
        return $this->redirectToRoute('package_show', $package->getRP());
    }

    #[Route('/', name: 'package_show', options: ['expose' => true])]
    public function show(Package $package): Response
    {
        return $this->render('package/show.html.twig', [
            'package' => $package,
        ]);
    }

    #[Route('/edit', name: 'package_edit', options: ['expose' => true])]
    public function edit(Request $request, Package $package): Response
    {
        $form = $this->createForm(PackageType::class, $package);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            return $this->redirectToRoute('package_index');
        }

        return $this->render('package/edit.html.twig', [
            'package' => $package,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/delete', name: 'package_delete', methods: ['DELETE'])]
    public function delete(Request $request, Package $package): Response
    {
        // hard-coded to getId, should be get parameter of uniqueIdentifiers()
        if ($this->isCsrfTokenValid('delete' . $package->getId(), $request->request->get('_token'))) {
            $entityManager = $this->entityManager;
            $entityManager->remove($package);
            $entityManager->flush();
        }

        return $this->redirectToRoute('package_index');
    }
}
