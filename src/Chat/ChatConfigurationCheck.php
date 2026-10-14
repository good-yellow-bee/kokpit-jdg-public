<?php

declare(strict_types=1);

namespace App\Chat;

use App\Connector\Claude\ChatRequestProfile;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Walidacja konfiguracji czatu przy budowie kontenera (D-10, D-60): `chat.model` tylko `claude-haiku-5-5`,
 * `chat.effort` jawny, bez wartości domyślnej. Inna wartość zatrzymuje start aplikacji, zanim powstanie żądanie.
 */
final class ChatConfigurationCheck implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter('chat.model') || !$container->hasParameter('chat.effort')) {
            throw new \InvalidArgumentException('chat.model i chat.effort są wymagane (D-60).');
        }
        ChatRequestProfile::assertValid($container->getParameter('chat.model'), $container->getParameter('chat.effort'));
    }
}
