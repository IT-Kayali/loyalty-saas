# Einstieg für spätere Weiterentwicklung

## Vorrangiger Sicherheitsstand: öffentlicher Beitritt

Der öffentliche Beitritt legt bei bereits bekannter normalisierter E-Mail keine weitere Login-Zeile und keine neue Wallet-Sitzung an. Neue E-Mails und einzelne Kundenkarten ohne E-Mail bleiben möglich. Das ist eine begrenzte Schutzmaßnahme, keine vollständige Freigabe der anderen Identitätswege. Bestehende Sitzungen und frühere Mitgliedszeilen werden nicht bereinigt. [Verhalten und Grenzen](MEMBER-JOIN-IDENTITY.md). Test mit künstlichen Daten: `PHP_BIN=php python3 tests/run-member-join-identity.py`; Browserteil benötigt Playwright/Chromium wie die übrigen UI-Tests.

## Lokale Umgebung

Die Plattform ist eine PHP-/SQLite-Anwendung ohne Composer-Laufzeitabhängigkeiten. PHP 8.1+ ist nötig (unter anderem `never`-Rückgabetypen). PDO SQLite, mbstring und GD sind für Datenbank, Text und Bildverarbeitung erforderlich; cURL/OpenSSL und ZIP für die entsprechenden Provider-, Wallet- und Backup-Pfade. Die Testumgebung muss diese Erweiterungen ebenfalls haben. Die tatsächlich benötigte Providerkonfiguration steht in den Fachdokumenten, nicht in diesem Repository als Geheimnis.

```bash
git clone https://github.com/IT-Kayali/loyalty-saas.git
cd loyalty-saas
cp config.example.php config.php
# base_url, Speicherpfade und optional Provider in config.php anpassen.
php seed.php
php -S 127.0.0.1:8099 -t public public/index.php
```

`seed.php` löscht die vorhandenen Demo-/Anwendungsdaten und legt Beispieldaten neu an. Niemals auf einer echten Kundendatenbank ausführen. Beispielzugänge gelten nur nach dem Seeding:

| Ansicht | Pfad | Zugang |
| --- | --- | --- |
| Betreiber | `/login` | `demo@loyalty.local` / `demo1234` |
| Staff Foundry Coffee | `/staff/login?b=1` | Business-Nr. `1`, Name `Lena`, PIN `4321` |
| Mitglied | `/member/login` | `aylin@example.com` / `kunde1234` |

Diese veröffentlichten Seed-Zugangsdaten sind keine Produktionsgeheimnisse und dürfen nie für einen echten Betrieb verwendet werden. Eigene Betreiber-/Team-/Mitgliederkonten und neue Schlüssel anlegen.

### Sichtbare Zugänge auf einer öffentlichen Demo

Nur für eine vollständig getrennte, wegwerfbare Demo in `config.php` den Schalter `'demo_banner' => true` setzen. Im Beispiel ist er `false`. Alternativ `DEMO_MODE=1` als Umgebungsvariable setzen. Beispiel lokal:

```bash
DEMO_MODE=1 php -S 127.0.0.1:8099 -t public public/index.php
```

Auf Apache/Shared Hosting ist der Config-Schalter meist einfacher. Bei PHP-FPM/Container/Prozessmanager muss eine alternativ verwendete Umgebungsvariable den PHP-Prozess erreichen. Das Flag zeigt den Demo-Hinweis samt obigen Test-Zugängen auf Startseite sowie Betreiber-, Staff- und Mitglieder-Login. Es erzeugt keine Konten, umgeht keine Anmeldung, ändert keine Rechte und schaltet keine externen Provider automatisch ab. Ohne aktivierten Config-Schalter oder exakt `DEMO_MODE=1` erscheint der Hinweis nicht. Kein Queryparameter oder Cookie kann ihn aktivieren.

Die konkrete Hosting-Adresse, Kostenfreigabe, Prozess-/Proxykonfiguration, Datensicherung und ein Reset-Zeitplan sind vom tatsächlichen Deployment abhängig. Dieses Dokument behauptet keine bereits laufende dauerhafte Demo. Öffentliche Demo: keine echten Kundendaten, Providergeheimnisse, Zahlungen oder Mailzustellung. Besucher teilen sich Testkonten und können sich gegenseitig Daten/Passwörter verändern. Ein reiner Zugangshinweis macht die Anwendung nicht zu einer gehärteten Sandbox. Sicheren Datenreset und Wiederherstellung der Testkonten im Hostingbetrieb gesondert organisieren.

## Architekturkarte

