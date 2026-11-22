# Wallet-Karte bei einem Geschäft in der Nähe

Stand: 22.11.2026. **Vorbereitet, nicht auf echten Geräten abgenommen.** Der Code kann Geschäftskoordinaten in die vorhandenen Apple-/Google-Passdaten aufnehmen. Er behauptet nicht, dass ohne gültigen installierten Pass eine Sperrbildschirm-Anzeige funktioniert.

## Einrichten

Organisation → Standort → **Wallet-Nähe**. Nur der Eigentümer des Unternehmens kann Koordinaten und Auswahl ändern. Vorhandene Standorte starten ausgeschaltet, ohne Koordinaten. Die bisherige Textadresse ist keine Geoposition und wird nicht automatisch geokodiert. Geprüften Breiten-/Längengrad des tatsächlichen Geschäfts mit Dezimalpunkt eingeben und Aufnahme ausdrücklich bestätigen. Keine Privatadressen oder geschätzten Beispielwerte verwenden. Koordinaten werden mit dem Pass geteilt, aber erst nach separater Kundenbestätigung. Zusätzlich Organisation → Unternehmen → **Wallet-Nähe: Datenschutzhinweise** pflegen. Mitglieder entscheiden im angemeldeten Wallet unter **Wallet-Nähe & Datenschutz**. Bestehende und neue Mitglieder starten ausgeschaltet; Marketingzustimmung ersetzt diese Wahl nicht. [Datenschutz, manuelle Angaben, Widerruf und Export](WALLET-NEARBY-PRIVACY.md).

Maximal zehn ausgewählte Standorte je Unternehmen, einschließlich aktuell deaktivierter Standorte. Diese Zählung verhindert, dass Reaktivierung die Grenze überschreitet. Inaktive Standorte fehlen in neuen Passdaten. Auswahl und Koordinaten lassen sich unabhängig von Name/Adresse ändern; beides leer und Auswahl Nein entfernt die gespeicherten Koordinaten. Keine Änderung der Mitarbeiterbindung oder Buchungsorte. Ein Netzwerk teilt Standorte nicht zwischen Unternehmen.

## Was die Plattformen unterstützen

**Apple:** Unsere Karte ist ein `storeCard`. `pass.json` enthält nach passender Kundenbestätigung `locations` mit bis zu zehn numerischen Breiten-/Längengraden. Das ist passive Sperrbildschirm-Relevanz, keine eigene Push-Mitteilung. Apple entscheidet, ob und wann die Karte erscheint. Für diesen Passtyp ist ein Relevanzdatum nicht vorgesehen, daher kein erfundenes `relevantDate` oder `relevantDates`. Kein eigener Radius oder Beacon.

**Google:** Das vorhandene `GenericObject` enthält nach passender Kundenbestätigung `merchantLocations` mit denselben Punkten, nicht das veraltete `locations`-Feld. Keine Unternehmensstandorte auf der gemeinsam verwendeten Klasse, damit keine anderen Mandanten deren Standorte erhalten. Google beschreibt Nähe-Benachrichtigungen nur bei erlaubten Benachrichtigungen und präzisem dauerhaften Standortzugriff für Google Wallet. Radius, Verweildauer und Mitteilungstext bestimmt Google.

Unsere Webanwendung liest dafür keinen Kundenstandort, richtet keinen Hintergrund-Geofence ein und protokolliert keine Kundenbewegungen. Die Wallet-App und das Betriebssystem führen ihre eigenen Standort-/Benachrichtigungsfunktionen aus. App-Inbox, E-Mail, Marketing-Push und NFC sind andere Funktionen.

## Ohne Schlüssel und mit vorhandenen Pässen

Die Auswahl, Validierung, Mandantentrennung und JSON-Erzeugung sind ohne externe Konten testbar. Für installierbare Apple-Pässe braucht es die vorhandene Signierkonfiguration (Zertifikat/Schlüssel/WWDR, passende IDs), für Google den Issuer und Service-Account. Zugangsdaten vorhanden heißt noch nicht, dass ein gültiger Pass installiert oder ein Gerät geprüft wurde. Apple verlangt für Relevanztests ein echtes Gerät, nicht den Simulator.

