<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Panel\SessionLifetimeSubscriber;
use App\Passkey\AppUser;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

final class SessionLifetimeTest extends TestCase
{
    public function testTwelveHourLimitExpiresEvenAnActiveSession(): void
    {
        $clock = new MockClock('2026-10-09T10:00:00Z');
        $tokens = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new AppUser('00000000-0000-7000-8000-000000000001', 'fictional-owner'), 'main', ['ROLE_USER']));
        $db = $this->createStub(Connection::class);
        $db->method('fetchOne')->willReturn('fictional-credential-id');
        $session = new Session(new MockArraySessionStorage());
        $session->set('auth.started_at', $clock->now()->getTimestamp() - 43200);
        $session->set('auth.last_seen_at', $clock->now()->getTimestamp());
        $request = Request::create('/');
        $request->setSession($session);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new SessionLifetimeSubscriber($tokens, $clock, $db))->onRequest($event);
        self::assertSame(302, $event->getResponse()?->getStatusCode());
        self::assertNull($tokens->getToken());
        self::assertFalse($session->has('auth.started_at'));
    }
}
