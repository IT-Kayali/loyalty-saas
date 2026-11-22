# API-/Shop-Mitglieder: Anlage und Karten zusammen

Stand: 22.11.2026. Lokal implementiert und mit parallelen PHP-Prozessen sowie echten HTTP-Anfragen geprüft. Keine echte Provider- oder Produktionsabnahme.

## Geltungsbereich

Der gemeinsame Helfer `api_member_upsert()` wird von genau diesen Eingängen verwendet:

- `POST /api/v1/members`
- `POST /api/v1/integrations/order`
- signierte Shopify-Bestellungen unter `/integrations/shopify/webhook/{businessId}`

Er öffnet `BEGIN IMMEDIATE`, bevor er Unternehmensinhaber, aktuellen Kanalplan, bestehendes Mitglied oder aktive Programme liest. Für ein neues Mitglied gehören Mitgliedszeile und sämtliche Karten der dabei aktiven Programme zu einem Commit. Gutschein-Nutzungen und Mehrfachkarten-Besuche erhalten weiterhin die konfigurierten Anfangswerte. Auch ein Unternehmen ohne aktive Programme darf ein Mitglied anlegen.

Ein Fehler bei der Mitgliedszeile oder einer späteren Karte rollt die gesamte Anlage zurück. Auch ein unerwartet ignorierter INSERT darf nicht als erfolgreiche vollständige Anlage durchgehen. Es bleiben weder das neue Mitglied noch ein halber Kartensatz zurück. Gleichzeitig aufrufende API-/Shop-Helfer mit derselben nichtleeren E-Mail im selben Unternehmen finden danach dasselbe Mitglied, statt ungeschützt zwei Anlagen auszuführen.

Planprüfungen erfolgen unter der Schreibsperre erneut. REST verwendet `api`, Shopify weiterhin `integrations`. Ein gültiger Integrationsplan wird nicht durch einen zusätzlich verlangten API-Schalter gesperrt. Bestehende SQL-Regeln prüfen außerdem Mitgliederlimit und Programmberechtigungen. Ein bereits vorhandenes Mitglied bleibt bei erreichtem Mengenlimit wiederverwendbar, sofern der Kanalplan die Aktion weiterhin erlaubt.

## Bewusst erhaltene Regeln

- Die Suche bleibt pro Unternehmen und mit dem bisherigen SQLite-Ausdruck `lower(email)=lower(?)`. Die geprüfte Groß-/Kleinschreibung betrifft ASCII, keine neue Unicode-/IDN-E-Mail-Normalisierung.
- Ein Treffer wird unverändert zurückgegeben. Name, Kennwort, Einwilligungen und vorhandene Karten werden nicht überschrieben. Später hinzugekommene Programme werden bei diesem Treffer nicht automatisch nachgetragen.
- Historisch doppelte E-Mails werden weder zusammengeführt noch sortiert neu ausgewählt. Es bleibt die bisherige erste Trefferabfrage ohne neue Reihenfolge.
- Eine leere E-Mail bedeutet weiterhin eine neue Anlage bei jedem Aufruf, auch bei gleichzeitigen Anfragen. Der Mitglieder-REST-Pfad verlangt weiterhin einen Namen; Bestellpfade verlangen weiterhin eine E-Mail.
- Der interne Namensfallback bleibt Name, sonst E-Mail, sonst `Kunde`. Die öffentliche Mitgliederantwort bleibt auf ihre bisherigen Felder beschränkt. Keine Kennwort-/Einwilligungsspalten oder internen Hilfsfelder gelangen hinein.
- Authentifizierung, Shopify-Signatur, Themenfilter, Rate Limits und Unternehmensbindung bleiben erforderlich.

Keine neue globale Unique-E-Mail-Regel, keine Datenmigration und keine Aussage, dass andere Eingänge wie öffentlicher Beitritt oder Merchant-Anlage dieselbe E-Mail-Semantik übernehmen. Direkte Datenbankzugriffe sind keine unterstützte öffentliche API.

## Ereignis nach dem Commit

`member.joined` wird nur für eine tatsächliche Neuanlage und erst nach dem lokalen Commit einmal angestoßen. Eigene Lesecursor des Helfers und des Shopify-Unternehmensabrufs werden vorher geschlossen. Die Mitgliedsanlage hält damit über die Providerübergabe keine eigene offene Schreibtransaktion. Der zusätzliche Callback-Parameter ist ausschließlich eine lokale Testnaht, kein Requestparameter.

