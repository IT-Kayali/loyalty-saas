# PWA und gespeicherte Karten

## Umfang

Die Plattform hat eine installierbare Web-App-Basis mit Manifest, 192-/512-Pixel-Icons, Service Worker und eigenständiger Offlineansicht. Mitglieder können im angemeldeten **Wallet** ausdrücklich eine lokale Ansicht ihrer Karten speichern. Die Offlineansicht bietet Suche, Typfilter, letzte Salden, Kundencodes und vergrößerbare QR-Codes. Keine Buchung, Einlösung oder Passnutzung wird offline bestätigt oder zur späteren Ausführung eingereiht.

Unterstützt sind bis zu 30 aktive Karten aus der bestehenden Karten-Tabelle: Stempel, Punkte, einfache Gutscheine und einfache Mehrfachkarten. Der QR identifiziert die Kundenkarte des Unternehmens, nicht eine einzelne Prämie oder einen Zahlungsanspruch. Separat verkaufte Passinstanzen aus dem Prepaid-Ledger gehören noch nicht zum Offline-Snapshot. Geldguthaben werden nicht implementiert oder offline autorisiert.

## Benutzung

1. Über HTTPS öffnen und als Mitglied anmelden. Für lokale Entwicklung funktioniert auch Loopback, etwa `http://127.0.0.1:8099`.
2. Im Wallet den Hinweis lesen und bestätigen, dass dies ein privates Gerät ist.
3. **Karten auf diesem Gerät speichern** drücken und die Bestätigung abwarten. Das Speichern benötigt eine aktive Anmeldung und funktionierende Verbindung.
4. **Gespeicherte Karten öffnen / Installationshilfe** führt zu `/offline.html`. Dort offline suchen und einen QR groß anzeigen.
5. Für aktuelle Stände und jede Kassenaktion wieder verbinden. Der gespeicherte Stand kann bereits unmittelbar nach dem Speichern überholt sein.
6. Zum Aktualisieren online erneut speichern. Die lokale Ansicht läuft nach 24 Stunden laut Geräteuhr ab. Es gibt keine automatische Hintergrundaktualisierung.

Installation: Android/Desktop über die Installationsoption des Browsers, iPhone/iPad über Safari → Teilen → Zum Home-Bildschirm. Browser können diese Option unterschiedlich anbieten oder ganz weglassen. Die Metadaten sind vorhanden, aber ein echter iOS-/Android-Installationslauf ist noch nicht abgenommen.

## Datenschutz und lokale Daten

Es wird nichts Persönliches automatisch offline gespeichert. Der Service Worker hält ausschließlich eine feste Liste öffentlicher Dateien: Offline-HTML, eigenständiges CSS/JavaScript, QR-Bibliothek, Icons und Manifest. Keine angemeldeten Seiten, APIs, Exporte, Abrechnung oder Karten-HTML gelangen in diesen Cache. Andere Navigationen benutzen das Netzwerk und erhalten bei fehlender Verbindung nur die generische Offline-Hülle. Staff-/Betreiberpfade zeigen dabei einen Wiederverbindungshinweis, keine gespeicherten Mitgliederkarten oder Kassenaktionen.

Nach Opt-in liegen die ausgewählten Kartendaten in `localStorage` unter `loyalty-offline-v1`: Unternehmens-/Programmnamen, Typ, Kundencode, letzte Salden, Speicher-/Ablaufzeit und ein Kontofingerabdruck. Namen der Mitglieder, E-Mail, Passwörter, API-Schlüssel und Inbox-Nachrichten werden nicht aufgenommen. **Kundencodes und Salden sind trotzdem personenbezogene Daten.** Der Code kann dieselbe öffentliche Kartenansicht öffnen wie ein online gezeigter QR. Kein Verschlüsselungs- oder Zugriffsschutz des lokalen Snapshots: Wer das Gerät oder Skriptzugriff auf denselben Ursprung hat, kann ihn lesen.

- **Lokale Kopie löschen** beziehungsweise **Alle lokalen Karten löschen** entfernt den Snapshot auch offline.
- Abmelden über die App, bestätigte Kontolöschung und ein auf einer Online-Seite erkannter Kontowechsel/abgelaufener Mitgliederzugang entfernen die Kopie. Für offene Offlineansichten werden lokale Änderungen zwischen Tabs berücksichtigt.
- Ein Offline-Abmeldeversuch löscht die lokale Kopie, beendet aber noch keine Serversession. Der Bildschirm fordert dazu auf, online erneut abzumelden. Es gibt keine heimliche spätere Abmelde- oder Schreibqueue.
- Ein Server kann ein getrenntes Offlinegerät nicht fernlöschen. Logout auf einem anderen Gerät, zwischenzeitliche Serverlöschung, Geräteverlust oder alte Browserbackups sind keine garantierte Fernlöschung. Das Gerät muss wieder online gehen oder lokal gelöscht werden.
- Ablauf wird beim Öffnen/Filtern und durch einen Timer geprüft. Gesperrte oder schlafende Browser können Timer verzögern; Geräteuhr und Browserdaten können manipuliert werden. Das ist kein Schutz gegen einen Angreifer und niemals ein Guthabennachweis.
- Browser können Speicher aus Platzgründen löschen oder ganz verweigern. Eine neue Browserinstallation beziehungsweise ein anderes Gerät hat keine gespeicherten Karten. Nicht als einziges Zugangsmittel einplanen.
- Bereits angezeigte Daten/Screenshots lassen sich nicht zurückholen. Nur auf privaten Geräten verwenden.

