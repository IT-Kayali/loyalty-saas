<?php
/**
 * Plugin Name: Loyalty SaaS für WooCommerce
 * Description: Gutschreibt Treuepunkte aus deiner Loyalty-SaaS-Installation für jede bezahlte WooCommerce-Bestellung.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Loyalty SaaS
 * License: MIT
 *
 * Installation:
 * 1. Diesen Ordner (integrations/woocommerce) nach wp-content/plugins/loyalty-saas-woocommerce kopieren.
 * 2. In WordPress unter "Plugins" aktivieren.
 * 3. Unter WooCommerce -> Loyalty SaaS die API-URL und den API-Schlüssel eintragen
 *    (Schlüssel steht in der Loyalty-SaaS-App auf der Business-Seite unter "Integrationen").
 */

if (!defined('ABSPATH')) {
    exit;
}

final class LoyaltySaaSWooCommerce
{
    private const OPTION_KEY = 'loyalty_saas_settings';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'registerSettingsPage']);
        add_action('admin_init', [self::class, 'registerSettings']);
        add_action('woocommerce_order_status_completed', [self::class, 'creditOrder']);
        add_action('woocommerce_order_status_processing', [self::class, 'creditOrder']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [self::class, 'settingsLink']);
    }

    public static function settings(): array
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), [
            'api_url' => '',
            'api_key' => '',
        ]);
    }

    public static function settingsLink(array $links): array
    {
        $url = admin_url('admin.php?page=loyalty-saas');
        array_unshift($links, '<a href="' . esc_url($url) . '">Einstellungen</a>');
        return $links;
    }

    public static function registerSettingsPage(): void
    {
        add_submenu_page(
            'woocommerce',
            'Loyalty SaaS',
            'Loyalty SaaS',
            'manage_woocommerce',
            'loyalty-saas',
            [self::class, 'renderSettingsPage']
        );
    }

    public static function registerSettings(): void
    {
        register_setting(self::OPTION_KEY, self::OPTION_KEY, [
            'sanitize_callback' => [self::class, 'sanitizeSettings'],
        ]);
        add_settings_section('loyalty_saas_main', 'Verbindung', '__return_null', 'loyalty-saas');
        add_settings_field('api_url', 'API-URL', [self::class, 'fieldApiUrl'], 'loyalty-saas', 'loyalty_saas_main');
        add_settings_field('api_key', 'API-Schlüssel', [self::class, 'fieldApiKey'], 'loyalty-saas', 'loyalty_saas_main');
    }

    public static function sanitizeSettings(array $input): array
    {
        return [
            'api_url' => esc_url_raw(rtrim(trim((string)($input['api_url'] ?? '')), '/')),
            'api_key' => sanitize_text_field((string)($input['api_key'] ?? '')),
        ];
    }

    public static function fieldApiUrl(): void
    {
        $s = self::settings();
        echo '<input type="url" name="' . esc_attr(self::OPTION_KEY) . '[api_url]" value="' . esc_attr($s['api_url']) . '" class="regular-text" placeholder="https://loyalty.deine-domain.de">';
        echo '<p class="description">Basis-URL deiner Loyalty-SaaS-Installation (ohne /api/v1 am Ende).</p>';
    }

    public static function fieldApiKey(): void
    {
        $s = self::settings();
        echo '<input type="text" name="' . esc_attr(self::OPTION_KEY) . '[api_key]" value="' . esc_attr($s['api_key']) . '" class="regular-text">';
        echo '<p class="description">Steht in der Loyalty-SaaS-App: Unternehmen -> Integrationen -> API-Schlüssel.</p>';
    }

    public static function renderSettingsPage(): void
    {
        echo '<div class="wrap"><h1>Loyalty SaaS</h1><form method="post" action="options.php">';
        settings_fields(self::OPTION_KEY);
        do_settings_sections('loyalty-saas');
        submit_button();
        echo '</form>';
        $s = self::settings();
        if ($s['api_url'] !== '' && $s['api_key'] !== '') {
            echo '<h2>Verbindungstest</h2>';
            $result = self::apiRequest('GET', '/api/v1/business');
            if ($result['ok']) {
                echo '<p style="color:green">Verbunden mit: <strong>' . esc_html((string)($result['data']['name'] ?? '')) . '</strong></p>';
            } else {
                echo '<p style="color:red">Fehler: ' . esc_html($result['error']) . '</p>';
            }
        }
        echo '</div>';
    }

    /** Punkte fuer eine Bestellung gutschreiben (idempotent ueber die Referenz). */
    public static function creditOrder(int $orderId): void
    {
        $s = self::settings();
        if ($s['api_url'] === '' || $s['api_key'] === '') {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order || $order->get_meta('_loyalty_saas_credited')) {
            return;
        }
        $email = (string)$order->get_billing_email();
        if ($email === '') {
            return;
        }
        $result = self::apiRequest('POST', '/api/v1/integrations/order', [
            'email'     => $email,
            'name'      => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'total'     => (float)$order->get_total(),
            'currency'  => (string)$order->get_currency(),
            'reference' => 'wc-order-' . $orderId,
        ]);
        if ($result['ok']) {
            $order->update_meta_data('_loyalty_saas_credited', time());
            $points = (int)($result['data']['points_credited'] ?? 0);
            $order->add_order_note(sprintf('Loyalty: %d Punkte gutgeschrieben.', $points));
            $order->save();
        } else {
            $order->add_order_note('Loyalty: Punktgutschrift fehlgeschlagen - ' . $result['error']);
            $order->save();
        }
    }

    private static function apiRequest(string $method, string $path, array $body = []): array
    {
        $s = self::settings();
        $response = wp_remote_request($s['api_url'] . $path, [
            'method'  => $method,
            'timeout' => 10,
            'headers' => [
                'Authorization' => 'Bearer ' . $s['api_key'],
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            'body'    => $body === [] ? null : wp_json_encode($body),
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message(), 'data' => []];
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode((string)wp_remote_retrieve_body($response), true) ?: [];
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'HTTP ' . $code . ': ' . (string)($data['error'] ?? 'unbekannt'), 'data' => $data];
        }
        return ['ok' => true, 'error' => '', 'data' => $data];
    }
}

LoyaltySaaSWooCommerce::init();
