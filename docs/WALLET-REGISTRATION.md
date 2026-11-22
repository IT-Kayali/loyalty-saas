# Apple-Registrierungen: Schutz vor verspäteten APNs-Fehlern

Stand: 22.11.2026. Lokal implementiert und geprüft. Kein Nachweis echter APNs- oder Gerätezustellung. Keine neue Versandfreigabe und keine NFC-Funktion.

## Welcher Fehler verhindert wird

Eine APNs-Anfrage kann noch laufen, während Wallet denselben Pass erneut registriert. Auch ein identisches Push-Token ist dann eine neue Registrierung. Eine später eintreffende Fehlerantwort zur alten Anfrage darf diese neue Registrierung nicht entfernen.

Der gemeinsame Transport für Kartenstand und optionalen Einzelversand schützt deshalb die Bereinigung mit einem vor dem Versand festgehaltenen Prüfstand. Token-, Seriennummer- und Pass-Type-Abgrenzung bleiben zusätzlich bestehen.

## Technische Umsetzung

`pass_devices` erhält zwei interne Spalten:

- `registration_generation`: neuer monotoner Wert bei jeder erfolgreichen authentifizierten Registrierung, auch bei unverändertem Token. Er stammt aus der vorhandenen privaten `apple_pass_clock`.
- `registered_at_ms`: Zeitpunkt dieser Registrierung als Millisekunden seit Unix-Epoch. Er wird nur für die zusätzliche zeitliche Einordnung von HTTP 410 gebraucht.

Registrierung, Zählerfortschritt und Metadaten werden in derselben kurzen Schreibtransaktion gespeichert. Bei Fehlern wird alles zurückgerollt. Wiederholungen behalten Zeilenkennung, ursprüngliches `created_at` und einen bereits positiven `registration_revision`-Catch-up-Wert. HTTP 200/201 und das Listen-/Cursorverhalten bleiben erhalten. Der globale Zähler kann bei Wiederholungen Lücken zwischen Inhaltsrevisionen bekommen. Er wird weiterhin nicht als Klartext an Geräte oder Betreiber ausgegeben.

Vor dem ersten Transportversuch werden Generation und Zähler der Uhr gelesen. Aufräumen erfolgt mit **einem atomaren SQL-Prädikat**: gleiche Serie, gleicher konfigurierter Typ, gleiches Token, Registrierungsstand höchstens beim Versandprüfstand und unveränderte Uhrgeneration. Eine Registrierung während der Anfrage erhält einen höheren Wert und bleibt erhalten. Das gilt auch bei Tokenwechsel mit späterem Rückwechsel, Löschen und Neuanlegen oder einem zusätzlich registrierten Gerät. Bereits vorher vorhandene ungültige Doppelregistrierungen desselben Tokens dürfen entfernt werden.

Über Netzwerkaufrufe hinweg wird keine Schreibtransaktion gehalten. Es braucht keinen unbeschränkten Schnappschuss sämtlicher Registrierungszeilen. Fehlt der Uhrprüfstand, wird ohne Provideraufruf ein unklares Ergebnis zurückgegeben. Ein Wechsel der Uhrgeneration schützt auch gegen Antworten aus einem älteren Wiederherstellungsstand. Das ersetzt keinen abgestimmten Offline-Restore.

## 400 und 410 unterscheiden

HTTP 400 mit `BadDeviceToken` oder `DeviceTokenNotForTopic` darf nur die beim Versand bereits vorhandenen passenden Registrierungen entfernen. Alle währenddessen erneuerten Registrierungen bleiben geschützt.

HTTP 410 mit `Unregistered` oder `ExpiredToken` benötigt zusätzlich Apples `timestamp`: den Zeitpunkt, zu dem APNs das Token als nicht mehr gültig bestätigt hat. Das ist **nicht** automatisch der Zeitpunkt der aktuellen Anfrage. Entfernt wird nur, wenn:

- ein positiver ganzzahliger Millisekundenwert vorliegt, der nicht in der lokalen Zukunft liegt,
- eine bekannte positive letzte Registrierungszeit vorliegt,
- diese Registrierung zeitlich **streng vor** dem APNs-Ungültigkeitszeitpunkt liegt.

Gleiche Millisekunde, neuere Registrierung, fehlende oder falsch typisierte Zeitwerte bleiben ohne Löschung. Zahlen als Zeichenkette und Gleitkommawerte gelten nicht als verlässlicher Millisekundenwert. Ein fälschlich in Unix-Sekunden übermittelter heutiger Zeitwert liegt weit vor heutigen in Millisekunden erfassten Registrierungen und löscht diese nicht. Der Vergleich setzt korrekt synchronisierte Systemzeit voraus. Der monotone Schutz einer Registrierung während der Anfrage hängt dagegen nicht von der Wanduhr ab.

