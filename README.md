# Loyalty SaaS

Digitale Stempelkarten, Punkte, Gutscheine und Mehrfachkarten für lokale Geschäfte — mit Apple & Google Wallet, REST-API, WooCommerce/Shopify-Anbindung und mehrsprachiger Oberfläche.

Reines PHP + SQLite, keine Composer-Abhängigkeiten, keine Build-Tools. Ein Binary reicht.

## Offline-Wallet / PWA

Installierbare Web-App-Basis mit ausdrücklich gespeicherten Karten, QR, Suche und letzter Saldoansicht. Keine Offline-Buchungen oder -Einlösungen. Nur auf privaten Geräten speichern, 24-Stunden-Ansicht und lokale Löschung/Logout-Cleanup. [Setup, Datenschutz und Grenzen](docs/PWA.md). Der lokale Kaltstart-Test prüft aktive Registrierung und Controller vor dem Offlinewechsel.

## Support-Tickets

Partner-Tickets auf jedem Plan, Admin-Inbox mit Status/Priorität/Zuständigkeit/Suche, unsichtbare interne Notizen, Ausblenden, Audit ohne Texte und ein öffentliches Kontaktformular ohne Konto-Zuordnung. [Funktionen und Grenzen](docs/SUPPORT.md).

## KI-Verbindungen (MCP)

Optionaler MCP-Endpunkt `/mcp` mit Bearer-Schlüsseln (Lesen oder Lesen und ändern), Idempotenz, Audit und Freigabe in der App für Löschungen. Standardmäßig aus. [Funktionen und Grenzen](docs/AI.md).

Fertige Programm-Vorlagen für Restaurant, Café, Imbisswagen, Grillhähnchen, Friseur und weitere Branchen. [Übersicht](docs/TEMPLATES.md).

Optionaler Punkteverfall bei Inaktivität pro Programm, per Cron oder Button. [Regeln und Grenzen](docs/POINTS-EXPIRY.md).

E-Mail-Automationen können einen persönlichen Einmal-Gutschein beilegen (Geburtstag, Winback, Willkommen). [Details](docs/LIFECYCLE-VOUCHERS.md).

Mitglieder-Punkteanfragen per Link, opt-in je Programm. [Regeln und Grenzen](docs/POINT-REQUESTS.md).

Webhook-Retries mit Backoff und Wiederholung durch Owner/Admin. [Ablauf und Cron](docs/WEBHOOK-RETRIES.md).
Einlösecodes mit QR für Prämien. [Ablauf und Grenzen](docs/REWARD-CODES.md).
Zeitzone je Unternehmen für Prämien-Zeitfenster. [Details und Grenzen](docs/TIMEZONE.md).
Gestaltete Webkarte mit Vorder-/Rückseite, Overlay und QR im Designer. [Anleitung und Grenzen](docs/CARD-DESIGNER.md).

## WordPress-Händlerdashboard

Eigener WordPress-Adminbereich für Mitglieder, aktuelle Salden, Punkte/Stempel und bestätigte Prämieneinlösung, auch ohne WooCommerce. Admin-only-Verbindung, geschützter API-Schlüssel und transaktionaler Wiederholschutz. Eine Site verbindet genau ein Unternehmen. Einrichtung, echte lokale WordPress-Tests und Grenzen: [Plugin-README](integrations/woocommerce/README.md).

## E-Mail & Automationen

TLS-SMTP, geprüfte Outbox-/Workerzustände, Einwilligung und Abmeldelinks, Welcome/Birthday/Winback-Textregeln und Einmal-Passwortreset mit Sessionwiderruf ergänzt. Ohne aktivierte SMTP-Konfiguration kein E-Mail-Versand. Echte Providerzustellung ist noch nicht abgenommen. Setup und Grenzen: [docs/MAIL.md](docs/MAIL.md).

## Programm-Ausbau

Eigener Designer pro Programm mit Hintergrundfoto, Overlay und Emoji; Code-Gutscheine mit Rabattregeln, Batches und Batchstorno/Wiederherstellen; separat verkaufte Besuchs-/Zeitpässe mit Ledger, Idempotency und Storno. Bisherige einfache Karten bleiben erhalten. Grenzen, Geldguthaben-Lücke und Tests: [docs/PROGRAMS.md](docs/PROGRAMS.md).

## Produktionsgrundlage und Planberechtigungen

