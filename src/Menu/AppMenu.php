<?php

namespace App\Menu;

use App\Controller\Admin\MeiliDashboardController;
use App\Repository\EndpointRepository;
use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Service\MenuService;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Traits\KnpMenuHelperTrait;
use Survos\MeiliBundle\Service\MeiliService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

// other available slots: MenuEvent::SIDEBAR, MenuEvent::BREADCRUMB, MenuEvent::PAGE_NAV, MenuEvent::PAGE_ACTIONS

final class AppMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private MenuService                                  $menuService,
        private Security                                     $security,
        private readonly MeiliService $meiliService,
        private readonly EndpointRepository $endpointRepository,
        private ?AuthorizationCheckerInterface               $authorizationChecker = null,
    ) {
    }

    // not wired up: this app doesn't use login/logout/register routes (see config/routes/survos_auth.yaml)
    public function appAuthMenu(MenuEvent $event): void
    {
        if (!$this->authorizationChecker) {
            return;
        }
        $this->authMenu($this->authorizationChecker, $this->security, $event->getMenu());
    }

    #[AsEventListener(event: MenuEvent::FOOTER)]
    public function footer(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, uri: 'https://github.com/survos-sites/packages', label: 'Github');
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
        {
        $menu = $event->getMenu();

        $this->add($menu, 'app_homepage', label: 'Home');
        $this->add($menu, 'admin', label: 'ez');
        $this->add($menu, MeiliDashboardController::MEILI_ROUTE, label: 'meili');

            if ($this->isEnv('dev')) {
            $this->add($menu, 'zenstruck_messenger_monitor_dashboard', label: '*msg');
            }

            $this->add($menu, 'survos_workflow_entities', label: '*entities');
        foreach ($this->meiliService->settings as $indexName => $settings) {
            $this->add($menu, 'meili_insta', ['indexName' => $indexName], label: $settings['baseName']);
        }
        foreach ($this->endpointRepository->findAll() as $endpoint) {
            try {
//                $index = $this->meiliService->getIndex($endpoint->name);
//                dd($index->getSettings());
            } catch (\Exception $e) {
                continue;
            }
            $settings = $endpoint->settings;
            if (count($settings['embedders'])) {
                $sub  = $this->addSubmenu($menu,  $endpoint->label);
                foreach ($settings['embedders'] as $embedder=>$embedderConfig) {
                    $this->add($sub, 'app_insta', ['embedder' => $embedder, 'indexName' => $endpoint->name, 'class' => 'grid-' . $endpoint->columns], label: $endpoint->label);
                }

            } else {
                $this->add($menu, 'app_insta', ['indexName' => $endpoint->name, 'class' => 'grid-' . $endpoint->columns], label: $endpoint->label);

            }
            // @todo: better way to map indexes to columns.  Number of fields?
        }

        return;

        $sub = $this->addSubmenu($menu, 'InstaSearch');
        $this->add($menu, uri: 'https://github.com/survos-sites/packages', label: 'Github');
        $this->add($menu, uri: 'https://packagist.org/', label: 'Packagist.org');
        if ($this->env === 'dev') {
            $this->add($menu, 'meili_proxy');
            $this->add($menu, 'mcp_controller');
            $this->add($menu, 'meili_admin_docs');
        }
        $this->add($menu, 'api_doc');
//        $this->add($menu, 'app_homepage', ['symfonyVersions'=>'7.0'], label: "Symfony 7");

        if ($this->isGranted('ROLE_ADMIN')) {
            $nestedMenu = $this->addSubmenu($menu, 'Credits');
            $this->add($menu, 'riccox_meili_admin');
            $this->add($menu, 'survos_workflows');
            $this->add($menu, 'survos_commands', if: $this->isEnv('dev') || $this->isGranted('ROLE_ADMIN'));
        }
    }
}