Wirft die Ereignisübergabe eine Ausnahme, bleibt das Mitglied mitsamt Karten erfolgreich angelegt. Die normale erfolgreiche Antwort erhält auf oberster Ebene:

```json
{"warning":"member_event_incomplete"}
```

Das gilt für den Mitglieder-REST-Pfad mit HTTP 201 und für erfolgreiche Bestell-/Shopify-Antworten mit HTTP 200. Bestellpunkte werden nach diesem abgefangenen Mitgliederereignisfehler weiterhin versucht. Das Mitgliederobjekt wird nicht durch interne Metadaten erweitert. Das Fehlerlog erhält nur Fehlerklasse und festen Kontext, nicht rohe Ausnahmetexte oder Providerdaten.

**Nicht erneut anlegen, um diese Warnung zu reparieren.** Vor allem bei leerer E-Mail würde ein weiterer POST absichtlich ein weiteres Mitglied erzeugen. Das Wiederfinden eines Mitglieds wiederholt ein fehlgeschlagenes `member.joined` nicht. Fehlende Warnung ist kein Zustellbeleg: keine konfigurierten Empfänger, gesperrte Webhookfunktion oder bereits intern behandelter Zustellfehler können ebenfalls ohne diese Warnung enden.

Keine neue universelle Outbox und keine genau-einmalige externe Zustellung. Ein Prozessabbruch nach dem Commit kann die Übergabe verlieren. Bereits gespeicherte Webhookzustellungen behalten ihr eigenes Retryverfahren; noch nicht gespeicherte Empfänger lassen sich dadurch nicht automatisch rekonstruieren.

## Grenzen bei Bestellungen und Wiederholungen

Die neue Transaktion umfasst **nicht** die gesamte Bestellung. Mitgliedsanlage und spätere Bestellpunkte sind getrennte lokale Vorgänge. Ein späterer Punktefehler kann deshalb weiterhin mit bereits angelegtem Mitglied enden. Die vorhandenen Bestellbelege schützen ihre eigene Punktebuchung nach Referenz; sie sind kein umfassender Auftrag mit Parameter-Fingerabdruck, atomarer Mitgliedsanlage und gesicherter externer Folgearbeit.

Die E-Mail-Suche ist kein Idempotency-Key. Blanko-E-Mail-Anlagen bleiben nicht idempotent; bei unklarer Antwort nicht blind wiederholen. Keine automatische Reparatur historischer Dubletten oder unvollständiger Kartensätze. Keine neue Oberfläche, kein NFC und keine Hostingänderung.

## Prüfung und Update

```bash
python3 tests/run-api-member-upsert.py
```

Die Suite prüft 21 Domänenfälle und 25 Integrationsfälle: unveränderte gespeicherte Profile samt Kennwort-/Einwilligungszeile, Fallbacks, Mandantentrennung, alte Dubletten, leere E-Mail, aktive Programme, Gutschein-/Pass-Anfangswerte, fehlgeschlagene und ignorierte Inserts, vollständigen Rollback, Fehler nach Commit und unabhängigen erfolgreichen Schreibabschluss während der Ereignisübergabe. Echte Startbarrieren erzeugen zwölf gleichzeitige gleichnamige E-Mail-Aufrufe, sechs leere E-Mails und konkurrierende Unternehmen. Ein anderer Schreiber deaktiviert ein Programm, während die Anlage auf den Lock wartet. Ein Mehrworker-PHP-Server bearbeitet 16 gleichzeitige REST-Anfragen sowie 16 gemischte REST-/signierte Shopify-Anfragen. Kanalpläne und zwei Bewerber um genau einen verbleibenden Mitgliederplatz werden ebenfalls geprüft. Providerarbeit bleibt simuliert oder scheitert gezielt vor dem Transport; keine echten Anbieteranfragen.

Es gibt keine Schemaänderung. Ein vollständiges Codeupdate reicht; laufende Worker müssen dieselbe neue Fassung verwenden. Die unveränderte WordPress-Integration und echte Provider-/Geräteabnahme sind nicht Teil dieser Etappe.

Nach der letzten PHP-Codeänderung wurden alle 55 weiteren Nicht-WordPress-Suiten frisch erfolgreich ausgeführt. PHP-Lint, 158 lokale Links in den betroffenen Dokumenten und `git diff --check` sind grün. Die gezielten Fälle wurden anschließend nochmals ausgeführt. Kein Lasttest und keine Aussage über verlorene Produktivdaten.
