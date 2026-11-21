# KI-Verbindungen (MCP)

Ein KI-Client arbeitet über das Model Context Protocol (JSON-RPC über HTTPS) mit deinem Betreiberkonto. Angelehnt an die AI-Connections- und Agent-API-Doku der Referenz, aber bewusst kleiner. Die App braucht keinen KI-Anbieter und keinen eigenen Schlüssel dafür. Die Wahl des Clients und seine Kosten liegen bei dir.

## Einschalten

`'ai_enabled' => true` in `config.php` (Standard: aus, `/mcp` antwortet dann 404). Danach erscheint "KI" in der Navigation und `/ai` ist erreichbar. Die Seite zeigt die Adresse `https://deine-domain/mcp`.

## Schlüssel

Unter `/ai` erstellst du Schlüssel (`rl_ai_...`): Name, Zugriff **Nur lesen** oder **Lesen und ändern**, Laufzeit 1 bis 365 Tage, höchstens 10 aktive. Der Schlüssel erscheint genau einmal. Gespeichert wird nur ein SHA-256-Hash, danach siehst du das Präfix. Widerrufen wirkt sofort und schließt offene Freigabeanfragen des Schlüssels. Ein Schlüssel gehört deinem Konto und erreicht nur deine Unternehmen. Er kann nie Schlüssel erstellen, Passwörter ändern oder KI-Verbindungen schalten, dafür gibt es kein Werkzeug.

## Protokoll

`POST /mcp` mit `Authorization: Bearer rl_ai_...`. Unterstützt: `initialize`, `ping`, `tools/list`, `tools/call`, `prompts/list`, `prompts/get`, Benachrichtigungen (202) und Batches bis 20. Anfragen mit `Origin`-Header (Browser) werden abgelehnt. 60 Anfragen pro Minute und Schlüssel. Jeder Werkzeugaufruf steht im Audit (Werkzeug, Schlüsselpräfix, Ergebnis, keine Mitgliedsdaten).

## Werkzeuge

Lesen (beide Zugriffsstufen): `list_businesses`, `get_business_overview`, `list_programs`, `list_members` (Suche, Cursor), `get_member`, `list_transactions`, `get_change_status`.

Ändern (nur Schlüssel mit Schreibzugriff, laufen sofort): `create_member`, `award_points`, `give_stamp`, `create_program` (Stempel oder Punkte), `run_approved_change`.

Wartet auf deine Freigabe: `delete_member`, `delete_program`.

Mit mehr als einem Unternehmen ist `business_id` Pflicht. Schreibwerkzeuge nehmen einen optionalen `idempotency_key` (8 bis 80 Zeichen): derselbe Aufruf wiederholt liefert das gespeicherte Ergebnis (`replayed: true`) und bucht nicht doppelt. Gleicher Schlüssel mit anderen Eingaben wird abgelehnt. War ein früherer Aufruf unklar beendet, wird er nicht blind wiederholt. Es gelten dieselben Plan-Regeln wie in der REST-API.

Prompts (als Jobs im Client): `weekly_report`, `member_lookup`, und mit Schreibzugriff `launch_stamp_card`.

## Freigaben

Ein riskantes Werkzeug ändert nichts. Es liefert `request_id`, `nonce` und einen Prüflink `/ai/approval/{id}`. Du siehst Änderung, Schlüssel, was sie betrifft (Anzahl Karten und Buchungen) und die Restzeit, und klickst Freigeben oder Ablehnen. Nur der Eigentümer kann entscheiden, fremde Konten bekommen 404. Die Anfrage gilt 10 Minuten ab Vorbereitung. Danach ruft der Client `run_approved_change` mit `request_id` und `nonce` auf. Das läuft höchstens einmal. Geprüft wird erneut: Schlüssel aktiv, Freigabe vorhanden und nicht abgelaufen, Datensatz unverändert seit der Prüfung (sonst "Nicht ausgeführt", Anfrage geschlossen), Plan. Zustände: pending, approved, rejected, expired, done, failed.

## Tests

`PHP_BIN=/pfad/php TEST_OUTPUT=/tmp/ai-images python tests/run-ai.py`: Endpunkt standardmäßig aus, 61 HTTP-Prüfungen (Schlüssel gehasht, Auth, Origin, Protokoll, Leserechte, Mandantentrennung, Idempotenz, Freigabe/Ablehnung/Ablauf/geänderter Datensatz, Widerruf, Schlüsselablauf, Audit, Rate-Limit) und 20 Chromium-Prüfungen mit Screenshots (Desktop und Mobil). Kein echter KI-Client, kein KI-Anbieter.

## Grenzen (ehrlich)

- Nicht mit echten Claude-, ChatGPT-, Cursor- oder anderen Clients getestet. Nur ein eigener JSON-RPC-Client.
- Nur Schlüssel (Bearer). Kein Browser-Login/OAuth, keine `/.well-known`-Discovery. Clients, die nur OAuth können, funktionieren nicht.
- Kleine Werkzeugmenge. Keine Plattform-/Admin-Werkzeuge, keine Mitglieder-Schlüssel, keine Tools für Kampagnen/E-Mail, Tiers, Gutscheine, Pässe, Support oder Löschen weiterer Objekte. Keine Rückgängig-Werkzeuge.
- Kein REST-Tool-Discovery-Endpunkt, kein OpenAPI für MCP, keine MCP-Apps-Ansichten, kein Streaming/SSE.
- Schlüssel sind kontoweit, nicht pro Unternehmen einschränkbar. Zugriffsstufen nur Lesen / Lesen und ändern, keine feineren Scopes.
- HTTPS wird nicht vom Code erzwungen. Nur hinter HTTPS betreiben.
- Gelesene Daten gehen an den KI-Anbieter des Clients, nach dessen Bedingungen.
- Nicht produktiv abgenommen.
