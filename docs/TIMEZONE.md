# Zeitzone je Unternehmen

Unternehmensseite, Abschnitt "Zeitzone" (`businesses.timezone`, Standard UTC, Auswahl aus 18 festen IANA-Namen, Audit-Eintrag `business.timezone`).

## Wirkung
- Intern bleibt alles UTC. Eine Änderung verschiebt keine gespeicherten Zeiten.
- Die Zeitzone gilt für: Zeitfenster der Prämien (Eingabe und Anzeige, Sommer- und Winterzeit werden berücksichtigt) und die Zeiten an der Einlösecode-Kasse und in Fehlermeldungen dazu. Die Wallet zeigt Zeiten mit Kürzel (CET/CEST).
- Beispiel Europe/Berlin: Eingabe 12:00 am 15.01. wird als 11:00 UTC gespeichert, 12:00 am 01.07. als 10:00 UTC.

## Für Entwickler
`app/inc/timezone.php`: `biz_tz($businessId)`, `tz_local($utc,$tz,$fmt)`, `tz_input_to_utc($input,$tz)`, `tz_abbr`. Neue Funktionen mit Zeiteingabe nutzen diese statt eigener Umrechnung.

## Grenzen
- Standard bleibt UTC, bestehende Unternehmen müssen die Zeitzone selbst setzen (keine stille Änderung).
- Andere Bereiche zeigen weiter UTC: Gutschein-Daten, Protokolle, Mails, Webhooks, KI-Freigaben, Abrechnung, Punkteverfall.
- Nur 18 Zeitzonen zur Auswahl. Zeiten in der Sommerzeit-Lücke (z. B. 02:30 am Umstellungstag) rutscht PHP vor, doppelte Zeiten im Herbst nimmt PHP die erste.
- Keine Zeitzone je Mitglied, die Anzeige folgt dem Unternehmen.
- Cron-Läufe und Wallet-Pässe sind unverändert.

Prämien-Zeitfenster binden beim Speichern nun die effektive Eingabezeitzone an den geprüften Fensterstand. Eine nach Öffnen geänderte Unternehmenszeitzone erfordert Neuladen statt stiller Umdeutung alter Formulare. Kein neuer Zeitparser. [REWARD-WINDOW.md](REWARD-WINDOW.md).
