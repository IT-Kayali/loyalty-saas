# Funktionsabgleich mit Reward Loyalty 5.x

Stand: 21.11.2026. Referenz: https://rewardloyalty.co/demo und https://rewardloyalty.co/docs/5.x.

## Ergebnis

Diese PHP/SQLite-Anwendung ist **kein vollständiger Nachbau aller aktuellen Reward-Loyalty-Funktionen**. Frühere Formulierungen wie „Vollversion“ bezeichneten unseren damaligen Lieferstand, nicht nachgewiesene Parität. Der Abgleich berücksichtigt den heutigen 5.x-Dokumentationsindex. 250 Dokumentationsadressen wurden erfasst, 133 waren lesbar; 117 Detailseiten lieferten eine Zugriffssperre. Die zugehörigen Punkte bleiben dort auf Index-Ebene geprüft, nicht im Detail bestätigt. Die Demo-Einstiegsseite bestätigt vier getrennte Rollenbereiche, nicht die korrekte Implementierung jedes Workflows.

Status: **Basis** = wesentlicher lokaler Workflow vorhanden, aber nicht jedes Originaldetail; **Neu** = in dieser Ausbaustufe ergänzt; **Offen** = nicht implementiert; **Setup** = Code vorhanden, externe Konten/Schlüssel und Ende-zu-Ende-Prüfung fehlen. Vorhanden ist keine Aussage über einen abgeschlossenen Produktionsaudit.

## In dieser Ausbaustufe ergänzt

1. SaaS-Stripe-Abrechnung: Pläne aus Serverkonfiguration, Checkout, Kundenportal, Abozustände, Kündigungsanzeige, signierte und deduplizierte Webhooks, Idempotency, optionale Unternehmenslimits.
2. Sicherer eigener Stempel-Bildupload mit Bilddekodierung und PNG-Neukodierung. Kundenkarte, Designer und Wallet-Bilddaten verwenden das Bild. Live-Vorschau für Farben, Text und Upload.
3. Separates Plattform-Admin-Dashboard: globale Kennzahlen, Betreiber/Unternehmen und Abostatus, Grund-Systemchecks, Transaktions-CSV, Plattformname, Registrierungssteuerung und Basis-Audit-Log. Admins werden explizit konfiguriert.
4. Responsive-Korrekturen an Navigation, Designer, Formularen und Tabellen. Browserprüfungen für 390px-Mobilansichten.
5. Automatisierte Stripe-Zustands-/Signaturtests und Browser-Regressionsprüfungen.

## Funktionsmatrix

