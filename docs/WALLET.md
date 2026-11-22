# Apple & Google Wallet einrichten

Die App erzeugt Wallet-Pässe komplett selbst (keine externen Dienste). Ohne Zugangsdaten antworten die Endpunkte `/pass/apple/...` und `/pass/google/...` mit **HTTP 501** — alles andere läuft normal.

Du brauchst:

- **Apple**: einen Apple-Developer-Account (99 $/Jahr, developer.apple.com)
- **Google**: einen Google-Wallet-Issuer-Account (kostenlos, pay.google.com/business/console)

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

Das **Apple Worldwide Developer Relations**-Zertifikat (G4) laden:
[apple.com/certificateauthority](https://www.apple.com/certificateauthority/) → "Worldwide Developer Relations - G4 (Expiring 12/10/2030)" → als `AppleWWDRCAG4.cer` laden, dann:

```bash
openssl x509 -inform DER -in AppleWWDRCAG4.cer -outform PEM -out wwdr.pem
```

### 1.4 APNs-Schlüssel (für Push-Updates)

Damit iPhone-Pässe sich bei Standänderungen selbst aktualisieren und eine Mitteilung auf dem Sperrbildschirm zeigen:

1. Developer-Portal → **Keys → +** → **Apple Push Notifications service (APNs)** anhaken
2. Key herunterladen → Datei `AuthKey_XXXXXXXXXX.p8` (nur einmal herunterladbar!)
3. Die **Key-ID** (10 Zeichen) und deine **Team-ID** (oben rechts im Portal) notieren

### 1.5 Dateien ablegen und config.php füllen

```
data/apple/pass_cert.pem   # Schritt 1.2
data/apple/pass_key.pem    # Schritt 1.2 (privater Schlüssel!)
data/apple/wwdr.pem        # Schritt 1.3
data/apple/AuthKey.p8      # Schritt 1.4
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
    'apns_key_id'    => 'XYZ9876543',
    'apns_auth_key'  => __DIR__ . '/data/apple/AuthKey.p8',
    'apns_topic'     => 'pass.deine.domain.loyalty',   // = pass_type_id
    'apns_sandbox'   => false,
],
```

`data/` liegt außerhalb des Document Root und ist in `.gitignore` — die Schlüssel landen weder im Repo noch im Web.

### 1.6 Wie es technisch läuft

- **Pass-Datei**: `/pass/apple/{CODE}.pkpass` baut das Pass-Paket live: `pass.json` (Stände, Farben, Logo aus dem Karten-Design), PNG-Bilder (GD), `manifest.json` (SHA1-Hashes), PKCS#7-Signatur mit deinem Pass-Zertifikat + WWDR.
- **Registrierung**: iPhones melden sich danach am eingebauten PassKit-Web-Service unter `/api/pass/v1/...` an (Apple verlangt dafür **HTTPS mit gültigem Zertifikat** — hinter einem Reverse-Proxy wie Caddy oder nginx).
- **Push-Updates**: Bei jedem Stempel/Punkt schickt die App eine APNs-Benachrichtigung (leerer Payload, Topic = Pass Type ID, ES256-signiertes JWT mit dem .p8-Key). Das iPhone holt sich dann das frische Pass vom Web-Service.

Testen ohne iPhone: die `.pkpass`-Datei im Mac-Simulator (Xcode) oder auf einem iPhone per AirDrop/Mail öffnen. Ungültige Signatur = Zertifikat gehört nicht zur Pass Type ID oder WWDR falsch.

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

Hinweise, getrennte Apple-/Google-Wahl und anpassbare Textvorlagen: [WALLET-PUSH.md](WALLET-PUSH.md). Einzelversand mit eigener Empfänger-/Textvorschau und ausdrücklicher Bestätigung ist lokal implementiert; standardmäßig bleibt `wallet_messages.enabled` aus. Reale Provider-/Geräteabnahme fehlt. Der neue Apple-Einzelversand verwendet das Passzertifikat, nicht den bisherigen p8-Kartenstandhelfer. Bestehende Kartenstand-Updates und Nähe-Relevanz sind eigene Pfade. Apple-Änderungsmitteilung ist kein beliebiger Pushalarm; Google-Nachricht braucht einen ausdrücklichen objektbezogenen Nachrichtenaufruf. NFC ist auf Nutzerwunsch nicht vorgesehen.

## PassKit-Update-Protokoll

Geänderte Serien, Registrierungsvorsprung, dauerhafte Revisionen, private verschlüsselte Cursor, stabile ETags/Datumsprüfungen und Restore-Verhalten: [WALLET-UPDATES.md](WALLET-UPDATES.md). Der normale Passabruf ist lokal mit selbstsignierten Testdaten geprüft, nicht von Apple oder auf Geräten abgenommen. Der ältere p8-/Sandbox-Kartenstandhelfer ist weiterhin ein eigener, noch zu korrigierender Pfad; der neue optionale Einzelversand nutzt schon das Passzertifikat.
