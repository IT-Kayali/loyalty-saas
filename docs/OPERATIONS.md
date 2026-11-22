# Betrieb und Planberechtigungen

## Planprüfung aktivieren

`stripe.enforce_limits=true` schaltet die gemeinsame serverseitige Richtlinie ein. Sie gilt für die **vorhandenen** betrieblichen Schreibwege: Betreiberformulare, Staff-Aktionen/Undo, öffentlichen Beitritt, REST-API und Shopify-Webhook. Ohne aktives oder Trial-Abo oder bei nicht konfiguriertem Plan werden Änderungen mit HTTP 402 abgelehnt. Daten lesen, anmelden, Abo verwalten, Daten exportieren und das eigene Mitgliederkonto löschen bleiben möglich. Stripe-Webhooks und PassKit-Geräteregistrierungen sind eigene technische Kanäle und bleiben erreichbar.

Zwei Ebenen greifen ineinander:

1. HTTP-Guards stoppen verbotene Aktionen vor Bildspeicherung oder externen Aufrufen.
2. SQLite-TEMP-Trigger pro App-Verbindung prüfen zusätzlich die tatsächlich geschriebenen Mandantentabellen. Mengenprüfung und INSERT erfolgen innerhalb desselben SQLite-Schreibzugriffs. Ein paralleler Request kann das letzte freie Kontingent nicht gleichzeitig verbrauchen.

Geschützte Tabellen: Unternehmen, Programme, Mitglieder, Team, Karten, Transaktionen, Prämien, Stufen, Bonuszeiten, Achievements, Inbox, Segmente, Kampagnen, Zustellprotokolle, Webhooks und Bestellbelege. Abo-/Plattform-/Securitytabellen unterliegen stattdessen ihren eigenen Authentifizierungsregeln. Neue Module müssen in `install_plan_guards` und dem zugehörigen HTTP-Guard ergänzt werden. Die DB-Datei und direkte Admin-Skripte sind kein öffentliches API, Dateisystemzugriff muss streng beschränkt sein.

### Limits und Features

`limits`: `businesses`, `programs`, `members`, `staff`. Es wird über alle Unternehmen eines Betreiberkontos gezählt. 0 oder fehlend bedeutet unbegrenzt. Ein Downgrade löscht keine Daten; das Hinzufügen weiterer Objekte über dem neuen Limit ist gesperrt, Bearbeitung bestehender Objekte bleibt mit aktivem Abo möglich.

Getestete Feature-Schalter: `api`, `integrations`, `campaigns`, `webhooks`. Fehlend bedeutet für bestehende eigene Konfigurationen erlaubt, explizites `false` sperrt den Schreibkanal. API-Lesezugriffe bleiben möglich. Ausgehende Webhooks werden bei deaktiviertem Webhook-Feature ausgelassen. Weitere interne Tabellengruppen haben Richtliniennamen, sind aber noch keine vollständige verkaufsfertige Feature-Matrix. Neue kostenpflichtige Feature-Schalter brauchen eigene Ende-zu-Ende-Tests.

Die Pläne im Beispiel sind Vorschläge, keine gesetzten Verkaufspreise. Produktiv muss der Betreiber seine Preise, Bedingungen und Plangrenzen freigeben. Stripe-Keys und echte Zahlungen sind weiterhin separat einzurichten und zu testen.

## Sessions und Missbrauchsschutz

- Nur Cookie-Sessions, Strict Mode, HttpOnly, SameSite=Lax. Secure-Cookies bei HTTPS-`base_url`.
- Session-ID-Wechsel bei Betreiber-/Staff-/Mitglieder-Anmeldung und Beitritt/Registrierung.
- Standardmäßig 2 Stunden Leerlauf bis zur Abmeldung, `session_idle_seconds` ist konfigurierbar.
- Login/Registrierung/Join: maximal 10 Versuche pro IP/Ziel/15-Minuten-Fenster, global 60 pro IP/15 Minuten. Gezählt werden auch erfolgreiche Versuche, um Missbrauch über wechselnde Konten zu begrenzen.
- API: maximal 120 authentifizierte Requests pro Schlüssel/Minute. 429 enthält Retry-After; der Header ist vorsichtig auf 15 Minuten gesetzt.
- Counter enthalten nur SHA-256-Schlüssel, Zeitfenster und Zähler. Hashes sind keine Anonymisierung. Alte Counter werden nach 24 Stunden entfernt.
- `REMOTE_ADDR` ist die IP-Quelle. Forwarded-Header werden nicht blind vertraut. Den echten Client am vertrauenswürdigen Reverse Proxy korrekt konfigurieren, sonst teilen alle Besucher dessen Limit.
- Grund-Response-Header gegen Framing, MIME-Sniffing und externe Formularziele. Die CSP erlaubt für den vorhandenen Bestand weiterhin Inline-Skripte/-Styles; eine nonce-basierte CSP ist offen.
- Interne Fehler geben keine SQL-/Dateipfade im Browser aus. Das Serverlog erhält eine Referenz und die Fehlerklasse, keine rohen Requestdaten oder Secrets.