| Bereich | Lokal | Noch nicht gleichwertig / nächste Schritte |
|---|---|---|
| Plattform, Mandanten, Betreiberregistrierung | Basis | Neu: Netzwerke als Gruppierung eigener Unternehmen und Standorte zur Buchungszuordnung. Keine geteilten Salden, Clubs oder fremden Betreiber. |
| Plattform-Admin | Neu, Basis | Globale Übersicht, Brandingname, Registrierungsschalter, CSV und Healthchecks vorhanden. Keine Managerdelegation, granularen Plattformrechte, Unternehmens-Sperrung oder Admin-Impersonation. |
| Betreiber-Dashboard | Basis | Programme, Mitglieder, Designer, Analytics, Team. Neu: Standort- und Teamverwaltung. Keine vollständigen Unternehmenseinstellungen oder monatlichen Zusammenfassungen. |
| Staff-Kasse | Basis | PIN-Login, Suche, QR-Scan, vergeben/einlösen, Undo. Neu: Viewer/Kasse/Schichtleitung mit Einzelrechten, Standortbindung, Deaktivierung und PIN-Reset mit Sitzungswiderruf. Keine Clubrechte, Managerkonten, Einladungen oder MFA. |
| Mitglieder-Wallet | Basis | Login, mehrere Unternehmen, Karten, Inbox, Export/Löschung. Neu: eigenes Wallet-Passwort mit bisherigem Passwort ändern und alle Sitzungen abmelden (MEMBER-PASSWORD.md). Neu: eigener Name getrennt je Mitgliedseintrag editierbar (MEMBER-PROFILE.md), keine Änderung von E-Mail/Adresse. Kein OTP-Login, anonyme Mitglieder, umfassender Profileditor, vollständige PWA-Details oder Live-Event-Updates. Offline-Basis mit Opt-in ergänzt, siehe PWA-Nachtrag. |
| Punkte & Prämien | Basis | Punkte, Prämien, Lifetime-Punkte, Stufen und Bonusstunden. Neu: Punkteverfall bei Inaktivität (Cron oder Button, siehe POINTS-EXPIRY.md), kein Verfall einzelner Pakete und keine Erinnerungsmail. Neu: Punkteanfragen zwischen Mitgliedern per Link, opt-in je Programm (POINT-REQUESTS.md), mit Tageslimits je Programm (Senden, Empfangen, Anzahl), ohne Missbrauchserkennung über Konten hinweg. Neu: individuelle, zeitlich begrenzte Einlösecodes mit QR je Prämie, opt-in je Programm (REWARD-CODES.md), mit optionalem Zeitfenster je Prämie (Codes, Einlösen, API nur darin, ohne Serientermine; Zeitzone je Unternehmen siehe TIMEZONE.md, gilt nur dafür, sonst UTC), ohne Punktereservierung, mit Anbindung an den Personal-Scanner (Scan öffnet nur Vorschau) und mobiler Code-Historie ohne seitliches Scrollen, ohne Test mit echten QR-Scannern. |
| Stempelkarten | Basis + Neu | Stempel/Rollover/Belohnungen, eigener Bildupload, Vorschau. Neu: eigenes Programmdesign, Hintergrundfoto/Deckkraft und kuratierte Emoji-Auswahl. Nativer Wallet-Pass bleibt ein Sammelpass, Fotos/Emoji nur im Web. |
| Gutscheine | Basis | Fester Wert und begrenzte Nutzungen. Neu: Code-Batches, Prozent/Festbetrag/Produkt/Punkte, einfache Eligibility und Limits. Geburtstags-/Winback-Gutscheine nur über die Mail-Automation (LIFECYCLE-VOUCHERS.md). Neu: Batchstorno und Wiederherstellen mit Audit (PROGRAMS.md), ohne Rücknahme alter Einlösungen oder Mitgliederbenachrichtigung. Claim-QR und eigene Analysen fehlen. |
| Prepaid | Basis | Neu: getrennt verkaufte Besuchs-/unbegrenzte Zeitpässe, Snapshots, Ablaufprüfung, Ledger/CSV und Storno. Geldguthaben, Refunds, Kauf-QR und Reminder fehlen. |
| Achievements | Basis | Schwellen/Metriken und Punktebonus. Keine wöchentlichen Läufe und vollständige Reward-/Limitkonfiguration. |
| Empfehlungen | Basis | Empfehlungslink, Prämien für Werber/Geworbenen. Kein detailliertes Missbrauchs-/Kampagnenmanagement. |
| Segmente | Basis | Gespeicherte regelbasierte Zielgruppen. Kein vollständiger verschachtelter Segment-Builder. |
| Kampagnen | Basis | Segmentauswahl, Inbox und vorhandener optionaler Mailpfad. Die SMTP-Konfiguration allein ist kein Nachweis einer produktionsreifen SMTP-Zustellung; Neu: TLS-SMTP, Queue/Worker und Abmeldung. Echte Providerzustellung, Bounces und Double-Opt-In fehlen. |
| Automatische Nachrichten | Offen | Neu: Welcome/Birthday/Winback-Textregeln mit Deduplizierung. Neu: optional persönlicher Einmal-Gutschein je Mail (Geburtstag/Winback/Willkommen, siehe LIFECYCLE-VOUCHERS.md), per E-Mail und/oder Wallet-Inbox (Inbox ohne SMTP, Cron-Worker nötig), ohne echte Zustellabnahme. Kein vollständiger Lifecycle-Katalog. |
| Karten-/QR-Studio | Basis | QR für Join/Staff/Karten, Logo und Druck/PNG. Keine trackbaren Kartenbatches, Batchinventar oder Hardwareverwaltung. |
| Apple Wallet | Setup + Neu | Passsignatur, PassKit-Webservice und APNs-Code vorhanden, Stempelbild als strip.png. Apple-Zertifikate/Konto fehlen. Geräteprüfung und echte Updates nicht abgenommen. |
| Google Wallet | Setup + Neu | JWT/API-Code, Stempelgrafik als GenericObject heroImage. Issuer/Service-Account fehlen, keine echte Geräteprüfung. Google steuert die tatsächliche Darstellung. |
| WooCommerce | Basis/Setup | Plugin und Bestellpunktpfad. Nicht gegen einen echten Shop Ende-zu-Ende abgenommen. |
| Shopify | Basis/Setup | HMAC-Webhook und Anleitung. Kein vollständiges Widget, OAuth-App-Lifecycle oder Shop-UI. |
| POS | Basis | Generische REST-Schnittstelle. Kein vollständiger Original-POS-Katalog oder fertige Kassengeräteanbindung. |
| Ausgehende Webhooks | Basis | HTTPS/öffentliche IPs, HMAC und Zustellprotokoll. Neu: Backoff-Retries (6 Versuche, per Cron), Status failed/dead, Wiederholung durch Owner und Admin (WEBHOOK-RETRIES.md). Zustellungs-Sperre gegen parallele Läufe, Hinweis (App und Mail) für Admin und Owner bei dead. Owner-Mail je Unternehmen abschaltbar, App-Hinweis bleibt aktiv. Keine echte Webhook-Queue und keine Auto-Deaktivierung. |
| REST API | Basis | Business-API-Key, Mitglieder/Karten/Bestellungen/Journal und OpenAPI. Kein gleichwertiges Session-Token-Frontend-API. |
| Agent API | Teilweise | MCP-Schlüssel mit Stufe, Unternehmensbindung und fünf Werkzeuggruppen (AI.md), Idempotenz und Freigaben vorhanden. Umfang bestehender aktiver Schlüssel editierbar (offene Freigaben verfallen). HTTP-Anfragelimit je Schlüssel 1 bis 60/Minute (Batch zählt als eine Anfrage), keine Mengen-/Betragsbudgets. Keine Rechte je Objekt, kein OAuth. Vorhandene REST API nicht als gleichwertig ausgeben. |
| AI/MCP | Neu, Basis | MCP über HTTPS (JSON-RPC) mit Bearer-Schlüsseln, 12 Werkzeuge plus 2 freigabepflichtige, Freigabe in der App, Idempotenz, Audit. Kein OAuth/Browser-Login, keine Tool-Discovery-REST, keine Admin-/Mitglieder-Werkzeuge, nicht mit echten Clients getestet. Siehe AI.md. |
| Branchen-Vorlagen | Neu, Basis | 21 Vorlagen (Stempel, Punkte, Mehrfachkarte) mit Prämien, Stufen, Bonus-Zeiten und Design, Übernahme per Klick (docs/TEMPLATES.md). Beträge sind Vorschläge, nur Deutsch, kein eigener Vorlagen-Editor, nicht im MCP. |
| Stripe | Neu/Setup | Checkout, Portal, signierte Webhooks, Zustände und neue-Unternehmen-Limit. Preise/Konto/Keys und echter Testkauf fehlen. Zentrale Schreibprüfung und Limits jetzt vorhanden, noch keine editierbare vollständige Planfeature-Matrix. |
| PayPal | Neu/Setup | SDK-v6-SaaS-Abos, verifizierte Webhooks, Statusabgleich und explizite Kündigung. Keine echte Sandbox-/Live-Abnahme, Proration oder vollständiger Planwechsel-/Reconciliation-Workflow. |
| Pläne verwalten | Basis | Serverkonfiguration statt editierbarer Admin-Planverwaltung; keine Planmigrationen/Feature-Toggles pro Mandant. |
| Branding/Homepages | Basis | Plattformname, Business-Farben/-Logo, Designer. Neu: gestaltete Webkarte mit Vorder- und Rückseite, Hintergrundbild mit Overlay und positionierbaren Elementen inkl. QR (CARD-DESIGNER.md), sichere Rückseiten-Defaults, Textschutz vor QR-Überdeckung und Live-Warnung im Designer (JavaScript; Stempel/QR werden nicht automatisch verschoben), nur für die Webkarte, nicht für Apple-/Google-Wallet-Pässe. Keine Smart/Showcase/Portal/Public-Listing-Homepages, eigenen Domains oder vollständigen E-Mail-Templates. |
| Sprachen | Basis | DE/EN für bisherigen Bestand, neue Texte teilweise noch deutsch als Fallback. Nicht alle Originalsprachen; keine Übersetzungsverwaltung. |
| PWA | Neu, Basis | Manifest/Icons, öffentliche Service-Worker-Hülle und ausdrücklicher Kartensnapshot mit QR/Suche/Typfilter. Keine Offlinebuchungen, Web-Push, automatische Erneuerung, eigenes Appbranding oder echten Gerätetests. |
| Datenschutz | Basis | Datenexport/-löschung. Kein vollständiges Consent-/Cookie-Management, Aufbewahrungsjobs, Abmeldeverwaltung, Rechtstexte oder AVV. Keine DSGVO-Konformitätszusage. |
| Aktivitätsprotokoll | Neu, Basis | Login und Admin-Einstellungen/Export protokolliert. Keine lückenlose Security-/Änderungshistorie für alle Module. |
| System Health | Neu, Basis | DB, Extensions, HTTPS, externe Setups und Webhook-Zähler. Backup-/Restore-CLI jetzt vorhanden und getestet, aber keine automatische Planung, Queuekontrollen oder Security-Anomalieerkennung. |
| Auth/Sicherheit | Basis | Passwort-Hash, CSRF, Ownership-Checks, Session-Login, sichere Bilduploads. Rate Limits, sichere Cookieparameter und Idle-Timeout jetzt vorhanden. Neu: Passwortreset mit Einmaltoken und Sessionwiderruf; eigenes Betreiberpasswort mit bisherigem Passwort ändern (OWNER-PASSWORD.md), beendet Websitzungen, nicht API-/Agent-Schlüssel. OTP, Geräteverwaltung und vollständiger Security-Audit fehlen. |
| Installation/Betrieb | Basis | PHP/SQLite-Schnellstart und additive Migrationen. Getestetes Backup-/Restore-Werkzeug jetzt vorhanden. Kein Browserinstaller, One-command-Ubuntu-Installer, Docker oder automatischer Updater. |
| Support | Neu, Basis | Partner-Tickets auf jedem Plan, Admin-Inbox mit Status/Priorität/Zuständigkeit/Suche, interne Notizen, Ausblenden, Audit ohne Texte, öffentlicher Kontakt ohne Konto-Bindung. Kein Kontakt-Thread, kein Mail-Eingang, keine Anhänge/Export/Support-API, keine echte SMTP-Abnahme. Siehe SUPPORT.md. |

