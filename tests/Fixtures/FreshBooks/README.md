# Fixtures FreshBooks

Ręcznie pisane odpowiedzi API według dokumentacji ([faktury](https://www.freshbooks.com/api/invoices), [płatności](https://www.freshbooks.com/api/payments), [uwierzytelnianie](https://www.freshbooks.com/api/authentication)). Firmy testowej nie ma (D-35), więc to nie są nagrania.

Wszystkie dane są fikcyjne (fictional): klienci, numery VAT, kwoty, identyfikatory i tokeny. Konto FreshBooks `fikc01` nie istnieje.

| Plik | Odpowiedź |
| --- | --- |
| `oauth-token.json`, `oauth-refresh.json` | `POST /auth/oauth/token`: kod autoryzacji i odświeżenie |
| `users-me.json` | `GET /auth/api/v1/users/me` z jednym kontem |
| `invoices-page1.json`, `invoices-page2.json` | lista faktur 2026 w dwóch stronach |
| `invoice-1001.json` | jedna faktura po id |
| `payments.json` | lista płatności |
| `invoices-f3-ready.json` | dwa szkice F3.2 ze znacznikiem `KSEF-GOTOWA` w `po_number`: klient z Polski (PLN) i z Niemiec (EUR) |
| `credit-notes.json` | dwie credit notes F3.2 część b: do faktury `FB-2026-0201` z `invoices-f3-ready.json` i do faktury nieznanej kokpitowi; numer faktury korygowanej w linii `Faktura: <numer>` w `notes` (D-53) |