Rate Limits sind Basismaßnahmen, kein Ersatz für Monitoring, WAF oder einen unabhängigen Sicherheitsaudit. Passwortwiederherstellung wurde inzwischen mit der Mail-Etappe ergänzt, siehe MAIL.md; sie erfordert eingerichtetes SMTP und zeitnahen Worker. OTP und MFA bleiben offen.

## Punkte- und Bestellkonsistenz

Owner-/Staff-Kartenaktionen und Undo lesen den aktuellen Stand erneut unter `BEGIN IMMEDIATE`. Ein veraltetes Formular darf Punkte nicht zweimal ausgeben; eine Stornierung ist nur einmal möglich. Bestellpunkte führen einen eindeutigen Beleg `(business_id, reference)`, Transaktion und Punktegutschrift werden zusammen committed. Eine erneute gleich referenzierte Bestellung gibt 0 neue Punkte statt einer doppelten Gutschrift zurück. Bei aktivierten Bestellpunkten ist eine stabile `reference` im REST-Bestellpfad Pflicht. Shopify verwendet die Bestell-ID.

Der gemeinsame API-/Shop-Mitgliederhelfer sichert inzwischen E-Mail-Suche, neue Mitgliedszeile und den vollständigen aktiven Kartensatz in einer eigenen Transaktion ab. Leere E-Mail bleibt eine neue Anlage pro Aufruf, bestehende Profile bleiben unverändert. Fehler bei der danach versuchten Ereignisübergabe melden sich als `member_event_incomplete`, nicht als fehlgeschlagene lokale Anlage. Mitglied und spätere Bestellpunkte bleiben getrennte Transaktionen. Keine globale E-Mail-Eindeutigkeit oder Altfallreparatur: [API-MEMBER-UPSERT.md](API-MEMBER-UPSERT.md).

Offen: vollständige Idempotency-Keys für jede Karten-/API-Aktion, umfassende Queue-/Retry-Verarbeitung aller Seiteneffekte und Lasttests. Die drei älteren direkten REST-Punkte-/Stempelpfade buchen inzwischen Saldo, Journal und lokale Meilensteine zusammen und wurden mit echten parallelen Prozessen und HTTP-Anfragen geprüft: [LEGACY-API-CREDITS.md](LEGACY-API-CREDITS.md). Sie bleiben ohne Idempotenzgarantie; andere API-Pfade und externe Folgearbeit sind damit nicht pauschal abgesichert.

## Versandzustand prüfen

Nur konfigurierte Plattform-Admins sehen unter `/admin/health` eine private GET-Momentaufnahme der gespeicherten Mail-/Webhook-Zustände. Die Seite zeigt fällige, noch gesperrte oder unklare Arbeit und auswertbare UTC-Zeiten, ohne Empfänger oder Inhalte auszugeben. Sie startet keinen Worker, wiederholt nichts und ersetzt weder Cron-Überwachung noch Providerprotokolle. `unknown` nicht blind erneut senden. Wallet-Einzelmitteilungen sind keine Retry-Queue und bleiben hier außerhalb. Zählerdefinitionen, genaue Zeitgrenzen und Tests: [ADMIN-HEALTH.md](ADMIN-HEALTH.md).

## Backup und Wiederherstellung

Erfordert zusätzlich `ext-zip`. Nur per CLI, nie als HTTP-Route:

```bash
php tools/backup.php backup /private/backups/loyalty-2026-11-21.zip
php tools/backup.php restore /private/backups/loyalty-2026-11-21.zip --yes
```

