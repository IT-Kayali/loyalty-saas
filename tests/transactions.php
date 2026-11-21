<?php
declare(strict_types=1);
require getcwd().'/public/index.php';
$n=0;
function verify(bool $yes,string $label):void {if(!$yes)throw new RuntimeException($label);echo 'PASS '.$label.PHP_EOL;$GLOBALS['n']++;}
$db=db();$db->exec('DELETE FROM achievements');$id=(int)$db->query("SELECT c.id FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.type='points' LIMIT 1")->fetchColumn();
$card=own_card(1,$id);$db->prepare('UPDATE cards SET points=50 WHERE id=?')->execute([$id]);
$db->prepare('INSERT INTO rewards(program_id,name,points_cost)VALUES(?,?,?)')->execute([$card['program_id'],'Unit reward',50]);$reward=(int)$db->lastInsertId();
$stale=own_card(1,$id);do_card_action($stale,'redeem_reward:'.$reward);do_card_action($stale,'redeem_reward:'.$reward);
verify((int)$db->query('SELECT points FROM cards WHERE id='.$id)->fetchColumn()===0,'stale repeated redemption cannot overdraw points');
$tx=(int)$db->query("SELECT id FROM transactions WHERE card_id=$id AND kind='points_redeem' ORDER BY id DESC LIMIT 1")->fetchColumn();
verify(undo_transaction($stale,$tx),'first reversal succeeds');verify(!undo_transaction($stale,$tx),'same reversal cannot run twice');
verify((int)$db->query('SELECT points FROM cards WHERE id='.$id)->fetchColumn()===50,'reversal restores exact points');
$b=$db->query('SELECT * FROM businesses WHERE id=1')->fetch();$b['points_per_cent']=1;
verify(api_credit_order_points($b,(int)$card['member_id'],100,'unit-order-001')===100,'first order credit succeeds');
verify(api_credit_order_points($b,(int)$card['member_id'],100,'unit-order-001')===0,'duplicate order receipt ignored');
verify((int)$db->query('SELECT points FROM cards WHERE id='.$id)->fetchColumn()===150,'duplicate cannot double-credit');
try{api_credit_order_points($b,(int)$card['member_id'],100,'');throw new RuntimeException('Missing reference accepted');}catch(InvalidArgumentException){verify(true,'missing stable reference rejected');}
echo $n.' assertions passed'.PHP_EOL;
