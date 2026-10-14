<?php

declare(strict_types=1);

namespace App\Invoice;

use App\Approval\ApprovalTransitions;
use App\Storage\FileStore;
use Doctrine\DBAL\Connection;

/**
 * KOD I faktury przyjętej w KSeF (docs/modules/ksef.md, „KOD I, KOD II i PDF”; [kody QR](https://raw.githubusercontent.com/CIRFMF/ksef-docs/main/kody-qr.md)):
 * `https://{host KOD I}/invoice/{NIP sprzedawcy}/{P_1 jako DD-MM-RRRR}/{SHA-256 pliku w Base64URL}`. Hash liczy się
 * wyłącznie z zapisanych bajtów `xml_sent`; XML nigdy nie jest serializowany ponownie. Host pochodzi z konfiguracji
 * celu `ksef.kod_i_hosts` dla środowiska faktury (cel KSeF w chwili wysyłki).
 */
final readonly class KodI
{
    /** @param array<string,string> $hosts cel → adres bazowy KOD I */
    public function __construct(private Connection $db, private FileStore $files, private Fa3Reader $reader, private array $hosts) {}

    /** Adres KOD I faktury przyjętej z numerem KSeF. */
    public function url(string $invoiceId): string
    {
        $sent = $this->sentXml($invoiceId);
        $document = $this->reader->read($sent['bytes']);
        $nip = $document->seller->nip ?? throw new \DomainException('Brak NIP sprzedawcy w xml_sent.');
        return self::build($this->host($sent['environment']), $nip, $document->issueDate, $sent['bytes']);
    }

    /** Adres z gotowych części; `$bytes` to dokładne bajty wysłanego pliku. */
    public static function build(string $host, string $nip, string $issueDate, string $bytes): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $issueDate);
        if ($date === false || !preg_match('/^[0-9]{10}$/D', $nip)) {
            throw new \InvalidArgumentException('Niepoprawny NIP albo P_1 dla KOD I.');
        }
        $hash = rtrim(strtr(base64_encode(hash('sha256', $bytes, true)), '+/', '-_'), '=');
        return sprintf('%s/invoice/%s/%s/%s', rtrim($host, '/'), $nip, $date->format('d-m-Y'), $hash);
    }

    /**
     * Bajty pliku, który przyjął KSeF: `xml_sent` o sha256 równym `invoiceHash` z przyjęcia (także przy 440 ze zgodnym
     * hashem wcześniejszej wysyłki), inaczej payload przyjętego zatwierdzenia, a przy fakturze z Subject1 jej jedyny XML.
     *
     * @return array{file_id:string,sha256:string,bytes:string,environment:string,ksef_number:string}
     */
    public function sentXml(string $invoiceId): array
    {
        $invoice = $this->db->fetchAssociative("SELECT environment, status, ksef_number FROM invoice WHERE id=? AND direction='sale'", [$invoiceId]);
        if ($invoice === false || !in_array($invoice['status'], ['accepted','corrected','superseded'], true) || !is_string($invoice['ksef_number']) || !is_string($invoice['environment'])) {
            throw new \DomainException('KOD I powstaje tylko dla faktury przyjętej w KSeF z numerem.');
        }
        $files = [];
        foreach ($this->db->fetchAllAssociative("SELECT id, sha256 FROM stored_file WHERE owner_table='invoice' AND owner_id=? AND kind='xml_sent' AND deleted_at IS NULL ORDER BY created_at, id", [$invoiceId]) as $row) {
            if (is_string($row['id']) && is_string($row['sha256'])) {
                $files[$row['sha256']] = $row['id'];
            }
        }
        $approval = $this->db->fetchAssociative(
            "SELECT payload_file_id, external_refs FROM approval WHERE subject_type IN ('sales_invoice','correction_invoice') AND subject_id=? AND status='accepted' ORDER BY finished_at DESC NULLS LAST, id DESC LIMIT 1",
            [$invoiceId],
        );
        $fileId = null;
        if ($approval !== false) {
            $hash = ApprovalTransitions::refs($approval)['invoice_hash'] ?? null;
            $decoded = is_string($hash) ? base64_decode($hash, true) : false;
            $fileId = is_string($decoded) && strlen($decoded) === 32 ? ($files[bin2hex($decoded)] ?? null) : null;
            $fileId ??= is_string($approval['payload_file_id']) ? $approval['payload_file_id'] : null;
        } elseif (count($files) === 1) {
            $fileId = reset($files);
        }
        $sha = $fileId === null ? false : array_search($fileId, $files, true);
        if ($fileId === null || !is_string($sha)) {
            throw new \DomainException('Brak jednoznacznego pliku xml_sent faktury.');
        }
        $bytes = $this->files->read($fileId);
        if (!hash_equals($sha, hash('sha256', $bytes))) {
            throw new \RuntimeException('Bajty xml_sent różnią się od sumy w stored_file.');
        }
        return ['file_id' => $fileId,'sha256' => $sha,'bytes' => $bytes,'environment' => $invoice['environment'],'ksef_number' => $invoice['ksef_number']];
    }

    private function host(string $environment): string
    {
        $host = $this->hosts[$environment] ?? throw new \DomainException('Brak hosta KOD I dla celu ' . $environment . '.');
        if (!str_starts_with($host, 'https://')) {
            throw new \DomainException('Host KOD I musi używać HTTPS.');
        }
        return $host;
    }
}
