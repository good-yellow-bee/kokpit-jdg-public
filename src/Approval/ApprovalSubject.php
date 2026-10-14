<?php

declare(strict_types=1);

namespace App\Approval;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Przedmiot zatwierdzenia jednego `subject_type`: generator payloadu, wersja źródła i skutki po stronie encji
 * („Powiązanie z bramką zatwierdzeń” w docs/data-model.md). Od F3.2 faktury sprzedaży i KOR (`SalesInvoiceSubject`),
 * w dev i test także adaptery `dummy`.
 */
#[AutoconfigureTag('app.approval_subject')]
interface ApprovalSubject
{
    public function subjectType(): string;

    /** Slug konektora wysyłki encji; NULL tylko dla szkicu do kopiowania (Q-35). Złożenie wybiera go po typie (F5). */
    public function connector(string $subjectId): ?string;

    /** Buduje payload i sprawdza warunki z kolumną „prośba”; niespełniony warunek rzuca wyjątek. */
    public function payload(string $subjectId): ApprovalPayload;

    /** Sprawdza warunki z kolumną „decyzja” (docs/approval-gate.md); niespełniony warunek rzuca wyjątek bez zmiany stanu. */
    public function checkDecision(string $subjectId): void;

    /** Bieżąca `version` encji; różna od `approval.source_version` oznacza zmianę źródła. */
    public function currentVersion(string $subjectId): int;

    /** Przestawia encję po zmianie `approval.status`. */
    public function onStatus(string $subjectId, string $status, ?string $cancelReason): void;
}
