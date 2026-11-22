<?php
require 'public/index.php';
$n=0;function yes($v,$label){global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$now=strtotime('2026-11-22 12:00:00 UTC');
$d=db();$d->exec('DELETE FROM mail_outbox;DELETE FROM webhook_deliveries');
$s=admin_queue_snapshot($now);$document=admin_health_document($s);$v=$document['data'];
yes(array_keys($document)===['success','data']&&$document['success']===true,'success is document retrieval, not queue health');
yes(array_keys($v)===['schema_version','scope','observed_at_utc','smtp_configured','mail','webhooks','limitations','not_checked'],'exact versioned document fields');
yes($v['schema_version']===1&&$v['scope']==='retained_delivery_queues','explicit local queue scope and schema');
yes($v['observed_at_utc']==='2026-11-22 12:00:00'&&$v['smtp_configured']===false,'UTC observation and configuration-only boolean');
yes(!isset($v['checks'])&&!isset($v['summary'])&&!isset($v['status']),'no invented severity summary or full reference diagnostic claim');
yes($v['mail']['total']===0&&$v['webhooks']['total']===0,'empty queues retain real zeros');
yes($v['mail']['oldest_queued']===null&&$v['webhooks']['oldest_due']===null,'empty queues have no invented timestamps');
yes($v['limitations']===['retained_rows_only','overlapping_subcounts','canonical_utc_only','smtp_configuration_only','pending_webhooks_not_retried','no_delivery_proof'],'stable documented limitations');
yes($v['not_checked']===['worker_heartbeat','cron_execution','smtp_connectivity','inbox_delivery','wallet_delivery','device_display','environment','migrations','storage','billing'],'explicit unsupported checks');
require 'fixture.php';health_fixture($now);$before=health_state();$s=admin_queue_snapshot($now);$v=admin_health_document($s)['data'];
yes($v['mail']===$s['mail']&&$v['webhooks']===$s['webhooks'],'JSON uses same atomic aggregate snapshot, no separate query');
yes(array_keys($v['mail'])===['total','queued','sending','stale_sending','unknown','sent','skipped','other','bad_open_time','oldest_queued'],'mail schema has only established aggregates');
yes(array_keys($v['webhooks'])===['total','pending','failed','dead','delivered','other','due_unlocked','due_locked','unscheduled','expired_locks','bad_open_time','oldest_due'],'webhook schema has only established aggregates');
foreach(['mail','webhooks'] as $group){foreach($v[$group] as $key=>$value)yes(str_starts_with($key,'oldest_')?is_string($value):is_int($value),$group.'.'.$key.' has stable scalar type');}
yes($v['mail']['total']===14&&$v['webhooks']['total']===13&&$v['mail']['stale_sending']===1&&$v['webhooks']['due_locked']===2,'same exact totals and strict time boundaries');
yes(!str_contains(json_encode($v),'PRIVATE-'),'no fixture personal text, secrets, payloads, raw errors or raw statuses');
yes(health_state()===$before,'document generation leaves queues, audit, settings and device state unchanged');
$s['unexpected_private_field']='PRIVATE-UNEXPECTED';yes(!str_contains(json_encode(admin_health_document($s)),'PRIVATE-'),'top-level snapshot metadata is not blindly serialized');
echo "$n admin health JSON domain assertions passed\n";