| Datei/Bereich | Zuständigkeit |
| --- | --- |
| `public/index.php` | Front Controller, Routing, Betreiberseiten; Webroot ist ausschließlich `public/` |
| `app/bootstrap.php` | Konfiguration, PDO/Schema und additive Migrationen, zentrale Helfer, Layout, Transaktionsjournal |
| `app/inc/roles.php`, `counter.php` | Staff-/Mitgliederzugang, kundenseitige Konten und Thekenabläufe |
| `app/inc/security.php`, `policy.php` | Sessions, Rate Limits, Fehlerausgabe, Plan-/Mengenrechte inklusive SQLite-Verbindungs-Trigger |
| `app/inc/designer.php`, `images.php` | Programmgestaltung und sichere Bildneukodierung |
| `app/inc/vouchers.php`, `prepaid.php` | Gutscheincodes und separat verkaufte Besuchs-/Zeitpässe mit Ledger |
| `app/inc/engage.php`, `segments.php`, `tiers.php` | Achievements, Referrals, Inbox, Kampagnen und Stufen |
| `app/inc/mail.php`, `recovery.php` | SMTP-Outbox, Einwilligung/Abmeldung, Textautomationen, einmalige Passwortresets |
| `app/inc/billing.php`, `paypal.php` | Stripe-/PayPal-Abos, providergebundener Statusabgleich und Webhooks |
| `app/inc/api.php`, `merchant.php` | REST/OpenAPI, Shop-Eingang, ausgehende Webhooks und neuer idempotenter Merchant-Pfad |
| `app/inc/wallet.php`, `wallet-versions.php`, `wallet-apns.php`, `wallet-auth.php`, `qr.php` | Apple-/Google-Wallet, PassKit-Web-Service, technische Inhaltsrevisionen/Validatoren und QR-Ausgaben |
| `app/inc/organization.php` | Netzwerke eigener Unternehmen, Standorte und Teamrechte |
| `app/inc/admin.php`, `admin-health.php`, `analytics.php` | Plattformverwaltung, lesender Versandzustand, Basis-Audit und Auswertungen |
| `integrations/woocommerce/` | WordPress-Händlerdashboard und optionaler WooCommerce-Bestellhook |
| `integrations/shopify/` | Shopify-Einrichtung des signierten Bestelleingangs |
| `tools/` | Betreiber-CLI für Backup/Restore, Mail-Worker und PayPal-Abgleich |
| `tests/` | Isolierte Domänen-, echte HTTP- und Chromium-Prüfungen; Provideradapter nur in Testinstallationen |
| `data/` | Private Laufzeitdaten außerhalb des Webroots, insbesondere SQLite und hochgeladene Bilder |

`config.php`, Datenbank, Uploads, Zertifikate, private Schlüssel und echte Providerzugänge gehören nicht in Git. Backups enthalten Geheimnisse. Der eingebaute PHP-Server ist ein Entwicklungsserver, kein Produktions-Webserver.

## Änderungen sicher einordnen

1. Änderungen nach Unternehmen und Eigentümer eingrenzen. Keine vom Browser gelieferte `business_id` als Berechtigung übernehmen.
2. HTTP-Rechte und SQL-Planregeln gemeinsam pflegen. Neue Mandantentabellen in `install_plan_guards()` aufnehmen. Datenschutzlöschung darf nicht durch einen abgelaufenen Plan blockiert werden.
3. Kontostand, Journal und Wiederholungsbeleg zusammen in einer SQLite-Schreibtransaktion ändern. Kein externer Seiteneffekt vor dem Commit. Vorhandene ältere API-Pfade sind nicht automatisch so stark abgesichert wie die neuen Merchant-/Bestellpfade.
4. Für jede neue API-Aktion Tenant-, Fehlertyp-, Plan-, Replay- und Paralleltests ergänzen. Die neue Merchant-API bewirkt noch keine zusätzlichen externen Workflows, siehe API-Doku.
5. Geheimnisse nie in Browserantworten oder Logs schreiben. SSRF-/TLS-/Redirect-Regeln der Integrationen erhalten.
6. Schemaänderungen additiv und rückwärtsverträglich planen. `seed.php` ist keine Produktionsmigration. Bestehende Daten sichern und Migrationen separat testen.
7. Änderungen und reale Grenzen in diesem Index, den passenden Fachseiten und `FEATURE-AUDIT.md` nachtragen. Codefertig, lokal getestet und vom externen Anbieter bestätigt sind verschiedene Zustände.

## Tests ausführen

Die Python-Runner arbeiten mit temporären Instanzen, starten aber feste lokale Ports, meist 8099. Nicht parallel starten. PHP muss als `php` erreichbar sein oder über `PHP_BIN` gesetzt werden. Für Browsertests werden Node.js, `playwright` und ein Chromium-Binary benötigt, beispielsweise in einer lokalen Testumgebung `npm install --no-save playwright` und `npx playwright install chromium`. Node ist keine Produktionsabhängigkeit. Testskripte und MU-Adapter niemals als produktive Endpunkte bereitstellen.

