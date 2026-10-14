<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\ApprovalController;
use App\Tests\Support\KsefSendTestTools;
use App\Tests\Support\SalesTestTools;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/** Baza testów F3.3: `KsefSendTestTools` nad `SalesTestTools`, `MockClock` 16.11.2026 10:00 w Warszawie, nagrania fikcyjne. */
abstract class KsefSendTestCase extends SalesTestCase
{
    /** @param array{system_code:string,schema_version:string,value:string} $formCode */
    protected function send(string $now = SalesTestTools::NOW, array $formCode = KsefSendTestTools::FORM_CODE, string $ksefTarget = 'test'): KsefSendTestTools
    {
        return new KsefSendTestTools($this->db(), $this->tools($now), $formCode, $ksefTarget);
    }

    /** Strona `/approvals` z kontenera (ta sama transakcja bazy), z sesją w pamięci dla CSRF i komunikatów. */
    protected function approvalsPage(): string
    {
        $request = Request::create('/approvals');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = self::getContainer()->get(RequestStack::class);
        self::assertInstanceOf(RequestStack::class, $stack);
        $stack->push($request);
        try {
            $controller = self::getContainer()->get(ApprovalController::class);
            self::assertInstanceOf(ApprovalController::class, $controller);
            return (string) $controller->index()->getContent();
        } finally {
            $stack->pop();
        }
    }
}
