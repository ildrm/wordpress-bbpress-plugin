<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/release-wordpress/wp-load.php';
if(DB_NAME!=='fi_release'||get_option('home')!=='http://127.0.0.1:8899'){throw new RuntimeException('Summary verification requires the owned release site.');}
$origin=str_replace('\\','/',(new ReflectionClass(ForumIntelligence\Domain\Knowledge\Knowledge::class))->getFileName());
if(!str_contains($origin,'/.runtime/release-wordpress/wp-content/plugins/forum-intelligence-for-bbpress/')){throw new RuntimeException('Summary verification must load the installed archive.');}
foreach(array('fi_summary_fixture','fi_knowledge_fixture','fi_release_fixture','fi_uninstall_fixture','fi_vector_performance_fixture') as $option){if(get_option($option)){throw new RuntimeException('Finish the other owned fixture first.');}}
$actor=(int)get_user_by('login','release_admin')->ID;wp_set_current_user($actor);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$owned=array();$article=0;$topic=0;
$missing=new stdClass();$beforeSettings=get_option('fi_settings',$missing);
update_option('fi_summary_fixture',array('actor'=>$actor,'ids'=>array()),false);
$remember=static function(int $id)use(&$owned,$actor):int{if($id<=0){throw new RuntimeException('Owned native source creation failed.');}$owned[]=$id;update_option('fi_summary_fixture',array('actor'=>$actor,'ids'=>$owned),false);return $id;};
try{
 update_option('fi_settings',array_merge($beforeSettings===$missing?array():$beforeSettings,array('multiple_solutions'=>true)));
 $forum=$remember((int)bbp_insert_forum(array('post_title'=>'Owned installed summary verification','post_author'=>$actor)));
 $topic=$remember((int)bbp_insert_topic(array('post_title'=>'Owned long discussion question','post_content'=>'Original problem context','post_parent'=>$forum,'post_author'=>$actor),array('forum_id'=>$forum)));
 $replies=array();
 for($i=0;$i<65;++$i){
  $date=gmdate('Y-m-d H:i:s',strtotime('2025-01-01 00:00:00 UTC')+$i*60);
  $id=$remember((int)wp_insert_post(array('post_type'=>bbp_get_reply_post_type(),'post_status'=>'publish','post_parent'=>$topic,'post_author'=>$actor,'post_content'=>'Reply '.$i.' public approach author@example.test api_key=summarysecret '.str_repeat('Ω',3400).' PACKAGED_SUMMARY_TAIL','post_date'=>$date,'post_date_gmt'=>$date)));
  update_post_meta($id,'_bbp_topic_id',$topic);update_post_meta($id,'_bbp_forum_id',$forum);$replies[]=$id;
 }
 $accepted=array_slice($replies,0,10);
 foreach($accepted as $id){$services->solutions->change($topic,$id,'accept',$actor);}
 $primary=$accepted[2];$services->solutions->change($topic,$primary,'primary',$actor);
 $provider=new class implements ForumIntelligence\Contracts\TextProvider{
  public array $sent=array();public int $calls=0;public $during=null;
  public function generate(string $instruction,array $evidence):array{++$this->calls;$this->sent=$evidence;if($this->during){($this->during)();}return array('answer'=>'PACKAGED_LONG_SUMMARY_REVIEWED_CANARY','source_ids'=>array_column($evidence,'id'));}
 };
 $knowledge=new ForumIntelligence\Domain\Knowledge\Knowledge($services->store,$services->queue,$services->access,$provider);
 $nativeHash=ForumIntelligence\Infrastructure\Search\Indexer::hash($topic);
 $knowledge->generate($topic,$actor);$ids=array_column($provider->sent,'id');$text=array_column($provider->sent,'text','id');
 if(count($ids)!==12||$ids[0]!==$topic||array_diff($accepted,$ids)||!str_starts_with($text[$primary],'Accepted solution (primary)')){throw new RuntimeException('Installed summary omitted accepted evidence or primary role.');}
 $dates=array();foreach(array_slice($ids,1) as $id){$dates[]=array(get_post($id)->post_date_gmt,$id);}
 $sorted=$dates;sort($sorted);if($dates!==$sorted){throw new RuntimeException('Installed summary lost native chronology.');}
 foreach($provider->sent as $entry){if(mb_strlen($entry['text'])>3000||str_contains($entry['text'],'author@example.test')||str_contains($entry['text'],'summarysecret')||str_contains($entry['text'],'PACKAGED_SUMMARY_TAIL')){throw new RuntimeException('Installed summary evidence broke projection bounds or redaction.');}}
 $summary=$knowledge->summary($topic,$actor);
 if(count($summary['sources'])!==61||$summary['evidence_ids']!==$ids||$summary['selection_version']!==2||$summary['sources'][$topic]!==$nativeHash){throw new RuntimeException('Installed summary changed native provenance or lost bounded selection metadata.');}
 $knowledge->command($topic,'edit','PACKAGED_LONG_HUMAN_KNOWLEDGE_CANARY',$actor);
 $article=$remember((int)$knowledge->command($topic,'draft','',$actor)['article_id']);
 if(!user_can($actor,'edit_post',$article)){throw new RuntimeException('Installed sixty-one-source human draft lost native rights.');}
 if(user_can($actor,get_post_type_object('fi_knowledge')->cap->create_posts)||!user_can($actor,'edit_posts')){throw new RuntimeException('Installed native knowledge creation bypassed reviewed conversion or removed ordinary editing.');}
 $review=$knowledge->reviewState($article,$actor);
 if($review['stale']||!$review['review_available']||$review['verified_at']!==null){throw new RuntimeException('Fresh installed human draft has incorrect source review state.');}
 $humanBody=get_post($article)->post_content;
 wp_update_post(array('ID'=>$replies[10],'post_content'=>'Old unaccepted approach outside the recent evidence window changed'));
 if(!$knowledge->reviewState($article,$actor)['stale']||!$knowledge->summary($topic,$actor)['stale']){throw new RuntimeException('Installed old unaccepted edit did not invalidate article and summary review state.');}
 $beforeLocked=$services->store->record('summary:'.$topic);
 if(empty($beforeLocked['data']['manual_lock'])||$beforeLocked['data']['answer']!=='PACKAGED_LONG_HUMAN_KNOWLEDGE_CANARY'){throw new RuntimeException('Native discussion invalidation changed the human summary lock/body.');}
 $knowledge->verifyArticle($article,$actor);$verified=$knowledge->reviewState($article,$actor);
 $provenance=get_post_meta($article,'_fi_provenance',true);
 if($verified['stale']||!$verified['verified_at']||$provenance['fingerprint_version']!==3||$provenance['discussion_revision']<=0||get_post($article)->post_content!==$humanBody||$services->store->record('summary:'.$topic)!==$beforeLocked){throw new RuntimeException('Installed human verification lost current provenance, authored content or summary lock isolation.');}
 $eventsBefore=(int)$services->store->row("SELECT COUNT(*) AS n FROM {$services->store->table('events')} WHERE kind='knowledge_verified' AND object_id=%d",array($topic))['n'];
 $knowledge->verifyArticle($article,$actor);
 if((int)$services->store->row("SELECT COUNT(*) AS n FROM {$services->store->table('events')} WHERE kind='knowledge_verified' AND object_id=%d",array($topic))['n']!==$eventsBefore){throw new RuntimeException('Installed unchanged source review created duplicate verification audit events.');}

 wp_update_post(array('ID'=>$accepted[0],'post_content'=>'Old accepted evidence updated'));
 $summary=$knowledge->summary($topic,$actor);
 if(!$summary['stale']||!$summary['manual_lock']||$summary['answer']!=='PACKAGED_LONG_HUMAN_KNOWLEDGE_CANARY'){throw new RuntimeException('Installed old accepted edit lost stale state or overwrote human text.');}
 $knowledge->command($topic,'unlock','',$actor);
 $before=$services->store->record('summary:'.$topic);
 $provider->during=static function()use($accepted):void{wp_update_post(array('ID'=>$accepted[0],'post_content'=>'Old accepted evidence edited during provider work'));};
 $rejected=false;try{$knowledge->generate($topic,$actor);}catch(ForumIntelligence\Domain\Failure $error){$rejected=$error->status===409;}
 if(!$rejected||$services->store->record('summary:'.$topic)['payload']!==$before['payload']){throw new RuntimeException('Installed in-flight accepted edit replaced the reviewed summary.');}
 $provider->during=null;wp_update_post(array('ID'=>$accepted[0],'post_password'=>'owned-summary-source-password'));
 $calls=$provider->calls;$before=$services->store->record('summary:'.$topic);$rejected=false;
 try{$knowledge->generate($topic,$actor);}catch(ForumIntelligence\Domain\Failure $error){$rejected=$error->status===403;}
 if(!$rejected||$provider->calls!==$calls||$services->store->record('summary:'.$topic)!==$before||user_can($actor,'edit_post',$article)){throw new RuntimeException('Installed restricted old accepted source allowed provider work, summary replacement or native article editing.');}
 wp_update_post(array('ID'=>$accepted[0],'post_password'=>''));
 if(!user_can($actor,'edit_post',$article)||!str_contains(get_post($article)->post_content,'PACKAGED_LONG_HUMAN_KNOWLEDGE_CANARY')){throw new RuntimeException('Installed restored source did not retain human draft content.');}
 echo "Installed human article review: blocked native blank creation, old unaccepted edit invalidation, explicit current source verification, idempotent audit and authored-body/summary-lock preservation. Long summaries: all ten old accepted answers, primary labels, twelve-source chronological bounded/redacted transfer, sixty-one-source native provenance, human stale/edit/draft retention, in-flight source change rejection and restricted no-provider/article permission checks passed.\n";
}finally{
 if($topic){$services->store->execute("DELETE FROM {$services->store->table('records')} WHERE record_key=%s",array('summary:'.$topic));}
 foreach(array_reverse($owned) as $id){$post=get_post($id);if(!$post){continue;}if((int)$post->post_author!==$actor){throw new RuntimeException('Refusing an unowned summary fixture post.');}wp_delete_post($id,true);}
 if($beforeSettings===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$beforeSettings);}
 delete_option('fi_summary_fixture');
}
