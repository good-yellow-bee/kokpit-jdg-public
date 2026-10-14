<?php

declare(strict_types=1);

namespace App\Xml;

/** Walidacja offline: `LIBXML_NONET` i loader mapujący znane `schemaLocation` na pliki z `resources/xsd`. */
final readonly class XsdValidator
{
    public function __construct(private XsdCatalog $catalog) {}
    /** @return list<string> błędy walidacji; pusta lista oznacza zgodność */
    public function validate(string $xml, string $schemaFile): array
    {
        $schema = $this->catalog->path($schemaFile);
        $map = $this->catalog->locations();
        $directory = dirname($schema);
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        libxml_set_external_entity_loader(static function (?string $public, string $system, array $context) use ($map, $directory): ?string {
            if (isset($map[$system])) {
                return $map[$system];
            }
            $real = realpath($system);
            // Plik lokalny z katalogu schematów (dokument główny i ścieżki względne); każdy inny adres odpada.
            return $real !== false && str_starts_with($real, $directory . '/') ? $real : null;
        });
        try {
            $document = new \DOMDocument();
            if (str_starts_with($xml, "\xEF\xBB\xBF")) {
                return ['Dokument ma BOM.'];
            }
            if (!$document->loadXML($xml, LIBXML_NONET)) {
                return $this->errors() ?: ['Dokument nie jest poprawnym XML.'];
            }
            foreach ($document->childNodes as $node) {
                if ($node instanceof \DOMProcessingInstruction || $node instanceof \DOMDocumentType) {
                    return ['Dokument zawiera instrukcję przetwarzania albo DTD.'];
                }
            }
            // Ostrzeżenie PHP przy błędnym schemacie dubluje błąd libxml, który zwracamy niżej.
            if (!@$document->schemaValidate($schema)) {
                return $this->errors() ?: ['Dokument niezgodny z XSD.'];
            }
            return [];
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
    /** @return list<string> */
    private function errors(): array
    {
        return array_map(static fn(\LibXMLError $error): string => sprintf('linia %d: %s', $error->line, trim($error->message)), libxml_get_errors());
    }
}
