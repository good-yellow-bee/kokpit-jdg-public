# Czat

Czat z Claude Haiku 5.5 na narzędziach tylko do odczytu: narzędzia, rola bazy, szkice, dane do modelu, profil żądań i budżety. Stan na 8 października 2026.

## Jak czytać

- Faza F6, ostatnia w kolejności z D-30. Model i pętla: D-10, D-11; dane, region i szkice: D-22; cięcia: D-29 w [../decisions.md](../decisions.md).
- Odłożenia czatu i warunki powrotu: [../roadmap.md](../roadmap.md). Wycofane nazwy: [../naming.md](../naming.md).
- Odłożone razem z F4: narzędzie `get_edelivery_message`, pseudonimy `S-NN` i `P-NN`, szkic rodzaju `edelivery_reply`. Takie wiersze mają dopisek „po powrocie F4”.
- Kolumny `chat_session`, `chat_message` i `draft` są w [../data-model.md](../data-model.md). Ten plik jest domem stanów `draft.status`.
- Maskowanie (`Redactor`) i retencję treści czatu definiuje [../security.md](../security.md). Tu jest tylko zakres danych do modelu.
- Claude API to konektor `claude` (`src/Connector/Claude`): nagrane odpowiedzi, `@group live`, licznik limitów `claude.messages` (D-34, [../testing.md](../testing.md)).

## Zasada podziału

Model rozumie pytanie, wybiera narzędzie i opisuje wynik. Kwoty, podatki, pliki dla urzędu i każda wysyłka zostają w deterministycznym kodzie kokpitu.

| Zadanie | Robi model | Robi kod kokpitu |
| --- | --- | --- |
| Pytanie o dane („ile przychodu w EUR w Q3?”) | rozumie pytanie, wybiera nazwane zapytanie, opisuje wynik | wykonuje zapytanie tylko do odczytu, zwraca liczby |
| Wykres | wybiera nazwane zapytanie i typ wykresu | sam pobiera dane i rysuje (Symfony UX Chart.js) |
| Mail do klienta | pisze szkic na Twoją prośbę | zapisuje `draft` z odbiorcą z bazy i oznaczeniem źródeł nieufnych |
| Pismo z e-Doręczeń (po powrocie F4) | streszcza, proponuje w tekście termin z cytatem źródła | udostępnia tylko pismo z zapisaną treścią; termin zapisuje formularz pisma po Twoim potwierdzeniu |
| Podatki, składki, JPK, FA(3) | nic | wylicza, generuje XML, waliduje XSD |

- Liczby na wykresie i w odpowiedzi pochodzą z wyniku narzędzia, nie z tekstu modelu.
- Brak narzędzi „wyślij fakturę”, „wyślij JPK”, „odbierz pismo”. Te przyciski istnieją tylko w panelu.
- Czat dostaje wyłącznie narzędzia tylko do odczytu oraz `draft_document`.
- Do powrotu F4 pisma nie trafiają do czatu. Termin z pisma wpisujesz ręcznie w kalendarzu ([e-doreczenia.md](e-doreczenia.md)). Żadne narzędzie czatu nie zapisuje terminu.

## Architektura

