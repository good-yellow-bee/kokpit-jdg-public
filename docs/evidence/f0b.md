# Dowód działania: f0b

| Pole | Wartość |
| --- | --- |
| Data, commit | 2026-10-20, `d55e20d` |
| Przebieg CI; live | https://github.com/good-yellow-bee/kokpit-jdg-public/actions/runs/39385540766 (job `evidence`, artefakt `evidence`); brak kryteriów live |
| Paczka `ci` | `20261020-0923-49c698`, sha256 `manifest.json` `299a3c014fb94c07448017fca930348ff4cc46e37e315d7d15fc0e061e399bb8` |
| Paczka lokalna z `inbox/` | brak (wiersze [manual] otwarte) |

| Kryterium | Wynik | Artefakt |
| --- | --- | --- |
| `previous-working-day` | pass | junit.xml: `PolishCalendarGoldenTest`, `PolishCalendarTest`, `PolishCalendarPropertyTest` (47 testów); `cmd/previous-working-day.txt` sha256 `ba6f5e9bf7fd…` |
| `calendar-golden` | pass | junit.xml: `PolishCalendarGoldenTest` (38 testów); `cmd/calendar-golden.txt` sha256 `7ac1c89ca32e…` |
| `calendar-property` | pass | junit.xml: `PolishCalendarPropertyTest` (4 testy); `cmd/calendar-property.txt` sha256 `6fba7fd22fbf…` |
| `obligation-engine` | pass | junit.xml: `ObligationEngineTest` (6 testów); `cmd/obligation-engine.txt` sha256 `d6775252fab9…`; `db/obligation-engine.csv` sha256 `9edc31d41564…` |
| `zus-amount` | pass | junit.xml: `ObligationEngineTest` (2 testy); `cmd/zus-amount.txt` sha256 `1dffc0640673…` |
| `advance-title` | pass | junit.xml: `ObligationEngineTest` (1 test); `cmd/advance-title.txt` sha256 `c6616d8fe466…` |
| `channel-check` | pass | junit.xml: `ManualChannelCheckTest` (1 test); `cmd/channel-check.txt` sha256 `5d808f9f6270…` |
| `reminder-dedupe` | pass | junit.xml: `NotificationDispatchTest` (2 testy); `cmd/reminder-dedupe.txt` sha256 `e000c8196643…`; `db/reminder-dedupe-notification.csv` sha256 `2408d26792bd…` |
| `obligation-confirm` | pass | junit.xml: `ObligationConfirmTest` (2 testy); `cmd/obligation-confirm.txt` sha256 `30536cde88a9…` |
| `credential-expiry` | pass | junit.xml: `CredentialExpiryTest` (1 test); `cmd/credential-expiry.txt` sha256 `ae0e6bd7e26c…` |
| `letter-deadline` | pass | junit.xml: `LetterDeadlineTest` (1 test); `cmd/letter-deadline.txt` sha256 `a01fcc15d7d6…`; `db/letter-deadline-obligation.csv` sha256 `a9999cc3ed90…` |
| `setting-change` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |
| `zus-payment` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |
| `passkey-step-up` | pass | junit.xml: `PasskeyStepUpTest` (2 testy); `cmd/passkey-step-up.txt` sha256 `667007dd1dda…`; `db/passkey-step-up-audit_log.csv` sha256 `7fa87620c91f…` |
| `gate-happy-path` | pass | junit.xml: `ApprovalGateTest` (1 test); `cmd/gate-happy-path.txt` sha256 `b6aff1bea360…`; `db/gate-happy-path-audit.csv` sha256 `3c3780773284…` |
| `gate-transitions` | pass | junit.xml: `ApprovalGateTransitionsTest` (102 testy); `cmd/gate-transitions.txt` sha256 `401f1f2a95be…` |
| `gate-supersede` | pass | junit.xml: `ApprovalGateTest` (1 test); `cmd/gate-supersede.txt` sha256 `1d60c7702ed9…` |
| `gate-timeout` | pass | junit.xml: `ApprovalGateTest` (2 testy); `cmd/gate-timeout.txt` sha256 `ac1658818286…` |
| `gate-bypass` | pass | junit.xml: `ApprovalGateBypassTest` (3 testy); `cmd/gate-bypass.txt` sha256 `d35f9def7f9a…` |
| `failed-retry` | pass | junit.xml: `FailedRetryTest` (1 test); `cmd/failed-retry.txt` sha256 `1645755f7e02…` |
| `payload-immutable` | pass | junit.xml: `ApprovalGateTest` (1 test); `cmd/payload-immutable.txt` sha256 `d96fc383c2ee…` |
| `gate-panel` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |
| `mutation-report` [manual] | [x] pass, 2026-10-21, Ty | https://github.com/good-yellow-bee/kokpit-jdg-public/actions/runs/39419058617, artefakt `mutation` (`mutation.json`): 4665 mutantów, 4379 zabitych, 221 ocalałych, 62 ignorowane, 3 timeouty, 0 błędów; MSI i covered MSI 95,2% przy progach 85/95, pokrycie 100%; `ApprovalGate` 131 mutantów, 0 ocalałych; `PolishCalendar` 151 mutantów, 9 ocalałych na liście zadań w [../testing.md](../testing.md#testy-mutacyjne) |
| `ci-green` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |
| `cc-01` | pass | junit.xml: `ConnectorsDisabledTest` (2 testy); `cmd/cc-01.txt` sha256 `5efa4687b980…` |
| `cc-02` | pass | junit.xml: `HostAllowlistTest` (6 testów); `cmd/cc-02.txt` sha256 `9c81dd5d1e69…` |
| `cc-06` | pass | junit.xml: `SessionLifetimeTest`, `ChatTurboFrameTest`, `PasskeyStepUpTest`, `PasskeyTest` (7 testów); `cmd/cc-06.txt` sha256 `1f9836e29ea1…` |
| `cc-07` | pass | junit.xml: `RedactorTest`, `RedactorPropertyTest`, `RedactorPersistenceTest` (5 testów); `cmd/cc-07.txt` sha256 `aa15d8d1f5a8…` |
| `cc-08` | pass | junit.xml: `BackupScriptTest`, `AuditLogTest`, `CalendarFeedTest`, `LedgerManualEntryTest` +8 (14 testów); `cmd/cc-08.txt` sha256 `72d12f5ac630…` |
| `cc-09` | pass | junit.xml: `ApprovalGateBypassTest`, `OfficialWritesTest` (2 testy); `cmd/cc-09.txt` sha256 `2e12a826b865…` |
| `cc-10` | pass | junit.xml: `BusinessDateTest`, `BusinessDatePropertyTest`, `SettingMinimalTest` (7 testów); `cmd/cc-10.txt` sha256 `0278c36eac0d…` |
| `cc-11` | pass | junit.xml: `EvidenceTest`, `DiagnosticsTest`, `ScannerTest` (4 testy); `cmd/cc-11.txt` sha256 `7a3349a10f33…` |
| `cc-12` | pass | `cmd/cc-12.txt` sha256 `0531263ed55c…` |
| `cc-13` | pass | junit.xml: `ApprovalFa3ReviewProdTest`, `BackupScriptTest`, `BusinessDateTest`, `CalendarFeedTest` +222 (821 testów); `cmd/cc-13.txt` sha256 `b190ded16b74…` |
| `cc-15` | pass | junit.xml: `EvidenceTest`, `ApprovalBuyerIdTest`, `LedgerManualEntryTest`, `LedgerPanelTest` +3 (19 testów); `cmd/cc-15.txt` sha256 `5ac4940acd24…` |
| `cc-16` | pass | junit.xml: `ApprovalGateTest`, `PasskeyStepUpTest` (3 testy); `cmd/cc-16.txt` sha256 `3b6fdf20bdc6…` |
| `cc-17` | pass | junit.xml: `ApprovalGateTest` (1 test); `cmd/cc-17.txt` sha256 `595b926ba8f2…` |
| `cc-18` | pass | junit.xml: `ApprovalGateTest` (1 test); `cmd/cc-18.txt` sha256 `34967b7ca7cd…` |
| `cc-19` | pass | junit.xml: `ApprovalGateTest` (1 test); `cmd/cc-19.txt` sha256 `7b3c3496b1f8…` |
| `cc-20` | pass | junit.xml: `ArchitectureTest` (3 testy); `cmd/cc-20.txt` sha256 `a13758380fd1…` |
| `cc-21` | pass | junit.xml: `ApprovalGateTest`, `FailedRetryTest` (2 testy); `cmd/cc-21.txt` sha256 `d6254f67a98a…` |
| `cc-22` | pass | junit.xml: `PolishCalendarGoldenTest`, `PolishCalendarTest`, `PolishCalendarPropertyTest` (47 testów); `cmd/cc-22.txt` sha256 `3b0adc4ec5e6…` |
| `cc-31` | pass | junit.xml: `ArchitectureTest` (3 testy); `cmd/cc-31.txt` sha256 `13f75763edc4…` |
| `cc-03` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |
| `cc-04` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |
| `cc-05` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |
| `cc-14` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0b/inbox/` albo odhaczony wpis |

Kryteria przekrojowe spoza rejestru `tests/Acceptance/f0b.yaml` (zakres z pliku fazy):

| Kryterium | Wynik | Artefakt |
| --- | --- | --- |
| `cc-23` | pass | job `mutation` w https://github.com/good-yellow-bee/kokpit-jdg-public/actions/runs/39419058617 (commit `c29d422`, artefakt `mutation`): `mutation.json` sha256 `83dfb6402c69…`; 4665 mutantów, 221 ocalałych, 62 ignorowane (D-105), MSI i covered MSI 95,20% przy progach 85/95 (D-104, D-105) |

Otwarte [manual]: `setting-change`, `zus-payment`, `gate-panel`, `ci-green`, `cc-03`, `cc-04`, `cc-05`, `cc-14`.
