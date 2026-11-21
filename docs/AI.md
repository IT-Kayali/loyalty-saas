# KI-Verbindungen (MCP)

Ein KI-Client arbeitet über das Model Context Protocol (JSON-RPC über HTTPS) mit deinem Betreiberkonto. Angelehnt an die AI-Connections- und Agent-API-Doku der Referenz, aber bewusst kleiner. Die App braucht keinen KI-Anbieter und keinen eigenen Schlüssel dafür. Die Wahl des Clients und seine Kosten liegen bei dir.

## Einschalten

`'ai_enabled' => true` in `config.php` (Standard: aus, `/mcp` antwortet dann 404). Danach erscheint "KI" in der Navigation und `/ai` ist erreichbar. Die Seite zeigt die Adresse `https://deine-domain/mcp`.

## Schlüssel

Unter `/ai` erstellst du Schlüssel (`rl_ai_...`): Name, Zugriff **Nur lesen** oder **Lesen und ändern**, Laufzeit 1 bis 365 Tage, höchstens 10 aktive. Der Schlüssel erscheint genau einmal. Gespeichert wird nur ein SHA-256-Hash, danach siehst du das Präfix. Widerrufen wirkt sofort und schließt offene Freigabeanfragen des Schlüssels. Ein Schlüssel gehört deinem Konto und erreicht nur deine Unternehmen. Er kann nie Schlüssel erstellen, Passwörter ändern oder KI-Verbindungen schalten, dafür gibt es kein Werkzeug.

## Protokoll

`POST /mcp` mit `Authorization: Bearer rl_ai_...`. Unterstützt: `initialize`, `ping`, `tools/list`, `tools/call`, `prompts/list`, `prompts/get`, Benachrichtigungen (202) und Batches bis 20. Anfragen mit `Origin`-Header (Browser) werden abgelehnt. Standard 60 HTTP-Anfragen pro Minute und Schlüssel, im Umfang-Editor auf 1 bis 60 einstellbar. Jeder Werkzeugaufruf steht im Audit (Werkzeug, Schlüsselpräfix, Ergebnis, keine Mitgliedsdaten).

## Werkzeuge

Lesen (beide Zugriffsstufen): `list_businesses`, `get_business_overview`, `list_programs`, `list_members` (Suche, Cursor), `get_member`, `list_transactions`, `get_change_status`.

Ändern (nur Schlüssel mit Schreibzugriff, laufen sofort): `create_member`, `award_points`, `give_stamp`, `create_program` (Stempel oder Punkte), `run_approved_change`.

Wartet auf deine Freigabe: `delete_member`, `delete_program`.

Mit mehr als einem Unternehmen ist `business_id` Pflicht. Schreibwerkzeuge nehmen einen optionalen `idempotency_key` (8 bis 80 Zeichen): derselbe Aufruf wiederholt liefert das gespeicherte Ergebnis (`replayed: true`) und bucht nicht doppelt. Gleicher Schlüssel mit anderen Eingaben wird abgelehnt. War ein früherer Aufruf unklar beendet, wird er nicht blind wiederholt. Es gelten dieselben Plan-Regeln wie in der REST-API.

Prompts (als Jobs im Client): `weekly_report`, `member_lookup`, und mit Schreibzugriff `launch_stamp_card`.

## Schlüssel-Scopes

Beim Erstellen legst du den Umfang fest, zusätzlich zur Stufe (Nur lesen / Lesen und ändern):

- **Unternehmen:** alle Unternehmen des Kontos oder genau eines. Mit einem Unternehmen entfällt `business_id` (Standard), ein anderes Unternehmen liefert "Unternehmen nicht gefunden", auch bei Freigaben. `list_businesses` zeigt nur dieses.
- **Werkzeuggruppen** (mindestens eine): Auswertungen (`get_business_overview`, `list_transactions`), Mitglieder (`list_members`, `get_member`, `create_member`), Punkte und Stempel buchen (`award_points`, `give_stamp`), Programme (`list_programs`, `create_program`), Löschen mit Freigabe (`delete_member`, `delete_program`). `list_businesses`, `get_change_status` und `run_approved_change` sind immer dabei (letzteres nur bei Schreibstufe).
- Nicht erlaubte Werkzeuge fehlen in `tools/list` und werden bei `tools/call` wie unbekannte abgelehnt (-32602) und als `ai.scope.denied` protokolliert. Prompts richten sich nach Gruppen und Stufe.
- Der Umfang steht in der Schlüsselliste und im Audit (`ai.key.create`). Bestehende Schlüssel bleiben unverändert (alle Unternehmen, alle Gruppen). Alle Gruppen angehakt wird als "alle" gespeichert.
- Die Prüfung läuft serverseitig bei jedem Aufruf, nicht nur in der Oberfläche. Die Stufe bleibt maßgeblich: Gruppe "Punkte" mit Stufe "Nur lesen" erlaubt nichts Schreibendes.

## Freigaben

Ein riskantes Werkzeug ändert nichts. Es liefert `request_id`, `nonce` und einen Prüflink `/ai/approval/{id}`. Du siehst Änderung, Schlüssel, was sie betrifft (Anzahl Karten und Buchungen) und die Restzeit, und klickst Freigeben oder Ablehnen. Nur der Eigentümer kann entscheiden, fremde Konten bekommen 404. Die Anfrage gilt 10 Minuten ab Vorbereitung. Danach ruft der Client `run_approved_change` mit `request_id` und `nonce` auf. Das läuft höchstens einmal. Geprüft wird erneut: Schlüssel aktiv, Freigabe vorhanden und nicht abgelaufen, Datensatz unverändert seit der Prüfung (sonst "Nicht ausgeführt", Anfrage geschlossen), Plan. Zustände: pending, approved, rejected, expired, done, failed.

