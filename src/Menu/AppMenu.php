<?php

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Service\MenuService;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

// other available slots: MenuEvent::SIDEBAR, MenuEvent::BREADCRUMB, MenuEvent::PAGE_NAV, MenuEvent::PAGE_ACTIONS

final class AppMenu
{
    use MenuBuilderTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private MenuService                                  $menuService,
        private Security                                     $security,
    ) {
    }

    #[AsEventListener(event: MenuEvent::FOOTER)]
    public function footer(MenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, uri: 'https://github.com/survos-sites/packages', label: 'Github', translationDomain: 'routing');
    }

    #[AsEventListener(event: MenuEvent::NAVBAR_MENU)]
    public function navbarMenu(MenuEvent $event): void
        {
        $menu = $event->getMenu();

        $this->add($menu, 'app_homepage', label: 'Home', translationDomain: 'routing');
        $this->add($menu, 'admin', label: 'ez', translationDomain: 'routing');

            if (($this->env === 'dev')) {
            $this->add($menu, 'zenstruck_messenger_monitor_dashboard', label: '*msg', translationDomain: 'routing');
            }

            $this->add($menu, 'survos_workflow_entities', label: '*entities', translationDomain: 'routing');
        $this->add($menu, 'survos_entity_ux_search', ['code' => 'app_package'], label: 'Faceted search', translationDomain: 'routing');

        return;

        $sub = $this->addSubmenu($menu, 'InstaSearch', translationDomain: 'routing');
        $this->add($menu, uri: 'https://github.com/survos-sites/packages', label: 'Github', translationDomain: 'routing');
        $this->add($menu, uri: 'https://packagist.org/', label: 'Packagist.org', translationDomain: 'routing');
        $this->add($menu, 'api_doc', translationDomain: 'routing', label: 'api_doc');

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $nestedMenu = $this->addSubmenu($menu, 'Credits', translationDomain: 'routing');
            $this->add($menu, 'survos_workflows', translationDomain: 'routing', label: 'survos_workflows');
            $this->add($menu, 'survos_commands', if: ($this->env === 'dev') || $this->security->isGranted('ROLE_ADMIN'), translationDomain: 'routing', label: 'survos_commands');
        }
    }
}
