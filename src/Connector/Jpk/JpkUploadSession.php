<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

/** Odpowiedź `InitUploadSigned`: numer referencyjny, `TimeoutInSec` i adresy Put Blob części (`RequestToUploadFileList`). */
final readonly class JpkUploadSession
{
    /** @param list<JpkUploadTarget> $targets */
    public function __construct(public string $referenceNumber, public int $timeoutSeconds, public array $targets) {}
}
