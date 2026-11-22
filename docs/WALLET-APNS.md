# Apple-Updates: gemeinsamer APNs-Transport

Stand: 22.11.2026. Lokal implementiert und mit Ersatztransporten geprüft, keine echte APNs-/Geräteabnahme. NFC bleibt ausgeschlossen.

## Aktivierung

Neue Installationen haben `apple_wallet.updates_enabled => false`. Ein signierbarer Pass allein schaltet keinen Versand ein. Für einen bewussten Produktivbetrieb in `config.php`:

```php
'apple_wallet' => [
    // Bestehende Pass Type ID und Signierdateien unverändert weiterverwenden.
    // Weitere Pflichtfelder stehen in WALLET.md und config.example.php.
    'updates_enabled' => true,
],
```

Das ist ein Ausschnitt, nicht der Ersatz für den ganzen Konfigurationsblock. Nur der boolesche Wert `true` aktiviert den neuen Schalter. `false`, `null`, Zahlen oder Zeichenketten aktivieren ihn nicht.

Abwärtskompatibilität für vorhandene Konfigurationen ohne diesen Eintrag: Nur wenn bisher `apns_key_id` und `apns_topic` nicht leer waren, die alte `apns_auth_key`-Datei noch existiert und `apns_sandbox` nicht eingeschaltet war, bleibt die bisherige Produktionsabsicht erhalten. Eine frühere Sandbox-Einstellung wird nicht still produktiv. Ein ausdrücklich gesetztes `updates_enabled => false` gewinnt immer.

Der Transport liest keine p8-Schlüsselbytes und erzeugt kein APNs-JWT mehr. Nach bewusster Umstellung auf den neuen Schalter werden die alten vier `apns_*`-Werte nicht mehr benötigt. Erst den neuen Schalter setzen, danach alte ausschließlich hierfür genutzte Dateien gegebenenfalls entfernen. Andere Nutzer derselben Schlüsseldatei vorher prüfen. Das ist die gewählte Passzertifikatsimplementierung, keine allgemeine Behauptung über jede mögliche APNs-Authentifizierungsart.

Zusätzlich nötig: HTTPS-`base_url`, cURL mit HTTP/2 sowie vorhandene und lesbare Passzertifikat-/Schlüsseldateien. Die Signiereinrichtung braucht auch das passende WWDR-Zertifikat. Die Bereitschaftsprüfung prüft keine Apple-Vertrauenskette, Zertifikatsgültigkeit oder reale Providerberechtigung.

## Zwei getrennte Anlässe, ein technischer Signalweg

- **Kartenstand:** Bestehende unterstützte Kartenaktionen geben einen Abrufhinweis für genau diesen Mitgliedspass. Keine Veröffentlichung eines neuen optionalen Texts, keine Umdeutung der Marketing- oder Wallet-Mitteilungswahl. Fehler des Apple-Signals dürfen eine bereits gebuchte Kartenaktion nicht nachträglich als fehlgeschlagen darstellen. Ein Signal erhält eine Ablaufzeit eine Stunde nach Erstellung. APNs berücksichtigt sie nach bestem Bemühen, nicht als garantierte Zustellgrenze.
- **Optionale Mitteilung:** Weiterhin zusätzlich `wallet_messages.enabled => true`, aktuelle freiwillige Apple-Kundenwahl, Empfänger-/Textvorschau und ausdrückliche Betreiberbestätigung. Die Wahl wird unmittelbar vor Übergabe erneut geprüft. Der Signalablauf entspricht genau dem geprüften Nachrichtenablauf. Gleiche Quoten, Wiederholungssperren und private Ergebnisprotokolle wie zuvor. Der Apple-Schalter allein gibt diesen Versand nicht frei.

Beide Wege verwenden `app/inc/wallet-apns.php`: ausschließlich Produktions-APNs, HTTP/2, TLS mindestens 1.2, geprüfter Servername und Zertifikat, keine Weiterleitungen, dasselbe Passzertifikat und derselbe private Schlüssel wie die Passsignatur. Topic ist die konfigurierte Pass Type ID. Gesendet wird nur `{}`, mit Hintergrundtyp, Priorität 5 und Ablaufzeit. Ein leerer Abrufhinweis enthält weder den optionalen Nachrichtentext noch Guthaben oder Kundendaten. Das Gerät muss den Pass danach selbst abrufen.