HTTP-Ergebnisklassen ändern sich dadurch nicht: 410 bleibt abgewiesen, auch wenn aus Vorsicht keine Zeile gelöscht wird. Zertifikatsfehler, unbekannte Fehlergründe oder fehlerhafte Antwortformen löschen keine Registrierung. Keine automatische Wiederholung, kein neuer Hintergrundjob und keine Rohantwort-/Tokenprotokollierung.

## Update, Altbestand und Datenschutz

Die additive Schemaerweiterung läuft beim Datenbankstart unter einer Schreibsperre. Bestehende Registrierungen bleiben unverändert erhalten; neue Metadaten beginnen mit `0`. Es gibt keine erfundene letzte Registrierungszeit und keine Massen-Neuregistrierung. Bei Legacy-Zeilen ohne bekannte letzte Registrierungszeit wird eine 410-Bereinigung vorsichtig ausgelassen. Erst eine reguläre neue authentifizierte Registrierung liefert diese Zeit. Eine aktuelle passende 400-Antwort kann eine unverändert alte Registrierung weiterhin entfernen.

Vor dem Update ein geschütztes vollständiges Backup erstellen. Alte HTTP-/CLI-Worker und laufende APNs-Anfragen beenden bzw. auslaufen lassen, danach alle Prozesse mit derselben neuen Fassung starten. **Kein Mischbetrieb alter und neuer Registrierungs- oder Bereinigungspfade.** Alte Software würde den neuen Prüfstand nicht fortschreiben und kennt die Schutzbedingung nicht. Auch eine Rückkehr zur alten Fassung entfernt diesen Schutz trotz vorhandener Zusatzspalten. Restore weiterhin ausschließlich nach dem vorhandenen Wartungs-/Sperrverfahren durchführen.

Die beiden Werte sind technische Metadaten vorhandener Geräteregistrierungen. Keine neue Kundenliste, kein zusätzlicher Provider und kein neuer Mitteilungszweck. Sie stehen nicht in Passpayload, Nachrichteninhalt oder Ergebnisprotokoll. Die bestehende Abmeldung und Kontolöschung entfernen die jeweilige Registrierungszeile samt Metadaten. Vollständige Datenbankbackups enthalten sie und müssen entsprechend geschützt bleiben. Die separate freiwillige Mitteilungswahl und alle Versandgrenzen aus [WALLET-PUSH.md](WALLET-PUSH.md) gelten unverändert.

## Lokale Prüfung

```bash
python3 tests/run-wallet-registration.py
python3 tests/run-wallet-apns.py
python3 tests/run-pass-service.py
```

Die neue Suite prüft 78 Domänenfälle und ein Upgrade der vorherigen Schemafassung mit sechs über eine Startbarriere gleichzeitig freigegebenen PHP-Prozessen. Enthalten sind vier bereinigende APNs-Fehlerklassen, identische/geänderte/zurückgeänderte Token, Neuanlage und zusätzliche Geräte, erhaltene Catch-up-Metadaten, echte parallele PHP-Registrierungen während des Ersatztransports, gleichgesetzte Wanduhrzeiten, strenge 410-Zeitprüfung, Altbestand, fremde Serie/Typ, Generationenwechsel, atomare Fehler und fehlender Prüfstand. Sie verwendet ausschließlich Ersatzantworten, keine Anfrage an Apple.

Die bestehenden APNs- und Passdienst-Suiten prüfen außerdem Aktivierung, Optionen, Klassen, Fehlerisolierung, echte lokale HTTP-Routen, 200/201/204/304, Cursor und unterstütztes Backup/Restore. Nach der letzten Codeänderung wurden alle 53 weiteren Nicht-WordPress-Suiten frisch erfolgreich ausgeführt; PHP-Lint, lokale Dokumentlinks und `git diff --check` sind ebenfalls grün. WordPress wurde nicht erneut ausgeführt, da das Plugin unverändert ist. Selbstsignierte lokale Testpässe sind kein Apple-vertrauenswürdiger Pass. Eine Abnahme mit echten Zertifikaten, Provider und physischem Gerät bleibt offen. Keine neue Oberfläche geändert.

## Quellen und Zusammenhang

- Apples Bedeutung von 410, `timestamp` in Millisekunden und Fehlergründe: https://developer.apple.com/documentation/usernotifications/handling-notification-responses-from-apns.md
- Passregistrierung und Webservice: https://developer.apple.com/documentation/walletpasses/adding-a-web-service-to-update-passes
- Gemeinsamer Transport und Aktivierung: [WALLET-APNS.md](WALLET-APNS.md)
- Catch-up, Cursor und Restore: [WALLET-UPDATES.md](WALLET-UPDATES.md)
- Stabile Wallet-Zugangsdaten: [WALLET-AUTH.md](WALLET-AUTH.md)
