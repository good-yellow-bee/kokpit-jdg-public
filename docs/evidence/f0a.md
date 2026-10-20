# Dowód działania: f0a

| Pole | Wartość |
| --- | --- |
| Data, commit | 2026-10-20, `63c4c4a` |
| Przebieg CI; live | https://github.com/good-yellow-bee/kokpit-jdg-public/actions/runs/39381784623 (job `evidence`, artefakt `evidence`); https://github.com/good-yellow-bee/kokpit-jdg-public/actions/runs/39381784623 (job `live`, artefakt `live-evidence`) |
| Paczka `ci` | `20261020-0845-e90f4f`, sha256 `manifest.json` `0c5a2641868ff5028afb808eba6066a2a6ec2d746cfac29ad09c9b3185554795` |
| Paczka `live` | `20261020-0848-c9ea45`, sha256 `manifest.json` `212a4c3af0d2e07f1a8bb7e4b6f868fc9d37851443fcb10cbd3aab9979e107af`; cele `KSEF_TARGET`, `JPK_TARGET`, `EDEKLARACJE_TARGET` = `test`, dane fikcyjne |
| Paczka lokalna z `inbox/` | brak (wiersze [manual] otwarte) |

| Kryterium | Wynik | Artefakt |
| --- | --- | --- |
| `secret-store` | pass | junit.xml: `SecretStoreTest`, `ArchitectureTest` (5 testów); `cmd/secret-store.txt` sha256 `cba877ce2d50…` |
| `credential-register` | pass | `cmd/credential-register.txt` sha256 `aeff5810c2e3…` |
| `credential-smoke` | pass | `cmd/credential-smoke.txt` sha256 `10b82f569cff…` |
| `connector-call-log` | pass | junit.xml: `ClaudeTransportTest`, `ConnectorCallTest`, `NbpConnectorTest` (5 testów); `cmd/connector-call-log.txt` sha256 `fcdb9b8f868e…` |
| `failed-transport` | pass | junit.xml: `FailedTransportTest`, `RateDeferralTest` (3 testy); `cmd/failed-transport.txt` sha256 `1a4f4de1aaed…` |
| `file-store` | pass | junit.xml: `FileStoreTest` (2 testy); `cmd/file-store.txt` sha256 `6b3e96b6f63e…` |
| `rate-counter` | pass | junit.xml: `RateCounterTest`, `RateDeferralTest` (4 testy); `cmd/rate-counter.txt` sha256 `6927b2824871…` |
| `retention-purge` | pass | junit.xml: `RetentionPurgeTest` (1 test); `cmd/retention-purge.txt` sha256 `5b5147f3ccd4…` |
| `target-change` | pass | junit.xml: `MakefileTest`, `RecordDeployTest`, `TargetBannerTest` (3 testy); `cmd/target-change.txt` sha256 `45d9fe5e33fc…` |
| `health-checks` | pass | junit.xml: `HealthCheckTest` (12 testów); `cmd/health-checks.txt` sha256 `b06d7c74148d…` |
| `live-guard` | pass | junit.xml: `ClaudeLiveGuardTest`, `DeploymentTest`, `LiveGuardTest` (10 testów); `cmd/live-guard.txt` sha256 `3a5c890ebf9e…` |
| `scrubber` | pass | junit.xml: `ScrubberTest` (2 testy); `cmd/scrubber.txt` sha256 `6b305b94a22c…` |
| `ci-shape` | pass | junit.xml: `CiWorkflowTest` (1 test); `cmd/ci-shape.txt` sha256 `6fad84d08601…` |
| `evidence-pack` | pass | junit.xml: `EvidenceTest`, `ApprovalBuyerIdTest`, `LedgerManualEntryTest`, `LedgerPanelTest` +3 (19 testów); `cmd/evidence-pack.txt` sha256 `80d528b5042d…` |
| `cc-01` | pass | junit.xml: `ConnectorsDisabledTest` (2 testy); `cmd/cc-01.txt` sha256 `ceccd45cae20…` |
| `cc-02` | pass | junit.xml: `HostAllowlistTest` (6 testów); `cmd/cc-02.txt` sha256 `9c81dd5d1e69…` |
| `cc-06` | pass | junit.xml: `SessionLifetimeTest`, `ChatTurboFrameTest`, `PasskeyStepUpTest`, `PasskeyTest` (7 testów); `cmd/cc-06.txt` sha256 `558a93976c01…` |
| `cc-07` | pass | junit.xml: `RedactorTest`, `RedactorPropertyTest`, `RedactorPersistenceTest` (5 testów); `cmd/cc-07.txt` sha256 `6934fee06a68…` |
| `cc-08` | pass | junit.xml: `BackupScriptTest`, `AuditLogTest`, `CalendarFeedTest`, `LedgerManualEntryTest` +8 (14 testów); `cmd/cc-08.txt` sha256 `89bb3a227fcd…` |
| `cc-09` | pass | junit.xml: `ApprovalGateBypassTest`, `OfficialWritesTest` (2 testy); `cmd/cc-09.txt` sha256 `160655957b33…` |
| `cc-10` | pass | junit.xml: `BusinessDateTest`, `BusinessDatePropertyTest`, `SettingMinimalTest` (7 testów); `cmd/cc-10.txt` sha256 `b14a023019cf…` |
| `cc-11` | pass | junit.xml: `EvidenceTest`, `DiagnosticsTest`, `ScannerTest` (4 testy); `cmd/cc-11.txt` sha256 `a212d6fcf3c0…` |
| `cc-12` | pass | `cmd/cc-12.txt` sha256 `0531263ed55c…` |
| `cc-13` | pass | junit.xml: `ApprovalFa3ReviewProdTest`, `BackupScriptTest`, `BusinessDateTest`, `CalendarFeedTest` +222 (821 testów); `cmd/cc-13.txt` sha256 `785561dc0b71…` |
| `cc-15` | pass | junit.xml: `EvidenceTest`, `ApprovalBuyerIdTest`, `LedgerManualEntryTest`, `LedgerPanelTest` +3 (19 testów); `cmd/cc-15.txt` sha256 `e9e697b8975a…` |
| `cc-31` | pass | junit.xml: `ArchitectureTest` (3 testy); `cmd/cc-31.txt` sha256 `57ebb8f61745…` |
| `cc-03` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `cc-04` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `cc-05` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `cc-14` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `deploy-prod` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `vpn-only` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `passkey-enrol` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `passkey-reset` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `connectors-enabled` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `push-test` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `push-test-mail` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `emergency-card` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `ci-green` [manual] | [ ] pending | czeka na Ciebie: plik w `var/evidence/f0a/inbox/` albo odhaczony wpis |
| `live-targets` | pass | junit.xml: `EDeklaracjeTePit28Test`, `EDeklaracjeTeVatUeTest`, `EDeklaracjeTeVatUekTest`, `JpkTeDuplicateTest` +14 (24 testy); `cmd/live-targets.txt` sha256 `f57a19b38a43…` |

Otwarte [manual]: `cc-03`, `cc-04`, `cc-05`, `cc-14`, `deploy-prod`, `vpn-only`, `passkey-enrol`, `passkey-reset`, `connectors-enabled`, `push-test`, `push-test-mail`, `emergency-card`, `ci-green`.