Kartenstand und optionaler Versand wählen nur Registrierungen für **dieselbe Seriennummer und die konfigurierte Pass Type ID**, ohne doppelte Token. Höchstens zehn Anfragen laufen gleichzeitig. Der Kartenstandweg bearbeitet größere Bestände in aufeinanderfolgenden Zehnergruppen; der optionale Einzelversand bleibt auf eine bis zehn Registrierungen begrenzt. Verbindungsaufbau maximal vier Sekunden, jede Anfrage maximal acht Sekunden. Mehrere Gruppen addieren Laufzeit. Kein Wiederholungsjob und keine automatische Wiederholung einer fehlgeschlagenen Anfrage.

## Ergebnisse und Aufräumen

- HTTP 200: Signal von APNs angenommen, kein Beleg für Geräteabruf, Anzeige oder Empfang einer Mitteilung.
- HTTP 4xx: abgewiesen. Andere Statuswerte sowie Netzwerk-/Transportfehler bleiben unklar.
- Ungültige Registrierung: Nur die konkrete Kombination aus Seriennummer, konfigurierter Pass Type ID und betroffenem Token wird entfernt, bei 400 `BadDeviceToken`/`DeviceTokenNotForTopic` oder 410 `Unregistered`/`ExpiredToken`. Andere Pässe oder Pass Type IDs mit demselben Token bleiben erhalten.
- Zertifikatsfehler und nicht erkannte Antwortgründe löschen keine Registrierung. Keine Rohantwort, Schlüsselbytes oder Gerätetoken landen im optionalen Ergebnisprotokoll.

APNs und Wallet können Hinweise verzögern, zusammenfassen oder auslassen. Ein Passupdate und eine sichtbare Änderungsmitteilung sind verschiedene Dinge. Kein beliebiger Sperrbildschirmalarm und keine Zustellgarantie. Der Simulator ersetzt die echte Push-Abnahme nicht.

## Prüfung

```bash
python3 tests/run-wallet-apns.py
```

Die Suite benötigt PHP mit cURL/HTTP/2 für den positiven Bereitschaftstest. Sie verwendet ausschließlich nicht signierfähige lokale Dateiplatzhalter und Ersatztransporte. Geprüft werden Aktivierungsregeln, alte Produktions-/Sandbox-Konfiguration, HTTPS-/Dateisperren, genaue Optionen und URL-Kodierung, Token-/Typtrennung, Antwortklassen, enges Aufräumen, fehlende Wiederholung, Fehlerisolierung bei Kartenaktionen und erneute Kundenwahlprüfung. Keine echte Anfrage an APNs.

Für eine Abnahme müssen ein gültiger installierter Pass, ein erreichbarer HTTPS-Passdienst, passende Zertifikate und ein physisches Gerät zusammen geprüft werden. Dabei Erstinstallation, Registrierung, Standänderung, optionalen Feldwechsel, Ablauf, Geräteberechtigungen und Zertifikatserneuerung getrennt prüfen.

## Noch eigene Arbeit

Die inzwischen eigene Etappe [WALLET-AUTH.md](WALLET-AUTH.md) erhält aktuelle Legacy-Zugangsdaten, trennt neue zufällige Wallet-Zugangsdaten vom API-Schlüssel und schützt auch bestehende Google-Stempelbild-Links bei Rotation. Das stellt keine früher bereits verlorenen Token wieder her.

Auch eine erneute Registrierung mit identischem Token während einer laufenden APNs-Anfrage braucht noch einen eigenen Schutz gegen veraltete Fehlerantworten. Die aktuelle Bereinigung grenzt Seriennummer, Typ und Token ein, unterscheidet aber nicht zwei zeitlich aufeinanderfolgende Registrierungen mit derselben Kombination.

## Quellen

- Passupdate, gleiches Zertifikat/Schlüssel, leeres JSON und nur Produktion: https://developer.apple.com/documentation/walletpasses/adding-a-web-service-to-update-passes
- HTTP/2, TLS, Header und Ablauf: https://developer.apple.com/documentation/usernotifications/sending-notification-requests-to-apns.md
- Status-/Fehlerklassen und ungültige Token: https://developer.apple.com/documentation/usernotifications/handling-notification-responses-from-apns.md
- Einrichtung: [WALLET.md](WALLET.md), optionale Wahl und Einzelversand: [WALLET-PUSH.md](WALLET-PUSH.md), Abrufprotokoll: [WALLET-UPDATES.md](WALLET-UPDATES.md).
