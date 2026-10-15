<?php

declare(strict_types=1);

namespace App\Tests\Live;

use App\Tests\Integration\JpkSendTestCase;
use App\Tests\Support\JpkSendTestTools;

/** Baza F5.3 (live): fikcyjny zbiór 2026 (`JpkSendTestTools`) z prawdziwym klientem na test-e-dokumenty (`JpkLiveRun`, D-69). */
abstract class JpkLiveTestCase extends JpkSendTestCase
{
    use JpkLiveRun;

    protected function liveSend(): JpkSendTestTools
    {
        return $this->send(live: self::liveClient());
    }
}
