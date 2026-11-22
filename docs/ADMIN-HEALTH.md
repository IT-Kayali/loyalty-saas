# Versandzustand für Plattform-Admins

Stand: 22.11.2026. Rein lesende lokale Übersicht, keine externe Betriebs- oder Zustellabnahme.

## Zugang und Zweck

Im Admin-Dashboard führt **Versandzustand** zu `/admin/health`. Zugriff haben nur angemeldete Betreiberkonten aus `platform_admin_emails` in der Serverkonfiguration. Ein normales Betreiber-, Staff- oder Mitgliederkonto bekommt dadurch keine Plattformrechte. Die Seite bleibt unabhängig vom bezahlten Plan eines einzelnen Unternehmens erreichbar.

Sie zählt die aktuell aufbewahrten Zeilen aus Mail-Outbox und Webhook-Zustellungen, plattformweit. Der UTC-Zeitpunkt steht über den Zahlen. **Momentaufnahme erneuern** lädt nur dieselbe GET-Seite neu. Es gibt keine automatische Abfrage, Hintergrundüberwachung, Versandaktion, Wiederholung oder Bestätigung von Fehlern.

Die Seite behauptet insbesondere nicht, dass Cron läuft, SMTP erreichbar ist oder eine Nachricht im Postfach beziehungsweise auf einem Gerät angekommen ist. Eine leere Tabelle ist kein Beweis eines gesunden Systems: Aufgaben können fehlen, bereits bereinigt worden sein oder außerhalb dieser Anwendung liegen.

## Mail-Outbox richtig lesen

| Anzeige | Bedeutung |
| --- | --- |
| Alle gespeicherten Zeilen | Aufbewahrte Outbox-Zeilen, kein festes Berichtszeitfenster |
| queued | Wartet auf den Mail-Worker |
| sending | Vom Worker übernommen, noch ohne gespeicherten Abschluss |
| Davon länger als 10 Minuten | `claimed_at` liegt strikt mehr als zehn Minuten vor der Momentaufnahme, nicht genau zehn Minuten |
| unknown | Ergebnis unklar oder vom Versandhelfer nicht sicher bestätigt, nicht blind erneut senden |
| sent | Der Versandhelfer hat Erfolg gespeichert, kein Nachweis der Ankunft im Postfach |
| skipped | Vom Worker übersprungen, etwa wegen fehlender Einwilligung oder Planberechtigung |
| Nicht erkannter Status | Zahl abweichender gespeicherter Statuswerte, ohne deren Rohinhalt anzuzeigen |
| Offene Zeiten prüfen | Bei queued fehlt ein gültiger Anlagezeitpunkt oder er liegt in der Zukunft; bei sending gilt das entsprechend für den Übernahmezeitpunkt |

Der älteste auswertbare queued-Zeitpunkt wird zusätzlich angezeigt. Ungültige oder zukünftige Zeitangaben erzeugen dafür keine erfundene Wartezeit. Kanonisches Speicherformat ist `JJJJ-MM-TT HH:MM:SS` in UTC; etwa ein nicht existierender Kalendertag oder ein abweichendes ISO-/Offsetformat wird nicht still normalisiert.

Bei gültiger SMTP-Konfiguration markiert der bisherige Worker alte sending-Zeilen erst bei seinem nächsten Lauf als unknown. Die Anzeige macht das nicht selbst. Sie prüft bei fehlender SMTP-Konfiguration nur die vorhandene Konfiguration, sie baut keine Verbindung auf. Ohne gültige SMTP-Konfiguration verarbeitet `mail_worker()` keine Aufträge. Ein gesetzter Schalter allein beweist umgekehrt keinen erreichbaren Anbieter.

## Webhooks richtig lesen

pending, failed, dead und delivered zeigen die gespeicherten Zustände. Nicht erkannte Statuswerte werden nur gezählt. Die Unterzähler sind Diagnosemengen und können sich überschneiden:

- **Fällig und nicht gesperrt:** failed, gültiger nächster Termin erreicht, Sperre nicht vorhanden oder bereits abgelaufen.
- **Fällig und noch gesperrt:** derselbe erreichte Termin, aber gültige Sperre bis mindestens zur Aufnahmezeit. Wie im bestehenden Worker gilt eine Sperre genau zur aktuellen Sekunde noch als aktiv.
- **failed ohne nächsten Termin:** keine geplante automatische Wiederholung, auch wenn der Status failed lautet.
- **Offene Zeilen mit abgelaufener Sperre:** nicht delivered, gültiger Sperrzeitpunkt bereits vergangen. Das entfernt oder erneuert keine Sperre.
- **Ungültige offene Zeitangaben:** nicht auswertbare gesetzte Sperren oder ungültige gesetzte Wiederholungstermine. Sie werden nicht still als freie beziehungsweise fällige Arbeit behandelt.