```bash
php tests/billing.php
python tests/run-policy.py
python tests/run-programs.py
python tests/run-mail.py
python tests/run-organization.py
python tests/run-paypal.py
python tests/run-merchant.py
python tests/run-demo.py
python tests/run-pwa.py  # 23 Assertions, Kaltstart mit Aktivierung + Online-Neuladen
python tests/run-support.py  # 23 Domänen-, 113 HTTP-, 58 Browserprüfungen
python tests/run-support-list.py  # 73 Domänen-, 87 HTTP-, 45 Browserprüfungen, privater Admin-Metadatenexport
python tests/run-support-search.py  # 42 Domänen-, 82 HTTP-, 48 Browserprüfungen, DE/EN-Labelsuche und sprachfeste Exporte
python tests/run-ai.py
python tests/run-templates.py
python tests/run-expiry.py
python tests/run-lifecycle.py
python tests/run-transfers.py
python tests/run-claims.py
python tests/run-cardlayout.py
python tests/run-voucher-batches.py
python tests/run-member-password.py
python tests/run-member-profile.py
python tests/run-owner-password.py
python tests/run-webhooks.py
python tests/run-backup.py
python tests/run-browser.py
```

`TEST_OUTPUT=/privater/pfad` setzt für die jeweiligen Browserrunner den Screenshot-Ordner. PHP-Lint und `git diff --check` zusätzlich ausführen. Für echte lokale WordPress-Rollen/Adminseiten wird aus heruntergeladenen WordPress-/SQLite-Testarchiven eine eigene temporäre Installation erzeugt:

```bash
python tests/run-wordpress.py \
  --wordpress-zip /pfad/wordpress.zip \
  --sqlite-zip /pfad/sqlite-database-integration.zip
```

Das WordPress-Testfixture blockiert E-Mail und externe Requests. Nur seine feste Testadresse wird per MU-Adapter zur lokalen Plattform geführt. Testzugänge in diesem temporären WordPress: `testadmin`, `manager`, `viewer`, jeweils `local-test-only`. Sie existieren weder automatisch auf der Plattform noch auf einer späteren Hosting-Demo. WooCommerce-Bestellobjekte werden nur nachgebildet. Provideradapter, Dummy-Schlüssel, Rückgabesignaturen und simulierte SDK-Fenster sind keine echten Sandbox- oder Live-Abnahmen.

## Fachseiten und offene Arbeit

- [Vollständiger Umfang und offene Parität](FEATURE-AUDIT.md)
- [Betrieb, Sicherheit und Backup/Restore](OPERATIONS.md)
- [REST- und Merchant-API](API.md), maschinenlesbar über `/api/v1/openapi.json` mit API-Authentifizierung
- [WordPress-Setup, Rollen, Datenhaltung und Retry](../integrations/woocommerce/README.md)
- [Programme, Gutscheine und verkaufte Pässe](PROGRAMS.md)
- [Offline-Wallet und PWA](PWA.md)
- [Support-Tickets](SUPPORT.md)
- [KI-Verbindungen und MCP](AI.md)
- [Programm-Vorlagen nach Branche](TEMPLATES.md)
- [Punkteverfall](POINTS-EXPIRY.md)
- [Lifecycle-Gutscheine](LIFECYCLE-VOUCHERS.md)
- [Mitglieder-Punkteanfragen](POINT-REQUESTS.md)
- [Webhook-Retries](WEBHOOK-RETRIES.md)
- [Einlösecodes für Prämien](REWARD-CODES.md)
- [Zeitzone je Unternehmen](TIMEZONE.md)
- [Karten-Designer: Vorder-/Rückseite](CARD-DESIGNER.md)
- [Organisation, Standorte und Team](ORGANIZATION.md)
- [SMTP, Outbox, Automationen und Recovery](MAIL.md)
- [Stripe](STRIPE.md), [PayPal](PAYPAL.md), [Wallet](WALLET.md)

Weiter offen sind unter anderem vollständige PWA-/Geräteparität, Support-Parität (siehe SUPPORT.md), volle AI/MCP-Parität (siehe AI.md), Geldguthabenpässe, vollständige Original-Detailregeln, mehrere Delegations-/Mitglieder-Authentifizierungsfunktionen sowie echte Hosting-/Provider-/Geräteabnahmen. Die Liste im Feature-Audit ist maßgeblich. Vor einer Produktionseinführung unabhängige Sicherheits-/Betriebsprüfung einplanen.

- [Mitglieder-Passwort ändern](MEMBER-PASSWORD.md)

- [Eigene Mitgliedsnamen](MEMBER-PROFILE.md)

- [Eigenes Betreiber-Passwort](OWNER-PASSWORD.md)

## Gutschein-QR

`python3 tests/run-voucher-qr.py`: persönliche QR-Codes, striktes Scanformat, Mandant/Rechte, keine GET-Buchung, Bestätigung, CSRF, Storno und Desktop/Mobil. Details: [VOUCHER-QR.md](VOUCHER-QR.md).

## Code-Gutschein-Auswertung

`python3 tests/run-voucher-report.py`: reine Eigentümer-Leseansicht, UTC-Zeitraum, Währungs-/Typtrennung, Snapshot- und Datensatzgrenzen sowie responsive Browserprüfungen. [VOUCHER-REPORT.md](VOUCHER-REPORT.md).

## Analytics