Die nächste Etappe ergänzt zentrale Planprüfungen über Betreiber, Staff, Join, REST-API und Shopify, Mengenlimits, sichere Sessions, Rate Limits sowie Backup/Restore. Aktivierung, geprüfte Grenzen und alle Betriebsbefehle: [docs/OPERATIONS.md](docs/OPERATIONS.md). Prüfung ist im Beispiel für lokale Demos weiterhin bewusst ausgeschaltet, `stripe.enforce_limits=true` schaltet sie ein.

## Aktueller Umfang

**Noch keine vollständige Funktionsgleichheit mit Reward Loyalty 5.x und noch kein produktionsfertiger SaaS-Launch.** Der erneute Abgleich mit der aktuellen Doku steht in [docs/FEATURE-AUDIT.md](docs/FEATURE-AUDIT.md), einschließlich der offenen Baupakete. Externe Stripe-/Wallet-/Shop-Zugänge müssen eingerichtet und Ende-zu-Ende getestet werden.

Neu in dieser Ausbaustufe:

- **Stripe-Abos**: Planwahl, Checkout, Kundenportal (Wechsel/Kündigung), signierte und deduplizierte Webhooks. Ohne Keys deaktiviert. Setup und Grenzen: [docs/STRIPE.md](docs/STRIPE.md).
- **Eigene Stempelbilder**: PNG/JPEG/WebP hochladen, sichere PNG-Neukodierung, direkte Designer-Vorschau, Webkarte und Wallet-Grafik.
- **Plattform-Admin**: globale Übersicht, Abostatus, Systemchecks, Transaktions-CSV, Plattformname, Registrierungssteuerung und Basis-Audit-Log. Erlaubte Admin-E-Mail-Adressen ausschließlich serverseitig in `platform_admin_emails` setzen. Keine automatische Adminrolle für den ersten Registrierten.

## Features

- **Vier Programmtypen**: Stempelkarte (mit Rollover-Belohnung), Punkte & Prämien, Gutschein, Mehrfachkarte (10er-Karte)
- **Apple Wallet**: signierte `.pkpass`-Pässe, eingebauter PassKit-Web-Service, APNs-Push bei Standänderungen — Sperrbildschirm-Mitteilung inklusive
- **Google Wallet**: "Zu Google Wallet hinzufügen"-Link (signiertes JWT) + automatische Updates über die Wallet-API
- **QR-Studio**: druckbare QR-Codes mit Firmenlogo in der Mitte (Beitritt, Staff-Login, einzelne Kundenkarten), PNG-Download + Druckansicht
- **Karten-Designer**: Farben, Logo, eigenes Stempel-Bild, Kopf-/Fußzeile und Felder der Kundenkarte pro Business im Admin anpassen — gilt für Webkarte und beide Wallets
- **REST-API** mit OpenAPI-Spec, API-Dokumentationsseite in der App (`/api/docs`) und `docs/API.md`
- **WooCommerce-Plugin** (fertig in `integrations/woocommerce`) und **Shopify-Webhook** (HMAC-geprüft, Anleitung in `integrations/shopify`)
- **Ausgehende Webhooks** mit HMAC-Signatur und Zustellprotokoll
- **Mehrsprachig**: Deutsch + Englisch ausgeliefert, weitere Sprachen durch eine Datei in `app/lang/`
- **Team & Rollen**: Betreiber, Staff mit Viewer/Kasse/Schichtleitung und Einzelrechten, PIN-Reset/Sitzungswiderruf, Mitglieder-Wallet. [Rechte und Grenzen](docs/ORGANIZATION.md)
- **Organisation**: Netzwerke eigener Unternehmen, Standorte und Zuordnung neuer Teamaktionen. Keine unternehmensübergreifenden Salden und keine isolierte Standort-Datensicht.
- **Marketing**: Segmente, Kampagnen (Inbox der Mitglieder, optional SMTP), Bonus-Zeitfenster (Happy Hour), Stufen/Tiers, Achievements
- **Analytics**: Mitgliederwachstum, Buchungen, Tageszeiten-Heatmap

Entwickler-Einstieg, Architekturkarte, alle Testbefehle und veröffentlichte Demo-Zugänge: [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md). Ein sichtbarer Hinweis mit Test-Zugängen auf Start-/Login-Seiten ist nur mit `'demo_banner' => true` in `config.php` oder `DEMO_MODE=1` aktiv, nie standardmäßig.

