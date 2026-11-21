<?php
/**
 * Plugin Name: Loyalty SaaS Händlerdashboard + WooCommerce
 * Description: Kunden, Punkte, Stempel und Prämien im WordPress-Admin verwalten. Optionale WooCommerce-Bestellpunkte.
 * Version: 1.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Author: Loyalty SaaS
 * License: MIT
 *
 * Installation:
 * 1. Diesen Ordner (integrations/woocommerce) nach wp-content/plugins/loyalty-saas-woocommerce kopieren.
 * 2. In WordPress unter "Plugins" aktivieren.
 * 3. Unter Einstellungen -> Loyalty Verbindung die API-URL und den API-Schlüssel eintragen
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
        add_submenu_page('options-general.php','Loyalty Verbindung','Loyalty Verbindung','manage_options','loyalty-saas',[self::class,'renderSettingsPage']);
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
        $old=self::settings();if(!current_user_can('manage_options'))return $old;
        $url=esc_url_raw(rtrim(trim((string)($input['api_url'] ?? '')), '/'));
        if($url!=='' && !self::publicEndpoint($url)){add_settings_error(self::OPTION_KEY,'url','Nur eine öffentliche HTTPS-API-Basisadresse ist erlaubt.');return $old;}
        $key=sanitize_text_field((string)($input['api_key'] ?? ''));
        if($key!=='' && !preg_match('/^[a-f0-9]{48}$/Di',$key)){add_settings_error(self::OPTION_KEY,'key','Ungültiger API-Schlüssel.');return $old;}
        if($url!==$old['api_url'] && $old['api_key']!=='' && $key==='' && empty($input['clear_key'])){add_settings_error(self::OPTION_KEY,'binding','Bei anderer URL einen neuen Schlüssel eingeben oder die Verbindung ausdrücklich löschen.');return $old;}
        return ['api_url'=>$url,'api_key'=>!empty($input['clear_key'])?'':($key!==''?$key:$old['api_key'])];
    }

    public static function fieldApiUrl(): void
    {
        $s = self::settings();
        echo '<input type="url" name="' . esc_attr(self::OPTION_KEY) . '[api_url]" value="' . esc_attr($s['api_url']) . '" class="regular-text" placeholder="https://loyalty.deine-domain.de">';
        echo '<p class="description">Basis-URL deiner Loyalty-SaaS-Installation (ohne /api/v1 am Ende).</p>';
    }

    public static function fieldApiKey(): void
    {
        echo '<input type="password" name="'.esc_attr(self::OPTION_KEY).'[api_key]" value="" autocomplete="new-password" class="regular-text">';
        echo '<p class="description">Leer lassen: gespeicherten Schlüssel behalten. Der gespeicherte Schlüssel wird nie im HTML angezeigt. Neu erzeugen in der Loyalty-App unter Unternehmen → Integrationen.</p><label><input type="checkbox" name="'.esc_attr(self::OPTION_KEY).'[clear_key]" value="1"> Gespeicherten Schlüssel löschen</label>';
    }

    public static function renderSettingsPage(): void
    {
        if(!current_user_can('manage_options'))wp_die('Keine Berechtigung.',403);
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
        if(!function_exists('wc_get_order'))return;
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

    private static function publicEndpoint(string $url): bool
    {
        $p=wp_parse_url($url);if(!$p || ($p['scheme'] ?? '')!=='https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (isset($p['port']) && $p['port']!==443))return false;
        $ip=gethostbyname($p['host']);return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false && wp_http_validate_url($url)!==false;
    }

    public static function apiRequest(string $method, string $path, array $body = []): array
    {
        $s = self::settings();
        if($s['api_key']==='' || !in_array($method,['GET','POST'],true) || !self::publicEndpoint($s['api_url']) || !preg_match('#^/api/v1/(?:business|merchant/(?:summary|members(?:/[A-Fa-f0-9]{10}|/\d+/edit)?|cards/\d+/action)|integrations/order)(?:\?[^\r\n]*)?$#D',$path))return ['ok'=>false,'status'=>0,'error'=>'Verbindung nicht eingerichtet oder Anfrage nicht erlaubt.','data'=>[]];
        $response = wp_safe_remote_request($s['api_url'] . $path, [
            'redirection'=>0,
            'limit_response_size'=>2097152,
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
            return ['ok'=>false,'status'=>0,'error'=>'Verbindung nicht bestätigt. Keine neue Buchung starten, vorhandenen Vorgang abgleichen.','data'=>[]];
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode((string)wp_remote_retrieve_body($response), true) ?: [];
        if(!is_array($data) || ($code>=200 && $code<300 && ($data===[] || ($method==='POST' && ($path==='/api/v1/integrations/order'? !isset($data['member'],$data['points_credited']):empty($data['ok'])))))){return ['ok'=>false,'status'=>0,'error'=>'Antwort nicht bestätigt. Vorhandenen Vorgang erneut abgleichen.','data'=>[]];}
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'status'=>$code, 'error' => 'HTTP ' . $code . '. Anfrage nicht bestätigt.', 'data' => $data];
        }
        if($method==='POST' && str_starts_with($path,'/api/v1/merchant/')){
            $valid=str_contains($path,'/cards/')?isset($data['card_id'],$data['member_id'],$data['points'],$data['stamps'],$data['pending_rewards']):isset($data['member']['id'],$data['member']['code']);
            if(!$valid)return ['ok'=>false,'status'=>0,'error'=>'Buchungsergebnis unvollständig. Vorhandenen Vorgang erneut abgleichen.','data'=>[]];
        }
        if($method==='GET'){
            $valid=match(true){$path==='/api/v1/merchant/summary'=>isset($data['business']['name'],$data['members'],$data['balances']['cards'],$data['balances']['points'],$data['balances']['pending_rewards'],$data['as_of']),str_starts_with($path,'/api/v1/merchant/members?')=>isset($data['members']) && is_array($data['members']),str_starts_with($path,'/api/v1/merchant/members/')=>isset($data['id'],$data['code'],$data['cards'],$data['rewards']),default=>isset($data['name'])};
            if(!$valid)return ['ok'=>false,'status'=>0,'error'=>'Antwortformat nicht bestätigt.','data'=>[]];
        }
        return ['ok'=>true,'status'=>$code,'error'=>'','data'=>$data];
    }
}

LoyaltySaaSWooCommerce::init();

require_once __DIR__.'/merchant-dashboard.php';