Speichern löst bewusst keine Massenaktualisierung, Nachricht, APNs-Anfrage oder Google-API-Anfrage aus. Die neuen Daten werden bei erneuter Passerzeugung und über die bestehenden Pass-Updatepfade verwendet. Bereits installierte oder heruntergeladene Pässe können alte Punkte behalten, auch nach Abwahl oder Standortdeaktivierung, bis die Kopie tatsächlich aktualisiert/ersetzt ist. Ein Standortspeichern allein ist kein bestätigter Geräteabgleich. Für eine kontrollierte Produktionsfreigabe: Schlüssel einrichten, echten Testpass neu installieren/aktualisieren, Geräteeinstellungen prüfen, Nähe und Entfernen auf iPhone/Android testen.

## Schutz und Grenzen

Eigentümer/CSRF/Planfeature `locations`, private/no-store ohne Referrer. Koordinaten als einzelne endliche Dezimalwerte: Breitengrad -90 bis 90, Längengrad -180 bis 180, maximal sieben Nachkommastellen. Keine Arrays, Exponenten oder Dezimalkommas. Nur ausdrückliche Auswahl mit geprüften Koordinaten und Bestätigung. Inaktive Standorte lassen sich nicht neu aktivieren, ohne erst separat den Standortstatus zu ändern.

SQLite-Schreiblock prüft Eigentümer, Plan, aktuellen Prüfstand und Zehnergrenze erneut. Koordinaten/Auswahl und ID-Audit `location.wallet_nearby` sind atomar. Kein Koordinaten-/Adress-/Kundeninhalt im Audit. Alte Tabs können Koordinaten-/Auswahl-/Statusänderungen nicht überschreiben; Name-/Adressbearbeitung ist unabhängig. Kein fortlaufender Versionszähler. Bei manuell beschädigten Daten werden ungültige Punkte ausgelassen; über zehn aktive ausgewählte Zeilen ergeben leere Standortlisten statt willkürlicher Kürzung.

## Tests

`python3 tests/run-wallet-nearby.py`: 51 HTTP-/Domänen- und 12 Chromium-Prüfungen. Standard aus, strikte Koordinaten/Arrays/Bestätigung, exakte Apple-/Google-JSON-Felder, kein Datum für storeCard, keine Google-Klassenstandorte, Unternehmenstrennung, De-/Reaktivierung, Entfernen, alte Tabs, Plan/CSRF, Audit-Rollback, Zehnergrenze mit pausierten Standorten und zwei gleichzeitige Auswahlen. Keine fachlichen Änderungen an Mitgliedsnamen, Marketingwahl, Salden, Journal, Mails oder Geräteregistrierung. Die Standort-Payloadtests setzen eine ausdrückliche Kundenfreigabe ausschließlich in ihrer isolierten Testdatenbank; die eigene Datenschutzsuite prüft die echten Bestätigungs-/Widerrufspfade. Keine externen Requests oder echten Providerannahme-/Signier-/Gerätetests. Desktop/Mobil visuell geprüft, Screenshots unter `/tmp/loyalty-wallet-nearby/`.

## Herstellerquellen

Am 22.11.2026 gelesen:
- Apple, Relevanz/pass style, maximal zehn Standorte, passiv statt Alarm, Gerätetest: https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Creating.html
- Apple, Location-Schema: https://developer.apple.com/documentation/walletpasses/pass/locations-data.dictionary
- Google, Nearby Notifications und Voraussetzungen: https://developers.google.com/wallet/generic/use-cases/trigger-push-notifications
- Google, aktuelles GenericObject-Feld und Obergrenze: https://developers.google.com/wallet/reference/rest/v1/genericobject
- Google, MerchantLocation-Schema: https://developers.google.com/wallet/reference/rest/v1/MerchantLocation

Historische Abnahme der Standort-Etappe vor Ergänzung der Kundenwahl: alle 42 weiteren Nicht-WordPress-`run-*.py`-Suiten grün, einschließlich Wallet-Bild-/Browserprüfung, Standorte, Deaktivierung und Buchungspfade. WordPress-Echtumgebung nicht erneut geprüft.