## Empfohlene weitere Baupakete

1. **Produktionsgrundlage:** Rate Limits, Konto-/Passwortwiederherstellung, SMTP-Provider mit Abmeldung/Queue, Tests für Geld-/Punkteoperationen, Backup/Restore, Deployment.
2. **Mandanten und Berechtigungen:** Netzwerk-Gruppierung, Standorte und Staff-Einzelrechte sind als Basis umgesetzt. Offen: Clubs/geteilte Salden, Managerkonten und editierbare Plan-/Featureverwaltung. Zentrale Paywall-Prüfungen sind umgesetzt.
3. **Programm-Parität:** Voucher-Batches/Eligibility, Prepaid-Verkaufsledger/Zeit/Guthaben, Punkteverfall, programmbezogenes Design, erweiterte Achievements.
4. **Kommunikation:** Lifecycle-Automationen, Geburtstags-/Winback-Flows, monatliche Reports und Zustellmonitoring.
5. **Ökosystem:** vollständige Shopflows, Agent API/MCP mit Freigaben, PayPal, Offline-PWA, weitere Homepages und Sprachen.

Die Pakete sind offene Implementierung, keine Funktionen, die ein vorhandenes Geheimnis nur noch einschalten würde. Stripe/Wallet dagegen sind teilweise externe Setup-Abhängigkeiten. Diese Unterschiede dürfen bei der Übergabe nicht vermischt werden.

