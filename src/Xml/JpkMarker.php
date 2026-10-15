<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * Znacznik KSeF wiersza JPK_V7M(3) według „Tabeli znaczników” w docs/modules/jpk.md: warunki od góry, wygrywa pierwszy
 * pasujący. Znacznik powstaje przy budowie pliku z pól faktury i nie jest zapisywany (docs/data-model.md, D-65).
 */
enum JpkMarker: string
{
    case NrKSeF = 'NrKSeF';
    case Off = 'OFF';
    case Bfk = 'BFK';
    case Di = 'DI';

    /** Wartość `invoice.jpk_doc_type` dowodu wewnętrznego (`TypDokumentu` albo `DokumentZakupu` = `WEW`). */
    public const WEW = 'WEW';

    /**
     * Wiersz 1: `WEW` → `DI`; 2: numer KSeF → `NrKSeF`; 3: `failure` → `OFF`; 4: `offline24`, `maintenance` → `DI`;
     * 5: `total_failure`, `outside_ksef` → `BFK`; 6: `online` bez numeru (faktura w toku) blokuje budowę. Pusty tryb bez
     * numeru to błąd danych (pusty tryb dopuszcza tylko faktura z numerem z importu KSeF) i też blokuje budowę.
     */
    public static function of(?string $jpkDocType, ?string $ksefNumber, ?string $issueMode, string $document): self
    {
        if (self::wew($jpkDocType)) {
            return self::Di;
        }
        if ($ksefNumber !== null && trim($ksefNumber) !== '') {
            return self::NrKSeF;
        }
        return match ($issueMode) {
            'failure' => self::Off,
            'offline24', 'maintenance' => self::Di,
            'total_failure', 'outside_ksef' => self::Bfk,
            'online' => throw new JpkBuildRefused(sprintf('Dokument %s: tryb online bez numeru KSeF (faktura w toku, `submitted` albo `unknown`) blokuje budowę JPK_V7M.', $document)),
            default => throw new JpkBuildRefused(sprintf('Dokument %s: brak trybu KSeF (`ksef_issue_mode`) i numeru KSeF; znacznika nie da się ustalić.', $document)),
        };
    }

    public static function wew(?string $jpkDocType): bool
    {
        return $jpkDocType === self::WEW;
    }
}
