<?php
declare(strict_types=1);
// Person filter on Today's worklist, exercised against fixture orders only.
$source=file_get_contents(dirname(__DIR__).'/index.php');
function personCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function loadPersonFunction(string $source,string $name):void{
 $start=strpos($source,'function '.$name.'(');
 if($start===false)throw new RuntimeException('Missing helper: '.$name);
 $code='';$depth=0;$started=false;
 foreach(token_get_all('<?php '.substr($source,$start)) as $token){
  if(is_array($token)&&$token[0]===T_OPEN_TAG&&$code==='')continue;
  $text=is_array($token)?$token[1]:$token;$code.=$text;
  if($token==='{'){$depth++;$started=true;}
  if($token==='}'&&--$depth===0&&$started){eval($code);return;}
 }
 throw new RuntimeException('Unclosed helper: '.$name);
}
foreach(['e','dashboardQueueView','dashboardWorklist','worklistPersonView','orderMatchesWorklistPerson','dashboardWorklistsForPerson'] as $name)loadPersonFunction($source,$name);

personCheck(worklistPersonView()==='all','The worklist person filter must default to All.');
personCheck(worklistPersonView('jay')==='jay'&&worklistPersonView('tony')==='tony','Jay and Tony must be selectable.');
personCheck(worklistPersonView('all')==='all','All must be selectable.');
personCheck(worklistPersonView('bogus','tony')==='tony','An invalid request must fall back to a remembered person.');
personCheck(worklistPersonView('bogus','invalid')==='all','Unknown person input must be rejected.');

personCheck(orderMatchesWorklistPerson(['assigned_to'=>'James'],'jay'),'James is Jay.');
personCheck(orderMatchesWorklistPerson(['assigned_to'=>'Jay'],'jay'),'Legacy Jay values remain Jay.');
personCheck(orderMatchesWorklistPerson(['assigned_to'=>'james'],'jay'),'Person matching is case-insensitive.');
personCheck(orderMatchesWorklistPerson(['assigned_to'=>'Tony'],'tony'),'Tony matches Tony.');
personCheck(!orderMatchesWorklistPerson(['assigned_to'=>'Tony'],'jay'),'Tony orders stay out of Jay.');
personCheck(!orderMatchesWorklistPerson(['assigned_to'=>''],'jay')&&!orderMatchesWorklistPerson(['assigned_to'=>''],'tony'),'Unassigned orders show only under All.');
personCheck(!orderMatchesWorklistPerson(['assigned_to'=>'Holding'],'jay')&&!orderMatchesWorklistPerson(['assigned_to'=>'Holding'],'tony'),'Holding orders show only under All.');

$orders=[
 1=>['id'=>1,'assigned_to'=>'James'],
 2=>['id'=>2,'assigned_to'=>'Tony'],
 3=>['id'=>3,'assigned_to'=>''],
 4=>['id'=>4,'assigned_to'=>'Holding'],
 5=>['id'=>5,'assigned_to'=>'Jay'],
 6=>['id'=>6,'assigned_to'=>'james'],
];
$payment=[$orders[1],$orders[2],$orders[3],$orders[5],$orders[4]];
$delivery=[$orders[1],$orders[2],$orders[3],$orders[6]];
$future=[$orders[2],$orders[1],$orders[3]];

$all=dashboardWorklistsForPerson($payment,$delivery,$future,'all');
personCheck($all['counts']===['all'=>6,'payment'=>5,'delivery'=>4,'future'=>3],'All counts must combine status filters and deduplicate orders.');
$jay=dashboardWorklistsForPerson($payment,$delivery,$future,'jay');
personCheck($jay['counts']===['all'=>3,'payment'=>2,'delivery'=>2,'future'=>1],'Jay counts must only include James/Jay orders.');
personCheck(array_column($jay['all'],'id')===[1,5,6],'Jay worklist must exclude Tony, unassigned and Holding orders.');
$tony=dashboardWorklistsForPerson($payment,$delivery,$future,'tony');
personCheck($tony['counts']===['all'=>1,'payment'=>1,'delivery'=>1,'future'=>1],'Tony counts must only include Tony orders.');
personCheck(array_column($tony['all'],'id')===[2],'Tony worklist must exclude James, unassigned and Holding orders.');

personCheck(str_contains($source,'$dashboardPerson=worklistPersonView('),'Dashboard must resolve the person filter.');
personCheck(str_contains($source,'dashboardWorklistsForPerson($awaitingPayment,$awaitingDelivery,$futureDeliveries,$dashboardPerson)'),'Dashboard counts must be built from the chosen person.');
personCheck(!str_contains($source,"ankh-worklist-person")&&!str_contains($source,"dashboard_who"),"Worklist person must not be remembered; it always opens on All.");

$start=strpos($source,'<section class="todo-board dashboard-action-queue"');
$end=strpos($source,'<!-- dashboard-worklist-end -->',$start);
personCheck($start!==false&&$end!==false,'Worklist template must exist.');
$template=substr($source,$start,$end-$start);
$dashboardPerson='jay';$dashboardQueue='all';$dashboardLayout='cards';$dashboardQueueCounts=$jay['counts'];
$dashboardWork=[];$stockShortages=[];$stockShortageUnits=0;$now=new DateTimeImmutable('2026-11-08T09:30:00+00:00');
ob_start();eval('?>'.$template);$html=ob_get_clean();
personCheck(str_contains($html,'data-worklist-person="jay"'),'The control must reflect the selected person.');
personCheck(str_contains($html,'aria-current="true">Jay</a>'),'Jay must be visibly selected.');
personCheck(substr_count($html,'class="worklist-person"')===1&&substr_count($html,'>All</a>')===1&&substr_count($html,'>Tony</a>')===1,'All, Jay and Tony must render once.');
personCheck(str_contains($html,'<span>All</span><b>3</b>'),'All chip count must use Jay totals.');
personCheck(str_contains($html,'<span>Unpaid</span><b>2</b>'),'Unpaid chip count must use Jay totals.');
personCheck(str_contains($html,'<span>To deliver</span><b>2</b>'),'To deliver chip count must use Jay totals.');
personCheck(str_contains($html,'<span>Future</span><b>1</b>'),'Future chip count must use Jay totals.');
personCheck(str_contains($html,'?view=dashboard&amp;queue=payment&amp;who=jay#dashboard-actions'),'Status filters must preserve the chosen person.');
personCheck(str_contains($html,'layout=dispatch&amp;who=jay#dashboard-actions'),'Layout switch must preserve the chosen person.');
personCheck(str_contains($html,'queue=all&amp;layout=cards&amp;who=tony#dashboard-actions'),'Person links must preserve the current queue and layout.');
echo "PASS: worklist person filter defaults to All, filters James/Jay/Tony by assigned_to, excludes unassigned and Holding, combines with status counts, and persists the choice.\n";
