<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\AuditLog;
use App\Calendar\NotificationDispatcher;
use App\Calendar\ObligationActions;
use App\Calendar\ObligationEngine;
use App\Config\ConnectorHosts;
use App\Connector\DeadMan\DeadManNotifier;
use App\Connector\Mail\GuardedMailTransport;
use App\Connector\Mail\MailNotifier;
use App\Connector\Push\PushNotifier;
use App\Http\ConnectorCallRecorder;
use App\Http\HostAllowlist;
use App\Rate\RateCounter;
use App\Security\SecretStore;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use App\Calendar\PolishCalendar;
use App\Fixtures\FictionalIdentifiers;
use App\Security\Redactor;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;

/** Silnik obowiązków na danych fikcyjnych. Kwoty ZUS są fikcyjne poza progami zdrowotnej 2026 z docs/tax-rules.md. */
final class ObligationTestTools
{
    public static function settings(Connection $db, ClockInterface $clock): SettingStore
    {
        return new SettingStore($db, $clock, new BusinessDate($clock), new AuditLog($db, $clock, new Redactor()), ConnectorTestTools::configuration(false));
    }
    public static function engine(Connection $db, ClockInterface $clock): ObligationEngine
    {
        return new ObligationEngine($db, new PolishCalendar(), self::settings($db, $clock), new BusinessDate($clock), $clock, ConnectorTestTools::configuration(false), self::tax($db, $clock));
    }
    /** @param array<string,array<string,string>> $rates */
    public static function tax(Connection $db, ClockInterface $clock, array $rates = []): \App\Tax\TaxCalculator
    {
        return new \App\Tax\TaxCalculator($db, self::settings($db, $clock), new \App\Fx\FxRateProvider($db, new PolishCalendar(), new BusinessDate($clock), $clock, F1TestTools::rates($rates)), new PolishCalendar(), ConnectorTestTools::configuration(false));
    }
    public static function actions(Connection $db, ClockInterface $clock): ObligationActions
    {
        return new ObligationActions($db, new AuditLog($db, $clock, new Redactor()), $clock, new BusinessDate($clock), ConnectorTestTools::configuration(false));
    }
    /** Dispatcher z push przez `MockHttpClient` i mailem w pamięci; konektory włączone tylko w procesie testu. */
    public static function dispatcher(Connection $db, MockClock $clock, MockHttpClient $push, MemoryMailTransport $mail, SecretStore $secrets): NotificationDispatcher
    {
        $configuration = ConnectorTestTools::configuration();
        $dates = new BusinessDate($clock);
        $http = ConnectorTestTools::http($db, $clock, $push);
        $transport = new GuardedMailTransport($configuration, $secrets, new HostAllowlist(ConnectorHosts::HOSTS, $configuration, new MockHttpClient()), new ConnectorCallRecorder($db, $clock), new RateCounter($db, $clock, $dates, []), $clock, $dates, $mail);
        return new NotificationDispatcher($db, new PushNotifier($http, $secrets, $configuration), new MailNotifier($transport, 'kokpit@example.invalid', 'owner@example.invalid'), new DeadManNotifier($http, $secrets, $configuration), $configuration, $dates, $clock, new Redactor());
    }
    /** @return array<string,mixed> */
    public static function zusParameters(string $social, string $other, int $bracket = 2): array
    {
        return ['social_base_pln' => '5000.00','social_pln' => $social,'other_pln' => $other,'health_bracket_manual' => $bracket,'health_brackets' => [
            ['revenue_up_to_pln' => '60000.00','base_pln' => '5537.18','amount_pln' => '498.35'],
            ['revenue_up_to_pln' => '300000.00','base_pln' => '9228.64','amount_pln' => '830.58'],
            ['revenue_up_to_pln' => null,'base_pln' => '16611.55','amount_pln' => '1495.04'],
        ]];
    }
    /** Rachunki fikcyjne, `zus.parameters` 2026 i `jpk_st.enabled` = false; wartości domyślne wstawia migracja. */
    public static function seed(Connection $db, ClockInterface $clock): void
    {
        $settings = self::settings($db, $clock);
        $settings->set('company.tax_micro_account', FictionalIdentifiers::account(1), '2026-01-01', null, 'fictional');
        $settings->set('company.zus_account', FictionalIdentifiers::account(2), '2026-01-01', null, 'fictional');
        $settings->set('zus.parameters', self::zusParameters('1500.00', '100.00'), '2026-01-01', null, 'fictional');
        $settings->set('jpk_st.enabled', false, '2026-01-01', null, 'fictional');
    }
    /** @return array<string,array<string,mixed>> wiersze `obligation` po `dedupe_key` */
    public static function rows(Connection $db): array
    {
        $result = [];
        foreach ($db->fetchAllAssociative('SELECT * FROM obligation ORDER BY due_date, dedupe_key') as $row) {
            if (!is_string($row['dedupe_key'])) {
                throw new \LogicException('Brak dedupe_key.');
            }
            $result[$row['dedupe_key']] = $row;
        }
        return $result;
    }
}
