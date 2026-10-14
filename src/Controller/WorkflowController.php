<?php

declare(strict_types=1);

namespace App\Controller;

use Survos\WorkflowExtrasBundle\Service\WorkflowCatalog;
use Survos\WorkflowExtrasBundle\Service\WorkflowGraphvizDumper;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\Dumper\GraphvizDumper;
use Symfony\Component\Workflow\Marking;

final class WorkflowController extends AbstractController
{
    #[Route('/state/workflow/{flowCode}', name: 'survos_workflow_extras', methods: ['GET'])]
    public function show(string $flowCode, WorkflowCatalog $catalog): Response
    {
        if (!isset($catalog->all()[$flowCode])) {
            throw $this->createNotFoundException('Unknown workflow.');
        }
        $workflow = $catalog->get($flowCode);
        $definition = $workflow->getDefinition();
        $marking = new Marking(array_fill_keys($definition->getInitialPlaces(), 1));
        $dumper = $catalog->all()[$flowCode]['type'] === 'state_machine' ? new WorkflowGraphvizDumper() : new GraphvizDumper();
        return $this->render('@SurvosWorkflowExtras/workflow.html.twig', [
            'flowCode' => $flowCode,
            'workflow' => $workflow,
            'definition' => $definition,
            'transitionGroups' => (new \Survos\WorkflowExtrasBundle\Service\TransitionGroups())->forWorkflow($workflow),
            'classes' => $catalog->all()[$flowCode]['supports'],
            'entity' => null,
            'commandRouteExists' => false,
            'digraph' => $dumper->dump($definition, $marking, [
                'graph' => ['bgcolor' => 'transparent', 'rankdir' => 'TB', 'pad' => '0.35', 'nodesep' => '0.45', 'ranksep' => '0.65'],
                'node' => ['fontsize' => '13', 'fontname' => 'Arial'],
                'edge' => ['color' => '#94a3b8', 'fontcolor' => '#52647a', 'fontsize' => '10'],
            ]),
        ]);
    }
}
