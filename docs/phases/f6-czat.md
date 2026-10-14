# F6 czat

Czat z Claude Haiku 5.5 w panelu: pytania o dane na nazwanych zapytaniach tylko do odczytu, wykresy rysowane z bazy i szkice `draft_document` tylko do kopiowania. Stan na 14 października 2026.

## Cel

Po F6 pytasz w panelu o dane kokpitu („ile przychodu w EUR w Q3?”) i dostajesz odpowiedź z liczbami z nazwanych zapytań. Wykres rysuje panel z bazy, a szkic maila do kontrahenta powstaje tylko na Twoją prośbę i tylko do skopiowania. Model rozumie pytanie, wybiera narzędzie i opisuje wynik; kwoty, podatki, pliki dla urzędu i wysyłki zostają w deterministycznym kodzie („Zasada podziału” w [../modules/czat.md](../modules/czat.md)).

Według D-30 F6 jest ostatnią fazą planu. Model to tylko `claude-haiku-5-5` z jawnym `chat.effort`, bez eskalacji do Sonnet 5.5 (D-10, D-29). Pętla narzędzi działa w Symfony na Messages API i PHP tool runnerze (D-11). Odpowiedź powstaje w synchronicznym żądaniu z ramką Turbo, bez Mercure, workera i transportu czatu (D-29); licznik wywołań to `claude.messages` (D-34). F0b daje bramkę zatwierdzeń i step-up, a F1, F2, F3 i F5 dają dane, które czytają narzędzia.

F6 dzieli się na pięć części. F6.1–F6.4 powstają offline: na ręcznie pisanych odpowiedziach Messages API z danymi fikcyjnymi w `MockHttpClient`, na PostgreSQL z CI i na fikcyjnym zbiorze danych (D-58). Tylko F6.5 (live) woła Claude API: testsuite `live`, dane fikcyjne, budżet przebiegu w USD.

## Wejścia

Brak wejścia oznaczonego „stop” zatrzymuje część i kończy się pytaniem. Pozostałe idą na założeniu domyślnym z [../decisions.md](../decisions.md).

| Wejście | Część | Źródło | Brak oznacza |
| --- | --- | --- | --- |
| F0b, F1, F2, F3 i F5 odebrane (D-30) | wszystkie | pliki faz w `docs/phases` | stop dla odbioru F6; kod F6.1–F6.4 przed odbiorem według D-58 |
| tabele źródłowe narzędzi na `main`: `obligation`, `invoice`, `revenue_entry`, `fx_rate`, `counterparty`, `verification`, `approval`, `stored_file` | F6.1, F6.4 | kod F0b, F1, F2 i F3 | stop dla narzędzia, którego tabela nie istnieje; pozostałe idą dalej |
| fakty F6 w [../facts.md](../facts.md) z „Następnym sprawdzeniem” = krok 0 F6 (profil Haiku 5.5, cennik, PHP SDK, AI Act) | wszystkie | [../facts.md](../facts.md) | sesja F6.1 sprawdza źródła jednorazowym odczytem dokumentacji i aktualizuje daty; zmiana profilu żądań albo ceny → stop i nowy rekord D-xx |
| `anthropic-ai/sdk` w dokładnej wersji 0.x: wstrzyknięcie klienta PSR-18 jako `transporter` i opcja wyłączenia ponowień | F6.2 | [../architecture.md](../architecture.md) („`HostAllowlist`”), „Architektura” w [../modules/czat.md](../modules/czat.md) | sesja potwierdza nazwy z kodu SDK w `vendor`; brak którejkolwiek opcji → stop, bo bez niej allowlista albo zakaz ponowień nie działa |
| `symfony/ux-chartjs` na liście dozwolonych licencji | F6.3 | [../architecture.md](../architecture.md) („Komponenty”: panel) | założenie domyślne: MIT, bez wyjątku licencji |
| rola `chat_ro` z hasłem w sejfie `dev` i fikcyjnym hasłem w CI | F6.1 | [../security.md](../security.md) („Role PostgreSQL”, wiersz haseł ról w „Inwentarzu sekretów”) | tworzy sesja F6.1 w `docker/db/init-db.sh`; PROD przy go-live |
| budżety czatu | F6.2 | „Budżet i koszt” w [../modules/czat.md](../modules/czat.md) | założenie domyślne: $1 dziennie, $10 miesięcznie, alert przy 80 % |
| region i podstawa przetwarzania | go-live | Q-33, D-22 | założenie domyślne: Claude API bezpośrednio na DPA z SCC, art. 6 ust. 1 lit. f RODO |
| wykonawca szkiców | F6.4 | Q-35, D-22 | założenie domyślne: tylko kopiowanie; kokpit niczego ze szkicu nie wysyła |
| treść pism w czacie | – | Q-34 | poza F6; wraca po powrocie F4 (D-29) |
| klucz Claude API osobnego workspace testowego z niskim limitem wydatków w sejfie testowym i w sekrecie środowiska GitHub joba `live` | F6.5 | „Warunki wstępne” w [../README.md](../README.md) | stop tylko dla F6.5; F6.1–F6.4 idą dalej bez klucza |
| budżet przebiegu live `chat.live_run_budget_usd` | F6.5 | „`@group live`” w [../testing.md](../testing.md) | założenie domyślne: $0.25 na przebieg |
| klucz PROD z limitem wydatków i zaakceptowane DPA z SCC | go-live | [../operations.md](../operations.md) („Go-live”, wiersz czat) | go-live czeka; odbioru F6.1–F6.5 nie blokuje |

