<?php

declare(strict_types=1);

namespace App\Xml;

use App\Invoice\Fa3Reader;
use App\Setting\SettingKeys;

/**
 * Kontrole FA(3) przed prośbą o zatwierdzenie (.claude/rules/integrations.md, „Kontrole przed wysyłką”): UTF-8 bez BOM,
 * bez instrukcji przetwarzania i znaków niezalecanych, rozmiar w limicie KSeF, `schema_version` zgodny z `formCode`
 * z konfiguracji KSeF, suma kontrolna każdego NIP i XSD z `LIBXML_NONET`. Pusta lista oznacza zgodność.
 */
final readonly class Fa3PresendCheck
{
    /** @param array{system_code:string,schema_version:string,value:string} $formCode */
    public function __construct(private XsdValidator $validator, private array $formCode, private int $maxBytes) {}

    /** `invoice.schema_version` odpowiadający `formCode`, np. `FA(3) 1-0E` (zapis jak w `Fa3Reader`). */
    public function schemaVersion(): string
    {
        return str_replace(' ', '', $this->formCode['system_code']) . ' ' . $this->formCode['schema_version'];
    }

    /** @return list<string> */
    public function check(string $xml, string $schemaVersion): array
    {
        $problems = [];
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $problems[] = 'Dokument ma BOM; KSeF przyjmuje UTF-8 bez BOM.';
        }
        if (!mb_check_encoding($xml, 'UTF-8')) {
            return [...$problems, 'Dokument nie jest poprawnym UTF-8.'];
        }
        if (strlen($xml) > $this->maxBytes) {
            $problems[] = sprintf('Dokument ma %d bajtów; limit KSeF bez załączników to %d.', strlen($xml), $this->maxBytes);
        }
        if (preg_match(self::discouraged(), $xml) === 1) {
            $problems[] = 'Dokument zawiera znak niedozwolony albo niezalecany w XML.';
        }
        if ($schemaVersion !== $this->schemaVersion()) {
            $problems[] = sprintf('schema_version %s różni się od formCode %s z konfiguracji KSeF.', $schemaVersion, $this->schemaVersion());
        }
        $body = str_starts_with($xml, "\xEF\xBB\xBF") ? substr($xml, 3) : $xml;
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($body, LIBXML_NONET)) {
                return [...$problems, 'Dokument nie jest poprawnym XML.'];
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('f', Fa3Reader::NS);
        $instructions = $xpath->query('//processing-instruction()');
        if ($instructions === false || $instructions->length > 0) {
            $problems[] = 'Dokument zawiera instrukcję przetwarzania XML.';
        }
        $forms = $xpath->query('/f:Faktura/f:Naglowek/f:KodFormularza');
        $form = $forms === false ? null : $forms->item(0);
        if (!$form instanceof \DOMElement || $form->getAttribute('kodSystemowy') !== $this->formCode['system_code'] || $form->getAttribute('wersjaSchemy') !== $this->formCode['schema_version'] || trim($form->textContent) !== $this->formCode['value']) {
            $problems[] = 'KodFormularza dokumentu różni się od formCode z konfiguracji KSeF.';
        }
        foreach ($xpath->query('//f:NIP') ?: [] as $nip) {
            if ($nip instanceof \DOMElement && !SettingKeys::validNip(trim($nip->textContent))) {
                $problems[] = sprintf('NIP w %s ma niepoprawną sumę kontrolną.', $nip->parentNode->parentNode->nodeName ?? 'dokumencie');
            }
        }
        if ($problems === []) {
            foreach ($this->validator->validate($xml, Fa3Reader::SCHEMA) as $error) {
                $problems[] = 'XSD: ' . $error;
            }
        }
        return $problems;
    }

    /** Znaki spoza XML 1.0 (kontrolne C0 poza TAB, LF, CR) i niezalecane z XML 1.0, sekcja 2.2. */
    private static function discouraged(): string
    {
        $ranges = '\x{0}-\x{8}\x{B}\x{C}\x{E}-\x{1F}\x{7F}-\x{84}\x{86}-\x{9F}\x{FDD0}-\x{FDEF}';
        for ($plane = 0; $plane <= 16; $plane++) {
            $ranges .= sprintf('\x{%XFFFE}-\x{%XFFFF}', $plane, $plane);
        }
        return '/[' . $ranges . ']/u';
    }
}
