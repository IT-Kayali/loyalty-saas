# Wallet-Mitteilungen

Stand: 22.11.2026. Erste Etappe: Betreiberhinweise, Textvorlagen und getrennte freiwillige Kundenwahl. **Noch kein Versandweg.** Das Speichern einer Vorlage oder Wahl ruft keinen Wallet-Anbieter auf und aktualisiert keinen installierten Pass. NFC ist auf Nutzerwunsch nicht vorgesehen.

## Bedienung

- Betreiber: Organisation → beim Unternehmen „Wallet-Mitteilungen: Hinweise & Texte“ (`/business/ID/wallet-push`). Nur dessen Eigentümer kann diese Angaben lesen und mit gültigem Schreibplan ändern.
- Kunden: Mein Wallet → „Wallet-Mitteilungen“ (`/member/wallet-push`). Pro eigener Mitgliedschaft gibt es eine eigene Apple- und Google-Wahl, beide zunächst aus. Keine vorausgewählte Bestätigung.
- Verantwortlicher mit Anschrift, Datenschutzkontakt und vollständiger HTTPS-Datenschutzlink ohne Zugangsdaten sind zum Einschalten nötig. Der Betreiber pflegt diese Angaben selbst. Ein Zusatz ist optional.
- Apple-Text: maximal 240 Zeichen. Google-Titel: maximal 80, Google-Text: maximal 500 Zeichen. Reiner Text, kein HTML, keine leeren Vorlagen. Die Längen sind konservative App-Grenzen, keine behaupteten Provider-Maximalwerte. Keine Variablenersetzung, Übersetzung oder automatische Personalisierung.
- Änderungen an Textvorlagen ändern keine Wahl und versenden nichts. Die Vorlagen sind kein garantierter Sperrbildschirmtext. Zum Zeitpunkt eines späteren Versandwegs müssen Inhalt und Empfänger nochmals ausdrücklich geprüft werden.
- Kunden können je Anbieter ohne erneutes Bestätigen der Hinweise ausschalten, auch ohne bezahlten Betreiberplan. Betreiber können nicht für Kunden zustimmen. E-Mail-Marketing, Wallet-Nähe, Sammeln, Einlösen und der bestehende Kartenstand-Updatepfad bleiben davon unabhängig. Die neue Wahl ist für optionale Betreiber-Mitteilungen, kein globaler Schalter für jede Betriebssystem- oder Kartenstandmeldung.

## Zweck und Datenschutzgrenzen

Die Wahl betrifft wichtige, zeitkritische Informationen zu einer bestehenden Kundenkarte, keine Werbung oder allgemeinen Angebote. Die App kann die rechtliche Einordnung eines frei eingegebenen Textes nicht zuverlässig prüfen. Betreiber dürfen diesen Zweck nicht durch Zusatztexte erweitern oder die festen technischen Hinweise überdecken.

Kunden sehen Verarbeitungszweck, Anbieter, Karten-/Gerätekennungen, mögliche Sichtbarkeit des Textes auf dem Sperrbildschirm, Widerruf und technische Grenzen. Keine vertraulichen Daten in Vorlagen aufnehmen. Die lokalen Betreiberangaben und Wahlmechanismen sind keine juristisch geprüfte Datenschutzvorlage und keine DSGVO-Garantie. Rechtsgrundlage, Minderjährige, Informationspflichten, Anbietertransfers, Verträge und Löschfristen müssen vor Produktivbetrieb fachlich geklärt werden. Die Wahl allein bestätigt keine Erfüllung dieser Anforderungen.

Gespeichert wird die letzte Wahl je Mitglied/Anbieter, eine fortlaufende Auswahlrevision, Bestätigungs-/Widerrufszeit in UTC, Hinweis-Hash und der zuletzt bestätigte volle Hinweisstand. Kein vollständiges Einwilligungsarchiv, keine IP-Sammlung. Der Kundenexport enthält beide Anbieterwahlen und die jeweils aktuellen/zuletzt bestätigten Hinweise. Lokales Löschen des Mitgliedskontos entfernt diese Wahlzeilen per Fremdschlüssel. Weder Ausschalten noch Löschen ruft bereits übermittelte Texte zurück, entfernt Gerätekopien oder ändert Anbieterberechtigungen.

Betreiberhinweise haben eine fortlaufende Revision. Geänderte Angaben oder ein anderer Unternehmensname sperren bisherige Zustimmungen für künftige optionale Mitteilungen, bis neu bestätigt wurde. Auch Rückkehr zu früheren Angaben oder zum früheren Namen reaktiviert nichts. Neue Texte innerhalb desselben Zwecks erfordern dagegen keine neue Hinweisbestätigung. Änderung des festen technischen Zwecks erfordert eine neue `WALLET_PUSH_CORE`-Version. Alte Einschaltformulare können eine inzwischen widerrufene Wahl nicht wieder einschalten. Das Ausschalten bleibt absichtlich auch aus einem älteren Formular möglich.

## Provider sind unterschiedlich

### Apple

APNs meldet einem registrierten Gerät mit leerem Payload nur, dass ein Pass geändert wurde. Das Gerät ruft den aktuellen Pass ab und vergleicht seine eigene Kopie. Ein geändertes Feld mit `changeMessage` kann eine Änderungsmitteilung auslösen. Das ist keine frei adressierbare Marketing-Push-Schnittstelle. Apple empfiehlt solche unterbrechenden Mitteilungen für wichtige zeitkritische Änderungen. Gleicher Text, zusammengefasste Updates, fehlende Registrierung, Geräteeinstellungen und nicht abgeholte Passversionen können eine sichtbare Meldung verhindern. Der vorhandene Kontostand hat schon eine eigene Änderungsmitteilung. Eine neue Vorlage allein wird nicht in den Pass eingebaut.

