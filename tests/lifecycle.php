<?php
declare(strict_types=1);
require getcwd().'/public/index.php';
$n=0;function ok(bool $b,string $s):void{if(!$b)throw new RuntimeException($s);echo 'PASS '.$s.PHP_EOL;$GLOBALS['n']++;}
$db=db();$uid=(int)$db->query('SELECT user_id FROM businesses WHERE id=1')->fetchColumn();
$mem=$db->query('SELECT id,email FROM members WHERE business_id=1 ORDER BY id LIMIT 2')->fetchAll();$mid=(int)$mem[0]['id'];$mid2=(int)$mem[1]['id'];
$db->exec('UPDATE members SET marketing_consent=1 WHERE id IN ('.$mid.','.$mid2.')');
$db->prepare("INSERT INTO programs(business_id,type,name,voucher_rules) VALUES(1,'voucher','Geburtstagsgutschein',?)")->execute([json_encode(['type'=>'fixed','value'=>500,'per_member'=>1])]);
$vp=(int)$db->lastInsertId();
$db->prepare("INSERT INTO programs(business_id,type,name) VALUES(2,'voucher','Fremdes Programm')")->execute();$foreign=(int)$db->lastInsertId();
// Geburtstag: Mitglied 1 heute, Mitglied 2 nicht
$db->prepare('UPDATE members SET birthday=? WHERE id=?')->execute(['1990-'.gmdate('m-d'),$mid]);
$db->prepare('UPDATE members SET birthday=? WHERE id=?')->execute(['1990-'.gmdate('m-d',time()+86400*40),$mid2]);
$db->prepare("INSERT INTO mail_automations(business_id,event,subject,body,voucher_program_id,voucher_days)VALUES(1,'birthday','Alles Gute {name}','Hallo {name}, dein Code: {code}, gültig bis {valid_until}',?,14)")->execute([$vp]);
ok(automation_enqueue()===1,'Geburtstag: genau eine Mail mit Gutschein eingereiht');
$code=(string)$db->query("SELECT code FROM voucher_codes WHERE program_id=$vp")->fetchColumn();
ok($db->query("SELECT COUNT(*) FROM voucher_codes WHERE program_id=$vp")->fetchColumn()==1 && (int)$db->query("SELECT member_id FROM voucher_codes WHERE program_id=$vp")->fetchColumn()===$mid,'Code gehört nur dem Geburtstagskind');
$body=(string)$db->query("SELECT body FROM mail_outbox WHERE member_id=$mid AND dedup_key LIKE 'auto:%'")->fetchColumn();
ok(str_contains($body,$code) && !str_contains($body,'{code}') && !str_contains($body,'{valid_until}'),'Platzhalter {code} und {valid_until} ersetzt');
$subj=(string)$db->query("SELECT subject FROM mail_outbox WHERE member_id=$mid AND dedup_key LIKE 'auto:%'")->fetchColumn();ok(str_contains($subj,'Alles Gute'),'Betreff personalisiert');
$snap=json_decode((string)$db->query("SELECT rules_snapshot FROM voucher_codes WHERE code='$code'")->fetchColumn(),true);
ok($snap['ends']===gmdate('Y-m-d',time()+14*86400),'Gültigkeit 14 Tage im Snapshot');
ok(automation_enqueue()===0 && $db->query("SELECT COUNT(*) FROM voucher_codes WHERE program_id=$vp")->fetchColumn()==1,'zweiter Lauf: keine zweite Mail, kein zweiter Code');
// Einlösung durch das richtige Mitglied, nicht durch ein anderes
$actor=['kind'=>'owner','id'=>$uid];
try{voucher_redeem($code,$mid2,2000,$actor,'life-wrong');ok(false,'fremdes Mitglied darf nicht einlösen');}catch(InvalidArgumentException $e){ok(true,'Code eines anderen Mitglieds wird abgelehnt');}
$r=voucher_redeem($code,$mid,2000,$actor,'life-ok');ok((int)$r['discount_cents']===500,'Gutschein löst 5,00 € ein');
try{voucher_redeem($code,$mid,2000,$actor,'life-twice');ok(false,'zweite Einlösung');}catch(InvalidArgumentException $e){ok(true,'Einmal-Code nicht erneut einlösbar');}
// Ohne Einwilligung nichts
$db->exec('UPDATE members SET marketing_consent=0 WHERE id='.$mid);$db->exec('DELETE FROM mail_outbox');$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM voucher_redemptions');$db->exec('DELETE FROM voucher_codes');
ok(automation_enqueue()===0 && $db->query('SELECT COUNT(*) FROM voucher_codes')->fetchColumn()==0,'ohne Einwilligung weder Mail noch Code');
$db->exec('UPDATE members SET marketing_consent=1 WHERE id='.$mid);
// Fremdes Programm (anderes Unternehmen): keine Ausgabe
$db->exec('UPDATE mail_automations SET voucher_program_id='.$foreign);
ok(automation_enqueue()===0 && $db->query('SELECT COUNT(*) FROM voucher_codes')->fetchColumn()==0,'Gutschein-Programm eines anderen Unternehmens wird nicht verwendet');
// Inaktives Gutschein-Programm
$db->exec('UPDATE mail_automations SET voucher_program_id='.$vp);$db->exec("UPDATE programs SET active=0 WHERE id=$vp");
ok(automation_enqueue()===0,'inaktives Gutschein-Programm gibt nichts aus');$db->exec("UPDATE programs SET active=1 WHERE id=$vp");
// Abgelaufene Programmlaufzeit
$db->prepare('UPDATE programs SET voucher_rules=? WHERE id=?')->execute([json_encode(['type'=>'fixed','value'=>500,'ends'=>'2001-01-01']),$vp]);
ok(automation_enqueue()===0,'Programm mit abgelaufenem Ende gibt nichts aus');
// Programmende kürzt die Gültigkeit
$end=gmdate('Y-m-d',time()+3*86400);$db->prepare('UPDATE programs SET voucher_rules=? WHERE id=?')->execute([json_encode(['type'=>'fixed','value'=>500,'ends'=>$end]),$vp]);
ok(automation_enqueue()===1 && json_decode((string)$db->query('SELECT rules_snapshot FROM voucher_codes')->fetchColumn(),true)['ends']===$end,'Programmende kürzt die Gültigkeit');
// Text ohne {code}: Code wird angehängt
$db->exec('DELETE FROM mail_outbox');$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM voucher_redemptions');$db->exec('DELETE FROM voucher_codes');$db->exec("UPDATE mail_automations SET body='Hallo {name}'");
ok(automation_enqueue()===1 && str_contains((string)$db->query('SELECT body FROM mail_outbox')->fetchColumn(),'Dein Gutscheincode: '),'ohne Platzhalter wird der Code angehängt');
// Winback mit Gutschein, Zyklus pro letzter Aktivität
$db->exec('DELETE FROM mail_outbox');$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM voucher_redemptions');$db->exec('DELETE FROM voucher_codes');$db->exec('DELETE FROM mail_automations');$db->exec('UPDATE members SET birthday=NULL');
$db->prepare("INSERT INTO mail_automations(business_id,event,days,subject,body,voucher_program_id)VALUES(1,'winback',30,'Wir vermissen dich','Hi {name} {code}',?)")->execute([$vp]);
$db->exec("UPDATE members SET created_at='2000-01-01' WHERE business_id=1");$db->exec("DELETE FROM transactions WHERE card_id IN (SELECT id FROM cards WHERE member_id IN (SELECT id FROM members WHERE business_id=1))");
$q1=automation_enqueue();$codes=(int)$db->query('SELECT COUNT(*) FROM voucher_codes')->fetchColumn();
ok($q1>=1 && $codes===$q1,'Winback: je eingereihter Mail genau ein Code');
ok(automation_enqueue()===0,'Winback: nicht erneut innerhalb desselben Inaktivitätszyklus');
// Kanal beide mit SMTP: eine Zustellung, eine Mail, eine Wallet-Nachricht, ein gemeinsamer Code
$db->exec('DELETE FROM mail_outbox');$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM voucher_redemptions');$db->exec('DELETE FROM voucher_codes');$db->exec('DELETE FROM mail_automations');$db->exec('DELETE FROM automation_runs');$db->exec('DELETE FROM member_messages');
$db->prepare('UPDATE members SET birthday=? WHERE id=?')->execute(['1990-'.gmdate('m-d'),$mid]);$db->exec('UPDATE members SET birthday=NULL WHERE id='.$mid2);$db->exec('UPDATE members SET marketing_consent=0 WHERE id<>'.$mid.' AND business_id=1');
$db->prepare("INSERT INTO mail_automations(business_id,event,subject,body,voucher_program_id,voucher_days,channel)VALUES(1,'birthday','Hi {name}','Code {code}',?,10,'both')")->execute([$vp]);
ok(automation_enqueue()===1 && (int)$db->query("SELECT COUNT(*) FROM mail_outbox")->fetchColumn()===1 && (int)$db->query("SELECT COUNT(*) FROM member_messages WHERE kind='automation'")->fetchColumn()===1 && (int)$db->query('SELECT COUNT(*) FROM voucher_codes')->fetchColumn()===1,'Kanal beide: eine Mail, eine Wallet-Nachricht, ein Code');
$code2=(string)$db->query('SELECT code FROM voucher_codes')->fetchColumn();
ok(str_contains((string)$db->query('SELECT body FROM mail_outbox')->fetchColumn(),$code2) && str_contains((string)$db->query("SELECT text FROM member_messages WHERE kind='automation'")->fetchColumn(),$code2),'Mail und Wallet nennen denselben Code');
ok(automation_enqueue()===0,'Kanal beide: zweiter Lauf ohne Doppelzustellung');
echo $n," Lifecycle-Prüfungen bestanden\n";
