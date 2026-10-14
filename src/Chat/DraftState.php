<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Obiekt znakowania Symfony Workflow `draft` (state_machine, magazyn `method` na polu `status`): stan wiersza `draft`
 * na czas jednego przejścia. Zapis do bazy robi `DraftSubject`.
 */
final class DraftState
{
    public function __construct(private string $status) {}

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }
}
