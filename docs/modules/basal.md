# basal

Lokalny klasyfikator basal: wersja, zakres pytań, tryb cienia, kalibracja i uruchomienie na CPU. Materiał referencyjny z 8 października 2026.

## Odłożone (D-29)

| Element | Stan |
| --- | --- |
| moduł | odłożony 8.10.2026 (D-29); dawna faza F7 nie ma pliku fazy ani sesji |
| nazwy | konektor `basal`, licznik `basal.classify`, `basal.model`, `basal.revision`, `classification` i `classification.outcome` zarezerwowane w [../naming.md](../naming.md) jako odłożone; nie powstają w kodzie |
| schemat | `classification` poza schematem F0a; `basal.thresholds`, `basal.max_input_tokens` i `basal.router_timeout_ms` nie powstają |
| reguły | `.claude/rules` bez sekcji basal; zostaje wskaźnik na [../roadmap.md](../roadmap.md) |
| host | wariant odłożonego F7 w [../roadmap.md](../roadmap.md); aktywny host w [../operations.md](../operations.md) |
| dlaczego | ok. 200 etykiet na pytanie przy kilku pismach i dziesiątkach faktur kosztowych miesięcznie to lata (przegląd z 8.10.2026, C1; archiwum poza repo) |
| powrót | wiersz „F7 basal” w [../roadmap.md](../roadmap.md) i nowy rekord D-xx |

- Dziś żadne pole panelu nie zbiera etykiet. `invoice.cost_review` ma tylko `business`, `private`, `not_mine`, `disputed`. 200 etykiet na pytanie zbierze dopiero tryb cienia po powrocie.
- Treść niżej opisuje stan projektu z 8.10.2026. Przy powrocie sprawdzasz wersję modelu, silnika i kalibracji od nowa (R-09 w [../facts.md](../facts.md)).

## Jak czytać

- Plik nie jest ładowany w żadnej fazie (tabela „Jak czytać dokumentację” w [../README.md](../README.md)). Czyta go dopiero sesja po rekordzie przywracającym moduł.
- Q-37 rozstrzygnięte: odłożone (D-29, zastępuje D-26). Dawne założenie „start od kategorii faktur kosztowych w trybie cienia” zostaje jako punkt wyjścia przy powrocie.
- Kolumny `classification` wracają wtedy do [../data-model.md](../data-model.md). Ten plik jest domem stanów `classification.outcome`.
- Host i pamięć serwera: D-18 i [../operations.md](../operations.md). Polityka testów: [../testing.md](../testing.md).

## Czym jest basal