Messages API i PHP tool runner z SDK `anthropic-ai/sdk` (`BetaRunnableTool`, `toolRunner()`), pętla w Symfony (D-11, [PHP SDK](https://platform.claude.com/docs/en/cli-sdks-libraries/sdks/php)). Managed Agents odrzucone. Odpowiedź powstaje w synchronicznym żądaniu panelu z ramką Turbo (D-29).

| Element | Reguła |
| --- | --- |
| Żądanie | formularz w ramce Turbo wysyła pytanie z identyfikatorem rozmowy i `seq`; kontroler woła API bez strumieniowania i zwraca ramkę z całą odpowiedzią |
| Stan ładowania | ramka pokazuje wskaźnik pracy do końca pętli narzędzi; bez zdarzeń pośrednich |
| Idempotencja | kontroler sprawdza `chat_message.seq`: odpowiedź po `seq` pytania już zapisana → brak wywołania API, ramka z zapisaną odpowiedzią |
| Limit czasu | pętla najwyżej 8 iteracji; limit czasu żądania w PHP-FPM i w kliencie SDK z konfiguracji, z zapasem na pętlę; po przekroczeniu komunikat w panelu |
| Po błędzie | komunikat w panelu; bez ponowienia w SDK; ponawiasz nowym pytaniem |
| Przerwana tura | błąd API albo limit czasu po zapisie `tool_use`, przed `tool_result`; przed kolejnym żądaniem kontroler dopisuje `tool_result` z `is_error: true` dla każdego otwartego `tool_use` |
| Klient HTTP | klient PSR-18 z dekoratora Symfony HttpClient z `HostAllowlist` ([integrations.md](../../.claude/rules/integrations.md)); bez strumieniowania Guzzle nie jest potrzebny. W `anthropic-ai/sdk` 0.58.0 opcja `RequestOptions::with(transporter: …)` (i `streamingTransporter`), sprawdzone w źródle w F6.2 (D-60) |
| Ponowienia SDK | wyłączone: `RequestOptions::with(maxRetries: 0)` daje jedno żądanie (F6.2, D-60); domyślnie SDK ponawia dwa razy 408, 409, 429, 5xx i błędy połączenia ([PHP SDK](https://github.com/anthropics/anthropic-sdk-php)) |
| Log | każde wywołanie w `connector_call` (hash i rozmiar); treść rozmowy tylko w `chat_message` |

- `composer.json` przypina dokładną wersję `anthropic-ai/sdk` 0.x.
- Jeden użytkownik nie potrzebuje strumienia. Żądanie bez strumieniowania jest bezpieczne przy krótkich odpowiedziach; `max_tokens` z konfiguracji trzyma odpowiedź poniżej limitu czasu HTTP.
- Żaden worker Messenger nie bierze udziału. Workery `reads` i `notifications` działają bez zmian.
- Panel F6.3 (D-61): `seq` w formularzu to numer następnej wiadomości; pytanie zapisane od tej `seq` daje ramkę z historii bez wywołania API, a blokada `chat.turn.<id>` szereguje równoległe wysłania. Wykres bierze `ChartSpec` tylko z `tool_result` executora i liczby z ponownego nazwanego zapytania. Limit czasu żądania panelu: 780 s w PHP-FPM i proxy.

## Narzędzia

Narzędzie to nazwane zapytanie: wyliczenie plus parametry w JSON Schema, z `strict: true`, `additionalProperties: false` i polami `required`. Model nigdy nie pisze SQL.

| Narzędzie | Dostępne od | Parametry | Zwraca |
| --- | --- | --- | --- |
| `get_obligations` | F0b | `status` (wyliczenie), `date_from`, `date_to` | obowiązki z kwotą i terminem po przesunięciu |
| `query_invoices` | F1 koszty, F2 ewidencja, F3 sprzedaż | `query` (wyliczenie), `date_from`, `date_to`, `currency` (wyliczenie z konfiguracji) | wiersze lub sumy z widoków `chat_*` |
| `get_fx_rate` | F1 | `currency`, `date` (data zdarzenia) | zapisany kurs z `fx_rate` według reguły `FxRateProvider::getRate`, numer i data tabeli; bez zapytania do NBP |
| `verify_counterparty` | F1 | `counterparty` (pseudonim `K-NN`) | zapisany snapshot weryfikacji i jego wiek; odświeżenie to przycisk w panelu |
| `render_chart` | F1 | `query` (to samo wyliczenie), `chart_type` (`bar`, `line`), parametry zapytania | specyfikacja wykresu; dane pobiera i rysuje panel |
| `get_period_summary` | F2 (D-95) | `month` (RRRR-MM) | przychód K_8 z ewidencji, liczby najnowszego przyjętego JPK_V7M okresu (`sales_net`, `vat_due`, `vat_input`, K_11, K_12, P_51, P_62) i kwoty `ryczalt_advance` oraz `zus` z `period_start` = początek miesiąca; bez przyjętego JPK pola JPK są puste |
| `get_cashflow` | F2 (D-95) | `date_to` | sumy per rodzaj i waluta: otwarte obowiązki z kwotą do `date_to` (z zaległymi), należności z faktur sprzedaży po płatnościach, koszty do zapłaty; reguły jak strona `/cashflow` (D-88), bez przeliczania walut |
| `compare_periods` | F2 (D-95) | `date_from`, `date_to`, `compare_from`, `compare_to` | dwa wiersze (`current`, `compare`): przychód z ewidencji, sprzedaż netto PLN i koszty firmowe netto PLN z liczbą dokumentów |
| `get_edelivery_message` | po powrocie F4 (D-29) | `message` (pseudonim `P-NN`) | tylko pismo z zapisaną treścią: `received` albo `received_elsewhere` po udanym „Pobierz kopię”; w innym stanie tylko metadane; forma z tabeli „Dane do modelu” |
| `draft_document` | F6 | `kind`, `recipient` (pseudonim `K-NN`; po powrocie F4 także `S-NN`), `subject`, `body`, `source_refs` (pseudonimy) | potwierdzenie zapisu szkicu `draft` w stanie `pending`, bez identyfikatora z bazy |

- Parametry obiektów przyjmują pseudonimy sesji: `K-NN` kontrahent; po powrocie F4 `S-NN` sprawa i `P-NN` pismo.
- Executor tłumaczy pseudonim przez `chat_session.pseudonym_map` i odrzuca nieznany. Identyfikatory z bazy nigdy nie trafiają do modelu.
- Serializer wyniku narzędzia podmienia identyfikatory z widoków `chat_*` na pseudonimy sesji.
- `get_fx_rate` stosuje regułę z [../tax-rules.md](../tax-rules.md) i [rejestry-kursy.md](rejestry-kursy.md) tylko do zapisanych wierszy `fx_rate`. Bez trafienia w cache `getRate` zwraca „brak zapisanego kursu”.
- Startowe wartości `query`: `revenue_by_month`, `sales_by_currency`, `costs_by_month`, `invoice_list`, `top_counterparties`. Nowa wartość to kod z testem i nowa wersja `tool_set`.
- Narzędzie wchodzi do listy po go-live swojej fazy. Lista jest stała w rozmowie, bo jej zmiana psuje cache i bloki thinking.
- Nowa lista narzędzi dostaje nową wartość `chat_session.tool_set` i działa tylko w nowych rozmowach.
- `verify_counterparty` nigdy nie woła rejestru. Liczniki białej listy są w [rejestry-kursy.md](rejestry-kursy.md).
- `get_edelivery_message` nie wyzwala odbioru: czat ma tylko dane po `receive()` ([e-doreczenia.md](e-doreczenia.md)).
- Wstrzyknięty „wykres z `audit_log`” odpada na walidacji schematu, bo takiej wartości `query` nie ma.

## Dostęp do bazy

| Element | Reguła |
| --- | --- |
| Rola | `chat_ro`: `SELECT` tylko na widokach `chat_*`; bez dostępu do tabel |
| Połączenie | osobne połączenie Doctrine `chat`, nigdy domyślny EntityManager |
| Transakcje | `default_transaction_read_only = on` dla roli |
| Limity | `statement_timeout` 2 s; limit wierszy wyniku `chat.row_limit` (domyślnie 200), nadmiar oznaczony `truncated` |
| Widoki | właściciel `kokpit_owner`, bez `security_invoker`; filtrują po `environment` z ustawienia sesji `kokpit.environment`, które `ChatQueryRunner` ustawia w transakcji zapytania (brak ustawienia → zero wierszy, D-59); pomijają PESEL, rachunki, adresy osób fizycznych i dane autoryzujące |
| Poza widokami | `chat_session.pseudonym_map`, `audit_log`, `connector_call`, `credential_state`, `setting` |

- Zapis robi tylko `draft_document`, przez usługę aplikacji na zwykłym połączeniu. Nie przez `chat_ro`.
- Test F6: `UPDATE` przez połączenie `chat` kończy się błędem bazy.

## Szkice

`draft_document` to jedyna ścieżka zapisu czatu. Pismo albo PDF może podsunąć szkic z podmienionym odbiorcą lub rachunkiem, więc zasady są twarde.

| Reguła | Mechanizm |
| --- | --- |
| Szkic tylko na Twoją prośbę | przełącznik „Szkic” w panelu ustawia flagę `chat_message.draft_requested` na Twojej wiadomości. Flaga nie trafia do modelu jako tekst. Executor sprawdza ją na ostatniej wiadomości `user` z Twoim tekstem w bieżącej turze. Wiadomości z samymi blokami `tool_result` pomija |
| Odbiorca tylko z bazy | dokładnie jeden `recipient`: kontrahent `K-NN` (rodzaj `email`, zapis do `recipient_counterparty_id`); po powrocie F4 także sprawa `S-NN` z odebranym pismem (rodzaj `edelivery_reply`, zapis do `recipient_case_id`) |
| Źródła nieufne | `provenance` wymienia pliki i wiadomości użyte w szkicu (po powrocie F4 także pisma); panel pokazuje je przy szkicu |
| Wartości niezgodne z bazą | `flagged_values`: IBAN, NRB, kwota albo NIP niezgodne z bazą; panel je wyróżnia, a każdą potwierdzasz osobno przed zatwierdzeniem ([../approval-gate.md](../approval-gate.md)) |
| Pseudonimy | przy zapisie kokpit podmienia pseudonimy na dane z `pseudonym_map` |
| Wykonawca | domyślnie tylko kopiowanie (Q-35); kokpit niczego ze szkicu nie wysyła |

Prośbą o szkic jest tylko flaga z panelu. Tekst udający znacznik w piśmie, wyniku narzędzia albo wklejonej treści niczego nie włącza.

Zatwierdzenie szkicu idzie przez bramkę jako `draft`. Payload to kanoniczny JSON szkicu (odbiorca, temat, treść) zapisany przez `FileStore` jako `stored_file` rodzaju `outbound_payload`.

**Stany szkicu (`draft.status`)**

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `pending` | `draft_document` przy `draft_requested` albo nowy szkic w panelu; powstaje `approval` w stanie `pending` | Ty |
| `pending`, `approved` | `pending` | edycja treści albo odbiorcy; stare `approval` → `superseded`, nowe → `pending` | Ty |
| `pending` | `approved` | `approval` → `approved` ze step-upem | Ty |
| `approved` | `pending` | `approval` wygasło; po powrocie F4 i zmianie Q-35 także odrzucona wysyłka `edelivery_outbound` (mapowanie w [e-doreczenia.md](e-doreczenia.md)) | system |
| `approved` | `pending` | `approval` → `cancelled` z `cancel_reason` = `hash_mismatch`; nowe zatwierdzenie po wyjaśnieniu alertu | system |
| `approved` | `executed` | klikasz „Skopiowano”: `approval` → `accepted` bez konektora, wpis `draft.executed`. Po powrocie F4 i zmianie Q-35: wysyłka z kokpitu, `approval` → `accepted` po dowodzie nadania | Ty, system |
| `pending`, `approved` | `discarded` | tylko anulowanie w panelu: odrzucasz szkic, `approval` → `cancelled` | Ty |

- Wygaśnięcie `approval` w stanie `pending` nie zmienia szkicu: zostaje w `pending`. Nowe zatwierdzenie powstaje na Twoje żądanie w panelu. Terminy wygaśnięcia: [../approval-gate.md](../approval-gate.md).
- Powiązanie stanów `approval` i `draft`: tabela „Powiązanie z bramką zatwierdzeń” w [../data-model.md](../data-model.md).
- Wysyłka odpowiedzi przez e-Doręczenia (`edelivery_outbound`) to osobna ścieżka w [e-doreczenia.md](e-doreczenia.md), odłożona z F4. Wysyłka maili do klientów wymaga nowej decyzji.

## Dane do modelu

Do modelu trafia tylko to, czego wymaga odpowiedź. Maskowanie działa na wynikach narzędzi i na Twoich pytaniach, przed modelem i przed zapisem w `chat_message`.

| Klasa danych | Do modelu | Forma |
| --- | --- | --- |
| Twoje pytania (wolny tekst) | tak | przez `Redactor` przed modelem i przed zapisem |
| sumy, liczby, kursy, terminy, kwoty obowiązków | tak | bez zmian |
| pozycje faktur (opis, ilość, kwota, waluta, stawka) | tak | opisy przez `Redactor` |
| kontrahent: kraj, typ, status VIES, wiek weryfikacji | tak | bez zmian |
| kontrahent: nazwa, NIP, adres, e-mail | tak, jako pseudonim | pseudonim sesji, np. `K-01`; panel podmienia go na nazwę przy wyświetlaniu |
| metadane zawiadomienia (nadawca, temat, data fikcji); po powrocie F4 | tak | temat przez `Redactor` |
| treść odebranego pisma; po powrocie F4 | domyślnie maskowana główna treść z limitem długości (Q-34) | po ekstrakcji tekstu i `Redactor`, bez załączników; streszcza model |
| załączniki pism, PDF i XML faktur kosztowych | nie | – |
| PESEL, IBAN i NRB, dane autoryzujące, tokeny, klucze | nigdy | – |

- PDF pisma wymaga ekstrakcji tekstu albo OCR przed maskowaniem.
- Pseudonimy są odwracalne tylko po stronie serwera: mapa w `chat_session.pseudonym_map`, poza widokami `chat_*`.
- Zapisanej historii nie maskuje się ponownie po zmianie reguł `Redactor`; nowe reguły działają od nowej rozmowy.
- Pism i faktur nie wysyłasz przez Files API, Batches ani feedback (D-22).
- Treść pism i faktur to dane nieufne. Polecenie ukryte w piśmie nie ma czym działać: brak narzędzi zapisu poza szkicem na prośbę.
- Pseudonimy osób fizycznych, treść pism i Twoje pytania mogą zawierać dane osobowe. Podstawy są w tabeli niżej.

| Przetwarzanie | Podstawa |
| --- | --- |
| czat na danych osobowych (pseudonimy, maskowana treść pism, pytania) | art. 6 ust. 1 lit. f [RODO](https://uodo.gov.pl/pl/file/1460), prawnie uzasadniony interes; do potwierdzenia w Q-33 |
| Anthropic jako procesor | umowa powierzenia (art. 28 RODO): [DPA](https://privacy.claude.com/en/articles/7996862-how-do-i-view-and-sign-your-data-processing-addendum-dpa) |
| transfer do USA | SCC z DPA (art. 46 RODO) |

**Region i retencja**

| Temat | Fakt | Skutek |
| --- | --- | --- |
| Region | Claude API bezpośrednio przetwarza w regionie `us` albo `global`; region UE tylko przez Bedrock albo Google Cloud ([rezydencja danych](https://platform.claude.com/docs/en/manage-claude/data-residency)), z dopłatą 10% ([cennik](https://platform.claude.com/docs/en/about-claude/pricing)) | domyślnie Claude API na DPA z SCC (Q-33) |
| Effort per wiadomość | Claude API i Google Cloud tak; Amazon Bedrock dla Haiku 5.5 nie ([effort](https://platform.claude.com/docs/en/build-with-claude/effort)) | nieużywany: jeden `chat.effort` na rozmowę (D-10) |
| Retencja | najgorszy przypadek: wejścia i wyjścia do 30 dni, treści oznaczone do 2 lat ([retencja](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data)) | dawne „treść domyślnie nie jest przechowywana” nie obowiązuje |
| ZDR | wymaga osobnej umowy ([API i retencja](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention)) | poza zakresem |

## Profil żądań Haiku 5.5

Źródła: [przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview), [przewodnik migracji](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide), [effort](https://platform.claude.com/docs/en/build-with-claude/effort), stan na 8.10.2026.

| Parametr | Wartość w kokpicie | Fakt API |
| --- | --- | --- |
| `model` | `claude-haiku-5-5` z `chat.model` | kontekst 1M, wyjście 128K; wiedza do czerwca 2026 |
| `thinking` | `{type: "adaptive"}` jawnie | adaptive domyślnie; `disabled` tylko przy effort `high` lub niższym, profil go nie używa |
| `thinking.display` | `omitted` | domyślnie pusty tekst z podpisem; `summarized` daje streszczenie; bez `display: "updates"`, tego Haiku nie ma |
| `output_config.effort` | jawnie `chat.effort` = `low` z konfiguracji; `chat_session.effort` zapisuje poziom ze startu rozmowy | poziomy `low`–`max`, domyślnie `medium`; dokumentacja zaleca `low` dla czatu |
| zmiana effort w rozmowie | nieużywana; jeden poziom na rozmowę | możliwa komunikatem systemowym z `output_config` (beta `mid-conversation-output-config-2026-07-01`); zmiana `output_config.effort` całego żądania unieważnia cache wiadomości |
| `tool_choice` | `auto`, narzędzia `strict: true` | wymuszony `tool_choice` działa, ale pomija thinking |
| `temperature`, `top_p`, `top_k` | nie wysyłamy | wartość niedomyślna daje 400 |
| prefill i kolejność `messages` | zakaz prefill; ostatni wpis to tura `user` albo komunikat `system` tuż po niej. Komunikat `system` z tekstem stoi zawsze po turze `user` | prefill daje 400; komunikat `system` z tekstem: po turze `user`, na końcu albo przed turą `assistant` ([komunikaty systemowe w rozmowie](https://platform.claude.com/docs/en/build-with-claude/mid-conversation-system-messages)) |
| `max_tokens` | z konfiguracji, z zapasem na thinking, poniżej progu wymagającego strumieniowania | thinking liczy się do `max_tokens`; możliwe `max_tokens` po samym bloku thinking |
| odczyt odpowiedzi | bloki po `type`, nigdy po pozycji | odpowiedź może zaczynać się blokami thinking |
| cache | prompt systemowy i lista narzędzi stałe, na początku żądania. Dwa punkty cache, oba z TTL 5 min (D-94): jawny `cache_control` na snapshocie prawa, ostatnim bloku stałego prefiksu, i automatyczny `cache_control` na poziomie żądania dla rosnącej historii. Zapisane wiadomości `chat_message` nie niosą znaczników | odczyt 0,1×, zapis 5 min 1,25×, zapis 1 h 2×; minimalny prefiks 512 tokenów; najwyżej 4 punkty w żądaniu; automatyczny punkt API stawia na ostatnim bloku, który się kwalifikuje, i przesuwa go wraz z rozmową ([prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching)) |

- Analiza pisma z wyższym effortem wraca z F4 (wiersz „Streszczenie pisma po odbiorze” w [../roadmap.md](../roadmap.md)). Wtedy osobna rozmowa z własnym poziomem, bez zmiany w trakcie; nazwa klucza powstanie wtedy w [../naming.md](../naming.md).

## Historia rozmowy

Bloki thinking Haiku 5.5 są ważne tylko przy niezmienionej historii. Zmiana `system`, `tools` albo wcześniejszych `messages` daje 400; dla kont założonych przed 31.08.2026 tylko przy `thinking.block_binding` ([przewodnik migracji](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide)).

- `chat_message` zapisuje surowe bloki treści w kolejności z API, tylko dopisywane ([../data-model.md](../data-model.md)).
- Bloki thinking wracają do API bez zmian, razem z wynikami narzędzi.
- Kontekst zmienny w trakcie rozmowy idzie komunikatem `role: "system"` w `messages` (Haiku 5.5 bez nagłówka beta). Nigdy przez edycję promptu systemowego.
- Komunikaty `role: "system"` zapisują się w `chat_message` jak każda inna wiadomość. `chat_message.role` obejmuje więc `user`, `assistant` i `system` ([../data-model.md](../data-model.md)).
- Zmiana `chat.model`, `chat.effort`, listy narzędzi albo promptu systemowego w konfiguracji otwiera nowe rozmowy. Bez zmiany modelu w trakcie rozmowy (D-29).
- Bloki thinking działają tylko na koncie, które je wytworzyło. Rozmowę odtwarzasz tym samym kontem.

**Limit rozmiaru.** Prompt powyżej 100 tys. tokenów kosztuje pięć razy więcej ([przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview)). Historia tylko rośnie, więc kokpit pilnuje rozmiaru:

| Próg `input_tokens` z ostatniego żądania | Skutek |
| --- | --- |
| 80 tys. | panel proponuje nową rozmowę |
| 100 tys. | kolejne pytanie w tej rozmowie jest odrzucane; panel otwiera nową |

Progi są w konfiguracji; liczy się suma wejścia i tokenów cache z `usage`.

- Pętla narzędzi sprawdza próg 100 tys. także przed każdą kolejną iteracją: ostatnie `usage` plus szacunek tokenów nowych wyników narzędzi.
- Po przekroczeniu pętla kończy turę komunikatem w panelu i propozycją nowej rozmowy.

## Odpowiedź i odmowy

- Gałęzie po `stop_reason`: `end_turn`, `tool_use`, `max_tokens`, `refusal`. Każda ma komunikat w panelu.
- `refusal` przychodzi jako HTTP 200, a `content` może być pusty. `stop_details.category` bywa pusta albo spoza listy (Haiku 5.5: `cyber`, `bio`, `frontier_llm`, `general_harms`). Panel pokazuje komunikat niezależnie od kategorii ([przewodnik migracji Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/migration-guide)).
- Haiku 5.5 nie ma server-side fallback, więc kokpit nie wysyła parametru `fallbacks`.
- Po `refusal` panel pokazuje komunikat. Bez automatycznego ponowienia i bez zmiany modelu.
- Licznik `rate_counter` konektora `claude` ma grupę `claude.messages` i zlicza każde wywołanie Messages API.
- Błąd 429: kokpit zapisuje `blocked_until` z `Retry-After` w grupie `claude.messages`. Pytanie jest odrzucane z komunikatem w panelu i czasem oczekiwania.
- Pytanie przy `blocked_until` w przyszłości jest odrzucane przed wywołaniem API.
- Żądanie synchroniczne nie trafia do kolejki i nie jest ponawiane. To wyjątek od reguły odkładania w `rate_counter` ([../data-model.md](../data-model.md)).

## Budżet i koszt

| Element | Reguła |
| --- | --- |
| Budżet dzienny i miesięczny | sumy `chat_message.cost_usd` w kodzie; wartości w konfiguracji (propozycja: $1 dziennie, $10 miesięcznie) |
| Alert | 80% budżetu → push kategorii `budget_alert` |
| Przekroczenie | żądanie odrzucone przed wywołaniem API, push |
| Pętla narzędzi | najwyżej 8 iteracji na turę; `max_tokens` na każde żądanie |
| Konto | kredyty startowe $200 na koncie API; osobny workspace i klucz z niskim limitem wydatków w konsoli jako ostatnia zapora; klucz w Symfony Secrets przez `SecretStore` |
| Koszt w bazie | `usage` i `cost_usd` na każde żądanie, sumy w `chat_session` |

**Koszt zgrubnie.** Haiku 5.5 kosztuje $0.10 / $0.50 za MTok do 100 tys. tokenów promptu i $0.50 / $2.50 powyżej ([cennik](https://platform.claude.com/docs/en/about-claude/pricing)).

- Jedno wywołanie 15 tys. + 1 tys. tokenów to ok. $0.002.
- Pytanie z pętlą narzędzi to kilka wywołań: każde wysyła pełny kontekst, a thinking płaci się jak wyjście.
- Szacunek: 4 wywołania po 15 tys. wejścia i 1,5 tys. wyjścia to ok. $0.01 bez cache. 100 pytań miesięcznie to ok. $1.

## Wiedza podatkowa

- Kwoty, stawki, progi i terminy pochodzą tylko z wyników narzędzi albo ze snapshotu prawa. Inaczej model mówi, że nie wie.
- Snapshot prawa to `setting` `chat.law_snapshot`: wersjonowany zestaw stawek i progów, wstrzykiwany na starcie rozmowy.
- `chat_session.law_snapshot_from` zapisuje użyty `valid_from`. Nowy snapshot działa od nowej rozmowy.
- Model zna świat do czerwca 2026 ([przegląd Haiku 5.5](https://platform.claude.com/docs/en/models/haiku-5-5/overview)). Zmian prawa po tej dacie nie zna.

## Oznaczenie AI

Od 2.08.2026 system AI informuje o interakcji z AI od pierwszej wiadomości ([AI Act, art. 50](https://artificialintelligenceact.eu/article/50/)).

- Widok czatu ma stałą etykietę „asystent AI” od pierwszej wiadomości.
- Szkic przechowuje `chat_session_id`, więc wiadomo, który model go napisał. Zatwierdzenie w bramce nie usuwa tej informacji.

## Testy

Polityka ogólna i nagrania: [../testing.md](../testing.md). Kryteria odbioru F6: [../phases/f6-czat.md](../phases/f6-czat.md).

- Nagrane odpowiedzi Claude API w `tests/Fixtures/Claude`, oczyszczone; testy live tylko w `@group live`, bo kosztują.
- Wklejona treść z ukrytym poleceniem i bez `draft_requested` → brak szkicu. Szkic na prośbę → `provenance` wskazuje źródło.
- Tekst znacznika prośby w wyniku narzędzia, bez `draft_requested` → brak szkicu.
- Szkic z IBAN spoza bazy → wartość w `flagged_values`.
- Odpowiedź `refusal` → komunikat w panelu, brak ponowienia.
- `render_chart` → panel pobiera dane nazwanym zapytaniem; wartości na wykresie = wynik zapytania; specyfikacja od modelu nie niesie liczb.
- Przekroczony budżet dzienny → żądanie odrzucone przed API, push `budget_alert`.
- Dwa wysłania tego samego `seq` → jedno wywołanie API, jedna odpowiedź w `chat_message`.
- Narzędzie fazy jeszcze nieukończonej nie występuje w `tool_set`.
- Po powrocie F4: `get_edelivery_message` na `received_elsewhere` bez pobranej kopii → tylko metadane; po udanym „Pobierz kopię” → treść.

## Otwarte pytania

| Id | Temat | Założenie domyślne |
| --- | --- | --- |
| Q-33 | transfer do USA na DPA z SCC czy region UE; podstawa przetwarzania (art. 6 RODO) | Claude API bezpośrednio; art. 6 ust. 1 lit. f |
| Q-34 | maskowana główna treść pism z limitem długości czy pełna maskowana treść z załącznikami (po powrocie F4) | maskowana główna treść z limitem długości, bez załączników; streszcza model |
| Q-35 | szkice wysyła kokpit czy tylko kopiujesz | tylko kopiowanie |

Rozstrzygnięte: Q-36 (D-29); odłożony zakres i warunek powrotu w [../roadmap.md](../roadmap.md).
