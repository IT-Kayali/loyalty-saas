<?php
/** Punkteverfall fuer alle Programme mit aktivem Verfall. Cron, z. B. taeglich: php tools/expire-points.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/inc/expiry.php';
[$c, $s] = expire_all_points();
echo "Karten: $c, Punkte entfernt: $s\n";