basal to otwarty polski model decyzyjny. Dostaje tekst i listę opcji, a zwraca wybraną opcję z prawdopodobieństwem każdej, bez generowania tekstu ([strona projektu](https://basal.si5.pl/)).

- W kokpicie przejmowałby szybkie, powtarzalne decyzje na Twoim serwerze. Czat zostaje do rozmowy, wykresów i szkiców ([czat.md](czat.md)).
- Treść pism i faktur przetwarzana przez basal nie opuszcza serwera. Czat wysyła do Anthropic maskowane dane według D-22.
- basal niczego nie wysyła ani nie odbiera. Kwoty i progi liczy kod, a wynik trafia do pytania jako fakt. Aktualne przepisy podajesz w kontekście, bo model nie zna zmian po treningu.

## Wersja

basal-1.5 jest publiczny od 5.10.2026, na licencji Apache-2.0, na bazie Bielik-4.5B-v3.0-Instruct ([strona projektu](https://basal.si5.pl/), [karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B)). Programu wcześniejszego dostępu strona nie opisuje, a wersji 2.0 nie zapowiada.

| Model | Parametry | Zamknięty test PL (3000 decyzji) | Werdykt PL+EN |
| --- | --- | --- | --- |
| basal-1.5 | 4.5B | 93,1% (basal-1.0: 87,4%) | 72,1% |
| basal-1.5 max | 11B | 93,3% | 77,3% |
| basal-1.5 mini | 1.5B | 91,0% | 60,7% |

Źródło tabeli: [strona projektu](https://basal.si5.pl/), stan na 8.10.2026.

- Przy celu 1% błędu basal-1.5 akceptuje 51,0% decyzji, basal-1.0 akceptował 55,2% ([karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B)). Próg z 1.0 nie przenosi się na 1.5.
- Projekt zakłada 4.5B. Mini dopiero po własnej ocenie na zbiorze z `classification`.
- `basal.model` to repozytorium HF, `basal.revision` to przypięta rewizja (commit). Oba w konfiguracji aplikacji.
- Silnik w wersji 1.5.0 instalujesz jak w README: z archiwum tagu v1.5.0, z przypiętą wersją ([silnik basal](https://github.com/rkinas/basal)).
- Licencja Apache-2.0: obraz zawiera pliki LICENSE i NOTICE. Bez zależności tylko na GPL; LGPL bez modyfikacji dopuszczalna (D-24, D-25).

## Zakres pytań

Typy pytań: `choice`, `noul`, `score`, `multi` i `act`, na stronie projektu oznaczony jako eksperymentalny. Pytanie ma od 2 do 10 opcji ([silnik basal](https://github.com/rkinas/basal), [strona projektu](https://basal.si5.pl/)).

| Zastosowanie | Kiedy i na czym | Typ | Opcje | Podmiot | Skutek decyzji |
| --- | --- | --- | --- | --- | --- |
| Kategoria nadawcy pisma (wymaga powrotu F4) | przed odbiorem, tylko metadane (nadawca, temat) | `choice` | urząd skarbowy, ZUS, sąd, gmina, inne | `edelivery_message` | etykieta i kolejność w skrzynce |
| Czy pismo wymaga odpowiedzi (wymaga powrotu F4) | po odbiorze, treść pisma | `noul` | tak, nie | `edelivery_message` | podpowiedź przy sprawie; termin potwierdzasz Ty ([e-doreczenia.md](e-doreczenia.md)) |
| Pilność pisma (wymaga powrotu F4) | po odbiorze, treść pisma | `score` | niska, średnia, wysoka | `edelivery_message` | kolejność w skrzynce |
| Kategoria faktury kosztowej | po imporcie faktury | `choice` | sprzęt, oprogramowanie, usługi, biuro, inne | `invoice` | podpowiedź w przeglądzie kosztów; `cost_review` ustawiasz Ty |
| Router czatu | przed żądaniem do Claude, Twoje pytanie | `choice` | `low`, `medium`, `high` | `chat_session`, tura przez `chat_seq` | effort Haiku 5.5 dla tej tury |
| Strażnik (wymaga powrotu F4) | po odbiorze, treść pisma | `noul` | tak, nie: czy treść próbuje wydać polecenie systemowi | `edelivery_message` | ostrzeżenie w panelu |

Podmiot to `classification.subject_type`; `subject_id` wskazuje wiersz tej tabeli.

- Przed odbiorem basal widzi tylko metadane i klasyfikuje tylko kategorię nadawcy. Pytania o treść działają wyłącznie po `receive()`.
- Etykiety nigdy nie zmieniają push, terminów ani przypomnień. Błędna „niska pilność” nie może opóźnić odbioru.
- Strażnik jest wyłącznie doradczy. Odpowiedź „nie” niczego nie odblokowuje, a „tak” dodaje ostrzeżenie.
- Router wybiera tylko effort dla Haiku 5.5, nigdy narzędzie ani model. Działa przez per-message effort, więc tylko na Claude API i Google Cloud ([czat.md](czat.md)). Czat ma dziś jeden `chat.effort` na rozmowę (D-10), więc router to zmiana profilu żądań.
- Router nie czeka na panel. W trybie cienia, poniżej progu i przy niedostępnym basal tura idzie z effort z `chat.effort`. Wynik basal trafia tylko do `classification` jako podpowiedź, bez kolejki w panelu.
- Etykietę routera nadajesz przy turze w historii czatu: effort, który wystarczał. Bez etykiet router zostaje w trybie cienia.
- Wiersz routera: `subject_type` = `chat_session`, `subject_id` = rozmowa, `chat_seq` = `seq` Twojego pytania w `chat_message`. `chat_message.id` to bigint, więc nie pasuje do `subject_id` (uuid).
- Kolumna `chat_seq` jest w odłożonej definicji `classification` w [../data-model.md](../data-model.md) (sekcja „Odłożone”).

## Zasady działania

| Reguła | Szczegół |
| --- | --- |
| Tryb cienia | każde pytanie startuje w trybie cienia: decyzje mają `needs_review` i trafiają do Ciebie jako podpowiedź; poza routerem (patrz „Zakres pytań”) |
| Koniec trybu cienia | co najmniej 200 oznaczonych przez Ciebie przypadków na pytanie, potem dobór progu dla celu 1% błędu |
| Automat | tylko gdy kalibracja jest `validated` i pewność ≥ próg z `basal.thresholds` |
| Poniżej progu | `needs_review`, decyzja czeka w panelu |
| basal niedostępny | decyzje trafiają do Ciebie: pozycja w zwykłym widoku (skrzynka, przegląd kosztów) bez podpowiedzi; do powrotu basal wiersz `classification` nie powstaje; poza routerem (patrz „Zakres pytań”) |
| Ponowienie przy awarii | wiadomość na `reads` ponawia się z backoffem; po powrocie basal przed ostatnią próbą wiersz powstaje z opóźnieniem; po ostatniej próbie trafia do `failed` z push `connector_failure` ([../operations.md](../operations.md)) |
| Wejście | po `Redactor` i przycięciu do `basal.max_input_tokens` z konfiguracji; zapis w `classification.input_text` |

- Autor zaleca dobór progu na „kilkuset” oznaczonych żądaniach z własnego procesu ([silnik basal](https://github.com/rkinas/basal)). Projekt przyjmuje minimum 200 na pytanie.
- Przy wolumenie jednej JDG zbieranie etykiet trwa miesiące. Dlatego start od jednego pytania (dawna D-26, zastąpiona przez D-29).

## Kalibracja

Dostarczony `CALIBRATION.json` ma progi dla 1% i 5% błędu. Są sprawdzone tylko dla bf16, opisów opcji bez kluczy (`option_keys: hide`) i pytań tak/nie ([silnik basal](https://github.com/rkinas/basal)). Porty GGUF i MLX dziedziczą kalibrację bf16, niesprawdzoną dla nich.

**Kiedy `classification.calibration` = `validated`**

- Próg pochodzi z Twojego doboru dla dokładnie tej krotki: `question_key`, `model`, `revision`, `engine_mode`, `criteria_version`.
- Dobór liczy się na co najmniej 200 etykietach tego `question_key` i tej `criteria_version`.
- Dla pytań `act` dodatkowo silnik zwraca `calibration` = `validated`. Inne typy tego pola nie zwracają ([server.py](https://github.com/rkinas/basal/blob/main/basal/server.py)).
- W każdym innym przypadku `unvalidated`, więc bez automatu.

**Etykiety**

- `user_label` to prawda podstawowa. Etykieta nie zależy od `model`, `revision` ani `engine_mode`.
- Nowa krotka dostaje `validated` po przeliczeniu istniejących etykiet (co najmniej 200), bez nowego trybu cienia.
- Zmiana zbioru opcji albo `criteria_version` unieważnia etykiety. Mapujesz je na nowe opcje albo wracasz do trybu cienia.

**Ponowna kalibracja po każdej zmianie:**

| Zmiana | Przykład |
| --- | --- |
| model | 4.5B → mini |
| rewizja HF | nowy commit w `basal.revision` |
| tryb silnika albo kwantyzacja | `llamacpp` Q8_0 → Q4_K_M; Mac z MLX |
| wersja silnika albo obrazu `llama-server` | v1.5.0 → v1.5.1; nowy digest |
| treść kryteriów lub opcji | nowe brzmienie opcji, nowa `criteria_version`; etykiety do mapowania albo nowy tryb cienia |

- `engine_mode` zapisuje tryb, kwantyzację, wersję silnika i digest obrazu `llama-server`, np. `llamacpp/Q8_0/1.5.0/<digest>`.
- Zmiana silnika albo obrazu zmienia prawdopodobieństwa przy tym samym modelu i rewizji. Dlatego daje nową krotkę.

**Procedura zmiany wersji**

Etykiety są tylko w bazie `kokpit-prod`, bo każde wdrożenie ma osobną bazę ([../architecture.md](../architecture.md)). Dlatego porównanie działa w `kokpit-prod`.

1. Kandydat startuje jako usługi `basal-candidate` i `llama-server-candidate` (profil compose `candidate`). Tylko sieć wewnętrzna, tylko na czas testu.
2. `app:basal:evaluate <question_key> --revision=<rev>` w kontenerze `app` przelicza oznaczone wiersze bieżącej `criteria_version`. Liczy na `basal` i na kandydacie; `--model` i `--engine-mode` przy zmianie modelu albo trybu.
3. Komenda tylko czyta `classification` i nie tworzy wierszy. Wynik to raport i snapshot zbioru: id wierszy, `user_label`, sha256 `input_text` i sha256 całości.
4. Raport: trafność obu krotek, pokrycie przy 1% błędu, nowy próg. Raport i snapshot trafiają do paczki dowodów przez `inbox/` ([../testing.md](../testing.md)).
5. Po Twojej akceptacji nowa konfiguracja i nowy wiersz `setting` `basal.thresholds` z `valid_from`. Usługi kandydata zatrzymujesz.

- Snapshot nie zawiera treści wejścia, więc pisma i faktury nie trafiają do paczki dowodów.
- Kandydat zajmuje tyle RAM co `basal`. Host na powrót musi mieć miejsce na dwa modele naraz.

Próg jest zapisany per pytanie, model, rewizja, tryb i wersja kryteriów ([../data-model.md](../data-model.md)).

## Uruchomienie

| Element | Reguła |
| --- | --- |
| Tryb | GGUF Q8_0 modelu 4.5B przez llama.cpp na CPU: `basal-serve` w trybie `llamacpp` nad działającym `llama-server` ([silnik basal](https://github.com/rkinas/basal)); tryb `gguf` sprawdza sesja po powrocie |
| Dlaczego nie GPU | tryb `fast` wymaga GPU NVIDIA sm80+ ([wymagania](https://github.com/rkinas/basal/blob/main/docs/HARDWARE.md)); tryb `eager` to referencja PyTorch; z mini na CPU README podaje go do testów i małego wolumenu ([silnik basal](https://github.com/rkinas/basal)) |
| Pamięć | Q8_0 4.5B ok. 5,1 GB, Q4_K_M ok. 2,9 GB, mini Q8_0 ok. 1,7 GB (Q4_K_M ok. 1,0 GB), pomiar na Apple M4 Pro ([wymagania](https://github.com/rkinas/basal/blob/main/docs/HARDWARE.md)); VPS z D-28 (co najmniej 2 GB) tego nie mieści, więc powrót wymaga większego hosta (Q-14) |
| Obraz | własny Dockerfile: Python 3.12, silnik z archiwum tagu v1.5.0; README nie opisuje obrazu Docker |
| `llama-server` | osobna usługa compose, obraz przypięty do digestu |
| Wagi | pobrane wcześniej przez `hf download` z przypiętą rewizją na wolumen; kontener bez wyjścia do internetu |
| Sieć | tylko wewnętrzna sieć compose, bez mapowania portu |
| Długość wejścia | `basal.max_input_tokens` w konfiguracji; vLLM i SGLang domyślnie odrzucają stany dłuższe niż 4096 tokenów ([karta modelu](https://huggingface.co/Remek/basal-1.5-4.5B)) |
| Zdrowie | `/health` w monitoringu ([../operations.md](../operations.md)) |

- Serwer domyślnie słucha na `0.0.0.0:8000` i nie ma uwierzytelnienia ([server.py](https://github.com/rkinas/basal/blob/main/basal/server.py)). Jedyną ochroną jest izolacja sieci.
- Endpoint: `POST /v1/systemone`.
- Na Macu basal może działać natywnie (MLX, Metal), jeśli host będzie Makiem. Domyślny host to VPS (Q-14, D-18). Docker na macOS nie ma dostępu do Metal.
- Tryb CPU GGUF zostaje w kontenerze także na Macu. Natywny MLX to nowa krotka kalibracji.
- Wyjątek: router wołałby basal synchronicznie w żądaniu czatu, przed żądaniem do Claude (czat jest synchroniczny, D-29). Limit czasu to `basal.router_timeout_ms`; po jego przekroczeniu tura idzie z `chat.effort`.
- Pozostałe decyzje liczy worker na transporcie `reads`, asynchronicznie. Panel nie czeka na basal.

## Tabela `classification`

Pola i typy wracają do [../data-model.md](../data-model.md) przy powrocie. Wiersz zawiera komplet wejścia i parametrów, więc da się go ponownie uruchomić jako przypadek testowy.

**Stany decyzji (`classification.outcome`)**

| Z | Do | Wyzwalacz | Aktor |
| --- | --- | --- | --- |
| – | `auto_accepted` | kalibracja `validated` i pewność ≥ próg | system |
| – | `needs_review` | tryb cienia, kalibracja `unvalidated` albo pewność < próg | system |
| `needs_review` | `corrected` | Twoja etykieta różna od `chosen_option` | Ty |
| `auto_accepted` | `corrected` | zmieniasz etykietę później | Ty |

- Twoja etykieta to `user_label` z `labelled_at` i `labelled_by`. Etykieta zgodna z `chosen_option` zostawia `outcome` bez zmian.
- Kolejka panelu to wiersze z `outcome` = `needs_review` i pustym `user_label`, bez pytania routera.
- Zbiór oznaczony to wiersze z wypełnionym `user_label`. Z niego liczy się trafność i próg.
- `input_text` przechodzi przez `Redactor`, bo wiersz trafia do backupu i testów.

## Konektor i testy

| Element | Wartość |
| --- | --- |
| Slug, katalog | `basal`, `src/Connector/Basal`; odłożone (D-29) |
| Fixtures | `tests/Fixtures/Basal`, nagrane odpowiedzi z fikcyjnymi danymi |
| Logi | `connector_call` dla każdej decyzji; licznik `basal.classify` w `rate_counter`: tylko zliczanie, bez limitu zewnętrznego |
| Testy live | `@group live` na kontenerze z przypiętą rewizją |
| Awaria | basal zatrzymany → zero nowych wierszy `classification`, pozycje widoczne bez podpowiedzi, po ostatniej próbie wiadomość w `failed` i push `connector_failure`, tura routera z `chat.effort` |

**Szkic kryteriów (przy powrocie).** Plik fazy powstanie z tej listy; klucze slug nada wtedy ([../naming.md](../naming.md)). Wiersz ryzyka zmiany wersji: R-09 w [../facts.md](../facts.md).

- kontener odpowiada na CPU na `POST /v1/systemone` z nagranym pytaniem → opcja i prawdopodobieństwa;
- pytanie w trybie cienia → wiersz `needs_review` z podpowiedzią w panelu, bez zmiany push ani terminów;
- Twoja korekta → `corrected` z `user_label`, `labelled_at`, `labelled_by`;
- awaria według wiersza „Awaria” wyżej;
- `app:basal:evaluate` na dwóch krotkach → raport trafności i snapshot ze sha256 zbioru, bez treści wejścia; `[manual]` do czasu zbioru oznaczonego;
- test architektury: basal nigdy nie woła konektora e-Doręczeń; pytania o treść tylko po `receive()`.

## Otwarte pytania

| Id | Temat | Stan |
| --- | --- | --- |
| Q-37 | budować w F7 czy odłożyć | rozstrzygnięte: odłożone (D-29); powrót przez [../roadmap.md](../roadmap.md) |
| Q-14 | host; basal natywnie (MLX) tylko na Macu | domyślnie VPS z projektem prod i co najmniej 2 GB (D-28); basal nie wpływa na wybór hosta (D-18) |