`python3 tests/run-analytics.py`: Code-Gutschein-Einlösungen im bisherigen Dashboard, Allzeit-/30-Tage-Grenzen, Stornos, Idempotenz, Rechte und mobile Programm-Performance. [ANALYTICS.md](ANALYTICS.md).

## Segment-Editor

`python3 tests/run-segments.py`: vorhandene Einzelregeln editieren, Konfliktschutz, kein Versand, Kampagnenverknüpfung/-historie und Rechte. [SEGMENTS.md](SEGMENTS.md).

## Unternehmensname

`python3 tests/run-business-settings.py`: Name des eigenen Unternehmens, Konfliktschutz, keine Nebenänderungen, bestehende Designertexte, Rechte/Plan und responsive Browserfälle. [BUSINESS-SETTINGS.md](BUSINESS-SETTINGS.md).

Die Segment-Suite prüft zusätzlich die ungespeicherte Anzahl-Vorschau ohne Änderungen an Segmenten, Kampagnen, Versand und Audit; Konfliktschutz bleibt auch nach Vorschau erhalten.

## Kampagnen-Vorbereitung

`python3 tests/run-campaign-dispatch.py`: atomare lokale Vorbereitung, Rollback durch injizierten Fehler, konkurrierende Prozesse, sichere Wiederholung und blockierte historische Teilversände, ohne SMTP. [CAMPAIGN-DISPATCH.md](CAMPAIGN-DISPATCH.md).

## Kampagnenentwurf

`python3 tests/run-campaign-edit.py`: 35 Domänen-/HTTP-/Parallelprüfungen und 12 Browserprüfungen für Editieren ohne Versand, vollständige Textansicht, Konflikte und unveränderbare vorbereitete Inhalte. [CAMPAIGN-EDIT.md](CAMPAIGN-EDIT.md).

Die Gutschein-Auswertung prüft jetzt auch den aggregierten CSV-Download: 40 Domänen-/HTTP- und 16 Browserprüfungen. Schema, Datumsfilter, Centwerte ohne Währungsaddition, Rechte und harter Abbruch bei zu vielen Datensätzen.

## QR gekaufter Pässe

`python3 tests/run-prepaid-qr.py`: 26 Domänen-/HTTP- und 11 Browserprüfungen für eigene Wallet-QRs, keine öffentlichen Vollcodes, Scan ohne Buchung, Rechte, Datum, Version und ausdrückliche Besuchsbestätigung. [PREPAID-QR.md](PREPAID-QR.md).

## Marketing-Löschschutz

`python3 tests/run-marketing-delete.py`: 23 Domänen-/HTTP- und 11 Browserprüfungen für Bestätigung, Revision, Mandant, unverknüpfte Segmente, unvorbereitete Entwürfe und Konkurrenz mit Versand. [MARKETING-DELETE.md](MARKETING-DELETE.md).

Die Support-Suite prüft jetzt 23 Domänen-, 65 HTTP- und 32 Browserfälle, einschließlich Kanal-/Namensfilter, kombinierter Paginierung über 52 Datensätze und vollständig lesbarer mobiler Inbox. [SUPPORT.md](SUPPORT.md).

Support-Thread-Download: Suite jetzt 23 Domänen-, 79 HTTP- und 36 Browserprüfungen. Eigenes JSON mit expliziter Feldliste, ohne interne Notizen/Metadaten, 1000-Nachrichten-/2-MiB-Grenzen, echte Browserdownloads. [SUPPORT.md](SUPPORT.md).

## Meilenstein-Editor

`python3 tests/run-achievement-edit.py`: 83 Domänen-/HTTP-/Parallel- und 24 Browserprüfungen für Bearbeiten vor erster Freischaltung, keine Vergabe beim Speichern, Konflikt, Regel-/Historienerhalt und atomare lokale Bonusvergabe. [ACHIEVEMENTS.md](ACHIEVEMENTS.md).

Meilenstein-Suite jetzt 50 Domänen-/HTTP-/Parallel- und 18 Browserprüfungen einschließlich Pause/Fortsetzen ohne Entzug oder nachträgliche Vergabe. [ACHIEVEMENTS.md](ACHIEVEMENTS.md).

### Admin-Protokoll-Leseansicht

`python3 tests/run-admin-audit.py`: unveränderte 39 HTTP-/Datenbank- und 20 Desktop-/Mobilbrowserprüfungen für reine Leseansicht, Filter, Cursor, Rollen, Nebenwirkungsfreiheit und ausgeschlossene Details. Neu: `python3 tests/run-admin-audit-export.py`, 99 Domänen-, 86 HTTP-/Integrations- und 51 Browserprüfungen für filtergleiches privates Vier-Felder-CSV, alle Treffer statt nur 100er-Seite, aktuelle Allowlist, Methodensperre, kein privates JSON-Payload, exakte Zeilen-/Byte-/Zellgrenzen, geschlossene/öffnende Hilfe und echte Browserdownloads bei 1280/390/320px. Die bisherigen UTC-Zeittextvergleiche bleiben ausdrücklich erhalten. [Vertrag und Grenzen](ADMIN-AUDIT.md).