Es werden weder POSTs gecacht noch Transaktionen in eine Offline-Queue geschrieben. Der Server bleibt für Kartenzustand, Rechte und Guthaben maßgeblich. Der Offlinebildschirm sagt dies ausdrücklich.

## Betrieb und Entwicklung

Dateien: `public/sw.js`, `public/manifest.webmanifest`, `public/offline.html`, `public/assets/pwa.js`, `offline.css`, `pwa-192.png`, `pwa-512.png`, serverseitig `app/inc/pwa.php`.

`GET /member/offline-snapshot` verlangt eine gültige Mitglieder-Session und antwortet mit `Cache-Control: private, no-store`. Es ist kein API-Key-Endpunkt und akzeptiert keine fremde E-Mail oder Member-ID. Kein anderer Mandant oder fremdes Mitglied kann per Requestparameter ausgewählt werden.

Die Anwendung erwartet wie bisher das Wurzelverzeichnis einer Domain. Service-Worker-Scope und Assetpfade sind `/`; eine Unterverzeichnisinstallation ist nicht abgenommen. Der Webserver muss JavaScript/Manifest/PNG korrekt ausliefern und HTTPS ohne Mixed Content bieten. Beim Aktualisieren der Offline-Dateien die Versionskennung `loyalty-public-shell-v1` in `sw.js` ändern. Alte eigene Shellcaches werden beim Aktivieren entfernt. Ein separates `localStorage`-Schema braucht eine eigene Migration oder Löschung. Service Worker können nach einem Deployment noch eine ältere Version halten; online neu laden, bei Tests nötigenfalls unter Browser-Anwendungsspeicher den Worker/Cache entfernen. Das Löschen aller Websitedaten entfernt auch Anmeldung und Kartensnapshot.

Der Service Worker gilt für die gesamte Plattform-Domain. Auf derselben Origin keine fremden Anwendungen betreiben. Keine geheimen Daten in die öffentliche Cache-Allowlist aufnehmen. Keine generische `cache.put()`-Behandlung für Sessionseiten oder APIs ergänzen.

## Verifikation und offene Parität

`PHP_BIN=/pfad/php TEST_OUTPUT=/tmp/pwa-images python tests/run-pwa.py` startet eine wegwerfbare PHP-Instanz und echten Chromium mit echter Offlineumschaltung. Geprüft: Manifest/Icons, Opt-in, datenarmes Snapshotformat, öffentlicher Cache ohne Session-/API-Seiten, Offline-Wallet, sichtbare Stale-Warnung, tatsächlich dekodierter QR, Suche, vergrößerte QR-Ansicht, mobile Breite, abgewiesener Offline-POST, Staff-Wiederverbindung, Ablauf, manuelles Löschen, Kontowechsel und Logout einschließlich anonymem Endpointzugriff.

Noch offen: echte Smartphone-/Installations-/Storage-Eviction-Tests, konfigurierbares Appbranding/Icons, Web-Push, automatische Kartenerneuerung, mehrsprachiger Offlinebildschirm und separat verkaufte Passinstanzen. Die Referenz speichert automatisch und kann weitere Seitentypen halten. Diese erste Stufe speichert bewusst nur nach ausdrücklichem Opt-in und keine angemeldeten HTML-Seiten. Keine vollständige PWA-Referenzparität behaupten.

## Geprüfte Grundlagen

- https://rewardloyalty.co/docs/5.x/platform-settings/pwa-configuration
- https://rewardloyalty.co/docs/5.x/members/install-offline
- https://developer.mozilla.org/en-US/docs/Web/API/Service_Worker_API/Using_Service_Workers
- https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable
- https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Caching

## Reproduzierbarer Kaltstart im Browser-Test

Der lokale Chromium-Test wartet beim ersten Start auf eine aktive Root-Registrierung und lädt die Wallet einmal online neu. Erst danach prüft er den Controller des erwarteten `/sw.js` und geht offline. Grund: `serviceWorker.ready` bestätigt eine aktive Registrierung, aber die parallel zum Login ausgetauschte Seite muss noch keinen Controller erhalten haben. Das frühere unmittelbare Warten auf dieser Seite konnte zeitlich auslaufen. Der Test erhöht keine Wartezeit und wiederholt fehlgeschlagene Assertions nicht automatisch.

Keine Änderung an Registrierung, Cache, Snapshot oder produktivem Offlineverhalten. Alle bisherigen Datenschutz-, QR-, Ablauf-, Logout- und Offline-Schreibprüfungen bleiben bestehen; zusätzlich aktive Root-Registrierung und exakter Controller geprüft. Dies ist eine lokale Teststabilisierung, kein Nachweis für alle Browser-/Geräte-Lebenszyklen. Ein erster Offlineaufruf vor abgeschlossener Installation ist weiterhin nicht garantiert.
