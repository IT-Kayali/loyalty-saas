<?php
define('DB_NAME','local');define('DB_USER','local');define('DB_PASSWORD','');define('DB_HOST','localhost');define('DB_CHARSET','utf8');define('DB_COLLATE','');$table_prefix='wp_';
define('WP_HOME','http://127.0.0.1:8100');define('WP_SITEURL','http://127.0.0.1:8100');define('WP_DEBUG',true);define('WP_DEBUG_LOG',true);define('WP_DEBUG_DISPLAY',false);define('DISABLE_WP_CRON',true);define('WP_HTTP_BLOCK_EXTERNAL',true);define('AUTOMATIC_UPDATER_DISABLED',true);
if(!defined('ABSPATH'))define('ABSPATH',__DIR__.'/');require_once ABSPATH.'wp-settings.php';