### Netzwerknamen

`python3 tests/run-network-edit.py`: 28 HTTP-/Domänen- und 14 Desktop-/Mobilbrowserprüfungen, einschließlich Rollen/CSRF/Plan, atomarem ID-Audit, Namensgrenzen, Parallel- und Zwei-Tab-Konflikten. [ORGANIZATION.md](ORGANIZATION.md).

### Standorttexte

`python3 tests/run-location-edit.py`: 32 HTTP-/Domänen- und 16 Desktop-/Mobilbrowserprüfungen für reine Textänderungen, feste Bindungen, unabhängige Aktivierung, Rechte, atomaren Audit und Parallel-/Zwei-Tab-Konflikte. [ORGANIZATION.md](ORGANIZATION.md).

### Unzugeordnete Netzwerke löschen

`python3 tests/run-network-delete.py`: 21 HTTP-/Domänen- und 12 Browserprüfungen für Vorschau/Bestätigung, Sperre bei Zuordnung, atomaren Audit und echtes Zuordnungs-/Löschrennen. [ORGANIZATION.md](ORGANIZATION.md).

### Expliziter Standortstatus

`python3 tests/run-location-state.py`: 19 HTTP-/Domänen- und 10 Browserprüfungen für explizite Aktivierung, Konflikte, Rechte, atomaren Audit und zwei parallele Deaktivierungen. `run-organization.py` prüft weiterhin den Team-Sitzungsentzug am inaktiven Standort. [ORGANIZATION.md](ORGANIZATION.md).

## Gutschein-Batch-Druck

Privater A4-Browserdruck und einzelne lokale QR-PNGs, ohne Erzeugen/Einlösen oder Versand: [VOUCHER-PRINT.md](VOUCHER-PRINT.md). Tests: `python3 tests/run-voucher-print.py`.

## UTC-Monatsübersicht

Separate private Eigentümer-Leseansicht mit Monatsfilter und klar abgegrenzten Ereigniszahlen, ohne Versand oder finanzielle Auswertung: [MONTHLY-SUMMARY.md](MONTHLY-SUMMARY.md). Tests: `python3 tests/run-monthly-summary.py`.

## Mitgliedsstufen-Editor

Name, Lifetime-Schwelle und Vorteil eigener Stufen mit Konfliktschutz bearbeiten, keine Punktebuchung oder automatische Vergünstigung: [TIER-EDITOR.md](TIER-EDITOR.md). Tests: `python3 tests/run-tier-edit.py`.

## Bonuszeiten-Editor

Bestehende Bonusfenster mit Konfliktschutz ändern. Die bisherige Serverzeitzonen-/Überlappungsregel bleibt erhalten und wird ausdrücklich angezeigt: [BONUS-EDITOR.md](BONUS-EDITOR.md). Tests: `python3 tests/run-bonus-edit.py`.

## Wallet-Nähe (vorbereitet, nicht gerätegeprüft)

Getrennter Standorteditor mit ausdrücklicher Auswahl und bis zu zehn Geschäftskoordinaten je Unternehmen. Apple `locations`, Google-Objekt `merchantLocations`, keine Kundenortung durch die App. Signierung/Issuer und echte Geräteprüfung bleiben nötig: [WALLET-NEARBY.md](WALLET-NEARBY.md). Tests: `python3 tests/run-wallet-nearby.py`.

## Wallet-Nähe: Datenschutz und manuelle Hinweise

Eigene Betreiberangaben, Datenschutzlink und Zusatztext; feste technische Warnungen bleiben sichtbar. Separate freiwillige Kundenwahl je Unternehmen, standardmäßig aus und unabhängig von Marketing. Neue Apple-/Google-Passdaten enthalten nur bei zum Hinweis-/Koordinatenstand passender Zustimmung Nähepunkte. Widerruf bleibt ohne aktives Betreiber-Abo möglich; keine garantierte Änderung alter Passkopien. Export enthält den bestätigten Hinweisstand, lokale Kontolöschung räumt Apple-Geräteregistrierungen atomar auf. Keine Rechtsprüfung oder DSGVO-Zusage. [Einrichtung, Datenmodell und Grenzen](WALLET-NEARBY-PRIVACY.md). Tests: `python3 tests/run-wallet-nearby-privacy.py`.

## Stufen-/Bonuslöschung

Private Prüfseite statt sofortigem Kreuz-Button, Bestätigung und Konfliktschutz: [PROGRAM-RULE-DELETE.md](PROGRAM-RULE-DELETE.md). Tests: `python3 tests/run-rule-delete.py`.

## Punkteprämien-Editor

Name und Punktekosten bestehender Prämien konfliktsicher bearbeiten, mit gemeinsamer strikter Anlageprüfung. Vorhandene Einlösecodes behalten ihre Name-/Kostensnapshots, keine Saldo-/Journaländerung beim Speichern: [REWARD-EDITOR.md](REWARD-EDITOR.md). Tests: `python3 tests/run-reward-edit.py`.

