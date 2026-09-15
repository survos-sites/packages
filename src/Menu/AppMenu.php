<?php

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Service\MenuService;
use Survos\TablerBundle\Traits\KnpMenuHelperInterface;
use Survos\TablerBundle\Traits\KnpMenuHelperTrait;
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

            if ($this->isEnv('dev')) {
            $this->add($menu, 'zenstruck_messenger_monitor_dashboard', label: '*msg');
            }

            $this->add($menu, 'survos_workflow_entities', label: '*entities');
        $this->add($menu, 'survos_entity_ux_search', ['code' => 'app_package'], label: 'Faceted search');

        return;

        $sub = $this->addSubmenu($menu, 'InstaSearch');
        $this->add($menu, uri: 'https://github.com/survos-sites/packages', label: 'Github');
        $this->add($menu, uri: 'https://packagist.org/', label: 'Packagist.org');
        $this->add($menu, 'api_doc');
//        $this->add($menu, 'app_homepage', ['symfonyVersions'=>'7.0'], label: "Symfony 7");

        if ($this->isGranted('ROLE_ADMIN')) {
            $nestedMenu = $this->addSubmenu($menu, 'Credits');
            $this->add($menu, 'survos_workflows');
            $this->add($menu, 'survos_commands', if: $this->isEnv('dev') || $this->isGranted('ROLE_ADMIN'));
        }
    }
}
