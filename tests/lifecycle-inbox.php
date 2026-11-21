<?php
declare(strict_types=1);
/* Ohne SMTP: Zustellung in die Wallet-Inbox. Der Zustand bleibt fuer den Browsertest erhalten. */
require getcwd().'/public/index.php';
$n=0;function ok(bool $b,string $s):void{if(!$b)throw new RuntimeException($s);echo 'PASS '.$s.PHP_EOL;$GLOBALS['n']++;}
$db=db();ok(!smtp_ready(),'SMTP ist aus');
$aylin=(int)$db->query("SELECT id FROM members WHERE business_id=1 AND email='aylin@example.com'")->fetchColumn();ok($aylin>0,'Demo-Mitglied Aylin vorhanden');
$db->exec('UPDATE members SET marketing_consent=0 WHERE business_id=1');$db->exec("UPDATE members SET birthday=NULL WHERE business_id=1");
$db->prepare('UPDATE members SET marketing_consent=1,birthday=? WHERE id=?')->execute(['1990-'.gmdate('m-d'),$aylin]);
$db->prepare("INSERT INTO programs(business_id,type,name,voucher_rules) VALUES(1,'voucher','Geburtstagsgutschein',?)")->execute([json_encode(['type'=>'percent','value'=>1500,'per_member'=>1])]);$vp=(int)$db->lastInsertId();
$ins=$db->prepare("INSERT INTO mail_automations(business_id,event,subject,body,voucher_program_id,voucher_days,channel)VALUES(1,'birthday',?,?,?,10,?)");
$ins->execute(['Alles Gute {name}','Dein Code {code} gilt bis {valid_until}',$vp,'email']);
ok(automation_enqueue()===0 && (int)$db->query('SELECT COUNT(*) FROM voucher_codes')->fetchColumn()===0,'Kanal E-Mail ohne SMTP: nichts, kein Code');
$db->exec('UPDATE mail_automations SET channel=\'inbox\'');
ok(automation_enqueue()===1,'Kanal Wallet-Inbox: eine Zustellung ohne SMTP');
$code=(string)$db->query('SELECT code FROM voucher_codes')->fetchColumn();
$msg=(string)$db->query("SELECT text FROM member_messages WHERE member_id=$aylin AND kind='automation'")->fetchColumn();
ok($code!=='' && str_contains($msg,$code) && str_contains($msg,'Alles Gute Aylin') && !str_contains($msg,'{'),'Wallet-Nachricht mit Code, ohne Platzhalter');
ok((int)$db->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn()===0,'keine E-Mail eingereiht');
ok(automation_enqueue()===0 && (int)$db->query('SELECT COUNT(*) FROM voucher_codes')->fetchColumn()===1 && (int)$db->query("SELECT COUNT(*) FROM member_messages WHERE kind='automation'")->fetchColumn()===1,'zweiter Lauf: keine Doppelzustellung');
$h=member_vouchers_html([$aylin]);ok(str_contains($h,$code) && str_contains($h,'15 % Rabatt') && str_contains($h,'gültig bis'),'Wallet zeigt Code, Wert und Gültigkeit');
ok(member_vouchers_html([(int)$db->query("SELECT id FROM members WHERE business_id=1 AND id<>$aylin LIMIT 1")->fetchColumn()])==='','andere Mitglieder sehen den Code nicht');
// eingelöst oder abgelaufen: nicht mehr in der Wallet
$db->exec("UPDATE voucher_codes SET used=1");ok(member_vouchers_html([$aylin])==='','eingelöster Code verschwindet aus der Wallet');
$db->exec("UPDATE voucher_codes SET used=0,rules_snapshot=json_set(rules_snapshot,'$.ends','2001-01-01')");ok(member_vouchers_html([$aylin])==='','abgelaufener Code verschwindet aus der Wallet');
$db->exec("UPDATE voucher_codes SET rules_snapshot=json_set(rules_snapshot,'$.ends','".gmdate('Y-m-d',time()+5*86400)."')");ok(str_contains(member_vouchers_html([$aylin]),$code),'gültiger Code wieder sichtbar');
// Einwilligung
$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM voucher_codes');$db->exec('DELETE FROM member_messages');$db->exec('UPDATE members SET marketing_consent=0 WHERE id='.$aylin);
ok(automation_enqueue()===0 && (int)$db->query('SELECT COUNT(*) FROM member_messages')->fetchColumn()===0,'ohne Einwilligung keine Wallet-Nachricht');
$db->exec('UPDATE members SET marketing_consent=1 WHERE id='.$aylin);
// Kanal both ohne SMTP: nur Inbox
$db->exec("UPDATE mail_automations SET channel='both'");
ok(automation_enqueue()===1 && (int)$db->query('SELECT COUNT(*) FROM mail_outbox')->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM member_messages')->fetchColumn()===1,'Kanal beide ohne SMTP: nur Wallet-Inbox');
// Automation ohne Gutschein, nur Text
$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM voucher_codes');$db->exec('DELETE FROM member_messages');$db->exec('DELETE FROM mail_automations');
$db->prepare("INSERT INTO mail_automations(business_id,event,subject,body,channel)VALUES(1,'birthday','Hallo','Schönen Tag {name}','inbox')")->execute();
ok(automation_enqueue()===1 && (int)$db->query('SELECT COUNT(*) FROM voucher_codes')->fetchColumn()===0,'reine Text-Nachricht ohne Gutschein');
// fuer den Browsertest: Gutschein-Automation neu
$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM member_messages');$db->exec('DELETE FROM mail_automations');
$ins->execute(['Alles Gute {name}','Dein Code {code} gilt bis {valid_until}',$vp,'inbox']);automation_enqueue();
echo $n," Inbox-Prüfungen bestanden\n";