## Konfliktsichere Prämien-Zeitfenster

Bestehende Datumsfelder prüfen vor dem Speichern Fenster und Unternehmenszeitzone erneut. Keine stille Umdeutung alter Eingaben nach Zeitzonenwechsel, Update/Audit atomar. Bestehende Code-Snapshots und Salden bleiben unverändert: [REWARD-WINDOW.md](REWARD-WINDOW.md). Tests: `python3 tests/run-reward-window.py`.

## Monatsübersicht als CSV

Privater aggregierter CSV-Download für den ausgewählten UTC-Monat, ohne Mitgliedslisten oder Geldsummen. Gleiche Definitionen wie die Monatsansicht, eigener aktueller Lesesnapshot und Formelschutz: [MONTHLY-SUMMARY.md](MONTHLY-SUMMARY.md). Tests: `python3 tests/run-monthly-csv.py`.

## Wallet-Mitteilungen: Wahl und Textvorlagen

Eigene, zunächst ausgeschaltete Apple-/Google-Wahl je Mitgliedschaft, manuelle Betreiberhinweise und getrennte Textvorlagen. Zusätzlich getrennte Vorschau und ausdrücklich bestätigter Einzelpass-Versand mit standardmäßig ausgeschaltetem Server-Schalter. Keine Werbefreigabe, keine Zustell- oder DSGVO-Zusage. Änderungen an Betreiberhinweisen oder Unternehmensname erfordern neue Bestätigung, Textvorlagen senden nichts. [WALLET-PUSH.md](WALLET-PUSH.md). Tests: `python3 tests/run-wallet-push.py` und `python3 tests/run-wallet-message.py`. NFC ist auf Nutzerwunsch nicht vorgesehen.

## Apple-PassKit-Prüfstände

`app/inc/wallet-versions.php` hält technische Inhaltsrevisionen, verschlüsselte gerätegebundene Cursor und HTTP-Validatoren. Die Cachemetadaten sind keine neue Betreiber-Schreibfunktion; sie müssen bei erlaubten Wallet-Leseabrufen auch ohne aktive Planbuchung konsistent fortgeschrieben werden können. Keine neue Guthaben-/Kampagnenfreigabe. Bei Rendereränderungen die Inhaltsformatkennung bewusst erhöhen. [WALLET-UPDATES.md](WALLET-UPDATES.md), Test: `python3 tests/run-pass-service.py`. `tools/backup.php` entwertet beim unterstützten Restore alte Cursor und leert den Prüfcache, nicht die Registrierungen oder Mitgliedsdaten. Native Geräte-/Signierabnahme bleibt offen.


## Apple-APNs: gemeinsamer Zertifikatstransport

Kartenstand und optionaler Einzelversand verwenden jetzt denselben Produktions-/Passzertifikatstransport. Neue Installationen bleiben mit `apple_wallet.updates_enabled => false` aus; alte Sandbox-Konfigurationen werden nicht still produktiv. Typ-/Serienabgrenzung, enge Tokenbereinigung, keine automatische Wiederholung und keine Unterbrechung bereits gebuchter Kartenaktionen durch Apple-Signalfehler. Die optionale Kundenwahl, Vorschau und Bestätigung bleiben getrennt nötig. [Einrichtung, Altconfig-Regeln und Grenzen](WALLET-APNS.md). Tests: `python3 tests/run-wallet-apns.py` (PHP/cURL mit HTTP/2; nur Ersatztransporte). Keine echte APNs-/Geräteabnahme. Stabile Wallet-Zugangsdaten bei API-Schlüsselrotation sind separat in WALLET-AUTH.md beschrieben.


## Wallet-Zugänge bleiben bei API-Schlüsselrotation erhalten

Private eigene Tabelle mit einmaliger atomarer Legacy-Übernahme und getrennten zufälligen Pass-/Bild-Token für neue Mitglieder. Ein neuer API-Schlüssel sperrt weiterhin den alten API-Bearer, aber nicht ungewollt installierte Apple-Pässe oder vorhandene Google-Stempelbild-Links. Eigentum, CSRF, Planregeln, Audit, Parallelität, Exportgrenzen und Backup/Restore lokal geprüft; keine echte Provider-/Geräteabnahme. Früher bereits verlorene Token bleiben ein gesonderter Altfall. [Migration und Sicherheitsgrenzen](WALLET-AUTH.md). Tests: `python3 tests/run-wallet-auth.py`.


## Apple-Registrierungen: Schutz vor verspäteten APNs-Fehlern

