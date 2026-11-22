<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/public/index.php';
if(count($argv)>2||(isset($argv[1])&&$argv[1]!=='--execute')){fwrite(STDERR,"Usage: php tools/wallet-message-cleanup.php [--execute]\n");exit(2);}
$execute=($argv[1] ?? '')==='--execute';
$count=wallet_message_cleanup($execute);
echo ($execute?'Deleted expired message records: ':'Dry run, expired message records: ').$count."\n";
