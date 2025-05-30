<?php

namespace App\Menu;

use Survos\BootstrapBundle\Event\KnpMenuEvent;
use Survos\BootstrapBundle\Service\MenuService;
use Survos\BootstrapBundle\Traits\KnpMenuHelperInterface;
use Survos\BootstrapBundle\Traits\KnpMenuHelperTrait;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

// events are
/*
// #[AsEventListener(event: KnpMenuEvent::NAVBAR_MENU2)]
#[AsEventListener(event: KnpMenuEvent::SIDEBAR_MENU, method: 'sidebarMenu')]
#[AsEventListener(event: KnpMenuEvent::PAGE_MENU, method: 'pageMenu')]
#[AsEventListener(event: KnpMenuEvent::FOOTER_MENU, method: 'footerMenu')]
#[AsEventListener(event: KnpMenuEvent::AUTH_MENU, method: 'appAuthMenu')]
*/

final class AppMenu implements KnpMenuHelperInterface
{
    use KnpMenuHelperTrait;

    public function __construct(
        #[Autowire('%kernel.environment%')] protected string $env,
        private MenuService $menuService,
        private Security $security,
        private ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {
    }

    public function appAuthMenu(KnpMenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->menuService->addAuthMenu($menu);
    }

    #[AsEventListener(event: KnpMenuEvent::FOOTER_MENU)]
    public function footer(KnpMenuEvent $event): void
    {
        $menu = $event->getMenu();
        $this->add($menu, uri: 'https://github.com/survos-sites/packages', label: 'Github');
    }

    #[AsEventListener(event: KnpMenuEvent::NAVBAR_MENU)]
    public function navbarMenu(KnpMenuEvent $event): void
    {
        $menu = $event->getMenu();
        $options = $event->getOptions();

        $this->add($menu, 'app_homepage');
        $this->add($menu, uri: 'https://github.com/survos-sites/packages', label: 'Github');
        $this->add($menu, uri: 'https://packagist.org/', label: 'Packagist.org');
//        $this->add($menu, 'app_homepage', ['symfonyVersions'=>'7.0'], label: "Symfony 7");

        if ($this->isGranted('ROLE_ADMIN')) {
            $nestedMenu = $this->addSubmenu($menu, 'Credits');
            $this->add($menu, 'app_homepage');
            $this->add($menu, 'riccox_meili_admin');
            $this->add($menu, 'survos_workflows');
            $this->add($menu, 'survos_commands', if: $this->isEnv('dev') || $this->isGranted('ROLE_ADMIN'));

            foreach (['bundles', 'javascript'] as $type) {
                $this->addMenuItem($nestedMenu, ['uri' => "#$type", 'label' => ucfirst($type)]);
            }
        }
    }
}
