# Apple PassKit: Update-Prüfung und Abrufcache

Stand: 22.11.2026. Lokale Protokollimplementierung, keine Apple-/Geräteabnahme. Diese Etappe verändert weder die Kundenwahl noch die Buchung von Guthaben und löst selbst keinen externen Push aus. NFC bleibt ausgeschlossen.

## Geänderte Serien statt vollständiger Listen

Der PassKit-Web-Service unter `/api/pass/v1/` führt einen dauerhaften Inhaltsprüfstand je Mitgliedspass. Das Prüfergebnis umfasst die erzeugten JSON-Felder, die für Icon/Stempelbild benötigten Gestaltungsdaten und hochgeladenen Bildbytes sowie den konkreten Signierkonfigurationsstand. Auch veröffentlichte Mitteilung, Ablauf, Widerruf und geänderte Betreiberhinweise fließen dadurch beim nächsten Abruf ein.

- Erste Geräteabfrage ohne Cursor: alle für dieses Gerät und die konfigurierte Pass Type ID registrierten, noch vorhandenen Pässe.
- Abfrage mit gültigem `passesUpdatedSince`: nur seit diesem Prüfstand veränderte Pässe oder neu hinzugekommene Registrierungen.
- Keine Treffer: HTTP 204 ohne JSON- oder Passinhalt.
- Falsche Pass Type ID: HTTP 404. Ein unbekanntes Gerät erhält keine Serien anderer Geräte.
- Alter ISO-Datumswert, beschädigter/fremder Cursor oder nicht mehr gültiger Wiederherstellungsstand: vollständige Neusynchronisierung ausschließlich der eigenen Registrierungen. Ein Array statt eines Cursorstrings wird mit HTTP 400 abgewiesen.

Ein SQLite-Zähler vergibt unter Schreibsperre streng steigende interne Revisionen, auch bei mehreren Änderungen in einer Sekunde. Die neue Metadatenmigration wird ebenfalls unter Schreibsperre angelegt und wurde mit vier gleichzeitigen Starts gegen ein Schema ohne diese Erweiterung geprüft. Inhaltsprüfung und Metadaten werden zusammen bestätigt oder zurückgerollt. Ein unveränderter Inhalt erhöht die Revision nicht. Das ist kein Versand- oder Empfangsbeleg.

Der ausgegebene Cursor enthält keinen lesbaren globalen Zähler. Er ist mit AES-256-GCM verschlüsselt und authentifiziert, an Gerätekennung und Pass Type ID gebunden. Auch der ETag legt weder Zähler noch Synchronisationsschlüssel offen. Damit werden nicht über den eigenen Pass hinaus globale Mandantenaktivitäten aus fortlaufenden Zahlen ablesbar. Die private Schlüsselerzeugung übernimmt die Anwendung lokal; der Schlüssel bleibt in der privaten SQLite-Datenbank und darf nicht veröffentlicht werden.

Eine neue Registrierung bekommt einen eigenen Catch-up-Prüfstand. Sonst könnte ein bereits älterer, neu auf diesem Gerät registrierter Pass hinter dessen Cursor anderer Pässe liegen und übersehen werden. Wiederholte Registrierung aktualisiert nur das Push-Token, erhält Zeilenkennung, Erstellungszeit und bestehenden Catch-up-Prüfstand und gibt HTTP 200 statt 201 zurück. Die Entscheidung geschieht atomar. Leere, nicht skalare, nicht druckbare oder mehr als 512 Zeichen lange Tokens werden abgewiesen; die Länge ist eine lokale Eingabegrenze, keine zugesicherte Apple-Tokenlänge.

## Stabile Validatoren für Passabrufe

Der direkte `.pkpass`-Download und der authentifizierte aktualisierte Pass verwenden denselben semantischen Prüfstand:

- schwacher stabiler `ETag`, `Last-Modified`, `Cache-Control: private, no-cache`, `Vary: Authorization`;
- `If-None-Match` hat Vorrang vor `If-Modified-Since`;
- gleicher ETag: HTTP 304 ohne Paket; unverändert korrekter Pass-Medientyp, kein versehentliches JSON;
- veralteter ETag: neues Paket, auch wenn zusätzlich ein scheinbar passendes Datum übermittelt wurde;
- fehlende/falsche ApplePass-Authentifizierung wird vor einem 304 abgewiesen; falsche Pass Type ID bleibt gesperrt;
- fehlt die konfigurierte Signierdatei, bleibt der aktualisierte Pass bei HTTP 501 statt einen scheinbar erfolgreichen Cachetreffer zu melden.

HTTP-Datumswerte haben nur Sekundenauflösung. Bei erstem Prüfstand, mehreren Änderungen in derselben Sekunde oder rückwärts springender Serveruhr reicht ein Datum deshalb nicht als Beweis für unveränderten Inhalt. Dann liefert der Server vorsichtshalber ein Paket; ein passender ETag kann weiterhin 304 ergeben. Ein gespeicherter Zeit-Höchststand verhindert, dass eine spätere Änderung nach einem Uhr-Rücksprung versehentlich als unverändert gilt. Ungültige, relative und zukünftige Datumswerte werden ignoriert; `Last-Modified` wird nie absichtlich in die Zukunft gesetzt. Es beschreibt die erste Beobachtung des aktuellen Inhalts, nicht den Zeitpunkt einer Buchung.

Die Datenbank wird beim Passabruf vor der Prüfung frisch gelesen. Der unter dieser Prüfung erzeugte JSON-Inhalt wird für genau dieses Paket verwendet, nicht nachträglich erneut ausgewählt. Dadurch kann ein zwischenzeitlich ablaufender Nachrichtentext nicht einen anderen JSON-Inhalt als den gerade validierten erhalten. Datei-/Zertifikatsänderungen außerhalb der Anwendung während eines laufenden Abrufs sind damit nicht atomar gesperrt; Schlüsselrotation im Betrieb gesondert abnehmen.

Wallet-Texte folgen jetzt deterministisch `default_lang` der Installation, nicht der Sprache einer zufälligen Websitzung. Das ändert keine gespeicherte Sprachwahl des Besuchers. Namen und selbst verfasste Programm-/Nachrichtentexte werden nicht automatisch übersetzt. Bestehende anderssprachig erzeugte Pässe können beim nächsten Abruf auf die konfigurierte Sprache wechseln.

## Speicherung und Wiederherstellung

`apple_pass_versions` enthält Mitgliedsbezug, Inhalts-Hash, Revision und technische Zeitprüfstände, keine Nachrichtenkopie, Namen, E-Mail-Adressen oder Anbieterzugänge. Die Zeile wird bei Mitgliedslöschung per Fremdschlüssel entfernt. `apple_pass_clock` hält den installationsweiten Zähler und den privaten Cursor-Schlüssel; die einzelne Systemzeile wird bei einer Mitgliedslöschung nicht zurückgesetzt. Wie die vorhandenen Geräteregistrierungen sind dies technische Wallet-Metadaten, keine neue schreibbare Betreiberfunktion und keine Umgehung einer Planbuchung.

Der unterstützte Restore mit `tools/backup.php restore ... --yes` wechselt den Cursor-Schlüssel und leert nur den lokalen Pass-Prüfcache samt Registrierungs-Catch-up-Werten. Mitgliedsdaten, Salden, echte Registrierungen und Push-Tokens werden dadurch nicht gelöscht. Alte Cursor und ETags passen danach nicht mehr, sodass die nächste Geräteabfrage neu synchronisiert. Ältere Backups ohne diese Tabellen erhalten den neuen Zustand bei der additiven Schemaanlage. Das automatische Rollback-Bündel des Restore bleibt erhalten.