Odpowiedzi Messages API dla części offline piszesz ręcznie według dokumentacji API ([PHP SDK](https://platform.claude.com/docs/en/cli-sdks-libraries/sdks/php), [przewodnik migracji Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide)): bloki `thinking` z podpisem, `tool_use`, `text`, `usage` z tokenami cache, `refusal` z `stop_details`, 429 z `Retry-After`. Każdy plik zawiera wyłącznie dane fikcyjne, oznaczone jako fikcyjne, i przechodzi przez skaner fixtures. F6.5 nagrywa scenariusze `app:fixtures:record claude <scenariusz>` ręcznie, poza CI, i zastępuje nimi wersje ręczne („Nagrania i odtwarzanie” w [../testing.md](../testing.md)).

## Ładuj tylko

Ta lista jest autorytatywna. Kolumna „Części” mówi, której części dotyczy wiersz. [../../.claude/rules/zasady-twarde.md](../../.claude/rules/zasady-twarde.md) ładuje się sam, a [../../.claude/rules/integrations.md](../../.claude/rules/integrations.md) sam przy edycji konektorów i fixtures.

| Plik | Sekcje | Części |
| --- | --- | --- |
| [../README.md](../README.md) | cały (start sesji), zwłaszcza „Odłożone” i „Warunki wstępne” | wszystkie |
| [../naming.md](../naming.md) | cały, z „Planem F6 (D-58)” i „Nazwami wycofanymi” | wszystkie |
| [../decisions.md](../decisions.md) | wiersze z F6 w kolumnie „Potrzebne w”; D-10, D-11, D-22, D-29, D-30, D-34, D-41, D-58; Q-33, Q-34, Q-35 | wszystkie |
| [../facts.md](../facts.md) | fakty oznaczone dla F6; R-38, R-39, R-40, R-41 | wszystkie |
| [../modules/czat.md](../modules/czat.md) | „Zasada podziału”, „Dostęp do bazy”, „Dane do modelu”, „Narzędzia” | F6.1 |
| [../modules/czat.md](../modules/czat.md) | „Architektura”, „Narzędzia”, „Dane do modelu”, „Profil żądań Haiku 5.5”, „Historia rozmowy”, „Odpowiedź i odmowy”, „Budżet i koszt”, „Wiedza podatkowa” | F6.2, F6.5 |
| [../modules/czat.md](../modules/czat.md) | „Architektura” (żądanie, stan ładowania, idempotencja, limit czasu), „Narzędzia” (`render_chart`), „Oznaczenie AI” | F6.3 |
| [../modules/czat.md](../modules/czat.md) | „Szkice” ze stanami `draft.status` | F6.4 |
| [../data-model.md](../data-model.md) | „Jak czytać”, „Konwencje”, „Kwoty i zaokrąglenia”, „Daty i strefa czasu”, „Środowiska”; tabele `chat_session`, `chat_message`, `draft`, `approval`, `stored_file`, `connector_call`, `rate_counter`, `notification`, `setting` (klucz `chat.law_snapshot`) i tabele źródłowe narzędzi; „Stany encji”: „Mapa stanów” i „Powiązanie z bramką zatwierdzeń” | wszystkie |
| [../security.md](../security.md) | „Maskowanie: `Redactor`”, „Role PostgreSQL”, wiersze klucza Claude API i haseł ról w „Inwentarzu sekretów”, wiersz czatu w „Retencji”, „Panel” (passkey, CSRF, step-up), „RODO” (procesorzy) | wszystkie |
| [../architecture.md](../architecture.md) | „Konektory” (`claude`), „`HostAllowlist`” (transporter SDK), „Liczniki limitów”, „Biblioteki” | F6.2, F6.5 |
| [../approval-gate.md](../approval-gate.md) | cały, bez „Odbierz”; wiersze `draft` | F6.4 |
| [../operations.md](../operations.md) | „Messenger” (czat poza Messenger), „Katalog zadań” (`retention.purge`), wiersz czat w „Go-live” | F6.1, F6.3, go-live |
| [../testing.md](../testing.md) | „Polityka per konektor”, „Nagrania i odtwarzanie”, „`@group live`”, „Testy bezpieczeństwa”, „Dowód działania”, „Konwencje kryteriów odbioru”, „Kryteria przekrojowe” | wszystkie |

API komponentów F0b–F5 czytasz z kodu (`src/Approval` z `ApprovalSubject` i atrapą `dummy.subject.draft`, `src/Ledger/FilingSubject` jako wzór przedmiotu bramki, `src/Http/ConnectorHttpClient`, `src/Rate/RateCounter`, `src/Security/Redactor`) oraz z list `docs/evidence/f0b.md`–`f5.md`.

## Zakres

### F6.1 Dane czatu i rola `chat_ro`

Dom: „Dostęp do bazy” i „Dane do modelu” w [../modules/czat.md](../modules/czat.md), tabele „Czat” i `draft` w [../data-model.md](../data-model.md), „Role PostgreSQL” i „Retencja” w [../security.md](../security.md). Bez sieci; PostgreSQL w jobie `integration`, fikcyjny zbiór danych.

| Element | Co powstaje |
| --- | --- |
| schemat | migracja `chat_session`, `chat_message` i `draft` według [../data-model.md](../data-model.md); `UNIQUE (chat_session_id, seq)`; trigger odrzuca `UPDATE` na `chat_message`; `CHECK` dla `draft.status` i `draft.kind` |
| rola `chat_ro` | tworzona w `docker/db/init-db.sh` jak `kokpit_app`, hasło z pliku hosta i z sejfu; `default_transaction_read_only = on` i `statement_timeout` 2 s na roli; `SELECT` tylko na widokach `chat_*`, zero uprawnień do tabel |
| widoki `chat_*` | właściciel `kokpit_owner`, bez `security_invoker`, więc `chat_ro` nie potrzebuje praw do tabel; filtr po `environment`; bez PESEL, rachunków, adresów osób fizycznych, danych autoryzujących, `pseudonym_map`, `audit_log`, `connector_call`, `credential_state` i `setting` |
| połączenie `chat` | osobne połączenie Doctrine z rolą `chat_ro`, nigdy domyślny EntityManager; limit wierszy `chat.row_limit` (domyślnie 200) z oznaczeniem obcięcia w wyniku |
| nazwane zapytania | `ChatQuery`: wartości `query` `revenue_by_month`, `sales_by_currency`, `costs_by_month`, `invoice_list`, `top_counterparties` oraz zapytania narzędzi `get_obligations`, `get_fx_rate`, `verify_counterparty`; parametry walidowane przed SQL; kwoty jako `brick/math`, bez `float` |
| retencja | `retention.purge` usuwa rozmowę 12 miesięcy po `closed_at` razem z wiadomościami; `draft.chat_session_id` → NULL, szkic zostaje |

### F6.2 Konektor `claude` i pętla narzędzi

Dom: „Architektura”, „Narzędzia”, „Profil żądań Haiku 5.5”, „Historia rozmowy”, „Odpowiedź i odmowy”, „Budżet i koszt” i „Wiedza podatkowa” w [../modules/czat.md](../modules/czat.md). Bez sieci: `MockHttpClient` z ręcznie pisanymi odpowiedziami w `tests/Fixtures/Claude`, przez ten sam dekorator co w produkcji.

| Element | Co powstaje |
| --- | --- |
| klient | `ClaudeClientFactory`: `anthropic-ai/sdk` w dokładnej wersji 0.x; `transporter` = dekorowany `Psr18Client` (`HostAllowlist`, `CONNECTORS_ENABLED`, `rate_counter`, `connector_call` z hashem i rozmiarem, bez treści); ponowienia SDK wyłączone; limit czasu z `chat.timeout_s` |
| profil żądania | `ChatRequestProfile`: `model` z `chat.model`, wyłącznie `claude-haiku-5-5` (walidacja konfiguracji); `thinking` adaptive z `display: omitted`; `output_config.effort` z `chat.effort`, bez wartości domyślnej; `tool_choice` `auto`; bez `temperature`, `top_p`, `top_k`, prefill i `fallbacks`; `max_tokens` z `chat.max_tokens`; `cache_control` na końcu stałego prefiksu (prompt systemowy, narzędzia, snapshot prawa) |
| narzędzia | `ChatToolRegistry`: schematy JSON z `strict: true`, `additionalProperties: false` i `required`; narzędzia i wartości `query` tylko z faz w `chat.phases_live`; lista stała w rozmowie jako `chat_session.tool_set` |
| executor | `ChatToolExecutor`: pseudonimy `K-NN` przez `PseudonymMap`, nieznany pseudonim → `tool_result` z `is_error`; wynik przez `Redactor` i serializer z pseudonimami; zapytania tylko przez połączenie `chat` |
| pętla | `ChatTurn`: najwyżej `chat.max_iterations` (8) iteracji; bloki czytane po `type`; bloki thinking wracają bez zmian; gałęzie `end_turn`, `tool_use`, `max_tokens`, `refusal`; przerwana tura dostaje `tool_result` z `is_error: true` dla każdego otwartego `tool_use` |
| limity | `rate_counter` `claude.messages` przed każdym wywołaniem; 429 → `blocked_until` z `Retry-After`, bez kolejki i ponowień |
| koszt i budżet | `usage` i `cost_usd` z cennika `chat.pricing` w `NUMERIC(12,6)`; `ChatBudget`: suma dzienna i miesięczna, alert przy `chat.budget_alert_ratio` → push `budget_alert`, przekroczenie → odmowa przed API i push |
| rozmiar | `ChatContextGuard`: `chat.context_warn_tokens` (80 tys.) → propozycja nowej rozmowy, `chat.context_max_tokens` (100 tys.) → odmowa; sprawdzenie także przed każdą iteracją pętli |
| wiedza | `chat.law_snapshot` na starcie rozmowy, `chat_session.law_snapshot_from`; hash promptu w `system_prompt_sha256`; zmiana modelu, effort, promptu albo listy narzędzi otwiera nową rozmowę |
| strażnik live | `LiveGuard` dla `claude`: testsuite `live` bez `chat.live_run_budget_usd` odmawia startu; po wyczerpaniu budżetu przebiegu kolejne testy `claude` są pomijane bez żądań |

### F6.3 Panel czatu i wykresy

Dom: „Architektura” (żądanie, stan ładowania, idempotencja, limit czasu) i „Oznaczenie AI” w [../modules/czat.md](../modules/czat.md), „Panel” w [../security.md](../security.md). Bez sieci; WebTestCase na `MockHttpClient`.

| Element | Co powstaje |
| --- | --- |
| widok | `/chat` za passkeyem; ramka Turbo `chat-turn`; formularz z tokenem CSRF `chat`, identyfikatorem rozmowy, `seq` i przełącznikiem „Szkic”; stała etykieta „asystent AI” od pierwszej wiadomości; wskaźnik pracy do końca pętli |
| żądanie | kontroler woła `ChatTurn` synchronicznie, bez Messenger, workera i Mercure; odpowiedź już zapisana po `seq` pytania → ramka z zapisaną odpowiedzią bez wywołania API |
| komunikaty | osobny komunikat dla `refusal`, `max_tokens`, limitu czasu, błędu API, 429 z czasem oczekiwania, budżetu i limitu rozmiaru; bez automatycznego ponowienia |
| wykres | `render_chart` daje `ChartSpec` bez liczb; panel pobiera dane tym samym nazwanym zapytaniem z tymi samymi parametrami i rysuje Symfony UX Chart.js (`symfony/ux-chartjs`); `chart_type` `bar` albo `line` |
| rozmowy | lista rozmów, nowa rozmowa i zamknięcie (`closed_at`); propozycja nowej rozmowy przy `chat.context_warn_tokens` |

### F6.4 Szkice `draft_document`

Dom: „Szkice” ze stanami `draft.status` w [../modules/czat.md](../modules/czat.md), wiersze `draft` w [../approval-gate.md](../approval-gate.md), „Powiązanie z bramką zatwierdzeń” w [../data-model.md](../data-model.md). Bez sieci; szkic nie ma konektora (Q-35).

| Element | Co powstaje |
| --- | --- |
| narzędzie | `DraftDocumentTool`: działa tylko przy `chat_message.draft_requested` na ostatniej wiadomości `user` z Twoim tekstem w bieżącej turze; wiadomości z samymi `tool_result` pomija; tekst udający znacznik prośby niczego nie włącza |
| odbiorca | dokładnie jeden `recipient` `K-NN` z `pseudonym_map`, zapis do `recipient_counterparty_id`; rodzaj `email`; przy zapisie pseudonimy w treści zamienione na dane z mapy |
| oznaczenia | `provenance` ze źródłami nieufnymi; `flagged_values` dla IBAN, NRB, NIP i kwot niezgodnych z bazą; `chat_session_id` i `model` |
| przedmiot bramki | `DraftSubject` (usługa `draft.subject`) dla `subject_type` = `draft` zamiast atrapy; poza `APP_ENV=prod` atrapa `dummy.subject.draft` zostaje dla identyfikatorów bez wiersza `draft`; payload: kanoniczny JSON (odbiorca, temat, treść) jako `stored_file` rodzaju `outbound_payload`; `approval.connector` = NULL; decyzja wymaga potwierdzenia każdej wartości z `flagged_values` |
| stany | Symfony Workflow `draft` według tabeli stanów w [../modules/czat.md](../modules/czat.md): edycja → stare `approval` `superseded` i nowe `pending`; „Skopiowano” → `approval` `accepted` bez konektora, szkic `executed`, wpis `draft.executed`; odrzucenie → `discarded`; `hash_mismatch` → szkic `pending` |
| panel | podgląd szkicu w kolejce zatwierdzeń z wyróżnionymi `flagged_values` i listą źródeł; przycisk „Skopiowano” |

### F6.5 (live) Claude API na danych fikcyjnych

Dom: „Polityka per konektor” i „`@group live`” w [../testing.md](../testing.md). Testsuite `live`, `CONNECTORS_ENABLED=1`, klucz workspace testowego z sejfu testowego, dane fikcyjne, budżet przebiegu `chat.live_run_budget_usd`.

| Element | Co powstaje |
| --- | --- |
| przebieg | pytanie o fikcyjne obowiązki → `tool_use` `get_obligations`, `tool_result`, `end_turn`; drugie żądanie z blokami thinking z pierwszego bez 400 |
| koszt | `usage` i `cost_usd` zapisane w `chat_message`; suma przebiegu poniżej `chat.live_run_budget_usd` |
| nagrania | `app:fixtures:record claude <scenariusz>` ręcznie, poza CI, przez `rate_counter` i scrubber; testy F6.2–F6.4 przechodzą na nowych nagraniach; inny kształt odpowiedzi niż w nagraniach ręcznych → nowy rekord D-xx |

**Dowody.** Rejestr `tests/Acceptance/f6.yaml` ma od startu wiersze przekrojowe; wiersze części dochodzą w jej sesji: klucz, komenda, asercja, artefakty, wykonawca ([../testing.md](../testing.md)).

## Poza zakresem

- `get_edelivery_message`, pseudonimy `S-NN` i `P-NN`, szkic `edelivery_reply` i streszczenie pism: po powrocie F4 (D-29, Q-34).
- Strumieniowanie odpowiedzi, Mercure, worker i transport czatu (D-29).
- Eskalacja do Sonnet 5.5, zmiana modelu w rozmowie i zmiana effort w trakcie rozmowy (D-10, D-29).
- Wysyłka szkiców z kokpitu: tylko kopiowanie (Q-35).
- Files API, Batches i feedback; region UE przez Bedrock albo Google Cloud (D-22, Q-33).
- Nowe narzędzia, zestaw ewaluacyjny, uzgodnienie kosztów z Cost API i tryb bez chmury: [../roadmap.md](../roadmap.md).
- Narzędzia zapisu poza `draft_document`, narzędzia wysyłki i odbioru pism: nigdy.

## Kolejność części

Jedna część na sesję. Każda część ma osobną tabelę w „Kryteriach odbioru”. Sesja kończy się, gdy przechodzą wiersze jej tabeli.

| Część | Zakres | Sieć | Warunek startu |
| --- | --- | --- | --- |
| F6.1 | schemat czatu, rola `chat_ro`, widoki `chat_*`, połączenie `chat`, nazwane zapytania, retencja | bez sieci; PostgreSQL w CI | wejścia „stop” z tabeli „Wejścia” dla F6.1 |
| F6.2 | klient SDK, profil żądania, narzędzia, executor, pętla, limity, budżet, rozmiar, snapshot prawa, strażnik live | bez sieci; `MockHttpClient`, odpowiedzi pisane ręcznie | F6.1 z pass; SDK z obiema opcjami (stop) |
| F6.3 | widok `/chat`, ramka Turbo, idempotencja `seq`, komunikaty, wykres | bez sieci; WebTestCase | F6.2 z pass |
| F6.4 | `draft_document`, `DraftSubject`, stany szkicu, panel szkicu | bez sieci | F6.2 z pass |
| F6.5 (live) | przebieg na Claude API, koszt, nagrania | Claude API, klucz workspace testowego | F6.2 z pass; klucz testowy (stop); po F6.4 nagrania obejmują też szkic |

- F6.3 i F6.4 nie zależą od siebie; obie potrzebują pętli z F6.2.
- Kod F6.1–F6.4 może powstać przed odbiorem F0b–F5, bo działa bez sieci i na danych fikcyjnych (D-58). Go-live F6 czeka na odbiór F6 i na wiersze `[manual]` z „Go-live w kryteriach”.

## Kryteria odbioru

Komendy uruchamiasz w kontenerze `app`. Wiersze F6.1–F6.4 działają bez sieci, przy `CONNECTORS_ENABLED=0` albo na `MockHttpClient`, na danych fikcyjnych.

Kolumna „Kryterium” zaczyna się kluczem z [../naming.md](../naming.md) (D-33). Wiersz bez dopisku ma wykonawcę `ci`, wiersz `(live)` wykonawcę `live`, wiersz `[manual]` wykonawcę `manual`. Kryterium nie zależy od treści odpowiedzi modelu: testy sprawdzają warstwę narzędzi, schematy i stan bazy ([../testing.md](../testing.md)).

| Zapis w „Dowodzie” | Znaczenie |
| --- | --- |
| `junit.xml`: `Nazwa*` | przypadki testowe z raportu JUnit paczki o nazwie zaczynającej się od `Nazwa` |
| `cmd/<klucz>.txt` | wyjście komendy po `Redactor` |
| `db/<klucz>-<tabela>.csv` | wiersze tabeli utworzone przez kryterium, po `Redactor` |
| `screens/<klucz>.html` | ramka panelu z testu WebTestCase, po `Redactor` |
| `manual/<klucz>.*` | plik z kroku `[manual]` z `var/evidence/f6/inbox/`; bez pliku wynik `pending` |

Wiersze `live` wymagają `CONNECTORS_ENABLED=1`, sejfu testowego z kluczem workspace testowego i `chat.live_run_budget_usd`. W CI uruchamia je ręcznie job `live` (`kokpit:evidence f6 --only=live`), nigdy na PROD.

### F6.1

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `chat-ro-denied`: rola tylko do odczytu | `vendor/bin/phpunit --filter ChatRoDenied`: przez połączenie `chat` `INSERT`, `UPDATE` i `DELETE` na tabeli i na widoku, `SELECT` z tabeli `invoice`, `SELECT` z widoku `chat_*`; `SHOW statement_timeout`, `SHOW default_transaction_read_only` | każdy zapis i odczyt tabeli kończy się błędem PostgreSQL; `SELECT` z widoku działa; `2s` i `on` | `junit.xml`: `ChatRoDenied*`; `cmd/chat-ro-denied.txt`: komunikaty błędów PostgreSQL |
| `chat-views-environment`: rozdział środowisk | `vendor/bin/phpunit --filter ChatViewsEnvironment`: fikcyjne wiersze `test` i `prod` w jednej bazie testowej | widoki zwracają tylko wiersze środowiska wdrożenia | `junit.xml`: `ChatViewsEnvironment*` |
| `chat-views-minimal`: zakres danych widoków | `vendor/bin/phpunit --filter ChatViewsMinimal`: kolumny widoków z `information_schema`; fikcyjne PESEL, IBAN, NRB, adres osoby fizycznej i `auth_data` w tabelach źródłowych | zero kolumn z listy zakazanej; zero trafień skanera w wynikach widoków | `junit.xml`: `ChatViewsMinimal*`; `cmd/chat-views-minimal.txt`: lista kolumn widoków |
| `chat-named-queries`: nazwane zapytania | `vendor/bin/phpunit --filter ChatNamedQueries`: każda wartość `query` i zapytania `get_obligations`, `get_fx_rate`, `verify_counterparty` na fikcyjnym zbiorze; parametr spoza wyliczenia, `date_from` po `date_to`; wynik ponad `chat.row_limit` | wyniki równe sumom policzonym z tabel źródłowych do grosza; złe parametry odrzucone przed SQL; wynik obcięty do limitu z oznaczeniem; `get_fx_rate` bez zapisanego kursu → „brak zapisanego kursu” | `junit.xml`: `ChatNamedQueries*` |
| `chat-history-append-only`: historia rozmowy | `vendor/bin/phpunit --filter ChatHistoryAppendOnly`: `UPDATE` wiadomości rolą `kokpit_app`; drugi wiersz z tym samym `seq` | błąd bazy w obu przypadkach | `junit.xml`: `ChatHistoryAppendOnly*` |
| `chat-retention`: retencja rozmów | `vendor/bin/phpunit --filter ChatRetention`: `MockClock`, rozmowa zamknięta 12 miesięcy i 1 dzień temu ze szkicem, druga 11 miesięcy temu, trzecia otwarta | pierwsza usunięta z wiadomościami, szkic zostaje z `chat_session_id` = NULL; druga i trzecia bez zmian | `junit.xml`: `ChatRetention*`; `db/chat-retention-draft.csv` |

### F6.2

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `claude-request-profile`: profil żądania | `vendor/bin/phpunit --filter ClaudeRequestProfile`: pytanie z fikcyjnymi danymi; żądanie przechwycone w `MockHttpClient` | `model` = `claude-haiku-5-5`; `thinking` adaptive z `display: omitted`; `output_config.effort` = `chat.effort`; `tool_choice` `auto`; narzędzia `strict` z `additionalProperties: false`; bez `temperature`, `top_p`, `top_k`, `fallbacks` i prefill; ostatni wpis to tura `user` albo komunikat `system` tuż po niej; `cache_control` na końcu stałego prefiksu | `junit.xml`: `ClaudeRequestProfile*`; `cmd/claude-request-profile.txt`: klucze żądania bez treści i sha256 ciała |
| `chat-model-fixed`: stały model i effort | `vendor/bin/phpunit --filter ChatModelFixed`: konfiguracja z `chat.model` innym niż `claude-haiku-5-5`; bez `chat.effort`; zmiana `chat.effort`, promptu systemowego i `chat.phases_live` przy otwartej rozmowie | zły model i brak effort → odmowa startu kontenera; zmiana konfiguracji → nowa rozmowa z nowym `effort`, `system_prompt_sha256` albo `tool_set`, stara bez zmian; zero żądań z innym `model` | `junit.xml`: `ChatModelFixed*` |
| `claude-transport`: allowlista, wyłączenie i brak ponowień | `vendor/bin/phpunit --filter ClaudeTransport`: host spoza allowlisty; `CONNECTORS_ENABLED=0`; odpowiedzi 500 i 529; timeout | obcy host i wyłączone konektory → wyjątek przed żądaniem, zero żądań; 500, 529 i timeout → dokładnie jedno żądanie i komunikat; `connector_call` z `operation` `messages.create`, hashem i rozmiarem, bez treści | `junit.xml`: `ClaudeTransport*`; `db/claude-transport-connector_call.csv` |
| `chat-tool-loop`: pętla narzędzi | `vendor/bin/phpunit --filter ChatToolLoop`: nagrania `tool_use` → `end_turn`; dziewięć kolejnych `tool_use`; odpowiedź zaczynająca się blokami thinking | `tool_result` z wynikiem nazwanego zapytania i pseudonimami; bloki thinking w kolejnym żądaniu równe bajtowo odpowiedzi; po 8 iteracjach koniec tury z komunikatem; `chat_message` w kolejności API | `junit.xml`: `ChatToolLoop*` |
| `chat-tool-schema`: walidacja narzędzi | `vendor/bin/phpunit --filter ChatToolSchema`: nagrana odpowiedź z `query_invoices` z `query` = `audit_log`, `render_chart` z danymi z `audit_log`, pole spoza schematu, narzędzie spoza `tool_set`, pseudonim spoza mapy | każde wywołanie → `tool_result` z `is_error`; zero zapytań SQL poza nazwanymi; zero zapisów; lista narzędzi to tylko odczyt i `draft_document` | `junit.xml`: `ChatToolSchema*` |
| `chat-tool-set`: narzędzia według faz | `vendor/bin/phpunit --filter ChatToolSet`: `chat.phases_live` = F0b i F1, potem z F2 i F3 | najpierw `get_obligations`, `query_invoices` tylko z `costs_by_month`, `get_fx_rate`, `verify_counterparty`, `render_chart` i `draft_document`; po zmianie nowe wartości `query` i nowa wartość `tool_set` tylko w nowej rozmowie | `junit.xml`: `ChatToolSet*` |
| `chat-stop-reasons`: zakończenia odpowiedzi | `vendor/bin/phpunit --filter ChatStopReasons`: `end_turn`; `max_tokens` po samym bloku thinking; `refusal` z pustym `content` i z `stop_details.category` pustą, znaną i spoza listy | każda gałąź kończy turę komunikatem; `refusal` bez ponowienia i bez zmiany modelu; jedno żądanie na pytanie | `junit.xml`: `ChatStopReasons*` |
| `chat-interrupted-turn`: przerwana tura | `vendor/bin/phpunit --filter ChatInterruptedTurn`: błąd API i limit czasu po zapisie `tool_use`, potem kolejne pytanie | kolejne żądanie zawiera `tool_result` z `is_error: true` dla każdego otwartego `tool_use` | `junit.xml`: `ChatInterruptedTurn*` |
| `chat-rate-limit`: licznik `claude.messages` | `vendor/bin/phpunit --filter ChatRateLimit`: 429 z `Retry-After`; kolejne pytanie przed `blocked_until` | `blocked_until` w grupie `claude.messages`; komunikat z czasem oczekiwania; zero żądań przed `blocked_until`; bez kolejki | `junit.xml`: `ChatRateLimit*`; `db/chat-rate-limit-rate_counter.csv` |
| `chat-budget`: budżet i koszt | `vendor/bin/phpunit --filter ChatBudget`: `MockClock`; `cost_usd` z nagranego `usage` i `chat.pricing`; suma dzienna 80 % i 100 %; suma miesięczna 100 % | koszt równy wyliczeniu `brick/math` do 6 miejsc; push `budget_alert` raz przy 80 %; przy 100 % odmowa przed API i push; zero żądań | `junit.xml`: `ChatBudget*`; `db/chat-budget-notification.csv` |
| `chat-context-limit`: rozmiar rozmowy | `vendor/bin/phpunit --filter ChatContextLimit`: `usage` z 80 001 i 100 001 tokenami wejścia z cache; szacunek przekroczenia w pętli | 80 tys. → propozycja nowej rozmowy; 100 tys. → odmowa bez żądania; w pętli koniec tury z komunikatem | `junit.xml`: `ChatContextLimit*` |
| `chat-redaction`: kontekst modelu | `vendor/bin/phpunit --filter ChatRedaction`: fikcyjne PESEL, IBAN, NRB, token i JWT w pytaniu i w wyniku narzędzia; nazwa i NIP fikcyjnego kontrahenta | zero wystąpień w ciele żądania w `MockHttpClient`, w `chat_message`, logach i `connector_call`; kontrahent jako `K-01` | `junit.xml`: `ChatRedaction*`; `cmd/chat-redaction.txt`: liczba trafień 0 |
| `chat-law-snapshot`: snapshot prawa | `vendor/bin/phpunit --filter ChatLawSnapshot`: dwa wiersze `chat.law_snapshot` z różnym `valid_from`; rozmowa przed i po drugim | snapshot w stałym prefiksie rozmowy; `law_snapshot_from` zapisane; otwarta rozmowa bez zmiany, nowa z nowym snapshotem | `junit.xml`: `ChatLawSnapshot*` |
| `claude-live-guard`: strażnik przebiegu live | `vendor/bin/phpunit --filter ClaudeLiveGuard`: bootstrap `live` bez `chat.live_run_budget_usd`; budżet wyczerpany po pierwszym teście | odmowa startu; kolejne testy `claude` pominięte bez żądań | `junit.xml`: `ClaudeLiveGuard*` |

### F6.3

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `chat-turbo-frame`: odpowiedź w ramce | `vendor/bin/phpunit --filter ChatTurboFrame`: WebTestCase z sesją passkeya; POST bez tokenu CSRF; POST bez sesji; POST poprawny | ramka `chat-turn` z całą odpowiedzią i etykietą „asystent AI”; bez CSRF i bez sesji odmowa; zero wiadomości Messenger; brak transportu `chat` i usługi Mercure w kontenerze | `junit.xml`: `ChatTurboFrame*`; `screens/chat-turbo-frame.html` |
| `chat-seq-idempotent`: jedno pytanie, jedno wywołanie | `vendor/bin/phpunit --filter ChatSeqIdempotent`: dwa POST z tą samą `seq` | jedno wywołanie API, jedna odpowiedź w `chat_message`, ta sama ramka | `junit.xml`: `ChatSeqIdempotent*` |
| `chat-render-chart`: liczby wykresu z bazy | `vendor/bin/phpunit --filter ChatRenderChart`: nagrana odpowiedź z `render_chart` dla `revenue_by_month` i z liczbami w tekście modelu innymi niż w bazie | dane wykresu w ramce równe wynikowi `revenue_by_month` na fikcyjnym zbiorze; liczby z tekstu modelu nie trafiają do wykresu | `junit.xml`: `ChatRenderChart*`; `screens/chat-render-chart.html`: atrybuty danych wykresu; `cmd/chat-render-chart.txt`: wynik zapytania |
| `chat-panel-messages`: komunikaty w panelu | `vendor/bin/phpunit --filter ChatPanelMessages`: `refusal`, `max_tokens`, limit czasu, błąd API, 429, budżet, 100 tys. tokenów | każdy przypadek: własny komunikat w ramce; zero ponownych żądań | `junit.xml`: `ChatPanelMessages*` |

### F6.4

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `draft-requested-only`: szkic tylko na prośbę | `vendor/bin/phpunit --filter DraftRequestedOnly`: wklejona fikcyjna treść z ukrytym poleceniem i nagrana odpowiedź z `draft_document` bez `draft_requested`; tekst znacznika prośby w wyniku narzędzia; ta sama odpowiedź z `draft_requested` | bez flagi zero wierszy `draft` i `approval`, `tool_result` z `is_error`; z flagą szkic `pending` i `approval` `pending`, `provenance` wskazuje wklejoną treść | `junit.xml`: `DraftRequestedOnly*`; `cmd/draft-requested-only.txt`: sha256 fixture treści i liczba wierszy `draft` |
| `draft-recipient-db`: odbiorca tylko z bazy | `vendor/bin/phpunit --filter DraftRecipientDb`: `recipient` `K-01` z mapy, `K-99` spoza mapy, adres e-mail zamiast pseudonimu, dwóch odbiorców | tylko `K-01` zapisany jako `recipient_counterparty_id`; pozostałe odrzucone przez schemat albo executor; pseudonimy w treści zamienione przy zapisie | `junit.xml`: `DraftRecipientDb*` |
| `draft-flagged-values`: wartości niezgodne z bazą | `vendor/bin/phpunit --filter DraftFlaggedValues`: treść z fikcyjnym IBAN, NIP i kwotą spoza bazy | `flagged_values` z trzema pozycjami; decyzja bez potwierdzenia każdej wartości odrzucona; po potwierdzeniu `approved` ze step-up | `junit.xml`: `DraftFlaggedValues*` |
| `draft-approval-copy`: bramka i kopiowanie | `vendor/bin/phpunit --filter DraftApprovalCopy`: zatwierdzenie ze step-up i „Skopiowano”; edycja w `approved`; odrzucenie; podmiana bajtów `outbound_payload` po decyzji | „Skopiowano”: `approval` `accepted` bez konektora, szkic `executed`, wpis `draft.executed`; edycja: `superseded` i nowe `pending`; odrzucenie: `discarded` i `cancelled`; podmiana: `cancelled` z `hash_mismatch`, szkic `pending` | `junit.xml`: `DraftApprovalCopy*`; `db/draft-approval-copy-approval.csv` |

### F6.5

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `claude-live-smoke` (`live`): przebieg na Claude API | `vendor/bin/phpunit --testsuite live --filter ClaudeLiveSmoke`: fikcyjny zbiór, pytanie o obowiązki | `tool_use` `get_obligations`, potem `end_turn`; drugie żądanie z blokami thinking bez 400; `usage` i `cost_usd` zapisane; koszt przebiegu nie większy niż `chat.live_run_budget_usd` | `junit.xml`: `ClaudeLiveSmoke*`; `cmd/claude-live-smoke.txt`: `stop_reason`, `usage`, `cost_usd`, `request_id` |
| `claude-fixtures-record` `[manual]`: nagrania | `bin/console app:fixtures:record claude <scenariusz>` lokalnie, przez `rate_counter` i scrubber; potem testy F6.2–F6.4 | nagrania z danymi fikcyjnymi; skaner bez trafień; testy F6.2–F6.4 przechodzą na nowych nagraniach | `manual/claude-fixtures-record.txt`: lista nagrań z sha256 i wynik skanera |

### Go-live w kryteriach

Wiersze `[manual]` wynikają z warunków go-live czatu w [../operations.md](../operations.md), nie z testów.

| Kryterium | Komenda / kroki | Oczekiwany wynik | Dowód |
| --- | --- | --- | --- |
| `chat-dpa` `[manual]`: umowa powierzenia | DPA z SCC z Anthropic zaakceptowane dla konta workspace PROD (Q-33, D-22) | Anthropic na liście procesorów w [../security.md](../security.md) z umową | odhaczony wpis w `docs/evidence/f6.md` |
| `chat-spend-limit` `[manual]`: limit wydatków PROD | osobny workspace PROD z limitem wydatków w konsoli; klucz w sejfie PROD przez `SecretStore` | limit ustawiony; klucz poza repo, logami i kontekstem modelu | `manual/chat-spend-limit.png`: zrzut limitu bez klucza |

**Kryteria przekrojowe:** wszystkie wiersze od F0a do F5 w [../testing.md](../testing.md) oraz `cc-28`, `cc-29` i `cc-30`. Pokrywają je: `cc-28` → `chat-ro-denied`; `cc-29` → `draft-requested-only` i `chat-tool-schema`; `cc-30` → `chat-redaction`. `cc-01` i `cc-02` obejmują od F6 konektor `claude` przez `claude-transport`, a `cc-07` ścieżkę czatu przez `chat-redaction`.

## Dowód fazy

**Dowód fazy:** `docs/evidence/f6.md`, jedna lista dla wszystkich części. Status F6 w [../README.md](../README.md) linkuje listę po odbiorze.

| Element | Treść |
| --- | --- |
| paczka | `bin/console kokpit:evidence f6 --only=ci` i `--only=live` → `var/evidence/f6/<YYYYMMDD-HHMM>/`; układ, `manifest.json` i wyniki `pass`, `fail`, `pending` według „Paczki dowodów” w [../testing.md](../testing.md); całość po `Redactor` |
| CI | job `evidence` uruchamia wiersze `ci` z `tests/Acceptance/f6.yaml`, ręczny job `live` wiersze `live`; oba wgrywają `var/evidence/**` jako artefakt przebiegu |
| lista | `docs/evidence/f6.md`, pisana ręcznie (D-28): data, commit, linki do przebiegów `evidence` i `live`, kryterium → pass albo fail → artefakt, odhaczone `[manual]`; bez treści rozmów |
| odbiór | wymagane joby zielone, udany przebieg `live` F6.5, wszystkie wiersze pass, wiersze `[manual]` odhaczone przez Ciebie |

## Go-live

Sekwencja i wycofanie: wiersz „czat” w „Go-live” w [../operations.md](../operations.md). Lista go-live nie należy do kryteriów odbioru, poza wierszami `[manual]` z „Go-live w kryteriach”.

| Krok | Wynik | Zapis |
| --- | --- | --- |
| test | wiersze F6.1–F6.5 z pass | `docs/evidence/f6.md` |
| umowa i klucz | `chat-dpa` i `chat-spend-limit` | wiersze `[manual]` w liście dowodów; wpis `credential.registered` dla klucza |
| narzędzia | `chat.phases_live` na `kokpit-prod` tylko z fazami po go-live | wiersz w liście dowodów |
| rola PROD | `chat_ro` z hasłem z sejfu PROD, utworzona przy wdrożeniu | wpis `ops.deployed` |
| pierwsza rozmowa | pytanie o obowiązki na `kokpit-prod`; koszt w `chat_session` | wiersz w liście dowodów, bez treści |

- Prawdziwe dane nie trafiają do Claude API w dev i testach; w dev, testach i F6.5 idą tylko dane fikcyjne.
- Wycofanie: konektor `claude` wyłączony w konfiguracji; historia i szkice zostają w bazie.

## Pierwszy prompt

```text
Przeczytaj docs/README.md i docs/phases/f6-czat.md, potem tylko pliki z sekcji „Ładuj tylko” dla tej części.
Krok 0: sprawdź docs/naming.md, wiersze F6 w docs/decisions.md i fakty F6 w docs/facts.md.
Brak wejścia oznaczonego „stop” oznacza stop i pytanie; brak klucza Claude API zatrzymuje tylko F6.5.
Zrealizuj jedną część z sekcji „Kolejność części”: pierwszą, której kryteria nie mają pass, a warunek startu jest spełniony.
F6.1–F6.4 działają bez sieci: MockHttpClient, odpowiedzi Messages API pisane ręcznie z danymi fikcyjnymi.
Model tylko claude-haiku-5-5, chat.effort jawnie, bez eskalacji; odpowiedź synchronicznie w ramce Turbo, bez Messenger.
Narzędzia to nazwane zapytania na roli chat_ro; jedyny zapis to draft_document na Twoją prośbę.
Zanim zaczniesz kodować, wypisz plan i pytania. Na koniec uruchom testy i bin/console kokpit:evidence f6 --only=ci,
pokaż wynik kryteriów tej części i otwarte wiersze [manual]; po F6.5 także kryteria przekrojowe z docs/testing.md.
```
