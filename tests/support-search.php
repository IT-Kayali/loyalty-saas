<?php
declare(strict_types=1);
require 'public/index.php';
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
$n=0;function yes(bool $v,string $label):void{global $n;if(!$v)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
$expected=[
 'de'=>[['open'=>'Offen','awaiting'=>'Wartet auf Antwort','closed'=>'Geschlossen'],['low'=>'Niedrig','normal'=>'Normal','high'=>'Hoch'],['partner'=>'Angemeldete Betreiber','contact'=>'Kontaktformular (unbestätigt)']],
 'en'=>[['open'=>'Open','awaiting'=>'Awaiting reply','closed'=>'Closed'],['low'=>'Low','normal'=>'Normal','high'=>'High'],['partner'=>'Signed-in operators','contact'=>'Contact form (unverified)']],
];
foreach($expected as $locale=>$groups){
 $_GET=['lang'=>$locale];
 foreach([SUPPORT_STATUS,SUPPORT_PRIORITY,SUPPORT_CHANNELS] as $i=>$group)foreach($group as $code=>$_)yes(support_label($group,$code)===$groups[$i][$code],$locale.' static label '.$code);
 yes(support_label(SUPPORT_STATUS,'Offen')==='Offen' && support_label(SUPPORT_TOPICS,'unknown')==='unknown',$locale.' unknown raw codes never translated');
 $f=support_list_filters(['q'=>'x','lang'=>$locale],true);yes(support_list_filter_query($f)===['q'=>'x','lang'=>$locale],$locale.' list/export query pins selected locale');
 $w=support_list_where(support_list_filters(['q'=>$groups[0]['awaiting'],'lang'=>$locale],true),true,1);
 yes(str_contains($w[0],'t.status IN (?)') && in_array('awaiting',$w[1],true),$locale.' visible label maps to bound stored code');
 [$where,$params]=support_list_where(support_list_filters(['q'=>'%_!','status'=>'closed','priority'=>'low','channel'=>'contact','assignee'=>'0'],true),true,1);
 yes(substr_count($where,'?')===count($params) && in_array('%!%!_!!%',$params,true) && str_contains($where,' AND ('),$locale.' wildcard literal and surrounding filter parameter shape unchanged');
}
foreach(['fr','EN','',[],"en\n","\xff"] as $i=>$value)foreach([false,true] as $export){
 $failed=false;try{support_list_filters(['lang'=>$value],true,$export);}catch(InvalidArgumentException){$failed=true;}yes($failed,'invalid locale rejected '.$i.' '.($export?'export':'list'));
}
$_GET=['lang'=>'en'];[$w,$p]=support_list_where(support_list_filters(['q'=>'UNASSIGNED','status'=>'closed','channel'=>'contact'],true),true,1);
yes(str_contains($w,'t.deleted_at IS NULL') && str_contains($w,'t.status=?') && str_contains($w,'t.channel=?') && str_contains($w,' OR a.name IS NULL)'),'unassigned shortcut is inside grouped search under all surrounding restrictions');
[$w,$p]=support_list_where(support_list_filters(['q'=>'Open'],false),false,17);
yes(str_contains($w,"t.user_id=? AND t.channel='partner'") && !str_contains($w,' IN (') && !str_contains($w,'requester_name') && !str_contains($w,'a.name'),'partner free text remains owner-scoped reference/subject only');
[$w,$p]=support_list_where(support_list_filters(['q'=>"x' OR 1=1 --"],true),true,1);
yes(!str_contains($w,"x' OR") && count($p)===6,'search text always bound never interpolated');
[$w,$p]=support_list_where(support_list_filters(['q'=>'Nicht zugewiesen'],true),true,1);
yes(str_contains($w,"COALESCE(a.name,'Nicht zugewiesen')"),'legacy German unassigned text still searchable in English');
$row=['reference'=>'0123456789ABCDEF','subject'=>'Offen','topic'=>'billing','status'=>'awaiting','priority'=>'high','updated_at_utc'=>'2026-11-22 12:00:00','channel'=>'contact','requester_name'=>'Niedrig','assigned_name'=>'Hoch'];
$j=json_decode(support_list_encode([$row],'json'),true,512,JSON_THROW_ON_ERROR)['tickets'][0];
yes($j===$row,'English search never translates exported codes names or subject');
$_GET=['lang'=>'de'];yes(support_label(SUPPORT_STATUS,'open')==='Offen','same-process language change returns German not cached English');
echo "$n support search domain assertions passed\n";
