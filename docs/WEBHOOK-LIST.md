# Eigene Webhook-Zustellliste und CSV-Metadaten

Stand: 22.11.2026. Rein lesende Erweiterung, keine Änderung an Versand oder Wiederholungen.

## Zugang

Auf der Unternehmensseite führt im Webhook-Bereich **Zustellliste und CSV-Metadaten** zu:

- `GET /business/{id}/webhook-deliveries`: gefilterte Liste, neueste gespeicherte Zustellkennungen zuerst, 50 Zeilen pro Seite.
- `GET /business/{id}/webhook-deliveries/export`: CSV mit allen passenden Zeilen, nicht nur der gerade sichtbaren Seite.

Eine aktuelle Betreiber-Session und das Eigentum an genau diesem Unternehmen sind erforderlich. Andere Unternehmen desselben Kontos brauchen ihre eigene URL. Mitglieds-/Staff-Sessions reichen nicht; Plattform-Admin zu sein ersetzt das Eigentum nicht. Unbekannte und fremde Unternehmen sind nicht verfügbar. Ein fremder Webhookfilter liefert keine Treffer und keine Auskunft über dessen Eigentümer.

Das bestehende Lesen eigener Unternehmensdaten bleibt auch bei abgelaufenem Plan möglich. Diese Erweiterung gibt keine Schreib-, Versand- oder zusätzlichen Planrechte. Sie richtet keinen Monitor ein, startet keinen Worker und ruft keinen Anbieter auf. Die bisherigen Retry-/Lösch-/Hinweisaktionen bleiben ausschließlich an ihren vorhandenen Stellen.

## Gemeinsame Filter

Liste und Download verwenden dieselbe gebundene SQL-Auswahl, immer unter der Kombination aus angemeldetem Owner und genau einer Unternehmens-ID:

| Parameter | Bedeutung |
| --- | --- |
| `hook` | Optionale positive Webhook-ID; nur Treffer dieses Unternehmens |
| `event` | Exakter gespeicherter Eventtext, maximal 100 Zeichen; `%`, `_` und `!` sind keine Platzhalter |
| `status` | Leer oder einer der lokalen Codes `pending`, `failed`, `dead`, `delivered` |
| `from` | Gültiger Tag `JJJJ-MM-TT`, letzter gespeicherter Versuch ab 00:00:00 UTC einschließlich |
| `to` | Gültiger Tag `JJJJ-MM-TT`, letzter gespeicherter Versuch bis 23:59:59 UTC einschließlich |
| `before` | Nur in der Liste: positive Zustell-ID für ältere Zeilen, nicht Bestandteil eines Exports |
| `lang` | Falls angegeben `de` oder `en`, vorhandener Sprachrahmen; diese neue Oberfläche ist deutsch, Codes und Auswahl bleiben sprachunabhängig |

Die Formularabgabe beginnt eine neue Liste ohne alten Seiten-Cursor. **Ältere 50** behält die Filter, aber der Exportlink lässt den Cursor immer weg. Ein direkt an den Export übergebener Cursor wird abgewiesen, nicht still als Teilmenge ausgegeben. Filter-Arrays, Nicht-Textwerte, ungültiges UTF-8, Steuerzeichen, zu große IDs, unbekannte Status-/Zusatzparameter und ungültige oder umgekehrte Datumsbereiche erhalten 422. Ungültige Eingaben verbreitern nie unbemerkt den Download.

Die UTC-Tage beziehen sich auf `webhook_deliveries.delivered_at`. Der historische Spaltenname ist missverständlich: Der vorhandene Helfer setzt ihn auch bei Fehlern oder einem deaktivierten Ziel. **Es ist kein Zustellnachweis und nicht einmal ein Beleg eines Netzwerkaufrufs.** Es ist weder die Ereignis- noch die Anlagezeit. Für pending kann der Zeitpunkt fehlen. Er wird nicht aus anderen Spalten erfunden.

Ohne Datumsfilter bleiben auch Zeilen ohne oder mit ungültigem Zeitpunkt sichtbar. Sobald ein Zeitraumfilter gesetzt ist, werden nur kanonische UTC-Zeiten im Format `JJJJ-MM-TT HH:MM:SS` verglichen. Nicht existierende Kalendertage und abweichende ISO-/Offsetformate werden nicht normalisiert. In der Ansicht steht für solche gespeicherten Zeitwerte **Ungültig gespeichert**. Das CSV behält die Rohwerte zu Diagnosezwecken; ein Importer muss Zeitwerte selbst validieren. Ein dortiger Spaltenname mit `_utc` garantiert also keine reparierten Altdaten.

