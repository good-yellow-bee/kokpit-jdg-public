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
| `invoice-2001-accepted.json` | F3.4: faktura 2001 z `invoices-f3-ready.json` po odczycie po id, `notes` bez numeru KSeF |
| `invoice-2001-written-back.json` | F3.4: ta sama faktura z linią zapisu zwrotnego i fikcyjnym numerem KSeF w `notes` |
| `invoice-update-2001.json` | F3.4: odpowiedź `PUT /invoices/invoices/2001` (zapis zwrotny) |
| `client-201.json` | F3.4: `GET /users/clients/201`, adres `faktury@klient.example.invalid` |
| `share-link-2001.json` | F3.4: `GET /invoices/invoices/2001/share_link`, link do płatności |
| `parallel-compare-3001.json`, `parallel-compare-3002.json` | F3.4 `app:ksef:parallel-compare`: faktura zgodna ze złotym plikiem `fa3-pl-b2b` i faktura 1000.00 PLN do XML Subject1 z innym P_13_1 |
