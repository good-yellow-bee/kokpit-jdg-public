<?php

declare(strict_types=1);

namespace App\Health;

final readonly class NativeHealthResources implements HealthResources
{
    public function __construct(private string $projectDir, private string $certificatePath) {}
    public function diskFreePercent(): ?int
    {
        $free = disk_free_space($this->projectDir);
        $total = disk_total_space($this->projectDir);
        return $free !== false && $total !== false && $total > 0 ? (int) floor($free / $total * 100) : null;
    }
    public function backupModifiedAt(): ?int
    {
        $path = $this->projectDir . '/var/run/backup/last_success';
        return is_file($path) ? (filemtime($path) ?: null) : null;
    }
    public function certificateValidUntil(): ?int
    {
        if (!is_readable($this->certificatePath)) {
            return null;
        }
        $bytes = file_get_contents($this->certificatePath);
        if ($bytes === false) {
            return null;
        }
        $certificate = openssl_x509_parse($bytes);
        $expires = is_array($certificate) ? ($certificate['validTo_time_t'] ?? null) : null;
        return is_int($expires) ? $expires : null;
    }
}
