# Loyalty – rewardloyalty.co-Klon

Eigenständige Loyalty-SaaS nach dem Vorbild von rewardloyalty.co (Feature-Set
entlang der offiziellen Doku https://rewardloyalty.co/docs/5.x).
Plain PHP 8.4 + SQLite + Vanilla JS/CSS. Keine Frameworks, keine Composer-Abhängigkeiten.

## Start

```bash
cd loyalty-app
bin/php -S 127.0.0.1:8099 -t . index.php
```

Dann http://127.0.0.1:8099 öffnen.

`bin/php` ist eine statische PHP-8.4-Binary (mit pdo_sqlite). Auf jedem
System mit eigenem PHP >= 8.1 geht auch einfach `php -S 127.0.0.1:8099 -t . index.php`.

## Demo-Daten

```bash
bin/php seed.php
```

- Betreiber-Login: `demo@loyalty.local` / `demo1234`
- Business "Foundry Coffee" mit allen vier Programmtypen, Tiers, Bonus-Stunden,
  Achievements, Segmenten und Referral-Einstellungen
- Business "Atelier Beauty" (zeigt die geteilte Wallet: Aylin ist in beiden)
- Staff: Lena (PIN 4321, Foundry) und Mira (PIN 9876, Atelier) -> `/staff/login?b=ID`
- Mitglieder: `aylin@example.com` / `ben@example.com`, Passwort `kunde1234` -> `/member/login`
- Der Seed gibt die öffentlichen Kundenkarten-URLs (`/c/CODE`) und den Beitritts-Link aus

Die SQLite-Datenbank liegt in `data/app.sqlite` und wird beim ersten
Start automatisch angelegt (Schema in `index.php` → `init_schema`).

## Features

**Programme & Karten**
- Registrierung/Login (Sessions, `password_hash`), mehrere Unternehmen pro Account, Markenfarbe
- Vier Programmtypen: **Stempelkarte** (mit Rollover-Belohnung), **Punkte & Prämien**,
  **Gutschein**, **Mehrfachkarte (Prepaid)**
- Mitglieder mit Code, Suche, Karten pro Programm
- Öffentliche Kundenkarte `/c/CODE` (mobil, QR-Code, ohne Login)
- Transaktionsverlauf pro Karte

**Rollen (Etappe 1)**
- Staff-Rolle mit PIN-Login und eigener Theken-Ansicht `/staff`
- Member-Rolle mit E-Mail-Login und geteilter Wallet `/wallet` über alle Businesses
- Öffentlicher Beitritts-Link `/join/CODE` (verteilt automatisch alle Karten)
- Datenschutz: JSON-Export und komplettes Löschen des Kundenkontos

**Theke (Etappe 2)**
- QR-Scanner `/staff/scan` (Kamera, jsQR) mit manueller Code-Suche als Fallback
- Undo: jede Aktion ist über den Verlauf rückgängig machbar (`reversed_at`),
  inkl. korrekter Auflösung von Rollover-Belohnungen; Doppel-Undo gesperrt

**Gamification (Etappen 3-4)**
- Membership Tiers (Schwellen auf Lifetime-Punkte, Badge + Fortschritt in Wallet)
- Bonus Hours: Wochentag/Zeitfenster mit Punkte-Multiplikator
- Achievements: Meilensteine (Stempel/Punkte/Besuche/Einlösungen) mit optionalem
  Punkte-Bonus, automatische Vergabe nach jeder Aktion
- Referrals: persönlicher Freunde-werben-Link pro Mitglied, Bonus für beide Seiten
- Member-Inbox im Wallet (Meilensteine, Boni, Kampagnen)

**Marketing (Etappe 5)**
- Dynamische Kunden-Segmente (alle / neu / aktiv / inaktiv / Lifetime-Punkte / Stempel)
- E-Mail-Kampagnen an Segmente mit Versand-Protokoll. Ohne SMTP erfolgt die
  Zustellung lokal in die Wallet-Inbox; der Versand-Schritt ist austauschbar.

**Integrationen (Etappe 6)**
- REST API mit Bearer-API-Key pro Business:
  `GET /api/v1/business`, `GET/POST /api/v1/members`, `GET /api/v1/members/{code}`,
  `GET /api/v1/programs`, `GET /api/v1/transactions`, `POST /api/v1/cards/{id}/points`
- Webhooks für 6 Events (member.joined, card.stamp, card.points, card.redeem,
  card.visit, achievement.unlocked), HMAC-SHA256-signiert, mit Zustell-Protokoll

**Analytics (Etappe 7)**
- Dashboard `/business/{id}/analytics`: KPIs, 30-Tage-Aktivitätschart (SVG),
  Programm-Performance, Top-Mitglieder

## Struktur

- `index.php` – Front-Controller, Schema, Owner-Flows
- `inc/roles.php` – Staff/Member/Join/Wallet/Export
- `inc/counter.php` – QR-Scan + Undo
- `inc/tiers.php` – Tiers + Bonus Hours
- `inc/engage.php` – Achievements + Referrals + Inbox
- `inc/segments.php` – Segmente + Kampagnen
- `inc/api.php` – REST API + Webhooks
- `inc/analytics.php` – Analytics-Dashboard
- `assets/` – CSS, qrcode.min.js, jsQR.js (lokal gebündelt)
- `seed.php` – Demo-Daten

## Bewusste Grenzen

- Kampagnen-Versand ohne SMTP nur lokal in die Wallet-Inbox
- Keine Apple/Google-Wallet-Pässe, kein mehrschichtiges Clubs/Networks
- Webhook-Zustellung synchron beim Event (kein Retry-Queue)
- Kein CSRF-Schutz (lokale Demo)
