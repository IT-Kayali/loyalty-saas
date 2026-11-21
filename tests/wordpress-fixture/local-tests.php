<?php
// Disposable test installation only. Never ship this adapter.
add_filter('pre_wp_mail',fn()=>true);
add_filter('pre_http_request',function($pre,$args,$url){
 if(false!==$pre)return $pre;
 $state=getenv('WORDPRESS_TEST_STATE');
 if(!str_starts_with($url,'https://example.com/api/v1/'))return $pre;
 file_put_contents($state.'/transport.json',json_encode(['url'=>$url,'args'=>$args]));
 if(file_exists($state.'/fail-response'))return new WP_Error('local','simulated uncertain outcome');
 $curl=curl_init('http://127.0.0.1:8099'.substr($url,strlen('https://example.com')));$headers=[];foreach($args['headers'] as $k=>$v)$headers[]=$k.': '.$v;
 curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$args['method'],CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>5]);if(!empty($args['body']))curl_setopt($curl,CURLOPT_POSTFIELDS,$args['body']);$body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
 if($args['method']==='POST')file_put_contents($state.'/last-write.json',json_encode(['request'=>json_decode($args['body'],true),'reply'=>json_decode($body,true)]));
 if(file_exists($state.'/lose-response'))return new WP_Error('local','simulated lost reply after commit');
 return ['headers'=>['content-type'=>'application/json'],'body'=>$body,'response'=>['code'=>$code,'message'=>'Test'],'cookies'=>[],'filename'=>null];
},10,3);
