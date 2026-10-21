<?php

declare(strict_types=1);

namespace App\Bank;

use App\Audit\AuditLog;
use App\Calendar\IsoDate;
use App\Calendar\ObligationActions;
use App\Config\ConnectorConfiguration;
use App\Storage\FileStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\UuidV7;

/**
 * Import wyciągu (D-108): wgranie → `pending` z plikiem w `FileStore`, podgląd liczony z pliku, zatwierdzenie zapisuje
 * nowe operacje i zaznaczone dopasowania w jednej transakcji albo odrzucenie. Bank niczego nie dostaje: tylko odczyt.
 */
final readonly class BankStatements
{
    /** Limit pliku w panelu: eksport roku to około 100 KB, a domyślny limit treści żądania nginx to 1 MiB. */
    public const MAX_BYTES = 1_000_000;
    private const LOCK = 'bank_statement';

    /** @param iterable<BankStatementParser> $parsers */
    public function __construct(
        private Connection $db,
        private ClockInterface $clock,
        private BusinessDate $businessDate,
        private FileStore $files,
        private AuditLog $audit,
        private ConnectorConfiguration $configuration,
        private BankLineMatcher $matcher,
        private ObligationActions $obligations,
        #[AutowireIterator(BankStatementParser::TAG)]
        private iterable $parsers,
    ) {}

    /** @return string id wyciągu `pending` */
    public function upload(string $bytes, ?string $originalName, string $userId): string
    {
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new BankStatementRefused(['plik większy niż 1 MB']);
        }
        $parser = null;
        foreach ($this->parsers as $candidate) {
            $parser ??= $candidate->supports($bytes) ? $candidate : null;
        }
        if ($parser === null) {
            throw new BankStatementRefused(['nieznany format: obsługiwana jest lista operacji CSV z mBanku']);
        }
        $parsed = $parser->parse($bytes);
        $environment = $this->configuration->environment();
        $sha = hash('sha256', $bytes);
        return $this->db->transactional(function () use ($bytes, $originalName, $userId, $parser, $parsed, $environment, $sha): string {
            $this->lock();
            if ($this->db->fetchOne("SELECT 1 FROM bank_statement WHERE environment=? AND status='pending'", [$environment]) !== false) {
                throw new BankStatementRefused(['najpierw zatwierdź albo odrzuć oczekujący wyciąg']);
            }
            if ($this->db->fetchOne("SELECT 1 FROM bank_statement WHERE environment=? AND file_sha256=? AND status<>'discarded'", [$environment, $sha]) !== false) {
                throw new BankStatementRefused(['ten plik jest już zaimportowany']);
            }
            $now = $this->clock->now();
            $id = UuidV7::generate($now);
            // Dowód zapłat w roku końca okresu; PIT-28 za ten rok składasz w następnym.
            $file = $this->files->put($bytes, $environment, 'evidence', 'text/csv', 'bank_statement', $id, IsoDate::year($parsed->periodTo) + 1, false, $originalName);
            $stamp = $now->format('Y-m-d\TH:i:s.uP');
            $this->db->insert('bank_statement', ['id' => $id,'environment' => $environment,'format' => $parser->format(),'file_sha256' => $sha,'file_id' => $file,'original_name' => $originalName,'period_from' => $parsed->periodFrom,'period_to' => $parsed->periodTo,'status' => 'pending','lines_in_file' => count($parsed->lines),'uploaded_at' => $stamp,'uploaded_by' => $userId,'created_at' => $stamp,'updated_at' => $stamp]);
            $this->audit->append('bank_statement.uploaded', ['format' => $parser->format(),'file_sha256' => $sha,'lines_in_file' => count($parsed->lines)], $environment, 'bank_statement', $id, $userId);
            return $id;
        });
    }

    public function preview(string $id): BankPreview
    {
        return $this->previewOf($this->statement($id));
    }

    /**
     * Zapisuje nowe operacje i zaznaczone propozycje. Token niezgodny z przeliczoną propozycją jest pomijany, nigdy
     * podmieniany; obowiązek z propozycji jest zablokowany do końca transakcji i zamyka go `ObligationActions::recordPayment`.
     *
     * @param list<string> $tokens
     * @return array{added:int,applied:int,skipped:int}
     */
    public function confirm(string $id, string $userId, string $fingerprint, array $tokens): array
    {
        return $this->db->transactional(function () use ($id, $userId, $fingerprint, $tokens): array {
            $this->lock();
            $statement = $this->statement($id);
            $preview = $this->previewOf($statement);
            if (!hash_equals($preview->fingerprint(), $fingerprint)) {
                throw new \DomainException('Podgląd jest nieaktualny: sprawdź operacje i dopasowania jeszcze raz.');
            }
            $accepted = array_flip($tokens);
            $environment = $this->configuration->environment();
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $applied = 0;
            foreach ($preview->lines as $line) {
                $match = $preview->proposals[$line->hash] ?? null;
                if ($match !== null && !isset($accepted[$match->token($line->hash)])) {
                    $match = null;
                }
                $this->db->insert('bank_statement_line', ['id' => UuidV7::generate($this->clock->now()),'environment' => $environment,'statement_id' => $id,'line_hash' => $line->hash,'account' => $line->account,'booked_on' => $line->bookedOn,'amount' => $line->amount,'currency' => $line->currency,'operation_type' => $line->operationType,'description' => $line->description,'counterparty_account' => $line->counterpartyAccount,'kind' => $line->kind->value,'split_payment' => $line->splitPayment ? 'true' : 'false','invoice_id' => $match?->invoiceId,'payment_id' => $match?->paymentId,'obligation_id' => $match?->obligationId,'match_rule' => $match?->rule->value,'matched_at' => $match === null ? null : $now,'matched_by' => $match === null ? null : $userId,'created_at' => $now]);
                if ($match?->obligationId !== null) {
                    $this->obligations->recordPayment($match->obligationId, $userId, (string) $line->amount->abs()->toScale(2), $line->bookedOn);
                }
                $applied += $match === null ? 0 : 1;
            }
            $result = ['added' => count($preview->lines),'applied' => $applied,'skipped' => count($accepted) - $applied];
            $this->db->update('bank_statement', ['status' => 'confirmed','lines_added' => $result['added'],'matches_applied' => $applied,'decided_at' => $now,'decided_by' => $userId,'updated_at' => $now], ['id' => $id]);
            $this->audit->append('bank_statement.confirmed', ['lines_added' => $result['added'],'duplicates' => $preview->duplicates,'future' => $preview->future,'matches_applied' => $applied,'matches_skipped' => $result['skipped']], $environment, 'bank_statement', $id, $userId);
            return $result;
        });
    }

    public function discard(string $id, string $userId): void
    {
        $this->db->transactional(function () use ($id, $userId): void {
            $this->statement($id);
            $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
            $this->db->update('bank_statement', ['status' => 'discarded','decided_at' => $now,'decided_by' => $userId,'updated_at' => $now], ['id' => $id]);
            $this->audit->append('bank_statement.discarded', [], $this->configuration->environment(), 'bank_statement', $id, $userId);
        });
    }

    /** @return array{format:string,file_sha256:string,file_id:string} */
    private function statement(string $id): array
    {
        $row = $this->db->fetchAssociative("SELECT format, file_sha256, file_id FROM bank_statement WHERE id=? AND environment=? AND status='pending' FOR UPDATE", [$id, $this->configuration->environment()]);
        if ($row === false) {
            throw new \DomainException('Brak oczekującego wyciągu.');
        }
        return ['format' => self::text($row['format']),'file_sha256' => self::text($row['file_sha256']),'file_id' => self::text($row['file_id'])];
    }

    /** @param array{format:string,file_sha256:string,file_id:string} $statement */
    private function previewOf(array $statement): BankPreview
    {
        $bytes = $this->files->read($statement['file_id']);
        if (!hash_equals($statement['file_sha256'], hash('sha256', $bytes))) {
            throw new \RuntimeException('Plik wyciągu nie zgadza się z sumą kontrolną.');
        }
        $parser = null;
        foreach ($this->parsers as $candidate) {
            $parser ??= $candidate->format() === $statement['format'] ? $candidate : null;
        }
        $parsed = ($parser ?? throw new \LogicException('Brak czytnika formatu wyciągu.'))->parse($bytes);
        $today = $this->businessDate->today();
        $current = array_filter($parsed->lines, static fn(BankLine $line): bool => $line->bookedOn <= $today);
        $known = $this->db->fetchFirstColumn('SELECT line_hash FROM bank_statement_line WHERE environment=? AND line_hash IN (?)', [$this->configuration->environment(), array_map(static fn(BankLine $line): string => $line->hash, $current)], [1 => ArrayParameterType::STRING]);
        $new = array_values(array_filter($current, static fn(BankLine $line): bool => !in_array($line->hash, $known, true)));
        return new BankPreview($new, $this->matcher->propose($new), count($current) - count($new), count($parsed->lines) - count($current));
    }

    private function lock(): void
    {
        $this->db->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', [self::LOCK]);
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new \LogicException('Niepoprawna wartość wiersza.');
    }
}
