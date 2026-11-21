<?php
require getenv('WORDPRESS_TEST_ROOT').'/wp-load.php';require_once ABSPATH.'wp-admin/includes/plugin.php';
$e=activate_plugin('loyalty-saas-woocommerce/loyalty-saas-woocommerce.php');if(is_wp_error($e))throw new Exception($e->get_error_message());
add_role('local_shop_manager','Shop Manager',['read'=>true,'manage_woocommerce'=>true]);
foreach(['manager'=>'local_shop_manager','viewer'=>'subscriber'] as $name=>$role){$uid=username_exists($name) ?: wp_create_user($name,'local-test-only',$name.'@example.test');$u=new WP_User($uid);$u->set_role($role);}
wp_set_current_user(username_exists('testadmin'));$db=new PDO('sqlite:'.getenv('WORDPRESS_TEST_STATE').'/app/data/app.sqlite');$key=$db->query('SELECT api_key FROM businesses WHERE id=1')->fetchColumn();if(!$key){$key=bin2hex(random_bytes(24));$db->prepare('UPDATE businesses SET api_key=? WHERE id=1')->execute([$key]);}update_option('loyalty_saas_settings',['api_url'=>'https://example.com','api_key'=>$key]);echo 'Plugin activated without WooCommerce, local roles configured'.PHP_EOL;
