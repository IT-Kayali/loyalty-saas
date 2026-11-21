# REST API

Basis: `https://deine-domain/api/v1` — Auth: `Authorization: Bearer DEIN_API_KEY` (Schlüssel: Business-Seite → Integrationen). Maschinenlesbare Spezifikation: `/api/v1/openapi.json`. Eine HTML-Übersicht gibt es unter `/api/docs`.

Alle Antworten sind JSON. Fehler: `{"error": "..."}` mit passendem HTTP-Status (401 falscher Schlüssel, 404 nicht gefunden, 422 fehlende/ungültige Felder).

## Endpunkte

| Methode | Pfad | Beschreibung |
| --- | --- | --- |
| GET | `/business` | Stammdaten des Business |
| GET | `/programs` | Alle aktiven Programme mit Konfiguration |
| GET | `/members` | Mitgliederliste mit Kartenständen |
| POST | `/members` | Mitglied anlegen oder per E-Mail finden: `{"name": "...", "email": "..."}` |
| GET | `/members/{code}` | Ein Mitglied inkl. Karten (code = 10-stelliger Mitgliedscode) |
| POST | `/members/{code}/points` | Punkte gutschreiben: `{"amount": 50}` — Bonus-Zeitfenster gelten |
| POST | `/members/{code}/stamp` | Einen Stempel geben; bei Erreichen des Ziels Rollover + `reward_earned: true` |
| POST | `/cards/{id}/points` | Punkte auf eine bestimmte Karte: `{"amount": 50}` |
| POST | `/integrations/order` | Bestellung verbuchen (Shop/POS): `{"email", "name"?, "total", "currency"?, "reference"?}` |
| GET | `/transactions` | Buchungsjournal |
| GET | `/openapi.json` | OpenAPI-3-Spezifikation |

## Beispiele

Punkte gutschreiben:

```bash
curl -X POST https://deine-domain/api/v1/members/ABC123DEF4/points \
  -H "Authorization: Bearer DEIN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"amount": 50}'
```

Antwort:

```json
{"member": "ABC123DEF4", "card_id": 3, "credited": 150, "bonus_multiplier": 3, "points": 320}
```

`credited` ist der tatsächlich gebuchte Betrag inkl. Bonus-Multiplikator.

Bestellung aus einem Shopsystem (WooCommerce-Plugin nutzt genau das):

```bash
curl -X POST https://deine-domain/api/v1/integrations/order \
  -H "Authorization: Bearer DEIN_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"email": "kunde@example.com", "name": "Karin Kunde", "total": 24.90, "currency": "EUR", "reference": "shop-4711"}'
```

Das Mitglied wird per E-Mail gefunden oder angelegt und bekommt Punkte nach der Einstellung **Punkte je Cent Umsatz** (Business → Integrationen). `reference` sollte je Bestellung eindeutig sein; sie landet im Journal und macht die Buchung nachvollziehbar.

## Webhooks (App → dein Server)

Business-Seite → Integrationen → Webhook anlegen. Events: Mitglied erstellt, Stempel, Punkte, Prämie eingelöst, Gutschein eingelöst, Kampagne.

- Nur **HTTPS-Ziele mit öffentlicher IP** (SSRF-Schutz; für lokale Entwicklung `webhook_allow_http => true` in config.php)
- Mit Secret: HMAC-SHA256-Signatur des Roh-Body im Header `X-Webhook-Signature` (`hash_hmac('sha256', $rawBody, $secret)`)
- Payload: `{"event": "...", "business_id": 1, "data": {...}, "sent_at": "..."}`
- Zustellprotokoll (Status, Antwort, Versuche) unter dem Webhook in der App
- Fehlgeschlagene Zustellungen werden automatisch wiederholt (Cron) und lassen sich manuell neu senden, Header `X-Webhook-Delivery` und `X-Webhook-Attempt`: [WEBHOOK-RETRIES.md](WEBHOOK-RETRIES.md)

## PassKit Web Service (intern)

`/api/pass/v1/...` ist **kein** Endpunkt für eigene Integrationen, sondern die von Apples Wallet-App aufgerufene Update-Schnittstelle (Registrierung, Seriennummern, Pass-Download, Abmeldung, Log). Authentifizierung über das im Pass eingebettete `authenticationToken` (HMAC aus Mitgliedscode + Business-API-Key).

## Shopify (spezieller Endpunkt)

`POST /integrations/shopify/webhook/{businessId}` — HMAC-geprüft (`X-Shopify-Hmac-Sha256` gegen das in der App hinterlegte Secret), verarbeitet `orders/paid` und `orders/create`. Setup: `integrations/shopify/README.md`.


## Merchant-API für WordPress (v1)

Authentifizierung wie oben über unternehmensgebundenen Bearer-Schlüssel. Dieser Schlüssel ist kein eingeschränktes WordPress-Benutzertoken. Keine `business_id` aus dem Request wird verwendet. HTTPS und serverseitige Schlüsselhaltung sind erforderlich. GET bleibt bei ausgelaufenem Plan für bestehende Daten verfügbar; POST unterliegt den API-/Programm-/Mitgliederrechten und Mengenlimits.

| Methode | Pfad nach `/api/v1` | Zweck |
| --- | --- | --- |
| GET | `/merchant/summary` | Unternehmensname, Mitglieder, Karten, Punktesalden, aktuelle Stempel und offene Stempelbelohnungen, `as_of` in UTC |
| GET | `/merchant/members?q=...&after=0` | Suche in Name/E-Mail/Code, max. 50; `next_after` als nächsten Cursor verwenden, `null` bedeutet Ende |
| GET | `/merchant/members/{code}` | Mitglied, Karten einschließlich `program_id`/`active`, verfügbare Prämien des Unternehmens |
| POST | `/merchant/members` | Neues Mitglied: `operation`, `name`, `email`; gleiche E-Mail wird nicht automatisch übernommen |
| POST | `/merchant/members/{id}/edit` | `operation`, neuer `name`, unveränderte `email`; Identitätsänderung gesperrt |
| POST | `/merchant/cards/{id}/action` | `operation`, `action`, ggf. `amount` oder `reward_id` |

`operation` ist eine neue zufällige 32-stellige kleine Hex-Zeichenfolge pro beabsichtigter Änderung. Bei Timeout dieselbe Kennung und unveränderte Eingaben verwenden. Wiederholung liefert das gespeicherte Ergebnis, abweichende Eingabe mit derselben Kennung ergibt 422. Schreibtransaktion umfasst Kontostand, Journal und Ergebnisbeleg. Neue Kennung bedeutet neue Buchung. Kein automatischer Ablauf; nach Kundenlöschung werden alte Belege anonym gesperrt.

Aktionen: `points_add` mit ganzzahligem `amount` 1–10000 (Bonuszeiten gelten), `stamp`, `redeem_reward` mit `reward_id`, `redeem_stamp_reward`. Nur aktive Karten im eigenen Unternehmen. Einlösung verlangt ausreichendes Guthaben beziehungsweise offene Belohnung. Der externe Client muss richtige Person und Einlösung ausdrücklich bestätigen lassen. Das WordPress-Plugin prüft diese Bestätigung serverseitig zusätzlich zum Nonce.

Der neue Merchant-Pfad bucht Salden und Journal, aber noch keine Achievement-Auswertung, ausgehenden Webhooks, E-Mail-Automation oder Wallet-Pushes. E-Mail, Passwörter und Marketingeinwilligung bleiben unverändert. Keine Gutscheincode- oder bezahlten Passaktionen. Details und Fehler-/Retry-Bedienung: [WordPress-README](../integrations/woocommerce/README.md).
