# Wallet-Mitteilungen

Stand: 22.11.2026. Betreiberhinweise, Textvorlagen, getrennte freiwillige Kundenwahl und ausdrücklich bestätigter Einzelpass-Versandweg. Der optionale Versand ist standardmäßig serverseitig ausgeschaltet. Das Speichern einer Vorlage oder Wahl ruft keinen Wallet-Anbieter auf und aktualisiert keinen installierten Pass. NFC ist auf Nutzerwunsch nicht vorgesehen.

## Bedienung

- Betreiber: Organisation → beim Unternehmen „Wallet-Mitteilungen: Hinweise & Texte“ (`/business/ID/wallet-push`). Nur dessen Eigentümer kann diese Angaben lesen und mit gültigem Schreibplan ändern.
- Kunden: Mein Wallet → „Wallet-Mitteilungen“ (`/member/wallet-push`). Pro eigener Mitgliedschaft gibt es eine eigene Apple- und Google-Wahl, beide zunächst aus. Keine vorausgewählte Bestätigung.
- Verantwortlicher mit Anschrift, Datenschutzkontakt und vollständiger HTTPS-Datenschutzlink ohne Zugangsdaten sind zum Einschalten nötig. Der Betreiber pflegt diese Angaben selbst. Ein Zusatz ist optional.
- Apple-Text: maximal 240 Zeichen. Google-Titel: maximal 80, Google-Text: maximal 500 Zeichen. Reiner Text, kein HTML, keine leeren Vorlagen. Die Längen sind konservative App-Grenzen, keine behaupteten Provider-Maximalwerte. Keine Variablenersetzung, Übersetzung oder automatische Personalisierung.
- Änderungen an Textvorlagen ändern keine Wahl und versenden nichts. Die Vorlagen sind kein garantierter Sperrbildschirmtext. Für einen Versand müssen Inhalt und Empfänger in der getrennten Einzelmitteilungsansicht nochmals ausdrücklich geprüft werden.
- Kunden können je Anbieter ohne erneutes Bestätigen der Hinweise ausschalten, auch ohne bezahlten Betreiberplan. Betreiber können nicht für Kunden zustimmen. E-Mail-Marketing, Wallet-Nähe, Sammeln, Einlösen und der bestehende Kartenstand-Updatepfad bleiben davon unabhängig. Die neue Wahl ist für optionale Betreiber-Mitteilungen, kein globaler Schalter für jede Betriebssystem- oder Kartenstandmeldung.

## Zweck und Datenschutzgrenzen

Die Wahl betrifft wichtige, zeitkritische Informationen zu einer bestehenden Kundenkarte, keine Werbung oder allgemeinen Angebote. Die App kann die rechtliche Einordnung eines frei eingegebenen Textes nicht zuverlässig prüfen. Betreiber dürfen diesen Zweck nicht durch Zusatztexte erweitern oder die festen technischen Hinweise überdecken.

Kunden sehen Verarbeitungszweck, Anbieter, Karten-/Gerätekennungen, mögliche Sichtbarkeit des Textes auf dem Sperrbildschirm, Widerruf und technische Grenzen. Keine vertraulichen Daten in Vorlagen aufnehmen. Die lokalen Betreiberangaben und Wahlmechanismen sind keine juristisch geprüfte Datenschutzvorlage und keine DSGVO-Garantie. Rechtsgrundlage, Minderjährige, Informationspflichten, Anbietertransfers, Verträge und Löschfristen müssen vor Produktivbetrieb fachlich geklärt werden. Die Wahl allein bestätigt keine Erfüllung dieser Anforderungen.

Gespeichert wird die letzte Wahl je Mitglied/Anbieter, eine fortlaufende Auswahlrevision, Bestätigungs-/Widerrufszeit in UTC, Hinweis-Hash und der zuletzt bestätigte volle Hinweisstand. Kein vollständiges Einwilligungsarchiv, keine IP-Sammlung. Der Kundenexport enthält beide Anbieterwahlen und die jeweils aktuellen/zuletzt bestätigten Hinweise. Lokales Löschen des Mitgliedskontos entfernt diese Wahlzeilen per Fremdschlüssel. Weder Ausschalten noch Löschen ruft bereits übermittelte Texte zurück, entfernt Gerätekopien oder ändert Anbieterberechtigungen.

