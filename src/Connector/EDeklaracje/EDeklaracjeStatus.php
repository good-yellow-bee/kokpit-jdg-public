<?php

declare(strict_types=1);

namespace App\Connector\EDeklaracje;

/** Odpowiedź `requestUPO`: status, opis i UPO przy 200. */
final readonly class EDeklaracjeStatus
{
    public function __construct(public int $status, public string $description, public ?string $upo) {}

    /** Dalsze odpytywanie: dokument w toku albo prośba o ponowny odczyt (100–102), bez ponownej wysyłki. */
    public function pending(): bool
    {
        return in_array($this->status, EDeklaracjeError::IN_PROGRESS, true) || in_array($this->status, EDeklaracjeError::READ_AGAIN, true);
    }
}
