# Kampagnenversand atomar vorbereiten

Der vorhandene Senden-Button bereitet Wallet-Inbox, Versandprotokoll, optionale Mail-Outbox und Kampagnenstatus nun gemeinsam in einer `BEGIN IMMEDIATE`-Transaktion vor. Es werden beim Klick keine SMTP-Nachrichten übertragen. Der separate Mail-Worker und seine Einwilligungs-/Planprüfung bleiben unverändert.

## Warum die Korrektur

Zuvor konnten ein Fehler während der Empfängerschleife oder zwei gleichzeitige Aufrufe einen teilweise geschriebenen Entwurf hinterlassen. Jetzt gilt für einen neuen Vorbereitungsversuch: entweder alle Daten einschließlich Status/Audit werden gespeichert, oder die neuen Änderungen werden zurückgenommen.

- Eigentümer und Planberechtigung werden in der Domänenfunktion erneut geprüft. Das Segment muss zum selben Unternehmen gehören.
- Der Datenbank-Schreiblock umfasst das erneute Lesen des Status, die Zielgruppenauswertung und alle lokalen Schreibvorgänge. Änderungen am Segment warten auf diesen Schreibvorgang. Der Versand benutzt damit einen innerhalb der Transaktion gelesenen Zustand.
- Bereits als `sent` markierte Kampagnen erzeugen beim Wiederholen keine neuen Inbox-Nachrichten, Belege oder Outbox-Einträge. Ein konkurrierender zweiter Prozess liest nach dem ersten Commit den abgeschlossenen Status.
- Ohne passende Mitglieder wird die Vorbereitung mit null Empfängern abgeschlossen. Später neu passende Mitglieder werden bei einer Wiederholung nicht nachträglich ergänzt.
- Mail-Outbox nur bei eingerichteter SMTP-Konfiguration, gültiger Adresse und Marketingeinwilligung. Wallet-Inbox folgt unverändert dem Segment, unabhängig von E-Mail-Einwilligung. Fehlende SMTP-Konfiguration blockiert keine Inbox-Kampagne.
- Audit `campaign.dispatch` mit Kampagne, Unternehmen und Zählern, ohne Inhalte oder Empfängeradressen.
- Die Anzeige nennt vorbereitete Inbox-Nachrichten und E-Mails in der Warteschlange ausdrücklich getrennt von tatsächlicher E-Mail-Zustellung. Der bisherige interne Statusname `sent` bedeutet hier keine bestätigte SMTP-Zustellung.

## Historische Teilversände

Ein noch nicht abgeschlossener Entwurf mit vorhandenen `campaign_sends` oder kampagnenbezogenen Outbox-Einträgen wird blockiert. Nicht blind erneut senden, die Einträge löschen oder eine Kopie nochmals verschicken. Bereits erzeugte Nachrichten könnten gelesen oder durch einen Worker zugestellt worden sein. Administrator muss alten Stand, Protokoll und Outbox abgleichen. Diese Etappe repariert oder löscht keine historischen Daten und bietet keinen automatischen Fortsetzen-/Rückgängig-Workflow. Alte verwaiste Inbox-Nachrichten ohne Versandbeleg und Kampagnenbezug lassen sich nicht zuverlässig automatisch zuordnen.

Ein unerwarteter Fehler zeigt, dass der neue Vorbereitungsversuch zurückgenommen wurde, und fordert zur Statusprüfung vor einem neuen Versuch auf. Antwortverlust nach erfolgreichem Commit ist weiterhin möglich: eine Wiederholung derselben Kampagne erkennt den abgeschlossenen Status, statt neu zu schreiben.

## Grenzen

Keine Queue-Worker-Neuentwicklung, kein Zeitplan, Double-Opt-In, Bounce-/Providerabnahme oder vollständige Marketingfreigabe. Kein neuer API-/MCP-Versand. Keine Aussage über rechtliche Zulässigkeit einer Kampagne. Die Transaktion schreibt synchron, damit kann sie bei großen Zielgruppen andere SQLite-Schreiber blockieren. Kein Produktions-Lasttest. Laufender Worker, externe Zustellung oder historische Nachrichten werden durch die Datenbanktransaktion nicht zurückgerufen. Löschschutz siehe MARKETING-DELETE.md; ungeklärte Vorgänge nicht durch Löschen bereinigen.

## Tests

`python3 tests/run-campaign-dispatch.py`: Wegwerfkonten, vollständige lokale Vorbereitung, Einwilligung/Adresse, Wiederholung, zwei konkurrierende PHP-Prozesse, gezielt injizierter Datenbankfehler beim zweiten Mitglied und vollständiger Rollback, erneuter Versuch nach Rollback, historische Teilversände, leere Zielgruppe, Eigentümer/Segment/CSRF/Plan, HTTP-Ergebnis und Fehlermeldung. Chromium Desktop/Mobil für Erfolg, sichere Wiederholung und Altlastwarnung. Keine SMTP-Verbindung oder echte Kampagne versendet. Screenshots unter `/tmp/loyalty-campaign-dispatch/` visuell geprüft.

Entwurfseditor und Volltextprüfung wurden separat ergänzt: [CAMPAIGN-EDIT.md](CAMPAIGN-EDIT.md).

Die bestehenden Löschaktionen sind jetzt separat gehärtet: Bestätigung, Konfliktschutz, keine verknüpften Segmente oder vorbereiteten Kampagnen löschen. [MARKETING-DELETE.md](MARKETING-DELETE.md). Unternehmens-/Mitgliederlöschung bleibt davon getrennt.
