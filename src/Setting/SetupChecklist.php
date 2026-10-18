<?php

declare(strict_types=1);

namespace App\Setting;

use App\Audit\AuditLog;
use App\Config\ConnectorConfiguration;
use App\Connector\Mail\MailNotifier;
use App\Connector\Push\PushNotifier;
use App\Http\ConnectorException;
use App\Passkey\PasskeyManager;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Kreator pierwszego uruchomienia (D-91): lista kroków od pustej bazy do działającego kokpitu, ze stanem z `setting`,
 * `credential_state`, `webauthn_credential` i `audit_log`. Zapis zostaje w istniejących formularzach i komendach;
 * kreator sam wysyła tylko test powiadomień.
 */
final readonly class SetupChecklist
{
    /** Pola `company.identity` potrzebne w `Podmiot1` JPK; część FA(3) dochodzi w F3. */
    public const IDENTITY_REQUIRED = ['nip','tax_office_code','first_name','last_name','email'];
    /** Poświadczenia do sprawdzenia w środowisku wdrożenia, jak rejestruje je `app:credential:register`. */
    public const CREDENTIALS = ['ksef_token_read','auth_data','freshbooks_oauth'];
    public const TEST_ACTION = 'notification.test_sent';

    public function __construct(private Connection $db, private SettingStore $settings, private PasskeyManager $passkeys, private ConnectorConfiguration $configuration, private BusinessDate $businessDate, private PushNotifier $push, private MailNotifier $mail, private AuditLog $audit) {}

    /**
     * @return list<array{key:string,label:string,done:bool,detail:string,route:string,anchor:?string}>
     */
    public function steps(?string $userId): array
    {
        $steps = [];
        $active = $userId === null ? 0 : count($this->passkeys->active($userId));
        $steps[] = self::step('passkeys', 'Dwa passkeye na dwóch urządzeniach', $active >= 2, sprintf('aktywne: %d', $active), 'panel_passkeys', null);
        $identity = $this->settings->current(SettingKeys::COMPANY_IDENTITY);
        $value = is_array($identity['value'] ?? null) ? $identity['value'] : [];
        $missing = array_values(array_filter(self::IDENTITY_REQUIRED, static fn(string $field): bool => !is_string($value[$field] ?? null) || $value[$field] === ''));
        $steps[] = self::step('company_identity', 'Dane podatnika (company.identity)', $identity !== null && $missing === [], $identity === null ? 'brak wiersza' : ($missing === [] ? 'od ' . $identity['valid_from'] : 'brakuje: ' . implode(', ', $missing)), 'panel_settings', 'company-identity');
        foreach ([SettingKeys::TAX_MICRO_ACCOUNT => 'Mikrorachunek podatkowy', SettingKeys::ZUS_ACCOUNT => 'Rachunek składkowy ZUS', SettingKeys::ZUS_VARIANT => 'Wariant składek ZUS', SettingKeys::ADVANCE_FREQUENCY => 'Częstotliwość zaliczek ryczałtu', SettingKeys::RYCZALT_SERVICE_MAP => 'Mapa usług na stawki ryczałtu', SettingKeys::KSEF_SALES_TRANSITION_ON => 'Dzień przejścia sprzedaży na KSeF'] as $key => $label) {
            $row = $this->settings->current($key);
            $steps[] = self::step(str_replace('.', '_', $key), sprintf('%s (%s)', $label, $key), $row !== null, $row === null ? 'brak wiersza' : 'od ' . $row['valid_from'] . ($row['set_by'] === null ? ', wartość domyślna: sprawdź' : ''), 'panel_settings', str_replace('.', '-', $key));
        }
        $year = substr($this->businessDate->today(), 0, 4);
        $zus = $this->settings->current(SettingKeys::ZUS_PARAMETERS);
        $steps[] = self::step('zus_parameters', sprintf('Parametry ZUS na rok %s (zus.parameters)', $year), $zus !== null && str_starts_with($zus['valid_from'], $year), $zus === null ? 'brak wiersza' : 'od ' . $zus['valid_from'], 'panel_settings', 'zus-parameters');
        $opening = $this->settings->openingConfirmed();
        $steps[] = self::step('opening_balance', 'Bilans otwarcia potwierdzony', $opening, $opening ? 'potwierdzony' : 'niepotwierdzony', 'panel_opening', null);
        foreach (self::CREDENTIALS as $kind) {
            $steps[] = $this->credential($kind);
        }
        $last = $this->db->fetchOne('SELECT occurred_at FROM audit_log WHERE action=? AND environment=? ORDER BY sequence DESC LIMIT 1', [self::TEST_ACTION, $this->configuration->environment()]);
        $steps[] = self::step('notification_test', 'Test powiadomień push i e-mail', is_string($last), is_string($last) ? 'ostatni: ' . $last : 'jeszcze nie wysłany', 'panel_setup', 'setup-notification-test');
        return $steps;
    }

    public function pending(?string $userId): int
    {
        return count(array_filter($this->steps($userId), static fn(array $step): bool => !$step['done']));
    }

    /**
     * Test kanałów jak `app:push:test`: przy wyłączonych konektorach nic nie wychodzi. Udany test zostaje w `audit_log`.
     *
     * @return ?string błąd do pokazania albo null po wysłaniu
     */
    public function sendTest(?string $userId): ?string
    {
        if (!$this->configuration->enabled) {
            return 'Konektory są wyłączone (CONNECTORS_ENABLED=0); test nie został wysłany.';
        }
        try {
            $this->push->send('push_test', 1, '/setup');
            $this->mail->send('push_test', 1, '/setup');
        } catch (ConnectorException|TransportExceptionInterface $exception) {
            return 'Test nie doszedł: ' . ($exception instanceof ConnectorException ? $exception->error->value : 'błąd SMTP') . '. Szczegóły w panelu „Konektory”.';
        }
        $this->audit->append(self::TEST_ACTION, ['channels' => ['push','mail']], $this->configuration->environment(), actorUserId: $userId);
        return null;
    }

    /** @return array{key:string,label:string,done:bool,detail:string,route:string,anchor:?string} */
    private function credential(string $kind): array
    {
        $environment = $this->configuration->environment();
        $row = $this->db->fetchAssociative("SELECT status, to_char(valid_until AT TIME ZONE 'Europe/Warsaw', 'YYYY-MM-DD') AS valid_until, to_char(last_smoke_ok_at AT TIME ZONE 'Europe/Warsaw', 'YYYY-MM-DD') AS smoke FROM credential_state WHERE environment=? AND kind=?", [$environment, $kind]);
        $status = is_array($row) && is_string($row['status']) ? $row['status'] : null;
        $detail = $status === null ? 'nie zarejestrowane' : $status . (is_string($row['valid_until'] ?? null) ? ', ważne do ' . $row['valid_until'] : '') . (is_string($row['smoke'] ?? null) ? ', test ' . $row['smoke'] : '');
        $done = in_array($status, ['valid','expiring'], true);
        return $kind === 'freshbooks_oauth'
            ? self::step('credential_' . $kind, sprintf('Połączenie FreshBooks (%s, %s)', $kind, $environment), $done, $detail, 'panel_freshbooks', null)
            : self::step('credential_' . $kind, sprintf('Poświadczenie %s (%s)', $kind, $environment), $done, $detail . ($done ? '' : sprintf('; zarejestruj: bin/console app:credential:register %s', $kind)), 'panel_connectors', null);
    }

    /** @return array{key:string,label:string,done:bool,detail:string,route:string,anchor:?string} */
    private static function step(string $key, string $label, bool $done, string $detail, string $route, ?string $anchor): array
    {
        return ['key' => $key,'label' => $label,'done' => $done,'detail' => $detail,'route' => $route,'anchor' => $anchor];
    }
}
