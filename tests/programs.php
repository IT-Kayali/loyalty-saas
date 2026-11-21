<?php
declare(strict_types=1);
require getcwd().'/public/index.php';
$n=0;
function yes(bool $x,string $s):void{if(!$x)throw new RuntimeException($s);echo 'PASS '.$s.PHP_EOL;$GLOBALS['n']++;}
function rejects(callable $fn,string $s):void{try{$fn();throw new RuntimeException('Not rejected: '.$s);}catch(InvalidArgumentException){yes(true,$s);}}
$db=db();$actor=['kind'=>'owner','id'=>1];
yes(money_cents('12,34')===1234,'exact cents parser');rejects(fn()=>money_cents('1.234'),'reject excess decimals');
$r=voucher_rules([]);$r['type']='percent';$r['value']=2000;$r['max_discount']=500;$r['min_spend']=1000;
yes(voucher_quote($r,10000)===500,'percentage capped in cents');rejects(fn()=>voucher_quote($r,900),'minimum spend');
$vp=(int)$db->query("SELECT id FROM programs WHERE type='voucher' LIMIT 1")->fetchColumn();
$db->prepare('UPDATE programs SET voucher_rules=? WHERE id=?')->execute([json_encode($r),$vp]);
$member=(int)$db->query("SELECT m.id FROM members m JOIN programs p ON p.business_id=m.business_id WHERE p.type='voucher' LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,'test','CODEUNIT',?)")->execute([$vp,json_encode($r)]);
$use=voucher_redeem('CODEUNIT',$member,10000,$actor,'unit-voucher');yes((int)$use['discount_cents']===500,'voucher redemption records computed discount');
$again=voucher_redeem('CODEUNIT',$member,10000,$actor,'unit-voucher');yes((int)$again['id']===(int)$use['id'],'voucher operation idempotent');
rejects(fn()=>voucher_redeem('CODEUNIT',$member,10000,$actor,'different-operation'),'used code cannot redeem again');
$past=$r;$past['ends']='2000-01-01';$db->prepare("INSERT INTO voucher_codes(program_id,batch,code,rules_snapshot)VALUES(?,'test','EXPIRED',?)")->execute([$vp,json_encode($past)]);rejects(fn()=>voucher_redeem('EXPIRED',$member,10000,$actor,'expired-op'),'expired voucher rejected');
$pp=(int)$db->query("SELECT id FROM programs WHERE type='pass' LIMIT 1")->fetchColumn();$db->prepare("UPDATE programs SET pass_visits=10,pass_valid_days=30,pass_price_cents=5000 WHERE id=?")->execute([$pp]);
$member=(int)$db->query('SELECT id FROM members WHERE business_id=1 LIMIT 1')->fetchColumn();
$id=pass_sale($pp,$member,$actor,'external-receipt-1',5000,'sale-1');yes($id>0,'visit pass sold as separate issue');
yes(pass_sale($pp,$member,$actor,'external-receipt-1',5000,'sale-1')===$id,'sale retry idempotent');
$id2=pass_sale($pp,$member,$actor,'external-receipt-2',5000,'sale-2');yes($id2!==$id,'same product can sell twice to same member');
$db->prepare("UPDATE programs SET pass_visits=20,pass_valid_days=90 WHERE id=?")->execute([$pp]);
yes((int)$db->query('SELECT original_visits FROM prepaid_issues WHERE id='.$id)->fetchColumn()===10,'sold terms survive product edit');
pass_use($id,2,0,$actor,'visit-1');yes((int)$db->query('SELECT remaining FROM prepaid_issues WHERE id='.$id)->fetchColumn()===8,'visits decremented');
pass_use($id,2,0,$actor,'visit-1');yes((int)$db->query('SELECT remaining FROM prepaid_issues WHERE id='.$id)->fetchColumn()===8,'visit retry idempotent');
rejects(fn()=>pass_use($id,1,0,$actor,'stale-op'),'stale pass version rejected');
rejects(fn()=>pass_use($id,9,1,$actor,'too-many'),'insufficient visits rejected');
$entry=(int)$db->query("SELECT id FROM prepaid_ledger WHERE issue_id=$id AND kind='visit'")->fetchColumn();
rejects(fn()=>pass_reverse($entry,['kind'=>'staff','id'=>999],'bad-reverse'),'staff cannot reverse another actor');
pass_reverse($entry,$actor,'reverse-1');pass_reverse($entry,$actor,'reverse-1');yes((int)$db->query('SELECT remaining FROM prepaid_issues WHERE id='.$id)->fetchColumn()===10,'reversal adds visits exactly once');
try{$db->exec('DELETE FROM prepaid_ledger WHERE id='.$entry);throw new RuntimeException('Ledger delete allowed');}catch(PDOException){yes(true,'ledger is append-only');}
$db->prepare("UPDATE programs SET pass_kind='unlimited',pass_valid_days=30 WHERE id=?")->execute([$pp]);$unlimited=pass_sale($pp,$member,$actor,'external-receipt-3',10000,'sale-3');pass_use($unlimited,1,0,$actor,'u-visit');yes($db->query('SELECT remaining FROM prepaid_issues WHERE id='.$unlimited)->fetchColumn()===null,'unlimited visit records without count');
$db->prepare("UPDATE prepaid_issues SET expires_at='2000-01-01' WHERE id=?")->execute([$id]);rejects(fn()=>pass_use($id,1,2,$actor,'expired-pass'),'expired pass cannot be used');
$GLOBALS['privacy_delete_authorized']=true;$db->exec('DELETE FROM members WHERE id='.$member);$GLOBALS['privacy_delete_authorized']=false;
yes($db->query('SELECT member_id FROM prepaid_issues WHERE id='.$id)->fetchColumn()===null,'privacy deletion detaches member from retained financial record');
yes((int)$db->query('SELECT COUNT(*) FROM prepaid_ledger WHERE issue_id='.$id)->fetchColumn()>0,'privacy deletion retains financial ledger');
echo $n.' assertions passed'.PHP_EOL;
