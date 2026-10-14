<?php

declare(strict_types=1);

namespace App\Connector\Jpk;

/**
 * Adres Put Blob jednej części z `RequestToUploadFileList`. `url` niesie token SAS magazynu w zapytaniu, więc nie
 * trafia do logów; `connector_call` zapisuje tylko host i ścieżkę.
 */
final readonly class JpkUploadTarget
{
    /** @param array<string,string> $headers nagłówki z `HeaderList` */
    public function __construct(public string $blobName, public string $fileName, public string $url, public string $method, public array $headers) {}
}