Jede authentifizierte Registrierung erhält einen neuen monotonen Stand, ohne bestehende Zeilenkennung, Erstellungszeit oder Catch-up-Revision zu verlieren. APNs-Bereinigung verwendet den vor dem Versand festgehaltenen Prüfstand und entfernt keine währenddessen erneuerten, zusätzlichen oder neu angelegten Registrierungen. Bei 410 zusätzlich strenger Vergleich mit Apples Ungültigkeitszeitpunkt; unbekannte Legacy-Zeiten und nicht eindeutig geordnete Zeitwerte bleiben ohne Löschung. Additive Migration, kein Mischbetrieb alter/neuer Worker, keine neue Versandfreigabe. [Verfahren und Grenzen](WALLET-REGISTRATION.md). Neue gezielte Suite: `python3 tests/run-wallet-registration.py` (78 Domänenprüfungen plus Upgrade mit sechs gleichzeitig gestarteten Prozessen). Keine echte APNs-/Geräteabnahme und keine neue Oberfläche.


## Ältere REST-Buchungen: Saldo und Journal gemeinsam

Die drei bestehenden Punkte-/Stempel-POSTs lesen unter Schreiblock und schließen Saldo, Journal, Rollover sowie lokale Meilensteinbelege/Bonuspunkte/Inbox gemeinsam ab. Providerarbeit erst danach; eine dort geworfene Ausnahme meldet die bereits gebuchte Aktion nicht falsch als 500, sondern ergänzt eine sichere Warnung. Bestehende Betrags-/Auswahlregeln bleiben dokumentiert erhalten. Keine Idempotenzgarantie und keine genau-einmalige externe Zustellung, bei Timeout nicht blind wiederholen. [Geltungsbereich, Antworten und Grenzen](LEGACY-API-CREDITS.md). Neue Prüfung: `python3 tests/run-api-credit.py` (34 Domänen- und 18 Integrationsfälle, echte parallele PHP-/HTTP-Aufrufe, erzwungene Rollbacks). Keine neue Oberfläche oder echte Providerabnahme.

## API-/Shop-Mitglieder: atomare Anlage

Mitgliedssuche, Neuanlage und alle aktuell aktiven Programmkarten schließen im gemeinsamen API-/Shop-Helfer unter Schreiblock ab. Bestehende Profile und Karten bleiben unverändert; leere E-Mail erzeugt weiterhin pro Aufruf ein neues Mitglied. Shopify behält seinen eigenen Integrationsplan. Ein danach geworfener Mitgliederereignisfehler lässt die erfolgreiche Anlage bestehen und ergänzt `warning=member_event_incomplete`. Keine globale E-Mail-Eindeutigkeit, historische Reparatur, vollständige Bestelltransaktion oder externe Genau-einmal-Zustellung. [Verhalten, Antworten und Grenzen](API-MEMBER-UPSERT.md). Tests: `python3 tests/run-api-member-upsert.py` (21 Domänen- und 25 Integrationsfälle, echte parallele PHP-/HTTP-Aufrufe). Keine neue UI oder echte Providerabnahme.

## Versandzustand für Plattform-Admins

Neue rein lesende Seite `/admin/health`, im Admin-Dashboard verlinkt: aufbewahrte Mail-/Webhook-Zustände, fällige oder gesperrte Wiederholungen, unklare Ergebnisse und gültige UTC-Zeiten. Keine Empfänger oder Inhalte, kein Versand, kein Retry und kein behaupteter Cron-Heartbeat. Private GET-Ansicht nur für konfigurierte Plattform-Admins, Desktop und mobil geprüft. Wallet-Mitteilungen bleiben ausdrücklich außerhalb dieser Queueübersicht. [Zähler, Grenzen und Prüfung](ADMIN-HEALTH.md). Tests: `python3 tests/run-admin-health.py` mit 21 Domänen-, 25 Integrations-/HTTP- und 24 Browserprüfungen. Keine echte Provider- oder Produktionsabnahme.


## Privates Queue-JSON für Plattform-Admins

`GET /admin/health/json` ergänzt die vorhandene Versandübersicht um versioniertes JSON derselben Mail-/Webhook-Momentaufnahme. Gültige Betreiber-Session und aktuelle Plattform-Allowlist erforderlich, kein API-Bearer-Ersatz; privat/no-store, nur GET, generische Fehler ohne Teilzähler. `success` bestätigt nur das Lesen, keinen gesunden Betrieb. Geltungsbereich und ungeprüfte Bereiche stehen in jeder erfolgreichen Antwort. Keine Ampeln, Heartbeats, Providerverbindungen, neuen Versand-/Retry-Aktionen oder vollständige Referenzdiagnosen. HTML-Link bei 1280/390/320px geprüft. [Vertrag, Zugang und Grenzen](ADMIN-HEALTH.md). Tests: `python3 tests/run-admin-health-json.py` mit 38 Domänen-, 81 HTTP-/Integrations- und 24 Browserprüfungen. Keine echte Provider-/Geräteabnahme.


## Eigene Webhook-Zustellliste mit CSV

