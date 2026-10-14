<?php

declare(strict_types=1);

namespace App\Chat;

use App\Config\ConnectorConfiguration;
use App\Security\Redactor;
use Doctrine\DBAL\Connection;

/**
 * Maskowanie pytań przed modelem i przed zapisem w `chat_message` (docs/modules/czat.md, „Dane do modelu”):
 * nazwa i NIP kontrahenta z bazy → pseudonim `K-NN`, potem `Redactor` (PESEL, IBAN, NRB, tokeny, JWT, e-mail).
 */
final readonly class ChatRedaction
{
    private const MIN_NAME_LENGTH = 3;

    public function __construct(private Connection $db, private ConnectorConfiguration $configuration, private Redactor $redactor) {}

    public function question(string $text, PseudonymMap $map): string
    {
        $rows = $this->db->fetchAllAssociative('SELECT id::text AS id, name, nip FROM counterparty WHERE environment = ? ORDER BY char_length(name) DESC, id', [$this->configuration->environment()]);
        foreach ($rows as $row) {
            if (!is_string($row['id']) || !is_string($row['name'])) {
                continue;
            }
            $patterns = [];
            if (mb_strlen($row['name']) >= self::MIN_NAME_LENGTH) {
                $patterns[] = '/' . preg_quote($row['name'], '/') . '/iu';
            }
            if (is_string($row['nip'])) {
                $patterns[] = '/(?<!\d)' . implode('[- ]?', str_split($row['nip'])) . '(?!\d)/';
            }
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    $text = preg_replace($pattern, $map->pseudonym($row['id']), $text) ?? throw new \RuntimeException('Błąd maskowania.');
                }
            }
        }
        return $this->redactor->text($text);
    }

    public function value(mixed $value): mixed
    {
        return $this->redactor->redact($value);
    }
}
