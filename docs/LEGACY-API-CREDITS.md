# Ältere REST-Buchungen: Saldo und Journal zusammen

Stand: 22.11.2026. Lokal implementiert und mit echten parallelen Prozessen sowie HTTP-Anfragen geprüft. Keine echte Providerzustellung oder allgemeine Produktionsabnahme.

## Geltungsbereich

Diese Absicherung betrifft genau die bestehenden drei REST-Endpunkte:

- `POST /api/v1/members/{code}/points`
- `POST /api/v1/members/{code}/stamp`
- `POST /api/v1/cards/{id}/points`

Sie sind **nicht idempotent**. Derselbe POST zählt erneut als beabsichtigte Buchung. Ein frei hinzugefügtes `operation`-Feld macht aus diesen alten Endpunkten keinen Wiederholungsbeleg. Bei Timeout oder Verbindungsabbruch nicht blind erneut senden. Der erste Versuch kann bereits committed sein.

## Was jetzt gemeinsam abgeschlossen wird

`app/inc/api-credit.php` öffnet `BEGIN IMMEDIATE`, bevor Mitglied, Programm und aktueller Kartenstand gelesen werden. Plan und Unternehmensbindung werden innerhalb dieser Transaktion erneut geprüft. Danach gehören zu einem gemeinsamen Commit:

- Punkte und Lifetime-Punkte beziehungsweise Stempel und offene Stempelbelohnungen,
- die zugehörigen Journalzeilen, einschließlich verdienter Rollover-Belohnung,
- fällige lokale Meilensteinbelege mit deren Bonuspunkten, Bonusjournal und Wallet-Inbox.

Ein Fehler in einer dieser lokalen Schreiboperationen rollt die gesamte Buchung zurück. Insbesondere darf ein fehlgeschlagener Journal- oder Inbox-Eintrag keinen bereits erhöhten Saldo zurücklassen. Gleichzeitige Stempelaufrufe lesen nicht mehr denselben ungeschützten alten Stand und überschreiben sich nicht gegenseitig.

Die vorhandene Meilensteinprüfung verwendet dafür einen gemeinsamen lokalen Helfer `achievement_award_locked()`. Der bisherige `check_achievements()`-Aufruf für andere Module behält seine eigene Transaktionshülle und anschließende Webhookarbeit. Das ist kein pauschaler Nachweis atomarer Komplettworkflows für alle anderen Module.

SQLite kann sehr große Ganzzahlrechnungen still auf Gleitkommawerte umstellen. Vor dem Commit werden die ganzzahligen Saldo-/Stempelfelder der betreffenden Mitgliedskarten im Unternehmen geprüft. Ein Überlauf oder bereits nicht ganzzahliger Stand führt hier zu HTTP 422 und Rollback, nicht zu einem gerundeten Guthaben.

## Antworten und Folgearbeit

Die bisherigen fachlichen Antwortfelder bleiben erhalten. `points` enthält den Stand dieser abgeschlossenen lokalen Buchung einschließlich dabei vergebener Meilensteinpunkte. Bei Parallelität ist das ein Vorgangsschnappschuss, nicht das Versprechen, dass nachfolgende Buchungen beim Eintreffen der Antwort noch nicht passiert sind. Die Stempelantwort enthält den zugehörigen Rollover-Stand.

Erst **nach** dem Commit werden zurückgegebene Meilenstein-Webhooks, das Kartenereignis und der Wallet-Abrufhinweis angestoßen. Dafür bleibt keine Schreibsperre offen. Jeder Folgeschritt wird einmal versucht. Eine geworfene Ausnahme eines Folgeschritts verhindert die späteren Schritte nicht und macht eine bereits abgeschlossene Kernbuchung nicht nachträglich zu HTTP 500. Stattdessen bleibt die erfolgreiche Buchungsantwort erhalten und bekommt:

```json
{"warning":"follow_up_incomplete"}
```

Das Feld kommt zu den normalen Buchungsfeldern hinzu. **Nicht die Buchung wiederholen, um diesen Hinweis zu reparieren.** Der Server schreibt nur Art des Folgeschritts und Fehlerklasse ins Fehlerlog, keine rohe Ausnahme, Empfänger, Token oder Providerantwort. Fehlende Warnung ist kein Zustellbeleg: bestehende Providerhelfer können abgewiesene oder unklare Signale selbst behandeln, und eine leere Konfiguration löst keinen Versand aus.

Keine transaktionale Universal-Outbox, keine neue automatische Wiederholung und keine genau-einmalige externe Zustellung. Ein Prozessabbruch nach lokalem Commit kann Folgearbeit verlieren. Bereits gespeicherte fehlgeschlagene Webhookzustellungen folgen weiterhin ihrem eigenen Retryverfahren. Ein teilweise abgebrochener Aufruf kann nicht automatisch alle noch nicht gespeicherten Webhookempfänger rekonstruieren. Die optionalen Wallet-Mitteilungen und ihre Kundenwahl werden durch diese Kartenstandsignale nicht freigegeben oder ersetzt.

