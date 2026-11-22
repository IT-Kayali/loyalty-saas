# CLI-Laufstatus für Plattform-Admins

Stand: 22.11.2026. Lokale Aufrufbelege, keine Cron-, Verfügbarkeits- oder Zustellabnahme.

## Zweck und Zugang

Unter **Admin → Versandzustand → CLI-Laufstatus** (`GET /admin/maintenance`) steht je unterstütztem CLI-Befehl der zuletzt gespeicherte Start mit seinem Abschluss. Nur eine aktuelle Betreiber-Session mit weiterhin freigegebener `platform_admin_emails`-Adresse erhält Zugriff. Normale Betreiber, Mitglieder, Staff und API-Bearer allein erhalten keine Plattformrechte. Die Leseansicht benötigt keinen aktiven Unternehmensplan.

Die Seite aktualisiert sich nicht automatisch. **Momentaufnahme erneuern** ist nur ein neuer GET. Weder Link noch Queryparameter oder POST starten eine Aufgabe. Andere Methoden sind 405 mit `Allow: GET`; die vorhandene zentrale CSRF-Prüfung kann POST vorher mit 403 abweisen. Antworten sind privat, nicht cachebar und senden keinen Referrer. Lesefehler ergeben eine generische 503-Seite ohne SQL, Dateipfade oder Teilbelege.

## Welche Aufrufe erfasst werden

| Aufgabe | Bestehender CLI-Befehl | Grenze |
| --- | --- | --- |
| Mail / Automation | `php tools/mail-worker.php [Limit]` | Der bestehende Lock gilt zuerst. Ohne SMTP bleibt der bestehende Inbox-Automationspfad möglich, aber es werden keine E-Mails gesendet. |
| Punkteverfall | `php tools/expire-points.php` | Verarbeitet nur bestehende aktivierte Inaktivitätsregeln; kein neuer Verfallsmodus. |
| Webhook-Wiederholungen | `php tools/webhook-retry.php` | Der bestehende Lock gilt zuerst; Auswahl und Retry-Verhalten bleiben im vorhandenen Helfer. |

Die Befehle und ihre fachlichen Ergebnisse werden nicht erweitert. Die Tabelle beschreibt Instrumentierung, **keine Aufforderung, diese Befehle zur Prüfung auf einer produktiven Installation auszuführen**. Mail/Automation kann Nachrichten vorbereiten oder versenden, Verfall kann Punkte abbuchen und Retry kann externe Ziele aufrufen. Einrichtung und Freigabe solcher Ausführungen bleiben getrennt. Diese Erweiterung installiert keinen Cronjob und führt bei Seitenabrufen keine Wartung aus.

Nicht erfasst: direkte Aufrufe derselben Helfer über Web/API oder andere PHP-Skripte, Backup/Restore, PayPal-Abgleich und Wallet-Nachrichtenbereinigung. Frühe Boot-, Schema- oder Lockfehler vor Beginn des Beobachters erzeugen keinen neuen Beleg. Ein vorhandener älterer Beleg bleibt dann unverändert.

## Status richtig lesen

| Anzeige | Gesicherte Aussage |
| --- | --- |
| Kein Lauf erfasst | Kein auswertbarer gespeicherter Beleg. Nicht: Die Aufgabe lief noch nie. |
| Kein Abschluss gespeichert | Ein Start wurde gespeichert, aber kein passender Abschluss. Der Prozess kann laufen, beendet worden sein oder seinen Abschluss nicht speichern können. |
| Ohne geworfenen Fehler beendet | Der beobachtete PHP-Aufruf kam ohne nach außen geworfenen Fehler zurück. Kein Erfolgsbeleg für jede einzelne Arbeit oder Zustellung. |
| Mit Fehler beendet | Der beobachtete Aufruf warf einen Fehler. Keine Fehlertexte oder betroffenen Datensätze werden gespeichert oder angezeigt. |
| Gespeicherte Daten prüfen | Status und UTC-Zeitangaben passen nicht verlässlich zusammen. Keine rohe ungültige Zeit und keine geschätzte Laufzeit. |

Start und Abschluss verwenden kanonische UTC-Zeiten `YYYY-MM-DD HH:MM:SS`, gültige Kalendertage ab Jahr 0001. Ein Abschluss vor dem Start, zukünftige Zeiten, fehlende Pflichtzeiten oder widersprüchliche Zustände werden nicht normalisiert. Die Aufnahmezeit wird erst nach dem Lesen der Belege genommen. Eine Uhrkorrektur kann deshalb Prüfbedarf erzeugen; es gibt keine automatische Datenreparatur.

**Ohne geworfenen Fehler beendet ist keine Gesund-Ampel.** Der Mail-Helfer kann einzelne Ergebnisse als `unknown` behandeln oder Arbeit wegen Einwilligung/Plan überspringen. Webhook-Versuche können fehlschlagen, obwohl der Retry-Aufruf normal endet. Deaktiviertes SMTP ist ebenfalls ein regulärer vorhandener Pfad. Die [Versandmomentaufnahme](ADMIN-HEALTH.md) und gegebenenfalls der Anbieter müssen getrennt geprüft werden. Keine Postfach-, Wallet- oder Geräteankunft wird behauptet.