Eine außerhalb des unterstützten Werkzeugs kopierte ältere Datenbank kann nicht zuverlässig als Restore erkannt werden. In diesem Fall darf nicht behauptet werden, dass alte Gerätecursor sicher entwertet wurden. Backups enthalten weiterhin Geheimnisse und müssen privat geschützt werden. Die Wiederherstellung ersetzt keine Prüfung von Signierkonfiguration, Authentifizierungstokens und vorhandenen Passkopien.

## Grenzen

- Änderungen werden bei Registrierungsliste oder Passabruf erkannt. Kein neuer Scheduler, kein automatisches Signal bei jeder Design-/Hinweisänderung oder beim Textablauf.
- Ein Gerät kann alte Inhalte bis zu seinem nächsten erfolgreichen Abruf behalten. Keine Fernlöschung und keine garantierte Sperrbildschirmanzeige.
- Die jüngste noch gültige Nachricht derselben aktuellen Kundenwahl wird ausgewählt. Läuft eine neuere kurze Nachricht ab, kann eine ältere noch gültige Nachricht wieder der aktuelle Passinhalt sein.
- Kartenstand und optionaler Einzelversand nutzen inzwischen denselben Produktions-/Passzertifikatstransport mit ausdrücklicher Aktivierung: [WALLET-APNS.md](WALLET-APNS.md). Keine echte Provider-/Geräteabnahme. Passauthentifizierung und Google-Stempelbild-Zugang bleiben jetzt bei unterstützter API-Schlüsselrotation erhalten, einschließlich aktueller Legacy-Werte: [WALLET-AUTH.md](WALLET-AUTH.md). Früher bereits verlorene Token werden dadurch nicht rekonstruiert.
- Bei Änderung des PNG-Renderverfahrens die interne Inhaltsformatkennung bewusst erhöhen. Keine allgemeine Dateisystem- oder Lastprüfung.
- Keine neuen Konten, Zertifikate, Pushzustellungen, Hosting- oder Cronänderungen.

## Lokale Prüfung

```bash
python3 tests/run-pass-service.py
```

37 Domänen- und 33 HTTP-/Restore-/Migrationsprüfungen: monotone und parallele Revisionen, unveränderte Abfragen, Registrierung/Catch-up, Geräte-/Typtrennung, verschlüsselte und manipulierte Cursor, alte/fremde/zukünftige Cursor, Bild-/Zertifikatswechsel, Nachrichtenablauf/Widerruf, stabile Sprache, atomare Fehler, Löschkaskade, Cacheheader und Validatorvorrang, Signier-Setup-Sperre sowie Restore-Neusynchronisierung. Die erzeugten Zertifikate sind ausschließlich lokale selbstsignierte Testdaten. Eine erzeugte ZIP-/PKCS#7-Datei ist kein Apple-vertrauenswürdiger Pass. Keine native Wallet-Darstellung oder Gerätezustellung geprüft. Alle 50 weiteren Nicht-WordPress-Suiten wurden nach der letzten Codeänderung erneut erfolgreich ausgeführt, darunter die bestehenden Wallet-Einzelversand-, Kundenwahl- und Näheprüfungen. PHP-Lint und `git diff --check` waren ebenfalls grün.

## Quellen

Am 22.11.2026 gelesen:

- Aktueller Webservice, Registrierung und Produktions-APNs: https://developer.apple.com/documentation/walletpasses/adding-a-web-service-to-update-passes
- Listenabfrage mit vorherigem Cursor, HTTP 200/204: https://developer.apple.com/documentation/walletpasses/get-the-list-of-updatable-passes
- Aktualisierter Pass und ApplePass-Authentifizierung: https://developer.apple.com/documentation/walletpasses/send-an-updated-pass
- Ausführlicher Apple-Leitfaden mit vergleichbaren opaken Tags und `If-Modified-Since`/304: https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Updating.html
