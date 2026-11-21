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
| Mitglieder-Wallet | Basis | Login, mehrere Unternehmen, Karten, Inbox, Export/Löschung. Kein OTP-Login, anonyme Mitglieder, umfassendes Profil-/Passwortmanagement, Offlineinstallation oder Live-Event-Updates. |
| Punkte & Prämien | Basis | Punkte, Prämien, Lifetime-Punkte, Stufen und Bonusstunden. Punkteanfragen zwischen Mitgliedern, Punkteverfall, individuelle Einlösecodes und zeitlich begrenzte Reward-QRs fehlen. |
| Stempelkarten | Basis + Neu | Stempel/Rollover/Belohnungen, eigener Bildupload, Vorschau. Neu: eigenes Programmdesign, Hintergrundfoto/Deckkraft und kuratierte Emoji-Auswahl. Nativer Wallet-Pass bleibt ein Sammelpass, Fotos/Emoji nur im Web. |
| Gutscheine | Basis | Fester Wert und begrenzte Nutzungen. Neu: Code-Batches, Prozent/Festbetrag/Produkt/Punkte, einfache Eligibility und Limits. Geburtstags-/Winback-Automation, Claim-QR, Batchstorno und eigene Analysen fehlen. |
| Prepaid | Basis | Neu: getrennt verkaufte Besuchs-/unbegrenzte Zeitpässe, Snapshots, Ablaufprüfung, Ledger/CSV und Storno. Geldguthaben, Refunds, Kauf-QR und Reminder fehlen. |
| Achievements | Basis | Schwellen/Metriken und Punktebonus. Keine wöchentlichen Läufe und vollständige Reward-/Limitkonfiguration. |
| Empfehlungen | Basis | Empfehlungslink, Prämien für Werber/Geworbenen. Kein detailliertes Missbrauchs-/Kampagnenmanagement. |
| Segmente | Basis | Gespeicherte regelbasierte Zielgruppen. Kein vollständiger verschachtelter Segment-Builder. |
| Kampagnen | Basis | Segmentauswahl, Inbox und vorhandener optionaler Mailpfad. Die SMTP-Konfiguration allein ist kein Nachweis einer produktionsreifen SMTP-Zustellung; Neu: TLS-SMTP, Queue/Worker und Abmeldung. Echte Providerzustellung, Bounces und Double-Opt-In fehlen. |
| Automatische Nachrichten | Offen | Neu: Welcome/Birthday/Winback-Textregeln mit Deduplizierung. Kein vollständiger Lifecycle-Katalog und noch keine automatischen Gutscheine. |
| Karten-/QR-Studio | Basis | QR für Join/Staff/Karten, Logo und Druck/PNG. Keine trackbaren Kartenbatches, Batchinventar oder Hardwareverwaltung. |
| Apple Wallet | Setup + Neu | Passsignatur, PassKit-Webservice und APNs-Code vorhanden, Stempelbild als strip.png. Apple-Zertifikate/Konto fehlen. Geräteprüfung und echte Updates nicht abgenommen. |
| Google Wallet | Setup + Neu | JWT/API-Code, Stempelgrafik als GenericObject heroImage. Issuer/Service-Account fehlen, keine echte Geräteprüfung. Google steuert die tatsächliche Darstellung. |
| WooCommerce | Basis/Setup | Plugin und Bestellpunktpfad. Nicht gegen einen echten Shop Ende-zu-Ende abgenommen. |
| Shopify | Basis/Setup | HMAC-Webhook und Anleitung. Kein vollständiges Widget, OAuth-App-Lifecycle oder Shop-UI. |
| POS | Basis | Generische REST-Schnittstelle. Kein vollständiger Original-POS-Katalog oder fertige Kassengeräteanbindung. |
| Ausgehende Webhooks | Basis | HTTPS/öffentliche IPs, HMAC und Zustellprotokoll. Automatische Queue-Retries und Admin-Wiederholungsworkflow fehlen. |
| REST API | Basis | Business-API-Key, Mitglieder/Karten/Bestellungen/Journal und OpenAPI. Kein gleichwertiges Session-Token-Frontend-API. |
| Agent API | Offen | Keine granularen Scopes, Idempotency-/Approval-Workflows und separate Agent-Authentifizierung. Vorhandene REST API nicht als gleichwertig ausgeben. |
| AI/MCP | Offen | Keine OAuth-AI-Verbindungen, Streamable-HTTP-MCP, Tool Discovery, Freigabewarteschlange oder AI-Schreibassistenz. |
| Stripe | Neu/Setup | Checkout, Portal, signierte Webhooks, Zustände und neue-Unternehmen-Limit. Preise/Konto/Keys und echter Testkauf fehlen. Zentrale Schreibprüfung und Limits jetzt vorhanden, noch keine editierbare vollständige Planfeature-Matrix. |
| PayPal | Offen | Kein PayPal-Abo-Adapter. |
| Pläne verwalten | Basis | Serverkonfiguration statt editierbarer Admin-Planverwaltung; keine Planmigrationen/Feature-Toggles pro Mandant. |
| Branding/Homepages | Basis | Plattformname, Business-Farben/-Logo, Designer. Keine Smart/Showcase/Portal/Public-Listing-Homepages, eigenen Domains oder vollständigen E-Mail-Templates. |
| Sprachen | Basis | DE/EN für bisherigen Bestand, neue Texte teilweise noch deutsch als Fallback. Nicht alle Originalsprachen; keine Übersetzungsverwaltung. |
| PWA | Offen | Kein Service Worker, Offline-Wallet, Web-Push oder installierbare Offline-App. |
| Datenschutz | Basis | Datenexport/-löschung. Kein vollständiges Consent-/Cookie-Management, Aufbewahrungsjobs, Abmeldeverwaltung, Rechtstexte oder AVV. Keine DSGVO-Konformitätszusage. |
| Aktivitätsprotokoll | Neu, Basis | Login und Admin-Einstellungen/Export protokolliert. Keine lückenlose Security-/Änderungshistorie für alle Module. |
| System Health | Neu, Basis | DB, Extensions, HTTPS, externe Setups und Webhook-Zähler. Backup-/Restore-CLI jetzt vorhanden und getestet, aber keine automatische Planung, Queuekontrollen oder Security-Anomalieerkennung. |
| Auth/Sicherheit | Basis | Passwort-Hash, CSRF, Ownership-Checks, Session-Login, sichere Bilduploads. Rate Limits, sichere Cookieparameter und Idle-Timeout jetzt vorhanden. Neu: Passwortreset mit Einmaltoken und Sessionwiderruf. OTP, Geräteverwaltung und vollständiger Security-Audit fehlen. |
| Installation/Betrieb | Basis | PHP/SQLite-Schnellstart und additive Migrationen. Getestetes Backup-/Restore-Werkzeug jetzt vorhanden. Kein Browserinstaller, One-command-Ubuntu-Installer, Docker oder automatischer Updater. |
| Support | Offen | Keine Tickets, Partner-Einladungen oder eingebauter Helpdesk. |

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
