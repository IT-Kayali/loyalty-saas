# Wallet-Nähe: manuelle Angaben und Kundenwahl

Stand: 22.11.2026. Technische Datenschutzvorkehrungen, **keine DSGVO-Konformitätszusage und keine Rechtsberatung**. Die Nähe-Funktion bleibt ohne gültige Apple-/Google-Konfiguration und echte Gerätetests nur vorbereitet. Siehe [WALLET-NEARBY.md](WALLET-NEARBY.md).

## Einrichtung durch den Betreiber

1. Organisation → Unternehmen → **Wallet-Nähe: Datenschutzhinweise** (`/business/ID/wallet-nearby-notice`). Alternativ vom Standort-Näheeditor öffnen.
2. Verantwortlichen mit Name/Anschrift, Datenschutzkontakt und vollständigen HTTPS-Link zu eigenen Datenschutzhinweisen eintragen. Optional bis zu 3.000 Zeichen Zusatztext. Keine HTML-Ausführung oder automatische Erstellung von Rechtstexten. Ohne vollständige Angaben ist eine neue Kundenbestätigung gesperrt. Es findet keine inhaltliche Rechtsprüfung oder Erreichbarkeitsprüfung der externen Seite statt.
3. Organisation → Standort → Wallet-Nähe: geprüfte Geschäftskoordinaten manuell eingeben und ausdrücklich auswählen. Keine automatische Geokodierung. Maximal zehn ausgewählte Standorte je Unternehmen, einschließlich pausierter Standorte.
4. Das Mitglied entscheidet selbst in seinem angemeldeten Wallet. Betreiber können dort keine Zustimmung stellvertretend setzen. Speichern von Betreibertext oder Standorten setzt kein Mitglied auf zugestimmt und startet weder Mails noch Anbieteranfragen.

Feste technische Hinweise sind nicht im Betreiberformular änderbar: Zweck, Weitergabe der Geschäftskoordinaten mit dem Mitgliedspass an den gewählten Wallet-Anbieter, keine Kundenortung durch diese Web-App, eigene Anbieter-/Gerätebedingungen, Anzeigegrenzen, Freiwilligkeit, Trennung von Marketing und Grenzen des Ausschaltens. Der freie Zusatz darf diesen Hinweisen nicht widersprechen. Verantwortliche müssen das fachlich prüfen.

## Freiwillige Kundenwahl

Im angemeldeten Wallet → **Wallet-Nähe & Datenschutz** (`/member/wallet-nearby`):

- Für jedes Unternehmen getrennt und anfangs **aus**, auch für bestehende Mitglieder. Keine Übernahme einer Marketing-E-Mail-Einwilligung. Sammeln und Einlösen bleiben ohne Nähe-Zustimmung möglich.
- Aktuelle feste Hinweise, Betreiberangaben und die zu teilenden Geschäftskoordinaten werden vor der Wahl angezeigt. Die Zustimmung braucht eine nicht vorangekreuzte Checkbox und eine eigene Schaltfläche.
- Eine zweite Schaltfläche schaltet jederzeit aus, ohne zusätzliche Checkbox, auch aus einem älteren Formular und bei inaktivem Betreiber-Abo. Das ändert keine Marketingwahl oder Geräteberechtigung.
- Betreiber-/Gastzugänge und fremde Mitglieds-IDs dürfen die Wahl nicht ändern. Mitgliedskonten derselben E-Mail bleiben je Unternehmen getrennt.

Die SQLite-Schreibtransaktion prüft Mitgliedszuordnung und aktuellen Hinweisstand erneut. CSRF schützt beide Formulare. Kunden- und Betreiberseite sowie der Export sind privat/no-store und senden keinen Referrer. Das verhindert keine manuell gespeicherten Kopien oder Screenshots.

## Passdaten und geänderte Hinweise

`wallet_member_locations()` liest die Mitgliedswahl frisch aus der Datenbank, auch wenn ein Aufrufer ein altes Mitgliedsobjekt hat. Ohne aktuelle Bestätigung enthalten neue Apple-Passdaten `locations: []`, Google-Objektdaten `merchantLocations: []`. Die gemeinsame Google-Klasse erhält keine Standorte.

Der SHA-256-Prüfstand umfasst die feste Fassung `wallet-nearby-v1`, Unternehmens-ID/-Name, Betreiberangaben und die aktuell ausgegebenen Koordinaten in stabiler Reihenfolge. Bei Abweichung wird die gespeicherte Zustimmung nicht stillschweigend auf den neuen Umfang übertragen: neue Passdaten bleiben ohne Nähe-Koordinaten, die Kundenseite fordert erneute Bestätigung. Ein altes Bestätigungsformular wird abgewiesen.

Es ist ein **Inhaltsprüfstand, kein fortlaufendes Änderungsjournal**: Wird exakt der früher bestätigte Stand wiederhergestellt, gilt eine nicht widerrufene Zustimmung wieder für diesen Stand. Ein ausdrückliches Ausschalten bleibt dagegen aus. Keine rückwirkende Behauptung, alte Passkopien seien geändert.

## Was Ausschalten und Löschen nicht tun

Ausschalten wirkt auf künftig erzeugte Passdaten, nicht als bestätigter Geräteabgleich. Es startet keinen APNs-Push, Google-API-Aufruf, Massenupdate oder Fernlöschauftrag. Bestehende reguläre Pass-Updatepfade verwenden beim nächsten Aufruf die aktuelle Wahl; ihre tatsächliche Wirkung auf bereits installierten Pässen ist hier nicht abgenommen.