## Schnellstart

```bash
git clone https://github.com/IT-Kayali/loyalty-saas.git
cd loyalty-saas
cp config.example.php config.php     # base_url anpassen
php seed.php                          # Demo-Daten (optional)
php -S 127.0.0.1:8099 -t public public/index.php
```

→ http://127.0.0.1:8099

**Wichtig:** Document Root ist `public/`, der Front Controller `public/index.php`. Beim eingebauten PHP-Server immer mit `-t public public/index.php` starten. Bei nginx/Apache entsprechend `root .../public` und `try_files $uri /index.php$is_args$args`.

### Demo-Logins (nach `php seed.php`)

| Rolle | Zugang |
| --- | --- |
| Inhaber | demo@loyalty.local / demo1234 |
| Staff (Foundry Coffee) | PIN 4321 über Staff-Login |
| Staff (Atelier Beauty) | PIN 9876 über Staff-Login |
| Mitglied | aylin@example.com / kunde1234 über Kunden-Login |

Nur für lokale Demos — vor Produktivbetrieb eigene Zugänge anlegen und die Seed-Daten löschen (`data/app.sqlite` entfernen).

## Konfiguration

`config.php` (nicht im Repo) aus `config.example.php` kopieren:

| Schlüssel | Zweck |
| --- | --- |
| `base_url` | Öffentliche URL (wird in QRs, Pässen und Links verwendet) |
| `default_lang` | `de` oder `en`; Nutzer können umschalten, `?lang=` setzt die Session |
| `db_path` | SQLite-Pfad — **muss außerhalb des Document Root liegen** (Standard: `data/app.sqlite`) |
| `webhook_allow_http` | Nur für lokale Entwicklung auf `true` |
| `apple_wallet` | Pass Type ID, Zertifikate, APNs-Key → [docs/WALLET.md](docs/WALLET.md) |
| `google_wallet` | Issuer-ID, Service-Account-JSON → [docs/WALLET.md](docs/WALLET.md) |
| `smtp` | Vorhandener optionaler Mailpfad. Produktive SMTP-Provider-Zustellung noch nicht abgenommen |
| `stripe` | Secret Key, Webhook Secret, Plan-/Price-IDs und optionale Unternehmenslimits |
| `platform_admin_emails` | Explizite Allowlist für den Plattform-Admin |

Ohne Wallet-Konfiguration läuft alles andere; die Pass-Endpunkte antworten dann mit HTTP 501 und einem Hinweis.

## Architektur

```
public/            Document Root (Front Controller + Assets)
app/bootstrap.php  Config, DB + Schema/Migrationen, i18n, CSRF, Layout
app/inc/           Feature-Module (Rollen, Wallet, API, Designer, QR, ...)
app/lang/          Sprachdateien (de = Quellstrings, en = Übersetzung)
data/              SQLite-DB, Uploads, Wallet-Zertifikate (nicht im Repo, nicht im Web)
integrations/      WooCommerce-Plugin + Shopify-Anleitung
docs/              WALLET.md (Zertifikate & Setup), API.md (REST-Referenz)
```

### Sicherheit

- **SQLite liegt außerhalb des Document Root.** In einer früheren Version war die Datenbank per URL herunterladbar — das ist durch den Umzug nach `data/` + Document Root `public/` behoben.
- **SSRF-Schutz für Webhooks:** Ziele müssen HTTPS sein und auf öffentliche IPs zeigen (DNS-Auflösung wird geprüft, Redirects werden nicht gefolgt).
- CSRF-Token auf allen Session-Formularen, prepared statements überall, Passwort-Hashing mit `password_hash`, PINs gehasht, HMAC-Signatur für eingehende (Shopify) und ausgehende Webhooks, Logo-/Stempeluploads per MIME, Größe, Pixelzahl und Bilddekodierung validiert, als PNG neu kodiert und mit Zufallsnamen gespeichert, `data/` per `.gitignore` aus dem Repo gehalten.

### Mehrsprachigkeit erweitern

`app/lang/en.php` kopieren, z. B. nach `fr.php`, Werte übersetzen und in `app/bootstrap.php` bei `AVAILABLE_LANGS` eintragen. Schlüssel sind immer die deutschen Quellstrings.

### Eigene Integrationen