Echter Nachweis erfordert gültigen signierten installierten Pass, HTTPS-Passdienst, passende APNs-Konfiguration und ein echtes Gerät. Der Simulator registriert sich laut Apple nicht für Pass-Push. Der bestehende APNs-Helfer liefert noch keinen belastbaren Ergebnisbericht; die Versandetappe muss das gesondert behandeln.

### Google

Für optionale Nachrichten ist der objektbezogene `genericObject/{resourceId}/addMessage`-Aufruf vorgesehen, mit einer `message`, deren `messageType` `TEXT_AND_NOTIFY` ist. Das legt einen Text in den Passdetails ab und kann benachrichtigen. Kein Klassen-Broadcast: Eine einzelne Kundenwahl darf nicht als Freigabe für alle Karten einer Klasse behandelt werden.

Google erlaubt höchstens drei benachrichtigende Nachrichten pro Pass in 24 Stunden und kann zusätzlich drosseln. Benachrichtigungen müssen am Gerät erlaubt sein. Google bestimmt den Sperrbildschirmtext. Ein vorhandenes `UPDATE` eines Objekts ist kein belegter Versand einer solchen Nachricht. Auch eine erfolgreiche API-Antwort beweist keinen Empfang. Nachrichtendaten können separat geändert/entfernt werden; ohne Endzeit bleiben sie laut Schema in der Liste, bis sie entfernt werden. Ein künftiger Versandweg muss deshalb begrenzte Sichtbarkeit, bestehende Nachrichtendaten und unklare Ergebnisse behandeln.

## Technik und Prüfung

- Einstellungen in `businesses.wallet_push_settings`, Hinweisrevision in `businesses.wallet_push_notice_revision`.
- Wahl in `wallet_push_choices`, Primärschlüssel `(member_id, provider)`, Mitglied-Fremdschlüssel mit `ON DELETE CASCADE`, Anbieter/Boolean-Prüfungen.
- App-Verbindung sperrt direkte INSERT-/UPDATE-Umgehungen der Wahlroutine per TEMP-Trigger. Kein Ersatz für Dateisystem-/Adminschutz der Datenbank.
- Wahlroutine liest Mitglied und aktuelle Hinweise unter SQLite-Schreibsperre neu, prüft Mitglieds-E-Mail, ausdrückliche Bestätigung, Hinweis-Hash und Auswahlrevision. Berechtigungsprüfung liest die Wahl frisch. Ausschalten braucht keine gültige alte Zustimmung und keinen Betreiberplan.
- Betreiberänderung bindet den Formularstand an die aktuellen Einstellungen; Schreiben und ID-basiertes Audit sind atomar. Keine Vorlagentexte oder Kundenkennungen im Einstellungs-Audit.
- Alle neuen Seiten sind authentifiziert, `private, no-store` und `no-referrer`; POST braucht den globalen Session-CSRF-Schutz.
- `PHP_BIN=$HOME/phpbin/php NODE_PATH=$HOME/pw/node_modules python3 tests/run-wallet-push.py`: HTTP-/Domänen- und Desktop-/Mobil-Prüfungen. Ausschließlich isolierte Testdaten, keine realen Providerkontakte.

## Noch offen für diese Funktion

Empfänger-/Textvorschau, ausdrücklicher Versandabschluss, objektbezogene Provideradapter mit verwertbaren Ergebnissen, Wiederholungs-/Quota-Schutz, begrenzte Nachrichtensichtbarkeit, frische Wahlprüfung am eigentlichen Versandzeitpunkt und Abnahme mit echten Zertifikaten/Konten/Geräten. Diese Punkte sind nicht durch das Speichern von Vorlagen erledigt.

## Quellen

Am 22.11.2026 geprüft. Für API-Verhalten sind Providerdokumente maßgeblich, für die Auswahlmechanik wurde die EDPB-Orientierung genutzt. Das ist eine technische Implementierungsgrundlage, kein Rechtsgutachten.

- Apple, Passaktualisierung und Änderungsmitteilungen: https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Updating.html
- Google, Mitteilungen, Gerätefreigaben und Drei-pro-24-Stunden-Grenze: https://developers.google.com/wallet/generic/use-cases/trigger-push-notifications
- Google, objektbezogener Endpunkt: https://developers.google.com/wallet/reference/rest/v1/genericobject/addmessage
- Google, Message-Schema und Sichtbarkeitsintervall: https://developers.google.com/wallet/reference/rest/v1/Message
- EDPB, Zweckbindung der Zustimmung, freie aktive Wahl und einfacher Widerruf: https://www.edpb.europa.eu/sme/be-compliant/process-personal-data-lawfully_en

Lokaler Stand dieser Etappe: 65 HTTP-/Domänen- und 18 Chromium-Prüfungen grün. 48 weitere Nicht-WordPress-Suiten grün; die Umbenennungsprüfung berücksichtigt die bewusst neue Hinweisrevision. PHP-Lint und `git diff --check` grün. Texteditoren und Kundenwahl auf Desktop/Mobil visuell geprüft. Kein Provider-/Gerätenachweis.