Betreiberhinweise haben eine fortlaufende Revision. Geänderte Angaben oder ein anderer Unternehmensname sperren bisherige Zustimmungen für künftige optionale Mitteilungen, bis neu bestätigt wurde. Auch Rückkehr zu früheren Angaben oder zum früheren Namen reaktiviert nichts. Neue Texte innerhalb desselben Zwecks erfordern dagegen keine neue Hinweisbestätigung. Änderung des festen technischen Zwecks erfordert eine neue `WALLET_PUSH_CORE`-Version. Alte Einschaltformulare können eine inzwischen widerrufene Wahl nicht wieder einschalten. Das Ausschalten bleibt absichtlich auch aus einem älteren Formular möglich.

## Provider sind unterschiedlich

### Apple

APNs meldet einem registrierten Gerät mit leerem Payload nur, dass ein Pass geändert wurde. Das Gerät ruft den aktuellen Pass ab und vergleicht seine eigene Kopie. Ein geändertes Feld mit `changeMessage` kann eine Änderungsmitteilung auslösen. Das ist keine frei adressierbare Marketing-Push-Schnittstelle. Apple empfiehlt solche unterbrechenden Mitteilungen für wichtige zeitkritische Änderungen. Gleicher Text, zusammengefasste Updates, fehlende Registrierung, Geräteeinstellungen und nicht abgeholte Passversionen können eine sichtbare Meldung verhindern. Der vorhandene Kontostand hat schon eine eigene Änderungsmitteilung. Eine neue Vorlage allein wird nicht in den Pass eingebaut. Ein ausdrücklich bestätigter Versand übernimmt den Text in ein stabiles Rückseitenfeld `issuer_message`, sofern die Kundenwahl aktuell ist. Das Feld steht schon vor einer ersten Nachricht im neuen Pass. Alte installierte Pässe ohne dieses Feld können beim ersten Update anders reagieren.

Echter Nachweis erfordert gültigen signierten installierten Pass, HTTPS-Passdienst, passende APNs-Konfiguration und ein echtes Gerät. Der Simulator registriert sich laut Apple nicht für Pass-Push. Der bestehende Kartenstand-Helfer ist ein eigener, bisheriger APNs-Pfad. Der neue optionale Versand nutzt das Passzertifikat und dessen privaten Schlüssel für HTTP/2 gegen die Produktions-APNs-Umgebung, leeres JSON, maximal zehn registrierte Geräte parallel und getrennte technische Ergebnisklassen. Ungültige Tokens werden nur für die angesprochene Passregistrierung entfernt. Der neue Pfad verwendet keinen Sandbox-Server und benötigt keinen zusätzlichen p8-Schlüssel. Ein APNs-200 bestätigt keinen Geräteabruf oder sichtbaren Text.

### Google

Für optionale Nachrichten ist der objektbezogene `genericObject/{resourceId}/addMessage`-Aufruf vorgesehen, mit einer `message`, deren `messageType` `TEXT_AND_NOTIFY` ist. Das legt einen Text in den Passdetails ab und kann benachrichtigen. Kein Klassen-Broadcast: Eine einzelne Kundenwahl darf nicht als Freigabe für alle Karten einer Klasse behandelt werden.

