<?php

namespace App\Tests\Unit;

use App\Security\Redactor;
use Monolog\Logger;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\TestCase;

final class RedactorTest extends TestCase
{
    public function testAllLogChannelsAndNestedValues(): void
    {
        $redactor = new Redactor();
        $redactor->registerSecret('fictional-secret-value');
        $handler = new TestHandler();
        $log = new Logger('fictional', [$handler], [$redactor]);
        $log->error('fictional-secret-value', ['nested' => ['password' => 'fictional-secret-value'],'exception' => new \RuntimeException('fictional-secret-value'),'pesel' => '11111111116']);
        $serialized = json_encode($handler->getRecords(), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('fictional-secret-value', $serialized);
        self::assertStringNotContainsString('11111111116', $serialized);
        self::assertStringContainsString('REDACTED', $serialized);
    }
    public function testHeadersAndPatterns(): void
    {
        $redactor = new Redactor();
        $value = $redactor->redact(['Authorization' => 'fictional-secret','cookie' => 'fictional-cookie','nested' => ['iban' => 'PL00111111111111111111111111'],'text' => '11111111116 eyJmb28iOiJiYXIifQ.eyJmb28iOiJiYXIifQ.signature']);
        self::assertIsArray($value);
        self::assertSame('[REDACTED]', $value['Authorization']);
        self::assertStringNotContainsString('11111111116', json_encode($value, JSON_THROW_ON_ERROR));
    }
    public function testPeselPatternLeavesUuidDigitsIntact(): void
    {
        $redactor = new Redactor();
        // Fikcyjne identyfikatory: ostatnia grupa UUID z 11 cyframi obok litery nie jest PESEL-em.
        $uuids = ['01a1c4af-8680-7003-a444-a36971627276','01a1c4af-8680-7003-a444-36971627276a'];
        self::assertSame($uuids, $redactor->redact($uuids));
        self::assertSame(['filing_id' => $uuids[0]], $redactor->redact(['filing_id' => $uuids[0]]));
        self::assertSame('PESEL [REDACTED], (fikcyjny [REDACTED])', $redactor->text('PESEL 11111111116, (fikcyjny 22222222222)'));
    }
}