## CSV-Vertrag

Genau sieben Spalten, in dieser Reihenfolge. Die Zeilengrenze zählt Datensätze, nicht physische Textzeilen innerhalb mehrzeiliger CSV-Zellen:

```text
delivery_id,webhook_id,event,status,attempts,last_attempt_at_utc,next_attempt_at_utc
```

- Kennungen gehören nur zu den eigenen passenden gespeicherten Zustellungen/Webhooks.
- Event, Status und Versuchszahl stammen aus dem gespeicherten Datensatz. Die Ansicht kürzt lange Textwerte; der Export tut das nicht.
- Fehlende Zeitwerte werden als leere CSV-Zelle ausgegeben, nicht als Nullzeit oder aktuelle Uhr.
- Kein Payload, Secret, Ziel-URL, roher Antworttext, Empfänger oder Mitglieder-/Journalinhalt.
- HTTP-Code, Laufzeit, eigenständige Event-ID und Testkennzeichnung werden bisher nicht getrennt gespeichert. Sie werden nicht aus Antworttexten erraten oder als leere angeblich unterstützte Referenzspalten angeboten.
- Reihenfolge nach Zustell-ID absteigend. Eine höhere ID beweist keine Ereigniszeit.

Alle Zellen sind in doppelte Anführungszeichen gefasst, innere Anführungszeichen verdoppelt, Zeilenenden CRLF. Kommas, Anführungszeichen und mehrzeilige Eventtexte bleiben dadurch eine Zelle. Riskante Formel-/Kontroll-/Fullwidth-Anfänge erhalten einen führenden Apostroph, gegebenenfalls nach Prüfung vorangestellter Leer-/Formatzeichen. Dieser Schutz verändert solche Textwerte. Keine universelle Garantie für Excel, LibreOffice oder Weiterverarbeitung: Speichern und erneutes Öffnen kann Schutzzeichen verändern. Für diese Etappe wurde kein echtes Tabellenprogramm abgenommen. Hintergrund: [OWASP CSV Injection](https://community.owasp.org/attacks/CSV_Injection).

### Grenzen ohne Teildatei

Eigene lokale Grenzen, nicht behauptete Grenzwerte der Referenz:

- Maximal 5000 passende Zeilen. Genau 5000 sind erlaubt, 5001 nicht.
- Maximal 4 MiB fertig kodiertes CSV einschließlich Header. Genau die Grenze ist erlaubt, ein Byte mehr nicht.
- Maximal 64 KiB pro gespeicherter Text-Metadatenzelle. Eine SQL-Projektion verhindert schon vor dem PHP-Fetch, dass übergroße Altzellen vollständig in die Anwendung geladen werden; die Zeilenprüfung kontrolliert zusätzlich unabhängig.
- Ungültiges gespeichertes UTF-8 wird nicht durch Ersatzzeichen verdeckt.

Bei einer dieser Überschreitungen oder ungültigem Text gibt es 422 ohne `Content-Disposition` und ohne Dateianfang oder stilles Abschneiden. Filter enger wählen beziehungsweise Altdaten gesondert prüfen. Ohne Treffer ist eine erfolgreiche CSV-Datei nur mit Header möglich. Es gibt hier keinen JSON-/TSV-Export und keine automatische Trunkierungsdatei.

Der Download wird vollständig innerhalb der Grenze aufgebaut, bevor CSV-/Attachment-Header gesendet werden. Die SELECT-Abfrage bildet einen konsistenten Lesestand; der Cursor wird auch bei Kodierungs-/Größenfehlern geschlossen. CSV und eine früher geöffnete Liste sind aber keine gemeinsame Langzeit-Momentaufnahme: Zwischen zwei Aufrufen können neue Zeilen hinzukommen, Zustände wechseln oder Webhooks samt Protokoll gelöscht werden.

## Datenschutz und Bedienung

Liste, Dateien und vom Handler erzeugte Fehler sind `Cache-Control: private, no-store`; zusätzlich `X-Content-Type-Options: nosniff`. Dateien heißen `webhook-deliveries-{business_id}-{UTC-Tag}.csv`, Content-Type `text/csv; charset=utf-8`. Nur GET ist zugelassen, andere Methoden nach Berechtigungsprüfung 405 mit `Allow: GET`. Die vorhandene zentrale CSRF-/Planprüfung bleibt vorgeschaltet und kann einen POST schon vorher abweisen. Die neue Ansicht enthält nur ein GET-Filterformular, keine Retry-/Run-now-/Ack-/Löschaktion.

Desktop und Mobilansichten bei 1280/390/320px wurden geprüft. Die Tabelle bricht auf dem Desktop um und zeigt auf schmalen Bildschirmen beschriftete Zeilenkarten ohne seitliches Scrollen. Der normale Seitenabruf, Filter, Blättern und Download schreiben keine Zustell-, Audit-, Mail-, Einstellungs- oder Gerätedaten. Eine notwendige Anmeldung wird separat wie bisher protokolliert.

Die Daten sind eine veränderliche Übersicht aufbewahrter Zeilen, kein lückenloses Zustelljournal, unveränderlicher Finanz-/Auditbeleg oder fester Berichtszeitraum. Löschen eines Webhooks entfernt dessen Zustellungen. Dieser Abruf führt keine neue Aufbewahrungsbereinigung aus. Große Aggregat-/Filterabfragen sind keine Last- oder Skalierungsgarantie.

## Unveränderte Versandgrenzen und Referenzabgleich

Die detaillierte Referenz [Deliveries, retries, and troubleshooting](https://rewardloyalty.co/docs/5.x/webhooks/deliveries-and-troubleshooting), erneut gelesen am 22.11.2026, bestätigt gefilterten CSV-Export ausschließlich von Zustellmetadaten, ohne Payloads, Secrets oder Antwortbodies. Das begründet diese begrenzte Lese-/Exportetappe, keine volle Analytics-/Pipeline-Parität.

Die Referenz beschreibt andere Status-/Retryregeln, drei Versuche, Testkennzeichnung, Metriken und 30-Tage-Bereinigung. Die lokale Anwendung behält ihre bisherigen sechs Versuche, Backoffs, manuellen Wiederholungen, Sperren und synchronen Erstversand. Keine neue Auto-Pause, Hintergrundpipeline, Testsendung, Mail, Preis-/Planänderung oder Retention. Siehe [bestehende Retry-Regeln](WEBHOOK-RETRIES.md). Die fehlenden getrennten Metadaten, Chart-/Dauer-Auswertung und vollständige Referenzparität bleiben offen. Support-Kontextangaben sind eine andere offene Etappe, nicht durch diesen Export ersetzt.

## Prüfung

```bash
python3 tests/run-webhook-list.py
```

89 Domänen-, 72 HTTP-/Integrations- und 33 Chromium-Prüfungen: strenge Filter/Typ-/Kalender-/ID-Grenzen, exakte Events, kombinierte Auswahl, isolierte Unternehmen desselben und anderer Owner, kein automatischer Admin-Ersatz, echte Mitglieder-/Staff-Sessions, leere Treffer, echte Zwei-Seiten-Navigation, vollständige gefilterte CSV-Downloads, sieben feste Spalten, Formelschutz/Quoting, fehlende/ungültige Zeitangaben, exakte 5000/5001-Zeilen- und 4-MiB-/Ein-Byte-darüber-Grenzen, übergroße Zellen und fehlerhaftes UTF-8 ohne Teildatei. Browser prüfen echte Downloads und die mobile Kartenansicht ohne horizontalen Überlauf. Daten bleiben unverändert außer genau drei erwarteten Browser-Login-Auditzeilen. Ein Transport-Spion bleibt unbenutzt. Nur lokale Wegwerf-Testdaten, keine echte Zielserver-, SMTP-, Tabellenprogramm- oder Produktionsabnahme.

Nach der letzten PHP-/CSS-/Browseränderung wurden alle 60 weiteren Nicht-WordPress-Suiten frisch auf unveränderten 209 Quelldatei-Hashes erfolgreich ausgeführt. 83 PHP-Dateien lintfrei, 227 relative Dokumentationsverweise geprüft und `git diff --check` sauber. Das unveränderte WordPress-Plugin wurde funktional nicht erneut geprüft.