Bereits installierte oder heruntergeladene Apple-/Google-Pässe können frühere Geschäftskoordinaten behalten. Nutzer sollen solche Kopien selbst aus Wallet und von ihren Geräten entfernen und Anbieter-/Geräteberechtigungen dort verwalten. Die Plattform kann das weder garantieren noch überprüfen. Ein neues Payload ohne Koordinaten ist kein Beweis, dass Anbieter- oder Gerätekopien gelöscht sind.

Die vorhandene Kontolöschung entfernt nun in **einer lokalen Transaktion** eigene `pass_devices`-Registrierungen anhand der Mitglieds-Passseriennummern, eigene Mail-Outbox-Einträge, Member-Passwortresets (E-Mail ohne Beachtung der Großschreibung) und Mitgliedszeilen mit deren vorhandenen Datenbankkaskaden. Fehler rollen die gesamte Transaktion zurück. Fremde Registrierungen bleiben bestehen. Apple-Gerätetoken werden dabei nicht an Google oder andere Stellen gesendet.

Nicht enthalten: bestätigte Löschung externer Google-Objekte, Apple-Fernlöschung, Anbieter-Backups, fremde Kopien oder ein allgemeiner Lösch-/Aufbewahrungsplan für sämtliche System-, Betriebs- und Sicherungsdaten. Die Oberfläche verspricht deshalb keine vollständige Fernlöschung.

## Gespeicherte Nachweise und Export

Additive Migration, keine Änderung bestehender Karten/Salden:

- `businesses.wallet_nearby_notice`: Betreiberangaben als JSON, anfangs leer.
- `members.wallet_nearby_consent`: 0/1, anfangs 0.
- `wallet_nearby_granted_at`, `wallet_nearby_withdrawn_at`: letzte Bestätigung bzw. letztes Ausschalten, UTC.
- `wallet_nearby_notice_hash`, `wallet_nearby_snapshot`: Hash und genauer zuletzt bestätigter Hinweisstand einschließlich damaliger Geschäftskoordinaten und fester Fassung.

Neue Bestätigung ersetzt den bisherigen Bestätigungsnachweis. Ausschalten behält den letzten Nachweis und setzt einen Ausschaltzeitpunkt. Kein vollständiges historisches Einwilligungsregister, keine manipulationssichere Archivierung. Es werden dafür keine Kunden-GPS-Punkte, IP-Adressen, Bewegungsdaten oder zusätzlichen Tracking-IDs gespeichert. Bestehende andere Anwendungs-/Webserverprotokolle sind damit nicht automatisch deaktiviert.

Der angemeldete JSON-Export enthält Wahl, Zeitpunkte, Hash, bestätigten und aktuellen Hinweisstand sowie die technische Wirksamkeit für neue Passdaten. Ergänzt sind Marketingwahl mit Quelle/Zeit und Geburtstag. Keine Pass-Auth-, Push- oder Passworttoken im neuen Exportblock. Der Export ersetzt keine rechtliche Vollständigkeitsprüfung aller Auskunftspflichten.

Betreiberänderungen erhalten einen ID-Audit `business.wallet_nearby_notice`, atomar mit dem Speichern, ohne Text-/Kontakt-/Koordinateninhalt. Konfliktschutz verhindert Überschreiben durch alte Betreiber-Tabs.

## Vor produktiver Nutzung prüfen

Die bewusste Kundenwahl ist eine technische Schutzentscheidung dieser Anwendung, keine Festlegung der rechtlich passenden Grundlage für jeden Verarbeitungsvorgang. Betreiber müssen insbesondere Zweck, Rechtsgrundlage, Informationspflichten, Anbieterrollen, internationale Transfers, Verträge, Betroffenenrechte und angemessene Aufbewahrung prüfen lassen. Inhalte hinter demselben externen Datenschutzlink sind nicht versioniert; wesentliche Änderungen müssen auch im gespeicherten Zusatz nachvollziehbar aktualisiert werden. Keine fertigen AVV-/Datenschutzerklärungen oder allgemeine Cookie-Verwaltung.

Orientierung: EDPB, gesetzliche Grundlagen und Anforderungen bei Einwilligung, am 22.11.2026 gelesen: https://www.edpb.europa.eu/sme/be-compliant/process-personal-data-lawfully_en

## Tests und Abnahmegrenzen

`python3 tests/run-wallet-nearby-privacy.py`: 53 HTTP-/Domänen- und 18 Browserprüfungen. Kunden-/Betreiberzuordnung, private Header, CSRF, fehlende/ungültige Betreiberangaben, alte Tabs, standardmäßig aus, separate bewusste Zustimmung, exakte Snapshot-/Exportdaten, Hashwechsel bei Hinweisen und Koordinaten, frisches Lesen nach Widerruf, planfreie Kundenwahl, escaped Zusatztext, keine Saldo-/Journal-/Marketing-/Provideränderung, transaktionale lokale Löschung mit Rollback und Fremddatenschutz. Chromium prüft Betreiber- und Kundenseite auf Desktop und 390px-Mobil, manuelle Textpflege, ungekreuzte Checkbox, Bestätigen und Widerrufen.

`python3 tests/run-wallet-nearby.py` prüft weiter die Standortkonfiguration und Provider-JSON-Felder mit **ausdrücklich im Test gesetzter** Kundenfreigabe. Es ist kein Endnutzer-Umgehungspfad.

Keine externen Anbieteranfragen, kein Signier-/Issuer-/Geräteannahmetest, kein juristischer Audit und kein Penetrationstest. Screenshots belegen lokale Oberflächen, keine echten Wallet-Anzeigen.

Etappenregression: zusätzlich die Standort-Nähe-Suite (51 HTTP-/Domänen- und 12 Browserprüfungen) und alle 42 weiteren Nicht-WordPress-`run-*.py`-Suiten grün. PHP-Lint und `git diff --check` ohne Befund. WordPress-Echtumgebung nicht erneut geprüft.
