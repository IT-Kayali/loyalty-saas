# Loyalty SaaS

Digitale Stempelkarten, Punkte, Gutscheine und Mehrfachkarten für lokale Geschäfte — mit Apple & Google Wallet, REST-API, WooCommerce/Shopify-Anbindung und mehrsprachiger Oberfläche.

Reines PHP + SQLite, keine Composer-Abhängigkeiten, keine Build-Tools. Ein Binary reicht.

## Features

- **Vier Programmtypen**: Stempelkarte (mit Rollover-Belohnung), Punkte & Prämien, Gutschein, Mehrfachkarte (10er-Karte)
- **Apple Wallet**: signierte `.pkpass`-Pässe, eingebauter PassKit-Web-Service, APNs-Push bei Standänderungen — Sperrbildschirm-Mitteilung inklusive
- **Google Wallet**: "Zu Google Wallet hinzufügen"-Link (signiertes JWT) + automatische Updates über die Wallet-API
- **QR-Studio**: druckbare QR-Codes mit Firmenlogo in der Mitte (Beitritt, Staff-Login, einzelne Kundenkarten), PNG-Download + Druckansicht
- **Karten-Designer**: Farben, Logo, Kopf-/Fußzeile und Felder der Kundenkarte pro Business im Admin anpassen — gilt für Webkarte und beide Wallets
- **REST-API** mit OpenAPI-Spec, API-Dokumentationsseite in der App (`/api/docs`) und `docs/API.md`
- **WooCommerce-Plugin** (fertig in `integrations/woocommerce`) und **Shopify-Webhook** (HMAC-geprüft, Anleitung in `integrations/shopify`)
- **Ausgehende Webhooks** mit HMAC-Signatur und Zustellprotokoll
- **Mehrsprachig**: Deutsch + Englisch ausgeliefert, weitere Sprachen durch eine Datei in `app/lang/`
- **Team & Rollen**: Owner, Staff (PIN-Login am Tresen), Mitglieder (eigenes Wallet-Login)
- **Marketing**: Segmente, Kampagnen (Inbox der Mitglieder, optional SMTP), Bonus-Zeitfenster (Happy Hour), Stufen/Tiers, Achievements
- **Analytics**: Mitgliederwachstum, Buchungen, Tageszeiten-Heatmap

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
| `smtp` | Optional: Kampagnen per echter E-Mail statt Inbox |

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
- CSRF-Token auf allen Session-Formularen, prepared statements überall, Passwort-Hashing mit `password_hash`, PINs gehasht, HMAC-Signatur für eingehende (Shopify) und ausgehende Webhooks, Uploads per `getimagesize` validiert und mit Zufallsnamen gespeichert, `data/` per `.gitignore` aus dem Repo gehalten.

### Mehrsprachigkeit erweitern

`app/lang/en.php` kopieren, z. B. nach `fr.php`, Werte übersetzen und in `app/bootstrap.php` bei `AVAILABLE_LANGS` eintragen. Schlüssel sind immer die deutschen Quellstrings.

### Eigene Integrationen

Die REST-API deckt Mitglieder, Karten, Punkte, Stempel, Bestellungen und Journal ab — für eigene Plugins, Kassensysteme oder Automatisierungen: [docs/API.md](docs/API.md).

## Anforderungen

PHP ≥ 8.1 mit `pdo_sqlite`, `gd`, `openssl`, `curl`, `mbstring`. Produktiv hinter HTTPS (Apple verlangt es für den Pass-Web-Service; der API-Schlüssel gehört ohnehin nicht auf unverschlüsselte Leitungen).