- App-Requests nehmen einen gemeinsamen Maintenance-Lock. Backup/Restore nimmt ihn exklusiv und wartet auf laufende Requests. In dieser Zeit warten neue Requests; deshalb Wartungsfenster nutzen und HTTP-Timeouts berücksichtigen.
- DB-Snapshot per SQLite `VACUUM INTO`. Dazu `config.php`, Uploads und Wallet-Dateien aus `data/apple` und `data/google`.
- Archive und Zwischenstände erhalten private Dateirechte. **Sie enthalten Geheimnisse**, sind nicht automatisch verschlüsselt. Verschlüsselt und außerhalb von `public/` aufbewahren, Transport und Zugriff absichern. Keine Backups in Git oder offene Downloadordner legen.
- Eigene Zertifikatpfade außerhalb von `data/apple`/`data/google` werden nicht automatisch mitgesichert und müssen separat gesichert werden.
- Restore akzeptiert nur erlaubte Pfade, prüft SHA-256-Manifest, Größen und SQLite-Integrität. Prüfsummen sind kein Herkunftsnachweis: nur eigene vertrauenswürdige Backups verwenden. Niemals fremde Backups als vertrauenswürdige Konfiguration ausführen. Vor Aktivierung der wiederhergestellten DB wird der private Apple-PassKit-Cursorschlüssel erneuert und nur der technische Pass-Prüfcache zurückgesetzt, damit alte Gerätecursor keinen älteren Stand übersehen. Keine Löschung von Mitgliedern, Salden oder Geräteregistrierungen. [Wallet-Update-Protokoll](WALLET-UPDATES.md).
- Vorherige Daten bleiben in einem privaten `data/restore-rollback-*`-Verzeichnis. Alte Rollbacks enthalten ebenfalls Geheimnisse und brauchen Lösch-/Aufbewahrungsregeln.
- Gesicherte Konfiguration wird **nicht automatisch aktiviert**. Sie liegt nach Restore als `data/restored-config.php` für die manuelle Zusammenführung bereit, damit Serverpfade, Domains und Stripe-/Wallet-Konten nicht still auf einen alten Serverstand wechseln.
- Externe DB-Schreiber müssen gestoppt sein. Vorhandene WAL-Dateien werden nicht still überschrieben. Nicht unterstützte Pfad-/Mount-Konfigurationen können zu einem sicheren Abbruch führen; Wiederherstellung vor dem Ernstfall auf derselben Zielumgebung proben.

Keine automatische Backup-Planung, externe Kopie oder Retention eingerichtet. Das ist bewusst Aufgabe des Deployments. Vor produktiver Nutzung Wiederherstellungsprobe und Aufbewahrungsplan festlegen.

## Tests

```bash
php tests/billing.php
python3 tests/run-browser.py
python3 tests/run-policy.py
python3 tests/run-backup.py
```

`run-policy.py` führt 19 DB-/Policytests, 8 Punkte-/Undo-/Bestelltests, 38 HTTP-Tests und einen echten Vier-Prozess-Test um den letzten freien Unternehmensplatz aus. `run-backup.py` prüft Backup/Restore, private Dateirechte und Ablehnung eines Path-Traversal-Archivs. Es werden ausschließlich temporäre Testinstallationen verwendet.


## Merchant-Wiederholungsbelege und Demo-Modus

Der neue `/api/v1/merchant/`-Schreibpfad führt Saldo, Transaktionsjournal und Ergebnisbeleg unter `BEGIN IMMEDIATE` zusammen. Gleiche Vorgangskennung plus gleiche Eingabe gibt das gespeicherte Ergebnis zurück, Konflikte werden abgelehnt. Kundenlöschung anonymisiert die betreffenden Ergebnisbelege und sperrt alte Create-Retries. Dieser Nachweis gilt nicht pauschal für alle älteren direkten API-Pfade. WordPress-Rollen, offene Vorgangsdaten und Folgen einer Schlüsselrotation: [Plugin-README](../integrations/woocommerce/README.md).

`demo_banner=true` in `config.php` oder `DEMO_MODE=1` zeigt nur die öffentlichen Seed-Zugänge auf Loginseiten, es ist keine Sicherheitsisolation und deaktiviert keine Anbieter. Nur bei einer getrennten Demo setzen. Default ist aus. Ohne aktivierten Schalter keine Anzeige. [Entwicklungs-/Demo-Anleitung](DEVELOPMENT.md).