## Verifikation

- `php tests/billing.php`: 29 isolierte Signatur- und Zustandstests, kein Netzwerk.
- `python tests/run-browser.py`: echter PHP-HTTP-Server und Chromium, Upload/CSRF/Tenant/Responsive-Checks, keine echten Zahlungen.
- Wallet-Stempelgrafik lokal erzeugt und visuell geprüft; Google-Payload auf heroImage geprüft.
- Echte Wallet-Pässe auf Geräten, Stripe-Testcheckout, vollständige Referenzdemo-Workflows und Last-/Penetrationstests sind nicht durchgeführt.

## Quellen

- https://rewardloyalty.co/demo
- https://rewardloyalty.co/docs/5.x
- https://rewardloyalty.co/docs/5.x/stamp-cards/designing-cards
- https://rewardloyalty.co/docs/5.x/administration/data-export
- https://rewardloyalty.co/docs/5.x/administration/partner-permissions
- https://rewardloyalty.co/docs/5.x/plans-and-billing
- https://rewardloyalty.co/docs/5.x/prepaid-passes
- https://rewardloyalty.co/docs/5.x/loyalty-cards
- https://rewardloyalty.co/docs/5.x/ai-connections
- https://rewardloyalty.co/docs/5.x/system-health-and-security
- https://developers.google.com/wallet/reference/rest/v1/genericobject