## Tests

`PHP_BIN=/pfad/php TEST_OUTPUT=/tmp/ai-images python tests/run-ai.py`: Endpunkt standardmäßig aus, 111 HTTP-Prüfungen (Scopes nach Unternehmen und Gruppe, Schlüssel gehasht, Auth, Origin, Protokoll, Leserechte, Mandantentrennung, Idempotenz, Freigabe/Ablehnung/Ablauf/geänderter Datensatz, Widerruf, Schlüsselablauf, Audit, Rate-Limit) und 38 Chromium-Prüfungen mit Screenshots (Desktop und Mobil). Kein echter KI-Client, kein KI-Anbieter.

## Grenzen (ehrlich)

- Nicht mit echten Claude-, ChatGPT-, Cursor- oder anderen Clients getestet. Nur ein eigener JSON-RPC-Client.
- Nur Schlüssel (Bearer). Kein Browser-Login/OAuth, keine `/.well-known`-Discovery. Clients, die nur OAuth können, funktionieren nicht.
- Kleine Werkzeugmenge. Keine Plattform-/Admin-Werkzeuge, keine Mitglieder-Schlüssel, keine Tools für Kampagnen/E-Mail, Tiers, Gutscheine, Pässe, Support oder Löschen weiterer Objekte. Keine Rückgängig-Werkzeuge.
- Kein REST-Tool-Discovery-Endpunkt, kein OpenAPI für MCP, keine MCP-Apps-Ansichten, kein Streaming/SSE.
- Scopes sind grob: Stufe, ein Unternehmen oder alle, und fünf feste Werkzeuggruppen. Keine Rechte je Programm, Mitglied oder Feld, keine Mengen- oder Betragslimits je Schlüssel, Scopes und HTTP-Anfragelimit sind im Editor änderbar.
- HTTPS wird nicht vom Code erzwungen. Nur hinter HTTPS betreiben.
- Gelesene Daten gehen an den KI-Anbieter des Clients, nach dessen Bedingungen.
- Nicht produktiv abgenommen.

## Umfang bestehender Schlüssel ändern

Unter `/ai` führt bei aktiven eigenen Schlüsseln "Umfang ändern" zum Editor. Einstellbar sind Nur lesen/Lesen und ändern, alle eigenen Unternehmen oder ein einzelnes sowie die fünf bestehenden Werkzeuggruppen. Mindestens eine Gruppe ist nötig. Abgelaufene und widerrufene Schlüssel bleiben nicht editierbar.

Speichern ändert den bestehenden Datensatz, nicht das Geheimnis oder Ablaufdatum. Derselbe Bearer-Schlüssel verwendet für neue Anfragen die neuen Rechte. Offene und bereits freigegebene, noch nicht ausgeführte Löschanfragen dieses Schlüssels werden in derselben Transaktion auf abgelaufen gesetzt, auch beim Speichern gleicher Werte. Dafür ist anschließend eine neue Anfrage und Freigabe nötig. Bereits begonnene API-Aufrufe werden nicht zurückgerufen. Der Editor nennt diese Wirkung ausdrücklich.

Nur der Eigentümer, CSRF, Eigentumsprüfung der ausgewählten Firma und Audit `ai.key.update`. Keine Änderung über den MCP-Endpunkt selbst. Der Client sollte anschließend `tools/list` neu laden; keine automatische `list_changed`-Push-Nachricht oder Stream-Verbindung. Weiterhin keine Objekt-Scopes oder eigenen Mengenlimits je Schlüssel.

Tests in `tests/ai-http.py` und `tests/ai-ui.cjs`: nachträgliche Einschränkung/Erweiterung und Gruppenwechsel mit demselben Schlüssel, Unternehmenswechsel, Hash/Ablauf unverändert, offene und freigegebene Anfragen verfallen, Fremd-Owner, CSRF, leere Gruppen und nicht editierbare alte Schlüssel. Desktop/Mobil; kein Test mit echten MCP-Clients.

## HTTP-Anfragelimit pro Schlüssel

Der Umfang-Editor bietet 1 bis 60 HTTP-Anfragen pro Minute. Neue und bestehende Schlüssel starten mit 60, das bisherige Schutzlimit wird also nicht erhöht. Die Liste zeigt den Wert. Das Limit ist an die Schlüssel-ID gebunden, atomar in SQLite und verwendet feste UTC-Minutenfenster. Am Fensterwechsel kann kurzfristig die doppelte Menge passieren, es ist kein gleitendes Fenster.

Gezählt werden authentifizierte POST-Anfragen an `/mcp`, einschließlich ping, Discovery und fehlerhaftem JSON. Ein JSON-RPC-Batch zählt als eine HTTP-Anfrage und darf weiter bis zu 20 Einzelaufrufe enthalten. Das Limit ist kein Punkte-/Stempel-/Buchungsbudget, keine Betragsgrenze und keine Gesamtgrenze über alle Schlüssel. Ein Schlüssel mit 1 HTTP/Minute kann in dieser Anfrage weiterhin einen Batch senden.

Bei Überschreitung: HTTP 429, JSON `rate_limited`, `Retry-After` bis zum nächsten Minutenfenster. Änderungen am Limit setzen den aktuellen Zähler nicht zurück. Wie beim übrigen Umfang-Editor verfallen offene Freigaben beim Speichern. Keine automatische Client-Benachrichtigung, keine automatische Wiederholung durch den Server.

Technik: `ai_keys.requests_per_minute`, additive Migration mit Default 60. Tests prüfen Standard, Grenzen, Eigentum/CSRF, individuelle Ablehnung, unabhängige Schlüssel und Wiederzulassung im nächsten Fenster. Kein externer Lasttest.
