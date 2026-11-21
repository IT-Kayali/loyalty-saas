# Einstieg für spätere Weiterentwicklung

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
| `app/inc/wallet.php`, `qr.php` | Apple-/Google-Wallet, PassKit-Web-Service und QR-Ausgaben |
| `app/inc/organization.php` | Netzwerke eigener Unternehmen, Standorte und Teamrechte |
| `app/inc/admin.php`, `analytics.php` | Plattformverwaltung, Basis-Audit und Auswertungen |
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
