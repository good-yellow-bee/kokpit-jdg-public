<?php

declare(strict_types=1);

namespace App\Xml;

/** Słowniki i wzorce typów prostych czytane wprost z XSD w `resources/xsd`, bez kopii w kodzie. */
final class XsdDictionary
{
    private const XS = 'http://www.w3.org/2001/XMLSchema';
    /** @var array<string,array{enumerations:list<string>,patterns:list<string>,max_length:?int}> */
    private array $cache = [];
    public function __construct(private readonly XsdCatalog $catalog) {}
    /** @return list<string> */
    public function enumerations(string $file, string $type): array
    {
        return $this->facets($file, $type)['enumerations'];
    }
    /** @return list<string> wzorce XSD (zakotwiczone niejawnie) */
    public function patterns(string $file, string $type): array
    {
        return $this->facets($file, $type)['patterns'];
    }
    public function maxLength(string $file, string $type): ?int
    {
        return $this->facets($file, $type)['max_length'];
    }
    /** Wartość zgodna ze wzorcem XSD typu; typ bez wzorca przyjmuje każdą wartość. */
    public function matchesPattern(string $file, string $type, string $value): bool
    {
        foreach ($this->patterns($file, $type) as $pattern) {
            $regex = '/^(?:' . str_replace('/', '\/', $pattern) . ')$/Du';
            $result = @preg_match($regex, $value);
            if ($result === false) {
                throw new \RuntimeException(sprintf('Wzorzec XSD typu %s nie jest zgodny z PCRE.', $type));
            }
            if ($result === 1) {
                return true;
            }
        }
        return $this->patterns($file, $type) === [];
    }
    /** @return array{enumerations:list<string>,patterns:list<string>,max_length:?int} */
    private function facets(string $file, string $type): array
    {
        $key = $file . '#' . $type;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $document = new \DOMDocument();
        $bytes = file_get_contents($this->catalog->path($file));
        if ($bytes === false || !$document->loadXML($bytes, LIBXML_NONET)) {
            throw new \RuntimeException(sprintf('Nie można odczytać XSD %s.', $file));
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('xsd', self::XS);
        $restriction = $xpath->query(sprintf('/xsd:schema/xsd:simpleType[@name="%s"]/xsd:restriction', $type));
        if ($restriction === false || !$restriction->item(0) instanceof \DOMElement) {
            throw new \RuntimeException(sprintf('Brak typu %s w XSD %s.', $type, $file));
        }
        $node = $restriction->item(0);
        $max = self::values($xpath, $node, 'maxLength');
        return $this->cache[$key] = ['enumerations' => self::values($xpath, $node, 'enumeration'),'patterns' => self::values($xpath, $node, 'pattern'),'max_length' => $max === [] ? null : (int) $max[0]];
    }
    /** @return list<string> */
    private static function values(\DOMXPath $xpath, \DOMElement $restriction, string $facet): array
    {
        $result = [];
        foreach ($xpath->query('xsd:' . $facet, $restriction) ?: [] as $element) {
            if ($element instanceof \DOMElement) {
                $result[] = $element->getAttribute('value');
            }
        }
        return $result;
    }
}
