<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Bank\BankLineMatcher;
use App\Bank\BankStatementParser;
use App\Bank\BankStatements;
use App\Bank\MbankCsvParser;
use App\Bank\ParsedStatement;
use App\Fixtures\FictionalIdentifiers;
use App\Security\Redactor;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;

/** Import wyciągu (D-108) na fikcyjnej liście operacji mBanku. */
final class BankTestTools
{
    /** Fikcyjna lista operacji; znaczniki `{account:N}` stają się fikcyjnymi NRB (skaner fixtures pada na 26 cyfrach). */
    public static function fixture(): string
    {
        $bytes = file_get_contents(dirname(__DIR__) . '/Fixtures/Bank/mbank-lista-operacji.csv');
        if ($bytes === false) {
            throw new \RuntimeException('Brak fixture wyciągu.');
        }
        return (string) preg_replace_callback('/\{account:([0-9]+)\}/', static fn(array $match): string => FictionalIdentifiers::account((int) $match[1]), $bytes);
    }

    public static function matcher(Connection $db, MockClock $clock): BankLineMatcher
    {
        return new BankLineMatcher($db, ObligationTestTools::settings($db, $clock), ConnectorTestTools::configuration(false));
    }

    public static function statements(Connection $db, MockClock $clock): BankStatements
    {
        return new BankStatements($db, $clock, new BusinessDate($clock), F1TestTools::files($db, $clock), new AuditLog($db, $clock, new Redactor()), ConnectorTestTools::configuration(false), self::matcher($db, $clock), ObligationTestTools::actions($db, $clock), [new MbankCsvParser(), self::otherBank()]);
    }

    /** Drugi czytnik w rejestrze: nic nie rozpoznaje, więc nie może nadpisać wybranego czytnika. */
    private static function otherBank(): BankStatementParser
    {
        return new class implements BankStatementParser {
            public function format(): string
            {
                return 'other_bank';
            }

            public function supports(string $bytes): bool
            {
                return false;
            }

            public function parse(string $bytes): ParsedStatement
            {
                throw new \LogicException('Czytnik testowy nie czyta plików.');
            }
        };
    }
}
