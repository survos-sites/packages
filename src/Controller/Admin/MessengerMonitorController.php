<?php

namespace App\Controller\Admin;

use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Zenstruck\Messenger\Monitor\Controller\MessengerMonitorController as BaseMessengerMonitorController;
#[When('dev')]
#[Route('/admin/messenger')]
//#[IsGranted('ROLE_ADMIN')]
class MessengerMonitorController extends BaseMessengerMonitorController
{
}
