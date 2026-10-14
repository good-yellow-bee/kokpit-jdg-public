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
}
