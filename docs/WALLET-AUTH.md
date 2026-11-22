# Wallet-Zugangsdaten bei API-Schlüsselrotation

Stand: 22.11.2026. Lokal implementiert und geprüft, keine Apple-/Google-/Geräteabnahme.

## Behobene Ursache

Apple verlangt für einen aktualisierten Pass dieselbe Seriennummer und dasselbe Authentifizierungstoken. Bisher wurde das ApplePass-Token aus Mitgliedscode und Business-API-Schlüssel abgeleitet. Eine API-Schlüsselrotation änderte daher ungewollt den Zugang eines bereits installierten Passes. Auch die tokenisierte Google-Stempelbild-Adresse hing an diesem API-Schlüssel.

Beide Wallet-Zugänge sind jetzt vom weiteren Lebenslauf des Business-API-Schlüssels getrennt. Der alte API-Bearer wird bei einer unterstützten Rotation weiterhin sofort ungültig. Bereits installierte Pässe und ihre bisherigen Stempelbild-Adressen bleiben dagegen verwendbar. Die Seriennummer wird nicht geändert.

## Datenmodell und Umstellung

Die private Tabelle `member_wallet_auth` enthält je Mitglied ein `auth_token` für ApplePass und ein anderes `image_token` für Stempelbild-Adressen. Keine neuen Geheimnisfelder in allgemeinen Mitgliedszeilen oder deren JSON-Ausgaben. Ein Fremdschlüssel entfernt die Zeile beim Löschen des Mitglieds; die Zugangsdaten sind gegen versehentliches Überschreiben per UPDATE gesperrt. Das entfernt keine bereits gespeicherten Passkopien vom Gerät.

Beim ersten Öffnen einer bestehenden Datenbank:

1. Die Migration sperrt schreibend und prüft ihren Abschlussstand innerhalb derselben Transaktion erneut.
2. Für aktuelle Mitglieder mit vorhandenem, nicht leerem Business-API-Schlüssel werden genau die bisherigen HMAC-Werte für beide Zwecke festgehalten. Schon vorhandene Wallet-Zugangsdaten werden nicht überschrieben.
3. Erst zusammen mit allen gespeicherten Werten wird die Abschlussmarkierung gesetzt. Ein Fehler rollt die ganze Migration zurück. Eine gescheiterte Initialisierung wird auch nach einem abgefangenen Fehler nicht als fertige Datenbankverbindung weiterverwendet. Gleichzeitige neue Anfragen warten und sehen danach denselben Stand.

Neue Mitglieder nach dieser Migration erhalten beim ersten Wallet-Zugriff zwei unabhängige, kryptografisch zufällige 256-Bit-Werte. Eine gleichzeitig eintreffende Anfrage verwendet nach dem Einfügekonflikt dieselbe tatsächlich gespeicherte Zeile. Es gibt keinen festen Testschlüssel und keine neue Ableitung aus API-Schlüssel, E-Mail oder Mitgliedscode. Der erste Zugriff schreibt diese private Zeile; spätere Zugriffe erzeugen keine neuen Werte.

Das Initialisieren eines fehlenden Business-API-Schlüssels ist ebenfalls gesperrt und wird nach dem Sperren erneut geprüft. Zwei Starts ersetzen ihn nicht zweimal. Bereits gespeicherte Wallet-Zugangsdaten bleiben dabei erhalten. Es werden keine vorhersehbaren Wallet-Werte mit einem leeren HMAC-Schlüssel festgeschrieben. Ohne alten Schlüssel lassen sich verlorene frühere Werte nicht rekonstruieren.

Vor einem produktiven Update ein geschütztes vollständiges Backup erstellen. Die erste Übernahme schreibt je bestehendem Mitglied eine private Zeile und kann bei großen Datenbeständen Zeit brauchen. Dafür ein Wartungsfenster vorsehen. Optional aus dem Projektverzeichnis mit der neuen Fassung initialisieren:

```bash
php -r 'require "app/bootstrap.php"; db(); echo "Initialisierung abgeschlossen\n";'
```

Nicht gleichzeitig alte und neue Programmfassungen für dieselbe Datenbank betreiben. Eine ältere Fassung kennt die privaten Zugangsdaten nicht. Ein Code-Rollback nach späteren API-Rotationen muss deshalb zusammen mit dem Datenbankstand und den ausgegebenen Passkopien geprüft werden.

## Unterstützte Rotation

