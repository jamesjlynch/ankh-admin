<?php
declare(strict_types=1);
require_once __DIR__.'/../customer-catalogue.php';
function announceCheck(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
ankhAnnouncementsInit($db);
$now=(new DateTimeImmutable('2026-10-22T12:00:00',new DateTimeZone('Europe/London')))->getTimestamp();
$input=[
 'announcement_type'=>'Delivery',
 'announcement_title'=>'Dispatch update',
 'announcement_message'=>"Delivery delayed 1 day.\nContact support.",
 'announcement_start'=>'2026-10-22T11:00',
 'announcement_end'=>'2026-10-22T13:00',
 'announcement_state'=>'Draft'
];
$id=ankhAnnouncementSave($db,$input,$now);
announceCheck($id>0 && ankhAnnouncementLive($db,$now)===null,'Draft must not be visible');
$input['announcement_id']=$id;$input['announcement_state']='Published';
ankhAnnouncementSave($db,$input,$now);
$live=ankhAnnouncementLive($db,$now);
announceCheck($live!==null && $live['title']==='Dispatch update'&&$live['message']===$input['announcement_message'],'Current published notice is visible');
announceCheck(ankhAnnouncementLive($db,$now+3600)===null,'Notice expires at exact end');
announceCheck(ankhAnnouncementLive($db,$now-3601)===null,'Notice hidden before start');
$input['announcement_start']='2026-10-22T12:30';$input['announcement_end']='2026-10-22T18:00';
ankhAnnouncementSave($db,$input,$now);
announceCheck(ankhAnnouncementLive($db,$now)===null && ankhAnnouncementLive($db,$now+1800)!==null,'Future scheduled message activates automatically');
$input['announcement_start']='2026-10-22T11:00';$input['announcement_title']='<script>alert(1)</script>';
ankhAnnouncementSave($db,$input,$now);
announceCheck(str_contains(ankhAnnEscape($input['announcement_title']),'&lt;script&gt;'),'Customer-authored text must be escaped');
ankhAnnouncementHide($db,$id,$now);
announceCheck(ankhAnnouncementLive($db,$now)===null,'Hidden notices vanish immediately');
announceCheck(ankhAnnouncementHistory($db)[0]['state']==='Hidden','Hidden notice remains archived');
foreach([
 ['announcement_title'=>''],
 ['announcement_message'=>''],
 ['announcement_type'=>'Invalid'],
 ['announcement_state'=>'Hidden'],
 ['announcement_end'=>''],
 ['announcement_end'=>'2026-10-22T10:00'],
 ['announcement_end'=>'2026-02-30T12:00'],
 ['announcement_start'=>'not a date'],
 ['announcement_start'=>'2029-10-22T12:00']
] as $bad){
  try{ankhAnnouncementSave($db,array_merge($input,['announcement_id'=>0],$bad),$now);throw new RuntimeException('Accepted bad announcement: '.json_encode($bad));}
  catch(InvalidArgumentException $expected){}
}
$draft=$input;$draft['announcement_id']=0;$draft['announcement_state']='Draft';$draft['announcement_end']='';$draft['announcement_title']='Draft allowed';
ankhAnnouncementSave($db,$draft,$now);
announceCheck(count(ankhAnnouncementHistory($db))===2,'Draft can be saved without expiry and no records deleted');
$dst=ankhAnnouncementDate('2026-10-25T01:30');
announceCheck(is_int($dst),'UK timezone dates supported');
try{ankhAnnouncementDate('2026-02-30T12:00');throw new RuntimeException('Accepted impossible date');}catch(InvalidArgumentException $expected){}
echo "Customer announcement scheduling, expiry, hiding, escaping, and validation passed.\n";
