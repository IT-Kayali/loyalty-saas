<?php
if(!defined('ABSPATH'))exit;

final class LoyaltySaaSMerchantDashboard
{
    public static function canManage(): bool {return current_user_can('manage_options') || current_user_can('manage_woocommerce');}
    public static function init(): void {
        add_action('admin_menu',[self::class,'menu']);add_action('admin_post_loyalty_merchant_action',[self::class,'handle']);
    }
    public static function menu(): void {
        $cap=current_user_can('manage_options')?'manage_options':'manage_woocommerce';
        add_menu_page('Loyalty Händlerdashboard','Loyalty',$cap,'loyalty-merchant',[self::class,'render'],'dashicons-groups',56);
    }
    private static function guard(): void {if(!self::canManage())wp_die('Keine Berechtigung.',403);}
    private static function link(array $args=[]): string {return add_query_arg(array_merge(['page'=>'loyalty-merchant'],$args),admin_url('admin.php'));}
    private static function start(): void {echo '<div class="wrap"><h1>Loyalty Händlerdashboard</h1><p>Kunden und Karten live aus deiner Loyalty-Plattform. Keine lokale Kopie der Kundendatenbank. Unbestätigte Änderungen werden bis zum Abgleich geschützt zwischengespeichert.</p><style>.loyalty-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.loyalty-box{background:white;border:1px solid #c3c4c7;padding:18px;margin:14px 0;max-width:100%;box-sizing:border-box}.loyalty-box input:not([type=hidden]),.loyalty-box select{max-width:100%}.loyalty-scroll{overflow:auto}.loyalty-box form{margin:12px 0}.loyalty-box label{display:block;margin:8px 0}.loyalty-box .button{white-space:normal}.loyalty-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;margin:18px 0}.loyalty-stats .loyalty-box{margin:0;padding:14px}</style>';}
    private static function form(string $target,array $hidden=[],string $label='Speichern',string $fields=''): void {
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('loyalty_merchant_write');
        $data=array_merge(['action'=>'loyalty_merchant_action','target'=>$target,'operation'=>bin2hex(random_bytes(16))],$hidden);
        foreach($data as $k=>$v)echo '<input type="hidden" name="'.esc_attr($k).'" value="'.esc_attr((string)$v).'">';
        echo $fields.'<button class="button button-primary">'.esc_html($label).'</button></form>';
    }
    private static function pending(): ?array {$p=get_option('_loyalty_pending_'.get_current_user_id(),null);return is_array($p)?$p:null;}
    private static function retry(array $p): void {
        echo '<div class="loyalty-box"><h2>Ungeklärter Vorgang</h2><p>Die letzte Änderung ist nicht sicher bestätigt. Keine neue Buchung starten. Derselbe Vorgang lässt sich ohne Doppelbuchung erneut abgleichen. Nicht löschen oder als neuen Vorgang senden.</p><p>Ziel: '.esc_html($p['target']).' · Vorgang '.esc_html($p['body']['operation']).'</p>';
        self::form('retry',['operation'=>$p['body']['operation']],'Denselben Vorgang sicher erneut prüfen');echo '</div>';
    }
    public static function render(): void {
        self::guard();self::start();$notice=get_transient('_loyalty_notice_'.get_current_user_id());if($notice){delete_transient('_loyalty_notice_'.get_current_user_id());echo '<div class="notice notice-warning"><p>'.esc_html((string)$notice).'</p></div>';}$pending=self::pending();if($pending){self::retry($pending);echo '</div>';return;}
        if(current_user_can('manage_options'))echo '<p><a href="'.esc_url(admin_url('admin.php?page=loyalty-saas')).'">Verbindung und API-Schlüssel verwalten</a></p>';
        $s=LoyaltySaaSWooCommerce::apiRequest('GET','/api/v1/merchant/summary');if(!$s['ok']){echo '<div class="notice notice-error"><p>'.esc_html($s['error']).'</p></div></div>';return;}
        $d=$s['data'];echo '<h2>'.esc_html((string)$d['business']['name']).'</h2><div class="loyalty-stats">';
        foreach(['Mitglieder'=>$d['members'],'Karten'=>$d['balances']['cards'],'Aktuelle Punkte'=>$d['balances']['points'],'Offene Stempelbelohnungen'=>$d['balances']['pending_rewards']] as $title=>$v)echo '<div class="loyalty-box"><strong>'.esc_html($title).'</strong><p style="font-size:26px">'.(int)$v.'</p></div>';
        echo '</div><p>Live-Stand: '.esc_html((string)$d['as_of']).'. Punkte sind aktuelle Salden, kein Umsatz.</p>';
        $code=strtoupper(sanitize_text_field((string)($_GET['member'] ?? '')));
        if(preg_match('/^[A-F0-9]{10}$/D',$code)){
            $r=LoyaltySaaSWooCommerce::apiRequest('GET','/api/v1/merchant/members/'.$code);if(!$r['ok']){echo '<p>'.esc_html($r['error']).'</p></div>';return;}$m=$r['data'];
            echo '<p><a href="'.esc_url(self::link()).'">Alle Mitglieder</a></p><div class="loyalty-box"><h2>'.esc_html((string)$m['name']).'</h2><p>'.esc_html((string)$m['email']).' · '.esc_html((string)$m['code']).'</p>';
            self::form('edit',['id'=>$m['id'],'member'=>$code],'Mitglied speichern','<label>Name <input name="name" maxlength="120" required value="'.esc_attr((string)$m['name']).'"></label><label>E-Mail <input type="email" name="email" maxlength="254" readonly required value="'.esc_attr((string)$m['email']).'"></label>');echo '<p>Nur der Name wird geändert. E-Mail, Marketingeinwilligung und Zugangsdaten bleiben unverändert.</p></div><div class="loyalty-grid">';
            foreach($m['cards'] as $c){echo '<section class="loyalty-box"><h3>'.esc_html((string)$c['program']).'</h3><p>Typ: '.esc_html((string)$c['type']).' · Karte #'.(int)$c['id'].'</p>';
                if(empty($c['active'])){echo '<p>Programm inaktiv. Keine Buchungen möglich.</p></section>';continue;}
                if($c['type']==='points'){
                    echo '<p><strong>'.(int)$c['points'].' Punkte</strong> · Lifetime '.(int)$c['points_lifetime'].'</p>';
                    self::form('card',['id'=>$c['id'],'member'=>$code,'card_action'=>'points_add'],'Punkte vergeben','<label>Basispunkte (aktive Bonuszeit wird berücksichtigt)<input type="number" name="amount" min="1" max="10000" value="1" required></label>');
                    foreach($m['rewards'] as $reward)if((int)$reward['program_id']===(int)$c['program_id'] && (int)$c['points']>=(int)$reward['points_cost'])self::form('card',['id'=>$c['id'],'member'=>$code,'card_action'=>'redeem_reward','reward_id'=>$reward['id']],'Einlösen: '.$reward['name'].' ('.$reward['points_cost'].' Punkte)','<label><input type="checkbox" name="confirmed" value="1" required> Richtigen Kunden und Prämie geprüft</label>');
                }elseif($c['type']==='stamps'){
                    echo '<p><strong>'.(int)$c['stamps'].' Stempel</strong> · '.(int)$c['pending_rewards'].' offene Belohnungen</p>';self::form('card',['id'=>$c['id'],'member'=>$code,'card_action'=>'stamp'],'+1 Stempel');
                    if((int)$c['pending_rewards']>0)self::form('card',['id'=>$c['id'],'member'=>$code,'card_action'=>'redeem_stamp_reward'],'Stempelbelohnung einlösen','<label><input type="checkbox" name="confirmed" value="1" required> Richtigen Kunden und Belohnung geprüft</label>');
                }else echo '<p>Restnutzungen: '.(int)($c['uses_left'] ?? $c['visits_left'] ?? 0).'. Gutscheincodes und verkaufte Pässe werden in der Loyalty-App verwaltet.</p>';
                echo '</section>';
            }echo '</div></div>';return;
        }
        $query=sanitize_text_field((string)($_GET['q'] ?? ''));$after=max(0,(int)($_GET['after'] ?? 0));
        echo '<form method="get"><input type="hidden" name="page" value="loyalty-merchant"><label for="loyalty-search">Name, E-Mail oder Kundencode</label> <input id="loyalty-search" name="q" value="'.esc_attr($query).'"><button class="button">Suchen</button></form>';
        $r=LoyaltySaaSWooCommerce::apiRequest('GET','/api/v1/merchant/members?'.http_build_query(['q'=>$query,'after'=>$after]));
        if(!$r['ok'])echo '<p>'.esc_html($r['error']).'</p>';else{echo '<div class="loyalty-scroll"><table class="widefat striped"><thead><tr><th>Mitglied</th><th>E-Mail</th><th>Karten / Salden</th></tr></thead><tbody>';
            foreach($r['data']['members'] as $m){$labels=[];foreach($m['cards'] as $c)$labels[]=$c['program'].': '.($c['type']==='points'?(int)$c['points'].' P.':($c['type']==='stamps'?(int)$c['stamps'].' Stempel':(int)($c['uses_left'] ?? $c['visits_left'] ?? 0).' Nutzungen'));echo '<tr><td><a href="'.esc_url(self::link(['member'=>$m['code']])).'">'.esc_html((string)$m['name']).'</a><br>'.esc_html((string)$m['code']).'</td><td>'.esc_html((string)$m['email']).'</td><td>'.esc_html(implode(' · ',$labels)).'</td></tr>';}
            echo '</tbody></table></div>';if($r['data']['next_after'])echo '<p><a class="button" href="'.esc_url(self::link(['q'=>$query,'after'=>$r['data']['next_after']])).'">Nächste 50</a></p>';
        }
        echo '<section class="loyalty-box"><h2>Mitglied anlegen</h2>';self::form('create',[],'Mitglied anlegen','<label>Name <input name="name" maxlength="120" required></label><label>E-Mail <input name="email" type="email" maxlength="254" required></label><p>Ohne Marketingeinwilligung, ohne Werbe-E-Mail. Zugang verwaltet die Loyalty-App.</p>');echo '</section></div>';
    }
    private static function back(string $notice=''): never {if($notice!=='')set_transient('_loyalty_notice_'.get_current_user_id(),$notice,300);wp_safe_redirect(self::link());exit;}
    public static function handle(): void {
        self::guard();check_admin_referer('loyalty_merchant_write');$target=sanitize_text_field((string)($_POST['target'] ?? ''));$pending=self::pending();$op=sanitize_text_field((string)($_POST['operation'] ?? ''));
        if(!preg_match('/^[a-f0-9]{32}$/D',$op))wp_die('Ungültige Vorgangskennung.',400);
        if($pending && ($target!=='retry' || $pending['body']['operation']!==$op)){self::back();}
        if(!$pending){if($target==='card' && str_starts_with((string)($_POST['card_action'] ?? ''),'redeem_') && ($_POST['confirmed'] ?? '')!=='1')wp_die('Einlösung ausdrücklich bestätigen.',400);$id=max(0,(int)($_POST['id'] ?? 0));if(in_array($target,['edit','card'],true) && $id<1)wp_die('Ungültige ID.',400);$path=match($target){'create'=>'/api/v1/merchant/members','edit'=>'/api/v1/merchant/members/'.$id.'/edit','card'=>'/api/v1/merchant/cards/'.$id.'/action',default=>''};if($path==='')wp_die('Ungültiges Ziel.',400);
            $body=['operation'=>$op,'action'=>sanitize_text_field((string)($_POST['card_action'] ?? '')),'amount'=>(int)($_POST['amount'] ?? 0),'reward_id'=>(int)($_POST['reward_id'] ?? 0),'name'=>sanitize_text_field(wp_unslash((string)($_POST['name'] ?? ''))),'email'=>sanitize_email(wp_unslash((string)($_POST['email'] ?? '')))];$s=LoyaltySaaSWooCommerce::settings();
            $pending=['target'=>$path,'body'=>$body,'member'=>sanitize_text_field((string)($_POST['member'] ?? '')),'connection'=>hash('sha256',$s['api_url'].'|'.$s['api_key'])];if(!add_option('_loyalty_pending_'.get_current_user_id(),$pending,'',false)){self::back();}
        }
        $s=LoyaltySaaSWooCommerce::settings();if(!hash_equals($pending['connection'],hash('sha256',$s['api_url'].'|'.$s['api_key'])))wp_die('Verbindung wurde geändert. Ungeklärten Vorgang zuerst mit dem Administrator im ursprünglichen Unternehmen abgleichen.',409);
        $r=LoyaltySaaSWooCommerce::apiRequest('POST',$pending['target'],$pending['body']);
        if($r['ok'] || (!empty($r['data']['error']) && in_array($r['status'] ?? 0,[400,401,402,403,404,422],true)))delete_option('_loyalty_pending_'.get_current_user_id());
        if(!$r['ok'])self::back($r['error']);
        $code=$r['data']['member']['code'] ?? $pending['member'];wp_safe_redirect(self::link($code!==''?['member'=>$code]:[]));exit;
    }
}
LoyaltySaaSMerchantDashboard::init();