Der bestehende Betreiberweg „API-Schlüssel: Neu erzeugen“ bleibt erhalten. Eigentum, CSRF und API-Planregeln bleiben nötig. Die eigentliche Schlüsselerneuerung und ein Audit mit ausschließlich der Business-ID erfolgen atomar. Ein Auditfehler lässt den alten API-Schlüssel unverändert. Es wird weder eine optionale Wallet-Mitteilung angelegt noch ein Provider aufgerufen.

Die Rotation allein verändert auch nicht den Apple-Passinhalt, dessen Revision oder ETag. Ein vorhandener ApplePass-Zugang kann weiter Passabruf und Geräteregistrierung nutzen; das neue API-Bearer-Token ist kein Ersatz für diesen Zugang. Das ApplePass-Token wird nicht als Bildlink verwendet. Der alte Google-Stempelbild-Link bleibt gültig, ohne ein neues Objektupdate nur wegen der API-Rotation zu brauchen.

## Sicherheits- und Wiederherstellungsgrenzen

- Die erste Migration erhält den aktuell berechenbaren Legacy-Stand. Wurde ein Pass schon vor dieser Umstellung durch eine frühere API-Rotation ausgesperrt, kann die App dessen früheres Token aus dem jetzigen API-Schlüssel nicht zurückrechnen. Keine automatische Reparaturbehauptung; die betroffenen Altpasskopien brauchen eine gesondert geprüfte Neuinstallation beziehungsweise Neuausgabe.
- Eine API-Schlüsselrotation ist keine Widerrufsfunktion für bereits ausgegebene Wallet-Zugänge oder Bildlinks. Bei einem Sicherheitsvorfall müssen deren Reichweite und eine gezielte Neuausgabe getrennt geprüft werden. Legacy-Mitglieder behalten aus Kompatibilitätsgründen ihre bisherigen HMAC-Werte; die zufälligen neuen Werte gelten für neu angelegte Mitglieder.
- Authentifizierungstoken sind Geheimnisse. Nicht aus der Datenbank in Logs, Tickets, Screenshots oder allgemeine Exporte kopieren. Mitglieder-API und Mitgliedsexport enthalten sie nicht. Tokenisierte Bildantworten verwenden private Cache- und No-Referrer-Header. Server-/Proxy-Zugriffslogs für die Bildpfade müssen ebenfalls geschützt oder redigiert werden; deren Konfiguration verändert die App nicht. Das vollständige Datenbankbackup enthält sie notwendigerweise und muss privat geschützt werden.
- Das unterstützte vollständige Backup/Restore erhält die im Backup erfassten Wallet-Zugangsdaten. Eine Rückkehr zu einem älteren Backup kann später angelegte Mitglieder und später erstmals erzeugte Zugangsdaten verlieren. Ein Restore kann außerdem frühere API-Schlüssel wiederherstellen. Das ist eine Wiederherstellung des damaligen Zustands, keine Wiederherstellung später verlorener Geheimnisse und keine garantierte Erhaltung jeder neueren Passkopie.
- Ein manuelles Leeren der privaten Tabelle, Zurücksetzen der Migrationsmarkierung oder Umschreiben von Wallet-Zugangsdaten ist keine unterstützte Reparatur. Das kann installierte Pässe und Bildlinks aussperren.

## Lokale Prüfung

```bash
python3 tests/run-wallet-auth.py
```

Die gezielte Suite prüft Legacy-Migration und Fehlerrollback, gleichzeitige Migration mit Rotation, konkurrierende Erstverwendung, wiederholte Rotation, Mandantengrenzen, Audit ohne Geheimniswerte, Unveränderbarkeit und Löschkaskade. HTTP-Prüfungen verwenden einen nur lokal selbstsignierten Testpass: alter ApplePass-Zugang und ETag bleiben nach Rotation gültig, der alte API-Bearer wird abgewiesen, der neue funktioniert. Registrierung, bestehender Bildlink, getrennte Tokenzwecke, CSRF, fremdes Unternehmen, Export ohne Token und vollständiges Backup/Restore sind ebenfalls abgedeckt. Keine echte Provideranfrage, keine Apple-vertrauenswürdige Signatur und keine native Wallet-Darstellung geprüft.

Technik: `app/inc/wallet-auth.php` wird bereits durch `app/bootstrap.php` geladen, damit auch CLI-Initialisierung und Wiederherstellung ohne geladenen Wallet-Controller korrekt arbeiten. Passdienst: [WALLET-UPDATES.md](WALLET-UPDATES.md), Signaltransport und eigener noch offener Registrierungsrennfall: [WALLET-APNS.md](WALLET-APNS.md).

Quelle für die unveränderliche Passidentität und Authentifizierung: https://developer.apple.com/documentation/walletpasses/adding-a-web-service-to-update-passes