Neue private, rein lesende Liste `/business/{id}/webhook-deliveries` mit exaktem Event-, Status-, Webhook- und UTC-Zeitraumfilter sowie 50er-Seiten. CSV exportiert alle passenden eigenen Metadaten, ohne Payload, Secret, Ziel-URL oder rohe Antwort. Feste sieben Spalten, maximal 5000 Zeilen/4 MiB/64 KiB je Zelle, sonst keine Teildatei. Fehlende Referenzmetadaten werden nicht erfunden; letzter gespeicherter Verarbeitungszeitpunkt ist kein Zustellnachweis. Rechte, sechs Versuche, manuelle Retries und Cron unverändert. Mobile Zeilenkarten bei 390/320px geprüft. [Vertrag, Filter, Grenzen und Tests](WEBHOOK-LIST.md). Neue Suite: `python3 tests/run-webhook-list.py` mit 89 Domänen-, 72 HTTP-/Integrations- und 33 Browserprüfungen. Keine echte Provider-/Tabellenprogrammabnahme und keine volle Referenzparität.

## Browserprüfungen: Zielseite vor dem Prüfen abwarten

Die Profilprüfung wartet nach **Meine Namen** ausdrücklich auf `/member/profile` und das erste sichtbare Namensformular. Die Prämienprüfung wartet nach den Links in den Editor und zurück ins Programm auf die jeweilige Ziel-URL und den sichtbaren Zielinhalt. Erst danach laufen die bisherigen fachlichen Aussagen.

Anlass war ein reproduzierter zu früher Profil-DOM-Check: Die richtige URL war schon erreicht, das Dokument noch `interactive`; die unmittelbare Abfrage fand null Formulare, nach Bereitschaft waren es zwei. Das ist kein nachgewiesener Produktfehler. Beim früheren Prämienabbruch bleibt die ursprüngliche Ursache offen; die entsprechenden Linkwechsel sind nun ebenfalls ausdrücklich abgesichert.

Keine festen Wartepausen, abgeschwächten Aussagen, erweiterten Timeouts oder Produkt-/Oberflächenänderungen. Entfernt man nur die drei ergänzten Bereitschaftswartefolgen aus den beiden Browserdateien, entsprechen sie bytegenau dem vorherigen Stand. Jeder der beiden vollständigen Runner bestand drei frische Wiederholungsläufe: Profil 18 HTTP-/Domänen- und 8 Browserprüfungen, Prämieneditor 39 HTTP-/Domänen- und 14 Browserprüfungen. Befehle bleiben `python3 tests/run-member-profile.py` und `python3 tests/run-reward-edit.py`.

Nach diesen beiden gezielten Dreifachläufen bestanden außerdem alle 61 Nicht-WordPress-Runner frisch auf unveränderten 209 Quell-Hashes. 83 PHP-Dateien lintfrei, beide geänderten Browserdateien syntaktisch geprüft, 227 relative Dokumentationsverweise gültig und `git diff --check` sauber. Keine Garantie, dass nie wieder ein Timingfehler auftritt; das unveränderte WordPress-Plugin und echte Provider wurden funktional nicht erneut abgenommen.

## Gesamtrücklauf: Protokoll-Metadatenexport

Nach der neuen Exportsuite bestanden alle 62 Nicht-WordPress-Runner frisch auf unveränderten 214 Quell-Hashes unter `app`, `public` und `tests`. Auch die bisherige Protokollsuite blieb unverändert grün. 86 PHP-Dateien lintfrei, neue Browserdatei syntaktisch geprüft, 227 relative Dokumentationsziele gültig und `git diff --check` sauber. Das unveränderte WordPress-Plugin wurde funktional nicht erneut geprüft. Keine echte Provider-, Tabellenprogramm-, Last- oder Produktionsabnahme.

## Punkteprogramm nach Zeitraum und Vorperiode

Private Auswertung je eigenem Punkteprogramm mit acht UTC-Kalenderzeiträumen, ganzer Vorperiode und drei Kennzahlen aus dem bestehenden Journal. Positive gespeicherte Einlösekosten, keine Saldenänderung; Stornos, Transfers und Verfall ausgeschlossen. Punktediagramm und vollständige Abschnittstabelle, beschriftete Mobilkarten bei 390/320px. Laufende Perioden, Datenstand und fehlende Archivierung sichtbar; ungültige Daten oder Überläufe liefern keine Teilzahlen. Keine erfundenen Aufrufzahlen, Einlösequote, Prozenttrends, Personenliste, neue Exportdatei oder Geldrechnung. [Kalender, Kennzahlen, Datenschutz und Grenzen](POINTS-ANALYTICS.md). Neue Suite: `python3 tests/run-points-analytics.py`, 231 Domänen-, 52 HTTP-/Integrations- und 45 Browserprüfungen. Bisherige Analytics-Suite unverändert grün, keine echte Provider-/Produktions-/Lastabnahme.

## CLI-Aufrufbelege

`python3 tests/run-maintenance.py` prüft lokale CLI-Befehle, bestehende Locks, echte Prozessüberlappung, fehlertolerante Statusschreiben und die private Admin-GET-Ansicht. Keine produktiven Worker zum Testen starten. Additive Tabelle und Abgrenzung zu Cron-/Zustellnachweisen: [CLI-MAINTENANCE.md](CLI-MAINTENANCE.md).