## Nachtrag Etappe Produktionsgrundlage

Zentrale Planberechtigung über bestehende Schreibkanäle, atomare Mengenlimits, Rate Limits, sichere Sessions/Response-Header, Bestellbeleg-Deduplizierung, frisch gelesene Staff-/Owner-Kartenbuchungen und Backup-/Restore-CLI ergänzt. Tests und weiterhin offene Produktionspunkte: [OPERATIONS.md](OPERATIONS.md). Keine Aussage, dass damit alle Sicherheits-/Betriebsanforderungen oder Referenzfunktionen erledigt wären.


## Nachtrag Etappe Programme

Programmspezifischer Designer mit Hintergrundfoto/Deckkraft und Emoji, Code-Gutscheinregeln/Batches/Kassenprüfung sowie verkaufte Besuchs-/unbegrenzte Pässe mit separatem Ledger ergänzt. Das ist ein weiterer Teil der Parität, kein Abschluss. Insbesondere Geldguthabenpässe, Kauf-QR, Geburtstags-/Winback-Flows und einige Original-Detailregeln bleiben offen. Details: [PROGRAMS.md](PROGRAMS.md).


## Nachtrag Etappe E-Mail

TLS-SMTP, Outbox, Worker, Einwilligung/Abmeldung, drei Textautomationen und Passwortwiederherstellung mit Sessionwiderruf ergänzt. Echte Providerzustellung bleibt extern ungeprüft. Details: [MAIL.md](MAIL.md).

## Nachtrag: Organisation und Team

Netzwerk-Gruppierung eigener Unternehmen, Standorte mit Staff-Buchungszuordnung, Rollenprofile/Einzelrechte, sofortiger Entzug und PIN-Sitzungswiderruf sind ergänzt. SQL-Integritätsprüfungen verhindern fremde Netzwerk-/Standortzuordnungen. Semantik, Migration und bewusste Grenzen: [ORGANIZATION.md](ORGANIZATION.md). Keine unternehmensübergreifenden Salden oder isolierten Standort-Datensichten.

## Nachtrag: PayPal-SaaS-Abrechnung

SDK-v6-Subscribe-Session, serverseitige Anlage und verbindlicher Statusabgleich, originaldatengetreue Webhook-Postback-Verifikation, Event-/Create-Deduplizierung, mandantengebundene Planwahl, explizite Kündigung und konservative Refund-/Reversal-Sperre sind ergänzt. Siehe [PAYPAL.md](PAYPAL.md). Keine echte Sandbox-/Live-Abnahme, kein automatischer Planwechsel/Proration, jährlicher Intervallwähler oder grafischer Reconciliation-Workflow. Fehlende Verkaufsereignisse verlangen Betreiberabgleich. Vollständige Parität bleibt offen.


