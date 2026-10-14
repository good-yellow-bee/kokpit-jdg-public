# Fixtures Claude

Ręcznie pisane odpowiedzi Messages API dla konektora `claude` (F6.2, D-60), według [dokumentacji Messages API](https://platform.claude.com/docs/en/api/messages). To nie są nagrania: nagrania `app:fixtures:record claude` powstają w F6.5.

Wszystkie dane są fikcyjne (fictional): identyfikatory `msg_fictional_*`, `toolu_fictional_*`, podpisy bloków thinking, teksty i liczby tokenów. Testy sprawdzają warstwę narzędzi i pętlę, nie tekst modelu.

| Plik | Odpowiedź |
| --- | --- |
| `messages-tool-use.json` | thinking z podpisem, tekst i `tool_use` `get_obligations`; `usage` z zapisem cache |
| `messages-two-tools.json` | dwa `tool_use`: `query_invoices` i nieznane narzędzie (wynik `is_error`) |
| `messages-end-turn.json` | thinking i tekst, `end_turn`; `usage` z odczytem cache |
| `messages-max-tokens.json` | sam blok thinking, `max_tokens` |
| `messages-refusal.json` | `refusal` z pustą treścią i `stop_details.category` `cyber` |
| `messages-refusal-no-category.json` | `refusal` z `stop_details.category` `null` |
| `messages-refusal-other-category.json` | `refusal` z kategorią spoza listy dokumentacji |
| `messages-unknown-stop.json` | nieznany kokpitowi `stop_reason` |
| `messages-large-context.json` | `end_turn` z promptem 85 tys. tokenów (próg ostrzeżenia) |
| `messages-top-counterparties.json` | `tool_use` `query_invoices` z `top_counterparties` (pseudonimy K-NN) |
| `messages-invalid-tools.json` | sześć wywołań odrzucanych przez warstwę narzędzi: `query` = `audit_log`, wykres z `audit_log`, pole spoza schematu, narzędzie spoza listy, pseudonim spoza mapy, szkic bez prośby |
| `messages-invoice-list.json` | `tool_use` `query_invoices` z `invoice_list` (test maskowania wyników) |
| `messages-rate-limit.json` | treść odpowiedzi 429; `Retry-After` ustawia test |
