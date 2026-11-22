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

Beide Tabellen werden in einer gemeinsamen SQLite-Lesemomentaufnahme ausgewertet. Die Live-Uhr wird erst nach dem Erwerb dieses Lesestands genommen, damit eine gerade abgeschlossene Zeile nach dem Warten auf einen Schreiber nicht fälschlich als Zukunftszeit erscheint. Die Lesetransaktion und Cursor enden vor der HTML-Ausgabe. Die Queue-Auswertung selbst legt keine Laufbelege, gespeicherten Kennzahlen oder Auditzeilen für normale Seitenabrufe an. Die später ergänzte getrennte CLI-Statusanzeige hat eine eigene additive Statustabelle, siehe unten.

Die Zähler umfassen die aufbewahrten Daten. Löschen und Bereinigung ändern somit auch die Zahlen; sie sind keine unveränderliche Statistik. Bei großen Datenmengen brauchen vollständige Aggregationen Zeit. Keine Lasttest-, Skalierungs- oder Verfügbarkeitsgarantie.

Nicht erfasst: Wallet-Einzelmitteilungen, technische Pass-Updates, Kampagnenvorbereitung, fehlende Cron-Heartbeats, Bounces, Anbieterprotokolle, Sicherheitsanomalien oder automatische Reparatur. Wallet-Einzelmitteilungen sind keine normale Retry-Queue und werden hier nicht als solche ausgegeben. Providerannahme wäre auch dort kein Beleg für eine Anzeige auf dem Gerät.

## Prüfung

```bash
python3 tests/run-admin-health.py
```

21 Domänenprüfungen, 25 Integrations-/HTTP-Prüfungen und 24 Chromium-Prüfungen: leere Tabellen, alle Zustände, genaue Zehn-Minuten-/Termin-/Sperrgrenzen, ungültige Daten, fehlende oder zukünftige Zeiten, korrekte älteste Termine, unveränderte Liefer-/Audit-/Einstellungsdaten, freigegebene Lesesperren und ein echter exklusiver Schreiber. Zusätzlich Rollen und entfernter Adminzugriff, privater Cache, Methodenbegrenzung und folgenlose Queryparameter. Desktop 1280px sowie Mobilansichten mit 390px und 320px wurden geprüft. Desktop und mobile Ansichten wurden als echte Pixel auf Lesbarkeit und vollständig sichtbare Werte kontrolliert. Screenshots verwenden ausschließlich künstliche Testdaten. Keine echte Providerverbindung oder Worker-Ausführung.