## Nachtrag: WordPress-Händlerdashboard

Eigenständiges WordPress-Adminmenü ohne WooCommerce-Abhängigkeit, Live-Kundenliste/Suche/Cursor, Salden, Anlegen und Namensänderung sowie Punkte-/Stempelbuchungen und bestätigte Prämieneinlösung ergänzt. Geschäftsbindung erfolgt über einen serverseitigen API-Schlüssel, Verbindungsverwaltung nur für Administratoren, Bedienung auch mit `manage_woocommerce`. Atomare Wiederholungsbelege einschließlich echter Parallel- und Antwortverlusttests. Lokal echte WordPress-Installation und responsive Formulare geprüft. Kein Multi-Vendor-/Pro-Verkäufer-Routing, kein E-Mail-Wechsel, keine Passverkäufe oder automatische Folge-Workflows über diesen neuen API-Pfad. Keine echte WooCommerce-/HTTPS-Produktionsabnahme. [Einrichtung, Datenhaltung, Tests und Grenzen](../integrations/woocommerce/README.md).


## Nachtrag: Offline-PWA-Basis

Installierbare Metadaten/Icons und generische öffentliche Offline-Hülle ergänzt. Mitglieder speichern bis zu 30 aktive einfache Karten ausdrücklich auf einem privaten Gerät, mit 24-Stunden-Anzeigefrist, Kundencode/QR, letztem Saldo, Suche/Filter und QR-Vergrößerung. Keine Namen/E-Mail im Snapshot, aber Codes/Salden bleiben sensible lokale Daten. Keine Session-/API-Seiten im Service-Worker-Cache und keine Offline-Schreibqueue. Logout/Kontowechsel/Löschung berücksichtigen die lokale Kopie; ein getrenntes Gerät lässt sich nicht fernlöschen. Separat verkaufte Passinstanzen und vollständige Referenz-/Geräteabnahme bleiben offen. [Details, Grenzen und Tests](PWA.md).

## Nachtrag: Support-Tickets

In-App-Helpdesk ergänzt (docs/SUPPORT.md): Partner-Threads auf jedem Plan, Admin-Inbox mit Filtern und Suche, unsichtbare interne Notizen, Status Offen/Wartet/Geschlossen mit Wiedereröffnung, Zuweisung, Priorität, bestätigtes Ausblenden, Audit ohne Nachrichtentexte, Vorgangs-Deduplizierung, Routenbindung, CSRF/Rate-Limits. Öffentlicher Kontakt bindet nie an ein Konto und löst keine Mail an die Absenderadresse aus. 23 Domänen-, 53 HTTP- und 22 Chromium-Prüfungen grün. Offen: Kontakt-Thread, E-Mail-Eingang, Anhänge, Export, Support-API, Teammitglieder-Zuweisung, Zeitzone/Sprache/Version-Snapshot, echte SMTP-/Geräteabnahme.

## Nachtrag: KI-Verbindungen (MCP)

Optionaler MCP-Endpunkt (docs/AI.md): Schlüsselverwaltung unter /ai (gehasht, Ablauf, Widerruf), JSON-RPC mit tools/prompts, 7 Lese- und 5 Änderungswerkzeuge, Idempotenz, Rate-Limit, Origin-Sperre, Audit ohne Mitgliedsdaten, Freigabe-Workflow (10 Minuten, nur Eigentümer, genau einmal, Datensatzprüfung) für Löschen von Mitgliedern und Programmen. 86 HTTP- und 28 Chromium-Prüfungen grün. Offen: echte Client-Abnahme (Claude, ChatGPT, Cursor), OAuth/Browser-Login, Plattform-/Admin- und Mitglieder-Werkzeuge, Kampagnen-/E-Mail-/Tier-/Gutschein-/Support-Werkzeuge, Scopes je Objekt oder Menge (Unternehmen und Werkzeuggruppen sind da), Tool-Discovery-Endpunkt, MCP-Apps-Ansichten, Streaming.
