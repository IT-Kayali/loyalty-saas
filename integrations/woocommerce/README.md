# WooCommerce-Plugin

Gutschreibt automatisch Treuepunkte für jede bezahlte WooCommerce-Bestellung.

## Installation

1. Ordner `integrations/woocommerce` nach `wp-content/plugins/loyalty-saas-woocommerce` auf dem WordPress-Server kopieren.
2. In WordPress unter **Plugins** aktivieren.
3. Unter **WooCommerce → Loyalty SaaS** eintragen:
   - **API-URL**: Basis-URL deiner Loyalty-SaaS-Installation, z. B. `https://loyalty.deine-domain.de` (ohne `/api/v1`).
   - **API-Schlüssel**: steht in der Loyalty-SaaS-App auf der Business-Seite unter **Integrationen → API-Schlüssel**.
4. Auf derselben Einstellungsseite erscheint ein Verbindungstest. Steht dort der Firmenname, läuft alles.

## Verhalten

- Bei Bestellstatus **Processing** oder **Completed** wird `POST /api/v1/integrations/order` aufgerufen.
- Das Mitglied wird per E-Mail gefunden oder automatisch angelegt. Es bekommt sofort eine Kundenkarte mit Code und QR.
- Die Punktzahl rechnet die Loyalty-SaaS-App aus der Einstellung **Punkte je Cent Umsatz** (Business-Seite → Integrationen).
- Jede Bestellung wird nur einmal gutgeschrieben (Bestell-Meta `_loyalty_saas_credited` + Referenz `wc-order-ID`).
- Erfolg und Fehler landen als Bestellnotiz in WooCommerce.

## Voraussetzungen

- WordPress ≥ 6.0, WooCommerce ≥ 7.0, PHP ≥ 8.0
- Die Loyalty-SaaS-Installation muss per HTTPS erreichbar sein (Sonst lehnen manche Server-Konfigurationen ausgehende HTTP-Aufrufe nicht ab, aber die API überträgt den Schlüssel im Header — also immer HTTPS).
