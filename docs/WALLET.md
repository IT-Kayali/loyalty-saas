# Apple & Google Wallet einrichten

Die App erzeugt Wallet-Pässe komplett selbst (keine externen Dienste). Ohne Zugangsdaten antworten die Endpunkte `/pass/apple/...` und `/pass/google/...` mit **HTTP 501** — alles andere läuft normal.

Du brauchst:

- **Apple**: eine passende Apple-Developer-Mitgliedschaft; aktuelle Bedingungen im Developer-Portal prüfen
- **Google**: einen Google-Wallet-Issuer-Account (pay.google.com/business/console)

---

## 1. Apple Wallet

### 1.1 Pass Type ID anlegen

1. [developer.apple.com](https://developer.apple.com) → **Certificates, Identifiers & Profiles → Identifiers → +**
2. **Pass Type IDs** wählen
3. Description frei wählen, Identifier im Format `pass.deine.domain.loyalty` (reverse DNS)

### 1.2 Signatur-Zertifikat erzeugen

1. Beim angelegten Pass Type ID auf **Create Certificate** klicken
2. Dafür wird ein CSR gebraucht — auf dem Mac in der Schlüsselbundverwaltung (Zertifikatsassistent → Zertifikat einer Zertifizierungsinstanz anfordern) oder per openssl:

```bash
openssl req -new -newkey rsa:2048 -nodes -keyout pass_key.pem -out pass.csr \
  -subj "/CN=pass.deine.domain.loyalty"
```

3. CSR hochladen, Zertifikat herunterladen (`pass.cer`)
4. In PEM umwandeln:

```bash
openssl x509 -inform DER -in pass.cer -outform PEM -out pass_cert.pem
```

### 1.3 WWDR-Zwischenzertifikat

Das zum Passzertifikat passende **Apple Worldwide Developer Relations**-Zwischenzertifikat aus [Apples Zertifikatsübersicht](https://www.apple.com/certificateauthority/) laden. Aussteller und Zertifikatskette des eigenen Passzertifikats prüfen, nicht blind ein altes Zwischenzertifikat übernehmen. Eine geladene DER-Datei in PEM umwandeln:

```bash
openssl x509 -inform DER -in AppleWWDR.cer -outform PEM -out wwdr.pem
```

### 1.4 APNs-Updates bewusst aktivieren

Die App nutzt für Passupdates dasselbe Passzertifikat und denselben privaten Schlüssel wie für die Signatur. Kein zusätzlicher p8-Schlüssel nötig. Passupdates verwenden ausschließlich Produktions-APNs, nicht den Sandbox-Server. HTTPS und cURL mit HTTP/2 sind erforderlich. Der neue Schalter `updates_enabled` bleibt zunächst `false`; erst nach Einrichtung und bewusster Entscheidung auf `true` setzen. Sichtbare Sperrbildschirm-Mitteilungen sind damit nicht garantiert. Bestehende Konfigurationen und die getrennte optionale Kundenwahl: [WALLET-APNS.md](WALLET-APNS.md).

### 1.5 Dateien ablegen und config.php füllen

```
data/apple/pass_cert.pem   # Schritt 1.2
data/apple/pass_key.pem    # Schritt 1.2 (privater Schlüssel!)
data/apple/wwdr.pem        # Schritt 1.3
```

```php
'apple_wallet' => [
    'team_id'        => 'ABCDE12345',
    'pass_type_id'   => 'pass.deine.domain.loyalty',
    'organization'   => 'Deine Firma GmbH',
    'cert_path'      => __DIR__ . '/data/apple/pass_cert.pem',
    'key_path'       => __DIR__ . '/data/apple/pass_key.pem',
    'key_password'   => '',
    'wwdr_path'      => __DIR__ . '/data/apple/wwdr.pem',
    'updates_enabled' => false, // erst bewusst nach Einrichtung aktivieren
],
```

`data/` liegt außerhalb des Document Root und ist in `.gitignore` — die Schlüssel landen weder im Repo noch im Web.

### 1.6 Wie es technisch läuft

- **Pass-Datei**: `/pass/apple/{CODE}.pkpass` baut das Pass-Paket live: `pass.json` (Stände, Farben, Logo aus dem Karten-Design), PNG-Bilder (GD), `manifest.json` (SHA1-Hashes), PKCS#7-Signatur mit deinem Pass-Zertifikat + WWDR.
- **Registrierung**: iPhones melden sich danach am eingebauten PassKit-Web-Service unter `/api/pass/v1/...` an (Apple verlangt dafür **HTTPS mit gültigem Zertifikat** — hinter einem Reverse-Proxy wie Caddy oder nginx).
- **Push-Updates**: Nur bei aktivierter Konfiguration gibt eine unterstützte Kartenaktion einen APNs-Abrufhinweis: leeres JSON, Topic = Pass Type ID, vorhandenes Passzertifikat/Schlüssel, HTTP/2 und Produktion. Das Gerät entscheidet über Abruf und Anzeige. Eine Konfigurationsprüfung beweist weder gültige Apple-Zertifikate noch Geräteempfang.

Eine lokale Passdatei kann auf Aufbau und Signatur geprüft werden. Der Simulator ist kein Push-Testgerät. Produktionsabnahme braucht einen korrekt signierten installierten Pass und ein echtes Gerät. Eine ungültige Signatur kann unter anderem an unpassendem Zertifikat/Schlüssel oder einer falschen Zertifikatskette liegen.


---

## 2. Google Wallet

### 2.1 Issuer-Account

1. [Google Pay & Wallet Console](https://pay.google.com/business/console) öffnen, Issuer-Account anlegen
2. Die **Issuer-ID** (lange Zahl) notieren

### 2.2 Service Account

1. [Google Cloud Console](https://console.cloud.google.com) → Projekt anlegen → **Google Wallet API aktivieren**
2. **IAM → Service Accounts → +** → Name frei wählen
3. Beim Service Account: **Schlüssel → Schlüssel hinzufügen → JSON** → Datei herunterladen
4. In der Wallet-Console den Service Account (E-Mail-Adresse) als Nutzer mit Zugriff auf den Issuer hinzufügen

### 2.3 config.php

```
data/google/service-account.json   # die heruntergeladene Datei
```

```php
'google_wallet' => [
    'issuer_id'     => '3388000000012345678',
    'class_suffix'  => 'loyalty',
    'service_account_json' => __DIR__ . '/data/google/service-account.json',
],
```

### 2.4 Wie es technisch läuft

- **Hinzufügen**: `/pass/google/{CODE}` leitet auf einen `pay.google.com/gp/v/save/{JWT}`-Link weiter. Das JWT (RS256 mit dem Service-Account-Key signiert) enthält die komplette Pass-Klasse und das Objekt — die Klasse muss nicht vorab per API angelegt werden.
- **Updates**: Bei Standänderungen ruft die App die Google-Wallet-REST-API auf (OAuth2-Zugriffstoken per JWT-Bearer-Flow, vorhandenes GenericObject per `PATCH`, sonst Anlage). Dies ist kein belegter Versand einer optionalen Nachricht oder eine Empfangsbestätigung.

Hinweis: Für produktive Pässe verlangt Google teilweise eine Freigabe der Klasse; im Testmodus kannst du Pässe für Testnutzer deines Google-Kontos sofort speichern.

---

## 3. Betrieb

- **Design**: Farben, Logo und Texte der Pässe kommen aus dem **Karten-Design** des Business (Admin → Karten-Design). Änderungen wirken ab dem nächsten Update-Push.
- **Sprache**: Pass-Texte folgen der Sprache der Installation (`default_lang`).
- **Ohne Zugangsdaten**: Wallet-Buttons bleiben sichtbar, der Endpunkt erklärt dem Besucher freundlich (501), dass Wallet noch nicht eingerichtet ist. Alternativ Buttons in `app/inc/wallet.php` (`wallet_buttons()`) ausblenden.


## Eigene Stempelbilder

Im Karten-Designer pro Unternehmen kann jetzt ein eigenes Stempel-Bild hochgeladen werden (PNG/JPEG/WebP, maximal 2 MB, 16–4096 px pro Seite und maximal 8 Megapixel). Der Server dekodiert es und speichert eine neue PNG außerhalb von `public/`. SVG, GIF, PHP und beschädigte Bilder werden abgewiesen. Transparente quadratische PNGs sind für kleine Stempel am besten geeignet.

Webkarte und Vorschau zeigen volle bzw. ausgegraute Stempel. Für Wallets erzeugt der Server aus dem aktuellen Stand eine Rastergrafik: Apple `strip.png`/`strip@2x.png`, Google GenericObject `heroImage` über eine tokenisierte PNG-Adresse. Die Grafik zeigt das erste aktive Stempelprogramm; andere Programme bleiben als Textfelder sichtbar. Die native Wallet-App bestimmt Platzierung und Darstellung. Ohne Zertifikate/Issuer ist nur die lokale Grafik-/Payloadprüfung möglich, kein Nachweis auf echten Geräten.

Designänderungen wirken beim nächsten Öffnen/Neuladen der Webkarte und beim nächsten Passabruf. Ein automatischer Push an bereits installierte Pässe allein durch Speichern im Designer ist noch nicht implementiert. Statusänderungen verwenden den bestehenden Wallet-Updatepfad.

Technische Google-Referenz: https://developers.google.com/wallet/reference/rest/v1/genericobject

## Wallet-Nähe (vorbereitet, nicht gerätegeprüft)

Getrennter Standorteditor mit ausdrücklicher Auswahl und bis zu zehn Geschäftskoordinaten je Unternehmen. Apple `locations`, Google-Objekt `merchantLocations`, keine Kundenortung durch die App. Signierung/Issuer und echte Geräteprüfung bleiben nötig: [WALLET-NEARBY.md](WALLET-NEARBY.md). Tests: `python3 tests/run-wallet-nearby.py`.


Wallet-Nähe wird zusätzlich durch die separate, standardmäßig ausgeschaltete Kundenwahl begrenzt. Manuelle Betreiberhinweise und Hinweis-/Koordinatenprüfstand, Widerruf, Export und lokale Löschgrenzen: [WALLET-NEARBY-PRIVACY.md](WALLET-NEARBY-PRIVACY.md). Keine DSGVO-Zusage oder bestätigte Fernlöschung vorhandener Kopien.

## Optionale Betreiber-Mitteilungen

Hinweise, getrennte Apple-/Google-Wahl und anpassbare Textvorlagen: [WALLET-PUSH.md](WALLET-PUSH.md). Einzelversand mit eigener Empfänger-/Textvorschau und ausdrücklicher Bestätigung ist lokal implementiert; standardmäßig bleibt `wallet_messages.enabled` aus. Reale Provider-/Geräteabnahme fehlt. Apple-Kartenstand und Einzelversand nutzen denselben Produktions-/Passzertifikatstransport, mit getrennter Freigabe: [WALLET-APNS.md](WALLET-APNS.md). Bestehende Kartenstand-Updates und Nähe-Relevanz sind eigene Pfade. Apple-Änderungsmitteilung ist kein beliebiger Pushalarm; Google-Nachricht braucht einen ausdrücklichen objektbezogenen Nachrichtenaufruf. NFC ist auf Nutzerwunsch nicht vorgesehen.

## PassKit-Update-Protokoll

Geänderte Serien, Registrierungsvorsprung, dauerhafte Revisionen, private verschlüsselte Cursor, stabile ETags/Datumsprüfungen und Restore-Verhalten: [WALLET-UPDATES.md](WALLET-UPDATES.md). Der normale Passabruf ist lokal mit selbstsignierten Testdaten geprüft, nicht von Apple oder auf Geräten abgenommen. Der gemeinsame Produktions-/Passzertifikatstransport ist lokal implementiert: [WALLET-APNS.md](WALLET-APNS.md). Die Stabilisierung bestehender Passauthentifizierung bei API-Schlüsselrotation ist noch eigene Codearbeit.
