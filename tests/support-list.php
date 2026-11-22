<?php
declare(strict_types=1);
require 'public/index.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
function rejects(callable $f,string $label,string $class=InvalidArgumentException::class):void{try{$f();}catch(Throwable $e){yes($e instanceof $class,$label);return;}throw new RuntimeException($label.' unexpectedly accepted');}
$f=support_list_filters([],true);yes($f===['q'=>'','status'=>'','topic'=>'','priority'=>'','assignee'=>'','channel'=>'','after'=>0],'empty filters have explicit safe defaults');
$f=support_list_filters(['q'=>'  Prüfung %_!  ','status'=>'open','topic'=>'billing','priority'=>'high','channel'=>'partner','assignee'=>'0002','after'=>'0007'],true);
yes($f['q']==='Prüfung %_!' && $f['assignee']==='2' && $f['after']===7,'valid filters trim search and canonicalize numeric IDs');
yes(support_list_filter_query($f)===array_diff_key($f,['after'=>1]),'export query omits list cursor and retains all active filters');
yes(support_list_filter_query(support_list_filters(['assignee'=>'0'],true))===['assignee'=>'0'],'unassigned zero survives query serialization');
foreach([
 ['q'=>[]],['status'=>[]],['topic'=>[]],['priority'=>[]],['channel'=>[]],['assignee'=>[]],['after'=>[]],['q'=>null],['q'=>"\xff"],['q'=>"line\nline"],['q'=>"hidden\u{200b}"],['q'=>str_repeat('ä',101)],['status'=>'garbage'],['topic'=>'garbage'],['priority'=>'garbage'],['channel'=>'garbage'],['assignee'=>'-1'],['assignee'=>'1.0'],['assignee'=>'999999999999999999999999'],['after'=>'-1'],['after'=>'1x'],['after'=>'999999999999999999999999'],
] as $i=>$input)rejects(fn()=>support_list_filters($input,true),'invalid applicable filter rejected '.$i);
foreach([['after'=>'0'],['deleted'=>'1'],['action'=>'delete'],['user_id'=>'1']] as $i=>$input)rejects(fn()=>support_list_filters($input,true,true),'export rejects cursor or unsupported scope '.$i);
$p=support_list_filters(['channel'=>[],'priority'=>'invalid','assignee'=>'-1','q'=>'same'],false);yes($p['channel']==='' && $p['priority']==='' && $p['assignee']==='','partner continues ignoring admin-only selectors');
[$w,$params]=support_list_where($p,false,17);yes(str_contains($w,"t.user_id=? AND t.channel='partner'") && $params[1]===17 && !str_contains($w,'requester_email'),'partner predicates bind exact owner and restrict searchable columns');
[$w,$params]=support_list_where(support_list_filters(['q'=>'%_!','assignee'=>'0','channel'=>'contact'],true),true,17);yes(str_contains($w,'deleted_at IS NULL') && str_contains($w,'COALESCE(t.assignee,0)') && in_array('%!%!_!!%',$params,true),'admin predicates exclude hidden and escape wildcard characters literally');
yes(support_list_filters(['q'=>str_repeat('ä',100),'after'=>(string)PHP_INT_MAX,'assignee'=>'0'],true)['after']===PHP_INT_MAX,'exact search and integer boundaries accepted');
$r=['reference'=>'0123456789ABCDEF','subject'=>'Normale Prüfung','topic'=>'billing','status'=>'open','priority'=>'normal','updated_at_utc'=>'2026-11-22 12:30:00','channel'=>'partner','requester_name'=>'Aylin','assigned_name'=>null,'body'=>'SECRET-BODY','requester_email'=>'SECRET-EMAIL'];
$j=json_decode(support_list_encode([$r],'json'),true,512,JSON_THROW_ON_ERROR);
yes($j['format']==='loyalty-support-inbox' && $j['version']===1 && $j['row_count']===1 && $j['row_limit']===5000 && $j['byte_limit']===4194304 && $j['truncated']===false && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$j['exported_at_utc'])===1,'JSON version count and UTC export time fixed');
yes(array_keys($j['tickets'][0])===SUPPORT_LIST_FIELDS && !str_contains(json_encode($j),'SECRET-'),'JSON projection also independently rejects extra private fields');
yes($j['tickets'][0]['assigned_name']===null && $j['tickets'][0]['subject']===$r['subject'],'JSON preserves original text and unassigned null');
foreach(['=1+2','+SUM(A1)','-1+2','@SUM(A1)','＝1+2','＋2','－2','＠x'," \t=1", "\u{00a0}=1", "\u{2003}+2", "\u{200b}@x", "\tplain", "\rplain", "\nplain"] as $i=>$v){
 $encoded=support_list_cell($v);yes(str_starts_with($encoded,'"\''),'spreadsheet risky prefix neutralized '.$i);
}
yes(support_list_cell('Safe, "quoted"; ä')==='"Safe, ""quoted""; ä"','quotes doubled and full field always quoted');
yes(support_list_cell("O'Neil")==='"O\'Neil"','ordinary apostrophe kept exactly');
foreach(['csv','tsv','json'] as $format){
 $empty=support_list_encode([],$format);yes($format==='json'?json_decode($empty,true)['tickets']===[]:substr_count($empty,"\r\n")===1,$format.' empty result is valid header or empty collection');
 $bad=$r;$bad['subject']="invalid\xff";rejects(fn()=>support_list_encode([$bad],$format),$format.' invalid stored UTF-8 never replaced',JsonException::class);
 yes(strlen(support_list_encode(array_fill(0,5000,$r),$format))>0,$format.' exact5000 rows accepted');
 rejects(fn()=>support_list_encode(array_fill(0,5001,$r),$format),$format.' row5001 hard error',LengthException::class);
 $big=$r;$big['subject']='';$base=strlen(support_list_encode([$big],$format));$big['subject']=str_repeat('x',SUPPORT_LIST_EXPORT_BYTES-$base);
 yes(strlen(support_list_encode([$big],$format))===SUPPORT_LIST_EXPORT_BYTES,$format.' exact4MiB encoded bytes accepted');
 $big['subject'].='x';rejects(fn()=>support_list_encode([$big],$format),$format.' byte over4MiB hard error',LengthException::class);unset($big);
}
rejects(fn()=>support_list_encode([$r],'html'),'unknown format rejected');
echo "$n support list domain assertions passed\n";
