<?php
require getcwd().'/public/index.php';$b=db()->query('SELECT * FROM businesses WHERE id=1')->fetch();$id=(int)db()->query("SELECT c.id FROM cards c JOIN programs p ON p.id=c.program_id WHERE p.business_id=1 AND p.type='points' LIMIT 1")->fetchColumn();echo json_encode(merchant_write($b,['operation'=>str_repeat('9',32),'action'=>'points_add','amount'=>2],'card',$id));