Google erlaubt höchstens drei benachrichtigende Nachrichten pro Pass in 24 Stunden und kann zusätzlich drosseln. Benachrichtigungen müssen am Gerät erlaubt sein. Google bestimmt den Sperrbildschirmtext. Ein vorhandenes `UPDATE` eines Objekts ist kein belegter Versand einer solchen Nachricht. Auch eine erfolgreiche API-Antwort beweist keinen Empfang. Nachrichtendaten können separat geändert/entfernt werden; ohne Endzeit bleiben sie laut Schema in der Liste, bis sie entfernt werden. Der Einzelversand setzt deshalb eine Endzeit, liest das vorhandene Objekt und dessen `hasUsers`, lässt bestehende Nachrichten unverändert und bricht bei zehn vorhandenen Nachrichten ab. Er legt weder Objekt noch Klasse an. Unklare Ergebnisse werden nicht automatisch wiederholt. Der Kartenstand-Updatepfad verwendet jetzt `PATCH` statt vollständigem `PUT`, damit parallel vorhandene Nachrichten nicht gelöscht werden. Ein entferntes lokales Logo/Bild wird beim nächsten Kartenstand-Update ausdrücklich mit JSON-`null` aus dem Objekt entfernt. Andere nicht übermittelte Felder bleiben unverändert. Das ist lokal am PATCH-Payload geprüft, noch nicht am realen Provider/Gerät. GenericClass enthält nur die dokumentierte neutrale Klassen-ID; Typ, Farben, Name und Bilder gehören ausschließlich ins individuelle GenericObject. Bestehende Klassen-/Objekt-IDs ändern sich nicht.

## Technik und Prüfung

- Einstellungen in `businesses.wallet_push_settings`, Hinweisrevision in `businesses.wallet_push_notice_revision`.
- Wahl in `wallet_push_choices`, Primärschlüssel `(member_id, provider)`, Mitglied-Fremdschlüssel mit `ON DELETE CASCADE`, Anbieter/Boolean-Prüfungen.
- App-Verbindung sperrt direkte INSERT-/UPDATE-Umgehungen der Wahlroutine per TEMP-Trigger. Kein Ersatz für Dateisystem-/Adminschutz der Datenbank.
- Wahlroutine liest Mitglied und aktuelle Hinweise unter SQLite-Schreibsperre neu, prüft Mitglieds-E-Mail, ausdrückliche Bestätigung, Hinweis-Hash und Auswahlrevision. Berechtigungsprüfung liest die Wahl frisch. Ausschalten braucht keine gültige alte Zustimmung und keinen Betreiberplan.
- Betreiberänderung bindet den Formularstand an die aktuellen Einstellungen; Schreiben und ID-basiertes Audit sind atomar. Keine Vorlagentexte oder Kundenkennungen im Einstellungs-Audit.
- Alle neuen Seiten sind authentifiziert, `private, no-store` und `no-referrer`; POST braucht den globalen Session-CSRF-Schutz.
- `PHP_BIN=$HOME/phpbin/php NODE_PATH=$HOME/pw/node_modules python3 tests/run-wallet-push.py`: HTTP-/Domänen- und Desktop-/Mobil-Prüfungen. Ausschließlich isolierte Testdaten, keine realen Providerkontakte.

## Einzelpass prüfen und ausdrücklich übergeben

Betreiber: Mitgliedsseite → „Wallet-Mitteilung für dieses Mitglied prüfen“ oder Hinweise & Textvorlagen → „Einzelne Mitteilung prüfen“. `/business/ID/wallet-message` ist nur für den Eigentümer zugänglich, private/no-store/no-referrer. Kein vorgewählter Empfänger oder Anbieter. Die Auswahl listet höchstens 200 Mitglieder; weitere lassen sich über deren private Mitgliedsseite öffnen.

1. Mitglied und Anbieter ausdrücklich wählen, Vorlage laden, Text bearbeiten. Die Auswahl und Vorschau rufen keinen Provider auf.
2. Vorschau zeigt konkreten Mitgliedspass mit Name/E-Mail, Anbieter/Konto, exaktem Text, Gültigkeit und jeweiligen Grenzen. Alle Personen/Geräte, die denselben Pass besitzen, können die Nachricht sehen. Das ist keine Garantie, dass ein bestimmtes Gerät oder Konto dieser E-Mail zugeordnet ist.
3. Zwei nicht vorausgewählte Bestätigungen für Empfänger/Text und den wichtigen zeitkritischen Zweck sind erforderlich. Erst „Diesen einen Pass jetzt benachrichtigen“ kann einen Übergabeversuch beginnen. Kein Massen-/Klassenversand.
4. Ohne `wallet_messages.enabled=true`, HTTPS-`base_url` und erforderliche Providerkonfiguration bleibt der Abschluss gesperrt, auch bei manipuliertem Formular. Keine echten Zertifikate oder Konten werden durch die lokalen Tests ersetzt.