Die Referenzübersicht [System health and security](https://rewardloyalty.co/docs/5.x/system-health-and-security) und die unten verlinkte Detailquelle beschreiben einen Health-Center-Bereich. Die lokalen Mail-/Webhook-Zähler sind nur ein begrenzter Ausschnitt, keine vollständige Sicherheitsüberwachung.

Zur ursprünglichen HTML-Etappe wurden nach der letzten damaligen PHP-/CSS-/Browseränderung alle 56 weiteren Nicht-WordPress-Suiten frisch erfolgreich ausgeführt. PHP-Lint, 157 lokale Dokumentlinks und `git diff --check` sind grün. Der eigene Runner wurde nach ergänzter Prüfung erfolgreicher Staff-/Mitgliederanmeldung nochmals ausgeführt. Das unveränderte WordPress-Plugin wurde nicht erneut geprüft.


## Private JSON-Momentaufnahme

`GET /admin/health/json` liefert dieselben Mail-/Webhook-Aggregate als JSON. In der HTML-Ansicht führt **JSON-Momentaufnahme** dorthin. Beide Links sind auch bei 320px getrennt bedienbar. Keine automatische Abfrage und kein eingerichteter Monitor.

Zugang nur mit einer gültigen Betreiber-Session, deren aktuelles Konto weiterhin in `platform_admin_emails` steht. Mitglieder-/Staff-Sessions, ein normaler Owner und ein API-Bearer allein reichen nicht. Widerrufene Sessions und entfernte Allowlist-Einträge verlieren sofort den Zugriff. Es gibt keinen zusätzlichen öffentlichen Health-Endpunkt, Login-Bypass, neuen Dienstschlüssel oder CORS-Zugriff. Monitoring-Software muss selbst eine berechtigte aktuelle Session verwenden und ihren Cookie-Speicher schützen. Diese Erweiterung richtet keine solche Anmeldung ein.

Die erfolgreiche Antwort hat diese Struktur. Gekürztes Beispiel, die vollständigen Zählernamen stehen in den beiden Tabellen-/Listenabschnitten oben:

```json
{
  "success": true,
  "data": {
    "schema_version": 1,
    "scope": "retained_delivery_queues",
    "observed_at_utc": "2026-11-22 12:00:00",
    "smtp_configured": false,
    "mail": {"total": 0, "queued": 0, "oldest_queued": null},
    "webhooks": {"total": 0, "failed": 0, "oldest_due": null},
    "limitations": ["retained_rows_only", "overlapping_subcounts", "canonical_utc_only", "smtp_configuration_only", "pending_webhooks_not_retried", "no_delivery_proof"],
    "not_checked": ["worker_heartbeat", "cron_execution", "smtp_connectivity", "inbox_delivery", "wallet_delivery", "device_display", "environment", "migrations", "storage", "billing"]
  }
}
```

`success: true` bedeutet nur: Die Momentaufnahme wurde gelesen. Es ist **kein Gesund-/OK-Status**. Die lokalen Felder und `schema_version: 1` sind ein eigener Vertrag, keine Nachbildung der Referenzantwort mit `checks` und `summary`. Es werden keine erfundenen Ampeln ausgegeben. Jede Antwort trägt Geltungsbereich und Grenzen mit sich.

- `observed_at_utc`: gemeinsame Aufnahmezeit im Format `JJJJ-MM-TT HH:MM:SS`, ausdrücklich UTC.
- `mail`: Integerfelder `total`, `queued`, `sending`, `stale_sending`, `unknown`, `sent`, `skipped`, `other`, `bad_open_time`; zusätzlich `oldest_queued` als kanonische UTC-Zeit oder `null`.
- `webhooks`: Integerfelder `total`, `pending`, `failed`, `dead`, `delivered`, `other`, `due_unlocked`, `due_locked`, `unscheduled`, `expired_locks`, `bad_open_time`; zusätzlich `oldest_due` als kanonische UTC-Zeit oder `null`.
- `smtp_configured`: Boolean aus der vorhandenen Konfigurationsprüfung, keine Verbindung und kein Zustelltest.
- `limitations`: nur aufbewahrte Zeilen, überlappende Untermengen, ausschließlich kanonische UTC-Zeiten, nur SMTP-Konfiguration, kein automatischer Retry von pending-Webhooks und kein Zustellnachweis.
- `not_checked`: nicht geprüfte Worker-/Cron-Ausführung, SMTP-Erreichbarkeit, Postfach-/Wallet-/Geräteankunft und die weiteren Referenzbereiche Umgebung, Migrationen, Speicher und Billing. Eine leere Queue hebt diese Grenzen nicht auf.

### HTTP und Fehler

Vom JSON-Handler erzeugte Antworten haben `Content-Type: application/json; charset=utf-8`, `Cache-Control: private, no-store` und `X-Content-Type-Options: nosniff`, auch bei Fehlern. Keine Download-Anlage und keine CORS-Freigabe.

| HTTP | Antwort |
| --- | --- |
| 200 | `success: true` mit `data` wie oben |
| 401 | `{"success":false,"error":"authentication_required"}`, keine gültige Betreiber-Session |
| 403 | `{"success":false,"error":"platform_access_required"}`, Konto nicht als Plattform-Admin freigegeben |
| 405 | `{"success":false,"error":"method_not_allowed"}`, nach erfolgreicher Berechtigungsprüfung nur GET erlaubt; Header `Allow: GET` |
| 503 | `{"success":false,"error":"snapshot_unavailable"}`, keine Teilzähler, SQL-Texte, Pfade oder Exceptiondetails |

HEAD wird ebenfalls mit 405 abgewiesen und hat entsprechend der HTTP-Semantik keinen Antwortbody. Die vorhandene zentrale CSRF-Prüfung bleibt vor der Route: Ein POST ohne gültiges Token kann deshalb schon dort mit 403 und HTML enden. Sonstige zentrale Start-/Sicherheitsfehler sind ebenfalls kein Versprechen des JSON-Vertrags. Ein POST mit gültigem Token bleibt 405, er führt keine Aktion aus.

Queryparameter wie `business_id`, `now`, `retry`, `action`, `format`, `callback` oder `lang` ändern weder Auswahl noch Uhr, Format oder Ausführung. Auch JSONP gibt es nicht. Normale GETs speichern keine Kennzahl und erzeugen keine Auditzeile. Eine Anmeldung wird wie bisher getrennt protokolliert. Aggregation und Fehlerbehandlung starten keinen Worker, setzen keine Sperre zurück und lösen keinen Versand aus.

### Zusätzliche Prüfung

```bash
python3 tests/run-admin-health-json.py
```

38 Domänen-, 81 HTTP-/Integrations- und 24 Chromium-Prüfungen: exakter Vertrag, Typen, Nullzeiten, gemeinsame Aggregate, keine Rohdaten, echte Rollen-/Sessionprüfung und Widerruf, kein API-Bearer-Ersatz, zentrale CSRF-Regel, alle abgewiesenen Methoden, Header, unveränderte Daten und erzwungener SQL-Fehler mit generischer 503-Antwort. Browser prüfen den echten Link bei 1280/390/320px, Abstand und Bedienbarkeit sowie unveränderte Daten außer genau drei erwarteten Login-Auditzeilen. Die bisherige Health-Suite verwendet denselben ausgelagerten künstlichen Fixture-Datensatz; ihre fachlichen Grenzfälle bleiben unverändert.

Die [Detailquelle zum Health center](https://rewardloyalty.co/docs/5.x/system-health-and-security/health-center), am 22.11.2026 erneut gelesen, nennt authentifiziertes Diagnose-JSON. Diese Etappe ergänzt nur das Ausgabeformat für vorhandene lokale Queue-Daten. Vollständige Referenzdiagnosen, tatsächliche Task-Laufbelege und manuelle Run-now-Steuerung sind damit nicht umgesetzt oder abgenommen. Keine Provider-, Produktions-, Last- oder Geräteabnahme.

Nach der letzten PHP-/CSS-/Browseränderung dieser JSON-Etappe wurden alle 59 weiteren Nicht-WordPress-Suiten auf unveränderten 204 Quelldatei-Hashes frisch erfolgreich ausgeführt. Der neue Zielrunner blieb bei 38/81/24 grün; 80 PHP-Dateien lintfrei, 222 lokale Dokumentationslinks gültig und `git diff --check` sauber. Das unveränderte WordPress-Plugin und echte Provider wurden nicht erneut beziehungsweise nicht live geprüft.

## Getrennte CLI-Aufrufbelege

Der zusätzliche Link **CLI-Laufstatus** führt zu `/admin/maintenance`. Er zeigt den zuletzt gespeicherten Start und Abschluss der drei instrumentierten vorhandenen CLI-Befehle, nicht die Gesundheit der Queue oder einen Scheduler-Heartbeat. Dafür wird eine additive, zunächst leere Tabelle angelegt. Die hier beschriebenen Mail-/Webhook-Aggregate und der Queue-JSON-Vertrag bleiben unverändert. Keine Ausführung per Seitenabruf. [Statustexte, Fehlerverhalten, Datenschutz und Grenzen](CLI-MAINTENANCE.md).

## HTTP-Testdaten ohne Sekundenrennen

Der JSON-HTTP-Runner setzt seine rein künstlichen Zeiten nun eine Stunde zurück und prüft ausdrücklich, dass seine Aggregate über ein Zwei-Minuten-Uhrfenster stabil bleiben. Der frühere Anker minus 60 Sekunden verschob eine plus-60-Sekunden-Sperre genau auf die aktuelle Sekunde. Dadurch konnten aufeinanderfolgende reine GETs korrekterweise verschiedene Sperrzähler liefern. Mit zwei um eine Sekunde versetzten Momentaufnahmen bei unveränderten Zeilen reproduziert. Kein Produktfehler und keine Änderung der Queue-Auswertung. Die isolierten fachlichen Tests behalten ihre feste Uhr und exakten Grenzwerte. Aktuell 38 Domänen-, 82 HTTP-/Integrations- und 24 Browserprüfungen für JSON; frühere Etappenstatistiken oben beschreiben den damaligen Stand.
