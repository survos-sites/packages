<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    protected function build(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
    {
        parent::build($container);
        // Remove the legacy runtime before Doctrine/Messenger collect service tags.
        $container->addCompilerPass(new class implements \Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface {
            public function process(\Symfony\Component\DependencyInjection\ContainerBuilder $container): void
            {
                $container->removeDefinition(\Survos\StateBundle\Doctrine\InitialPlaceKickoffListener::class);
                $container->removeDefinition(\Survos\StateBundle\Service\WorkflowListener::class);
                $container->findDefinition(\Survos\StateBundle\Service\WorkflowHelperService::class)->setAutoconfigured(false);
            }
        }, priority: 1000);
        // AbstractUxBundle is also auto-run at -10000; update UI metadata afterwards.
        $container->addCompilerPass(new \App\DependencyInjection\NativeWorkflowCompatibilityPass(), priority: -10001);
    }
}