Der älteste auswertbare fällige Wiederholungstermin bezieht auch noch gesperrte fällige Zeilen ein. Ein Termin in der Zukunft ist dagegen eine normale geplante Wiederholung, kein fehlerhafter Zeitstempel.

`pending` ist nicht Bestandteil der Auswahl von `webhook_retry_due()`. Für diese Zeilen gibt es außerdem keinen gespeicherten Anlagezeitpunkt. Die Seite erfindet deshalb weder ein Alter noch die Behauptung, Cron werde sie automatisch versenden. `delivered_at` wird im bestehenden Zustellhelfer auch bei fehlgeschlagenen Versuchen gesetzt und ist hier ausdrücklich kein erfolgreicher Zustellzeitpunkt.

Die tatsächliche Wiederholung bleibt im vorhandenen Worker beziehungsweise in den bestehenden gesonderten Admin-/Owner-Aktionen. failed kann per Cron wiederholt werden, dead verlangt eine gesonderte Prüfung. Details: [WEBHOOK-RETRIES.md](WEBHOOK-RETRIES.md). Diese Seite führt keine dieser Aktionen aus und prüft auch nicht vorab, ob Hook, Plan oder Ziel für einen künftigen Versuch noch gültig sind.

## Datenschutz und technische Grenzen

Die aggregierenden Abfragen lesen nur Status- und Zeitspalten. Keine Empfänger, Betreffzeilen, Inhalte, Rohstatuswerte, Payloads, URLs, Geheimnisse, Providerantworten oder Zeilen-IDs werden ausgegeben. Die Antwort ist `Cache-Control: private, no-store` und nur GET ist zugelassen. Ein POST mit gültigem CSRF-Token erhält HTTP 405, kein vorhandener Admin-Versandbefehl wird hier weitergereicht. Queryparameter schaffen keinen Ausführungsmodus und keinen Zeit- oder Mandantenfilter.

Beide Tabellen werden in einer gemeinsamen SQLite-Lesemomentaufnahme ausgewertet. Die Live-Uhr wird erst nach dem Erwerb dieses Lesestands genommen, damit eine gerade abgeschlossene Zeile nach dem Warten auf einen Schreiber nicht fälschlich als Zukunftszeit erscheint. Die Lesetransaktion und Cursor enden vor der HTML-Ausgabe. Es gibt keine neue Tabelle, Migration, gespeicherte Kennzahl oder Auditzeile für einen normalen Seitenabruf.

Die Zähler umfassen die aufbewahrten Daten. Löschen und Bereinigung ändern somit auch die Zahlen; sie sind keine unveränderliche Statistik. Bei großen Datenmengen brauchen vollständige Aggregationen Zeit. Keine Lasttest-, Skalierungs- oder Verfügbarkeitsgarantie.

Nicht erfasst: Wallet-Einzelmitteilungen, technische Pass-Updates, Kampagnenvorbereitung, fehlende Cron-Heartbeats, Bounces, Anbieterprotokolle, Sicherheitsanomalien oder automatische Reparatur. Wallet-Einzelmitteilungen sind keine normale Retry-Queue und werden hier nicht als solche ausgegeben. Providerannahme wäre auch dort kein Beleg für eine Anzeige auf dem Gerät.

## Prüfung

```bash
python3 tests/run-admin-health.py
```

21 Domänenprüfungen, 25 Integrations-/HTTP-Prüfungen und 24 Chromium-Prüfungen: leere Tabellen, alle Zustände, genaue Zehn-Minuten-/Termin-/Sperrgrenzen, ungültige Daten, fehlende oder zukünftige Zeiten, korrekte älteste Termine, unveränderte Liefer-/Audit-/Einstellungsdaten, freigegebene Lesesperren und ein echter exklusiver Schreiber. Zusätzlich Rollen und entfernter Adminzugriff, privater Cache, Methodenbegrenzung und folgenlose Queryparameter. Desktop 1280px sowie Mobilansichten mit 390px und 320px wurden geprüft. Desktop und mobile Ansichten wurden als echte Pixel auf Lesbarkeit und vollständig sichtbare Werte kontrolliert. Screenshots verwenden ausschließlich künstliche Testdaten. Keine echte Providerverbindung oder Worker-Ausführung.

Die Referenzübersicht [System health and security](https://rewardloyalty.co/docs/5.x/system-health-and-security) bestätigt einen Health-Center-Bereich mit Diagnosen. Daraus wird keine bestätigte Detailparität dieser eigenen Mail-/Webhook-Zähler oder eine vollständige Sicherheitsüberwachung abgeleitet.

Nach der letzten Änderung am PHP-/CSS-/Browsercode wurden alle 56 weiteren Nicht-WordPress-Suiten frisch erfolgreich ausgeführt. PHP-Lint, 157 lokale Dokumentlinks und `git diff --check` sind grün. Der eigene Runner wurde nach ergänzter Prüfung erfolgreicher Staff-/Mitgliederanmeldung nochmals ausgeführt. Das unveränderte WordPress-Plugin wurde nicht erneut geprüft.