## Bewusst erhaltene Legacy-Regeln

Diese Etappe verändert die bestehenden Auswahl- und Eingaberegeln nicht still:

- Mitgliedspfade wählen weiterhin eine passende **aktive** Punkte-/Stempelkarte. Bei mehreren passenden Programmen gibt es hier keine neue Programmauswahl. Für eine bestimmte Karte den Kartenpfad verwenden.
- Der ältere explizite Karten-Punktepfad akzeptiert historisch auch Punktekarten eines inaktiven Programms. Das bleibt aus Kompatibilitätsgründen so. Nicht mit der strengeren aktiven Kartenauswahl der Merchant-API gleichsetzen.
- Der Basisbetrag wird weiterhin wie bisher nach Integer-Cast auf 1 bis 10.000 begrenzt. Ein fehlender oder ungültiger Betrag kann deshalb weiterhin eine Basispunktgutschrift von 1 ergeben. Neue Clients sollen immer einen gültigen positiven ganzzahligen Betrag senden. Eine neue strenge Eingabeversion ist damit nicht implementiert.
- Bonuszeiten verwenden weiter die PHP-Serverzeitzone und den höchsten passenden Multiplikator. Die Begrenzung betrifft den Basisbetrag vor dem Bonus.
- API-Bearer, bestehendes Anfragelimit, Unternehmensgrenzen und Planregeln bleiben nötig. Neue Buchungsendpunkte oder Einlösungen werden nicht ergänzt.

Für neue Integrationen, die gespeicherte Vorgangskennungen brauchen, gibt es die getrennte Merchant-API mit `operation`, siehe [API.md](API.md). Sie ist kein stiller Ersatz für diese alten Abläufe: Ihr dokumentierter Funktionsumfang umfasst derzeit keine automatische Meilenstein-/Webhook-/Wallet-Folgearbeit. Den gewünschten Workflow vor einer Umstellung prüfen.

Ein normales vollständiges Codeupdate reicht, es gibt keine neue Schemaänderung. Laufende Worker müssen anschließend dieselbe neue Fassung verwenden. Diese Etappe repariert keine historischen Teilbuchungen, doppelte Credits oder früheren Datenfehler automatisch; dafür ist ein eigener Abgleich nötig.

## Prüfung

```bash
python3 tests/run-api-credit.py
```

Die neue Suite prüft 34 Domänenfälle und 18 Integrationsfälle: beide Punktepfade, Stempelrollover, Bonuszeit, atomare Meilensteinpunkte/Inbox, genau einmalige lokale Meilensteine, Fehler im ersten/zweiten Journal sowie Award-/Inbox-Schritt, fremde/falsche Ziele, alte Auswahl-/Betragsregeln, Überlauf und Fehler nach Commit. Echte Startbarrieren erzeugen konkurrierende PHP-Prozesse für 30 gemischte Punktebuchungen und 24 Stempel; ein anderer Schreiber ändert den Aktivstatus, während die Buchung auf die Sperre wartet. Ein PHP-HTTP-Server mit mehreren Workern bearbeitet zusätzlich 20 gleichzeitige Stempelanfragen. Alle erwarteten Salden, Journal- und Rolloverzahlen werden geprüft.

Nach der letzten PHP-Codeänderung wurden alle 54 weiteren Nicht-WordPress-Suiten frisch erfolgreich ausgeführt. PHP-Lint, geänderte lokale Dokumentlinks und `git diff --check` sind grün. Das unveränderte WordPress-Plugin wurde nicht erneut geprüft. Eine zusätzliche lokale Negativ-/Positivkontrolle mit derselben künstlichen 100-ms-Pause nach dem Stempellesen zeigte beim alten Pfad 15 fehlgeschlagene Antworten von 16, beim neuen Pfad 16 erfolgreiche Antworten mit exakt 16 Stempeln im Saldo und Journal. Das ist eine gezielte Interleaving-Prüfung, kein Befund verlorener Produktivdaten oder ein Lasttest.

Die Providerpfade verwenden Ersatzfehler oder ausgeschaltete Konfiguration. Kein echter Webhook, SMTP-, Apple- oder Google-Aufruf und kein Gerät getestet. Kein Last-/Penetrationstest und keine neue Oberfläche. Der Mehrprozess-HTTP-Server wird einschließlich seiner Worker beendet.

Zusammenhang: [Betrieb und Berechtigungen](OPERATIONS.md), [Meilensteine](ACHIEVEMENTS.md), [Webhook-Retries](WEBHOOK-RETRIES.md), [Wallet-Signaltransport](WALLET-APNS.md).