Die Sitzung hält höchstens zehn Vorschauen, jeweils zehn Minuten. Der Server übernimmt beim Abschluss ausschließlich den gespeicherten Prüfinhalt, nicht nachträglich übermittelte Text-/Empfängerfelder. Unter SQLite-Schreibsperre werden Eigentümer, Schreibplan, aktuelle Kundenwahl samt Revision, Empfängeridentität, Wallet-Konto samt Konfigurations-/Zertifikatsprüfstand, Einstellungen und Ablauf erneut geprüft. Reservierung und ID-basiertes Audit sind atomar. Eine eindeutige Vorschau-ID verhindert doppelte Übergabe beim Wiederholen derselben Anfrage. Ein neuer Versuch mit identischem Text wird 24 Stunden blockiert. Die App begrenzt beide Anbieter konservativ auf drei Versuche je Mitglied/Anbieter in rollenden 24 Stunden, einschließlich Ablehnung und unklarem Ausgang. Google kann unabhängig strenger begrenzen. Vorschau und Abschluss binden außerdem den konkreten Wallet-Konfigurationsstand. Bei Konto-/Zertifikatswechsel ist neue Prüfung nötig. Der Google-Zugriffstoken-Cache ist an den Hash der aktuellen Service-Account-Datei gebunden; ein altes oder ungebundenes Cache-Token wird nicht als neues Konto verwendet und neue Cachedateien werden privat atomar geschrieben.

Provideraufrufe laufen erst nach Reservierung außerhalb der Datenbanksperre. Vor dem eigentlichen Google-Nachrichtenaufruf und dem Apple-Signal wird die Wahl nochmals frisch geprüft. Ein schon laufender externer Aufruf kann dennoch nicht zurückgerufen werden. Ein Prozessabbruch nach Übergabestart bleibt „Ergebnis offen“; keine automatische Wiederholung und kein angeblicher Empfangsnachweis. Neue Versuche nach Fehlern erfordern eine neue ausdrückliche Betreiberentscheidung innerhalb der Schutzgrenzen, nicht bloß erneutes Laden.

Apple muss die neue Passinformation vor dem Updatesignal bereitstellen. Deshalb kann der Text beim nächsten Passabruf sichtbar sein, selbst wenn das Updatesignal abgelehnt wurde oder dessen Ergebnis unklar ist. `published` bedeutet in diesem Protokoll nur serverseitig zum Abruf bereitgestellt. Es bedeutet keinen Geräteempfang. Ablauf, Widerruf oder neue Hinweis-/Wahlrevision entfernen alte Mitteilungstexte aus künftigen Payloads; keine Rückruf- oder Fernlöschgarantie für vorhandene Kopien. Eine erneute Zustimmung veröffentlicht nicht die Nachricht einer früheren Wahlrevision.

## Ergebnis, Daten und Bereinigung

Die letzten 20 Übergabeversuche sind privat beim Eigentümer sichtbar. Zustände unterscheiden Übergabestart/offenes Ergebnis, technische Annahme, Teilannahme, Ablehnung und unklaren Ausgang. Nur freigegebene technische Kategorien werden gespeichert, keine rohen Providerantworten, Exceptions, Zugangstokens oder Gerätekennungen. Der Kundenexport enthält die eigenen gespeicherten Versuchstexte und Zustände. Mitglieder-/Unternehmenslöschung entfernt die lokalen Zeilen per Fremdschlüssel, nicht externe Passkopien oder Google-Nachrichten.

`wallet_push_deliveries` bindet Eigentümer, Unternehmen und Mitglied durch Datenbanktrigger aneinander; Identität und übergebener Inhalt sind nach Anlage unveränderlich. Der feste Hinweisstand ist wegen dieser zusätzlichen Protokollierung jetzt `wallet-push-v2`, ältere Zustimmung gilt nicht stillschweigend weiter.

Bereinigung als Serverbetreiber:

```bash
php tools/wallet-message-cleanup.php             # nur zählen, keine Änderung
php tools/wallet-message-cleanup.php --execute   # ältere abgelaufene Einträge lokal löschen
```

Es werden nur Zeilen entfernt, deren Erstellung länger als 30 Tage zurückliegt und deren Mitteilung abgelaufen ist. Dadurch bleibt der 24-Stunden-Schutzverlauf erhalten. Für eine eingehaltene Frist muss der Serverbetreiber diesen Job regelmäßig selbst einrichten; hier wurde kein Hosting/Cron geändert. Ohne laufenden Job keine Löschfristgarantie. Der Job löscht keine Anbieter-/Gerätekopien und verändert keine Guthaben oder Journalaktionen. Kein vollständiges rechtliches Aufbewahrungs-/Löschkonzept.

## Noch offen für echte Abnahme

Gültige Apple-Zertifikate und Google-Issuer-/Service-Account-Berechtigungen, HTTPS und echte Passinstallationen auf physischen Geräten müssen eingerichtet und getrennt abgenommen werden. Zu prüfen sind erster Apple-Feldwechsel auf Altpass, gebündelte Feldänderungen, Geräteeinstellungen, Zertifikatserneuerung, APNs-Antworten, Google-Quoten und Remote-Entfernen alter optionaler Bilder. Keine reale Provider-/Gerätenachricht wurde in den automatisierten Tests versandt. Keine Massenkampagnen, Terminplanung, wiederholenden Versandjobs oder NFC.

## Quellen

Am 22.11.2026 geprüft. Für API-Verhalten sind Providerdokumente maßgeblich, für die Auswahlmechanik wurde die EDPB-Orientierung genutzt. Das ist eine technische Implementierungsgrundlage, kein Rechtsgutachten.

- Apple, Passaktualisierung und Änderungsmitteilungen: https://developer.apple.com/library/archive/documentation/UserExperience/Conceptual/PassKit_PG/Updating.html
- Google, Mitteilungen, Gerätefreigaben und Drei-pro-24-Stunden-Grenze: https://developers.google.com/wallet/generic/use-cases/trigger-push-notifications
- Google, objektbezogener Endpunkt: https://developers.google.com/wallet/reference/rest/v1/genericobject/addmessage
- Google, Message-Schema und Sichtbarkeitsintervall: https://developers.google.com/wallet/reference/rest/v1/Message
- Google, GenericClass-Schema: https://developers.google.com/wallet/reference/rest/v1/genericclass
- Google, PATCH und explizites Entfernen mit JSON-null: https://developers.google.com/wallet/generic/resources/performance-tips
- Google, Teilaktualisierungen ohne Überschreiben ausgelassener Felder: https://developers.google.com/wallet/generic/use-cases/updates
- EDPB, Zweckbindung der Zustimmung, freie aktive Wahl und einfacher Widerruf: https://www.edpb.europa.eu/sme/be-compliant/process-personal-data-lawfully_en

Die erste Wahl-/Vorlagenetappe bestand 65 HTTP-/Domänen- und 18 Chromium-Prüfungen plus 48 weitere Nicht-WordPress-Suiten. Der neue Einzelversand bestand 61 zusätzliche Domänen- und 23 Chromium-Prüfungen mit `tests/run-wallet-message.py` und `tests/wallet-message-ui.cjs`, dazu CLI-Bereinigung in isolierten Testdaten. Alle 49 weiteren Nicht-WordPress-Suiten wurden mit dem neuen Stand erneut erfolgreich ausgeführt, einschließlich der Wahl-/Vorlagenprüfungen. Providerergebnisse werden ausschließlich mit lokalen Testtransporten simuliert. Kein Provider-/Gerätenachweis.

Zusätzlich geprüft: aktueller Apple-Passdienst mit Produktions-/Zertifikatshinweisen https://developer.apple.com/documentation/walletpasses/adding-a-web-service-to-update-passes und Google-Objekt mit `hasUsers`/Nachrichtenlimit https://developers.google.com/wallet/reference/rest/v1/genericobject .
