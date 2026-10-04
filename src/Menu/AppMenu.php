<?php

declare(strict_types=1);

namespace App\Menu;

use Survos\TablerBundle\Event\MenuEvent;
use Survos\TablerBundle\Menu\MenuBuilderTrait;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * The home page is the faceted search, so the navbar has no links of its own: the logo is Home, GitHub sits in the top
 * row, and the admin tools (messenger monitor, entities, workflows, ...) come from ADMIN_NAVBAR_MENU.
 * Someday: an About page, or an AI chat, in NAVBAR_PRIMARY.
 */
final class AppMenu
{
    use MenuBuilderTrait;

    private const GITHUB = 'https://github.com/survos-sites/packages';

    #[AsEventListener(event: MenuEvent::NAVBAR_END)]
    public function github(MenuEvent $event): void
    {
        $this->add($event->getMenu(), uri: self::GITHUB, label: 'GitHub', icon: 'tabler:brand-github', external: true, translationDomain: false)
            ->setExtra('tooltip', 'Source on GitHub');
    }

    #[AsEventListener(event: MenuEvent::FOOTER)]
    public function footer(MenuEvent $event): void
    {
        $this->add($event->getMenu(), uri: self::GITHUB, label: 'GitHub', translationDomain: false);
    }
}
