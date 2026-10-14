<?php

declare(strict_types=1);

namespace App\Controller;

use App\Panel\TargetBanner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PanelController extends AbstractController
{
    #[Route('/', name: 'panel_dashboard', methods: ['GET'])]
    public function dashboard(\App\Config\ConnectorConfiguration $configuration, \App\Passkey\PasskeyManager $passkeys, \App\Counterparty\SelfCheck $selfCheck): Response
    {
        $user = $this->getUser();
        $warning = $user instanceof \App\Passkey\AppUser && $passkeys->warning($user->id());
        return $this->render('panel/dashboard.html.twig', ['banner' => new TargetBanner(['KSeF' => $configuration->ksefTarget,'JPK' => $configuration->jpkTarget,'e-Deklaracje' => $configuration->edeklaracjeTarget], $configuration->enabled),'passkey_warning' => $warning,'registry_alerts' => $selfCheck->alerts()]);
    }
}