Die REST-API deckt Mitglieder, Karten, Punkte, Stempel, Bestellungen und Journal ab — für eigene Plugins, Kassensysteme oder Automatisierungen: [docs/API.md](docs/API.md).

## Anforderungen

PHP ≥ 8.1 mit `pdo_sqlite`, `gd`, `openssl`, `curl`, `mbstring`. Produktiv hinter HTTPS (Apple verlangt es für den Pass-Web-Service; der API-Schlüssel gehört ohnehin nicht auf unverschlüsselte Leitungen).

## Aktualisierung und Tests

Bestehende `config.php` um die neuen Schlüssel aus `config.example.php` ergänzen. Schema wird additiv angelegt, **nicht erneut seeden**, wenn vorhandene Daten erhalten bleiben sollen. Vor jedem Update Backup der DB, Uploads und Konfiguration erstellen. Geheimnisse nie ins Repo übernehmen.

```bash
php tests/billing.php
# Browser-Regressionsprüfung in temporärer Testinstallation:
npm install --no-save playwright
npx playwright install chromium
python3 tests/run-browser.py
python3 tests/run-policy.py
python3 tests/run-backup.py
python3 tests/run-programs.py
python3 tests/run-mail.py
```

Browser-Tests benötigen Port 8099 und erzeugen nur lokale Testdaten. Ausgaben standardmäßig unter dem temporären Verzeichnis `loyalty-browser-results`; mit `TEST_OUTPUT` änderbar, PHP-Binary mit `PHP_BIN`. Das sind lokale Tests, kein echter Stripe-Testkauf oder Geräte-Wallet-Test.

### Standorte und Teamrechte prüfen

`PHP_BIN=php python tests/run-organization.py` prüft die neue Verwaltung, Mandantengrenzen, Staffrechte und Sitzungswiderruf in einer Wegwerfinstallation. Bestehende Staffzugänge behalten beim Update über das Profil Schichtleitung ihre bisherigen Rechte; vor Produktivbetrieb reduzieren. Neue PINs benötigen 6 bis 12 Ziffern. Details: [docs/ORGANIZATION.md](docs/ORGANIZATION.md).

### PayPal-Abrechnung

Optionales SaaS-Abo zusätzlich zur Stripe-Anbindung: SDK v6, serverseitiges Create und Statusprüfung, signaturverifizierte Webhook-Verarbeitung mit Originaldaten, Retry-/Tenantbindung, Refund-Sperren und ausdrücklich bestätigte Kündigung. Ohne Keys aus. Keine echte Sandbox-/Live-Zahlung wurde abgenommen. Einrichtung, manueller Refund-Abgleich und Grenzen: [docs/PAYPAL.md](docs/PAYPAL.md). Test: `PHP_BIN=php python tests/run-paypal.py`. Planwechsel kündigen kein laufendes Abo still.

Mitglieder können ihr eigenes Shared-Wallet-Passwort ändern und alle Wallet-Sitzungen abmelden: [docs/MEMBER-PASSWORD.md](docs/MEMBER-PASSWORD.md).

Eigene Namen pro Mitgliedseintrag in der Wallet: [docs/MEMBER-PROFILE.md](docs/MEMBER-PROFILE.md).

Eigenes Betreiber-Passwort ohne SMTP ändern: [docs/OWNER-PASSWORD.md](docs/OWNER-PASSWORD.md).

Persönliche Gutschein-QRs: [Ablauf und Grenzen](docs/VOUCHER-QR.md), Tests `python3 tests/run-voucher-qr.py`.

Code-Gutschein-Auswertung pro Programm: [Umfang und Zahlengrenzen](docs/VOUCHER-REPORT.md), Tests `python3 tests/run-voucher-report.py`.

Analytics-Einlösungszahlen und mobile Programm-Performance: [ANALYTICS.md](docs/ANALYTICS.md), Tests `python3 tests/run-analytics.py`.

Gespeicherte Einzelregel-Segmente bearbeiten und Anzahl ohne Speichern prüfen: [SEGMENTS.md](docs/SEGMENTS.md), Tests `python3 tests/run-segments.py`.

Unternehmens-Anzeigenamen ändern: [BUSINESS-SETTINGS.md](docs/BUSINESS-SETTINGS.md), Tests `python3 tests/run-business-settings.py`.

Kampagnen-Inbox, Versandbelege und Mail-Outbox atomar vorbereiten: [CAMPAIGN-DISPATCH.md](docs/CAMPAIGN-DISPATCH.md), Tests `python3 tests/run-campaign-dispatch.py`.

