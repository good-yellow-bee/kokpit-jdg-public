<?php

declare(strict_types=1);

namespace App\Approval;

/**
 * `expires_at` z konfiguracji `approval.expiry.<subject_type>.<pending|approved>` (docs/approval-gate.md, „Wygaśnięcie”):
 * czas ISO 8601 albo `end_of_p1_day`, czyli północ po dniu prośby w Europe/Warsaw (P_1 to dzień prośby, D-16).
 */
final readonly class ApprovalExpiry
{
    /** @param array<string,array{pending:string,approved:string}> $expiry */
    public function __construct(private array $expiry) {}

    public function at(string $subjectType, string $state, \DateTimeImmutable $requestedAt, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $rule = $this->expiry[$subjectType][$state] ?? throw new \DomainException('Brak wygaśnięcia dla ' . $subjectType . '.');
        if ($rule === 'end_of_p1_day') {
            return $requestedAt->setTimezone(new \DateTimeZone('Europe/Warsaw'))->setTime(0, 0)->modify('+1 day');
        }
        return $now->add(new \DateInterval($rule));
    }
}