Manuelle CLI-Aufrufe und Cron sind nicht unterscheidbar. Es gibt keinen lebenden Prozess-Heartbeat, keine PID-Prüfung, keine Sollfrequenz und keine automatische Überfälligkeitswertung. Auch ein eben gespeicherter Abschluss beweist nicht, dass der nächste Cronlauf stattfinden wird.

## Speicherung, Parallelität und Fehler

Die additive Schemaerweiterung in `app/bootstrap.php` legt `maintenance_status` an. Wie beim bisherigen Schema erfolgt das beim Initialisieren der Datenbank. Vor einer Installation/Übernahme daher wie sonst eine aktuelle private Sicherung erstellen. Keine bestehenden Zeilen werden dabei in Laufbelege umgedeutet. Die Tabelle bleibt zunächst leer, bis ein unterstützter CLI-Aufruf den Beobachter erreicht.

Maximal drei Zeilen, genau ein Schlüssel je unterstützter Aufgabe. Gespeichert werden Aufgabenschlüssel, interner zufälliger Aufruftoken, Start, Abschluss und einer der drei Speicherzustände. Keine Zähler zu Mitgliedern, Adressen, Nachrichten, Fehlertexte, SQL, URLs, Inhalte, Geheimnisse, PIDs oder Benutzernamen. Der interne Token wird nicht für die Anzeige ausgewählt und ist keine Zugriffsberechtigung.

Ein neuer gespeicherter Start ersetzt den vorherigen Beleg. Sein Abschluss wird nur mit demselben Token eingetragen. Ein älterer überlappender Prozess kann damit keinen neueren Aufruf als beendet markieren. Die Anzeige ist bewusst keine Laufhistorie und kein unveränderliches Auditarchiv. Es gibt auch keine Behauptung, alle tatsächlich gestarteten Aufrufe erfasst zu haben.

Statusschreiben verwenden eine getrennte SQLite-Verbindung mit 150 ms Busy-Timeout, ohne Transaktion über die eigentliche Arbeit. Scheitert ein Statusschreiben, meldet STDERR nur einen festen Hinweis und die eigentliche Arbeit wird trotzdem einmal ausgeführt beziehungsweise ihr Ergebnis oder ursprünglicher Fehler beibehalten. Ein gescheiterter Abschluss kann als offener Beleg stehenbleiben. Die vorhandene gemeinsame Datenbankinitialisierung behält ihre bisherigen Zeit-/Fehlergrenzen; das 150-ms-Limit ist keine Gesamtlaufzeitgarantie.

Die CLI-Befehle behalten ihre fachlichen Ausgaben und vorhandenen Lockgrenzen. Die Leseansicht startet keinen Worker, prüft keinen Anbieter und verändert keine Laufzeile. Die bisherige Queue-JSON-Momentaufnahme unter `/admin/health/json` behält unverändert ihren eigenen Vertrag und ihre Grenzen.

## Prüfung und verbleibender Umfang

`python3 tests/run-maintenance.py` prüft die drei echten lokalen CLI-Befehle an einer Wegwerf-Datenbank ohne konfigurierte Provider/aktive Versandarbeit, beleggleiche Busy-Lock-Abbrüche, echte überlappende Prozesse, Token-Schutz, Rückgabewert/ursprünglichen Fehler, fehlgeschlagene Statusschreibvorgänge und Zeit-/Statusgrenzen. HTTP prüft Rollen, Widerruf der Adminfreigabe, Planunabhängigkeit, private Header, reine GETs, abgewiesene Methoden und generische Lesefehler. Chromium folgt dem echten Health-Link bei 1280/390/320px und prüft alle Statusfelder, Umbruch, Refresh und fehlende Ausführungsformulare. Screenshots enthalten nur künstliche Daten.

Der Zielrunner besteht 54 Domänen-, 44 HTTP-/Integrations- und 33 Browserprüfungen. Nach der letzten Produkt-/Teständerung sind alle 64 Nicht-WordPress-Suiten auf 229 unveränderten Anwendungs-/Public-/Test-/CLI-Dateihashes frisch bestanden. 91 PHP-Dateien sind syntaktisch geprüft. Die beiden erklärten vorhandenen Zeitrennen im Health-JSON-HTTP-Test und PayPal-SDK-Navigationstest sind korrigiert, ohne fachliche Aussagen zu entfernen; der PayPal-Testfix bestand zusätzlich dreimal auf sauberer Etappe-16-Basis. Das unveränderte WordPress-Plugin bleibt separat, keine echte Providerabnahme.

Kein Ersatz für fehlende vollständige Referenzdiagnosen, geplante Tasksteuerung, Run-now-Freigaben, Scheduler-/Prozessüberwachung, Aufgabenhistorie, Alarmierung, echte SMTP-/Webhook-Zustellung oder Produktions-/Lastabnahme. Diese Dinge sind teilweise weiterer lokaler Bau, nicht ausschließlich fehlende Anbieterzugänge.

Referenz: [Reward Loyalty Health center](https://rewardloyalty.co/docs/5.x/system-health-and-security/health-center), am 22.11.2026 gelesen. Belegt letzten Lauf und Ergebnis von Wartungsaufgaben. Die lokale kleine Statusanzeige ist bewusst kein vollständiges Scheduled-tasks-Panel. Technische Grundlage: [SQLite UPSERT](https://sqlite.org/lang_upsert.html); Datenschutz und vom Geschäftsvorgang getrennte Protokollfehler: [OWASP Logging Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html). Keine Behauptung eines vollständigen Sicherheitsaudits.