Eigene Kampagnenentwürfe bearbeiten und vollständigen Text prüfen: [CAMPAIGN-EDIT.md](docs/CAMPAIGN-EDIT.md), Tests `python3 tests/run-campaign-edit.py`.

Die Code-Gutschein-Auswertung bietet einen aggregierten CSV-Download für denselben UTC-Zeitraum, ohne Mitgliedsdaten/Codes: [VOUCHER-REPORT.md](docs/VOUCHER-REPORT.md).

Persönlicher QR bereits gekaufter Prepaid-Pässe mit getrennter Team-Prüfung vor Besuchsbuchung: [PREPAID-QR.md](docs/PREPAID-QR.md), Tests `python3 tests/run-prepaid-qr.py`.

Bestätigtes Löschen nur unverknüpfter Segmente und unvorbereiteter Kampagnenentwürfe: [MARKETING-DELETE.md](docs/MARKETING-DELETE.md), Tests `python3 tests/run-marketing-delete.py`.

Support-Admin-Inbox: Kanalfilter, Zuständigkeit per Name und mobile Volldatenliste, siehe [SUPPORT.md](docs/SUPPORT.md). Tests `python3 tests/run-support.py`.

Eigene sichtbare Support-Threads lassen sich privat als JSON herunterladen, ohne interne Notizen: [SUPPORT.md](docs/SUPPORT.md).

Meilensteinregeln vor erster Freischaltung bearbeiten, erreichte Regeln schützen: [ACHIEVEMENTS.md](docs/ACHIEVEMENTS.md), Tests `python3 tests/run-achievement-edit.py`.

Meilensteine können ohne Entzug bestehender Freischaltungen pausiert und fortgesetzt werden: [ACHIEVEMENTS.md](docs/ACHIEVEMENTS.md).

## Support nach Thema

Supportlisten für Betreiber und Admin zeigen das Ticketthema und lassen sich danach filtern, mit den vorhandenen Filtern kombinierbar und über Folgeseiten erhalten. Keine neue Kontaktfreigabe oder automatische Kategorisierung. [Support, Rechte und Grenzen](docs/SUPPORT.md).

## Meilenstein-Anlage prüfen

Neue Meilensteine und Bearbeitungen verwenden dieselben Grenzen für Namen, Schwelle und Bonus. Ungültige Zahlen werden abgelehnt statt still gekürzt. Anlage vergibt keine Punkte; die nächste unterstützte Kartenaktion prüft die aktive Regel. [Regeln und verbleibende Grenzen](docs/ACHIEVEMENTS.md).

## Eigener Supportstatus

Betreiber können eigene Tickets ohne neue Nachricht schließen und wieder öffnen. Thread bleibt erhalten, keine Mail und kein neues Kontaktrecht. Alte Formulare überschreiben keinen neueren Stand. [Supportstatus und Grenzen](docs/SUPPORT.md).

## Admin-Aktivitätsprotokoll

Plattform-Admins können bestehende Protokolle nach UTC-Tagen, exakter Aktion und Konto filtern und ältere Einträge lesen. Keine Detaildaten oder Exporte, keine Zusage einer vollständigen Sicherheitsgeschichte. [Umfang, Datenschutz und Tests](docs/ADMIN-AUDIT.md).

## Netzwerknamen bearbeiten

Organisation zeigt eigene Netzwerke samt Namenseditor mit Konfliktschutz. Nur Gruppierungsname, keine Änderung an Unternehmenszuordnung, Mitgliedern oder Salden. [Rechte, Wirkung und Grenzen](docs/ORGANIZATION.md).

## Standortdaten bearbeiten

Eigene Standortnamen und beschreibende Adressen lassen sich separat bearbeiten. Konfliktschutz, keine Änderung an Aktivierung, Unternehmens-/Teamzuordnung oder Salden. Keine Adressprüfung. [Wirkung und Grenzen](docs/ORGANIZATION.md).

## Leere Netzwerkgruppen entfernen

Eigene Netzwerke ohne zugeordnetes Unternehmen lassen sich nach getrennter Prüfung und exakter Namensbestätigung endgültig entfernen. Keine automatische Abkopplung oder Saldoänderung. [Schutz und Grenzen](docs/ORGANIZATION.md).
