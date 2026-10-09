<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/release-wordpress/wp-load.php';
if(DB_NAME!=='fi_release'||get_option('home')!=='http://127.0.0.1:8899'){throw new RuntimeException('Automatic verification requires the owned release site.');}
$origin=str_replace('\\','/',(new ReflectionClass(ForumIntelligence\Domain\Knowledge\Automation::class))->getFileName());
if(!str_contains($origin,'/.runtime/release-wordpress/wp-content/plugins/forum-intelligence-for-bbpress/')){throw new RuntimeException('Automatic verification must load the installed archive.');}
foreach(array('fi_automatic_summary_fixture','fi_summary_fixture','fi_knowledge_fixture','fi_release_fixture','fi_uninstall_fixture','fi_notification_fixture','fi_saved_search_race','fi_vector_performance_fixture') as $option){if(get_option($option)){throw new RuntimeException('Finish other owned fixtures first.');}}
$actor=(int)get_user_by('login','release_admin')->ID;wp_set_current_user($actor);
$missing=new stdClass();$before=get_option('fi_settings',$missing);$owned=array();$topic=0;$option='fi_automatic_summary_fixture';
$provider=new class implements ForumIntelligence\Contracts\TextProvider{
 public int $calls=0;
 public function generate(string $instruction,array $evidence):array{++$this->calls;return array('answer'=>'PACKAGED_AUTOMATIC_SUMMARY_CANARY','source_ids'=>array_column($evidence,'id'));}
};
$replacement=static fn()=>$provider;add_filter('fi_text_provider',$replacement);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$store=$services->store;
update_option($option,array('actor'=>$actor,'ids'=>array()),false);
$remember=static function(int $id)use(&$owned,$actor,$option):int{if($id<=0){throw new RuntimeException('Owned native automatic fixture creation failed.');}$owned[]=$id;update_option($option,array('actor'=>$actor,'ids'=>$owned),false);return $id;};
$job=static function()use($store,&$topic):?array{return $store->row("SELECT * FROM {$store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",array('summary_auto:'.$topic));};
$ready=static function()use($store,&$topic):void{
 $past='2000-01-01 00:00:00';
 $store->execute("UPDATE {$store->table('jobs')} SET available_at=%s WHERE kind='summary_auto' AND job_key=%s",array($past,'summary_auto:'.$topic));
 $store->execute("UPDATE {$store->table('records')} SET updated_at=%s WHERE record_key=%s",array($past,'knowledge_source_revision:'.$topic));
};
try{
 $settings=array_replace($before===$missing?array():$before,array('ai_enabled'=>true,'summary_auto_enabled'=>true,'summary_auto_actor'=>$actor,'summary_min_replies'=>3,'summary_debounce_seconds'=>120,'summary_min_interval'=>600));
 $settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
 $forum=$remember((int)bbp_insert_forum(array('post_title'=>'Owned installed automatic summary forum','post_author'=>$actor)));
 $topic=$remember((int)bbp_insert_topic(array('post_title'=>'Owned installed automatic question','post_content'=>'Public automatic source context','post_parent'=>$forum,'post_author'=>$actor),array('forum_id'=>$forum)));
 $reply=static function()use($remember,$topic,$forum,$actor):int{return $remember((int)bbp_insert_reply(array('post_content'=>'Public installed automatic approach','post_parent'=>$topic,'post_author'=>$actor),array('topic_id'=>$topic,'forum_id'=>$forum)));};
 $reply();$reply();if($job()!==null||$provider->calls!==0){throw new RuntimeException('Installed automation scheduled below the native reply threshold.');}
 $reply();$first=$job();if(!$first||json_decode($first['payload'],true)!==array('topic'=>$topic)||$provider->calls!==0||strtotime($first['available_at'].' UTC')<time()+115){throw new RuntimeException('Installed native scheduling violated threshold, quiet delay or payload/provider bounds.');}
 $ready();$services->queue->run(1);
 if($provider->calls!==1||$services->get('knowledge')->summary($topic,$actor)['answer']!=='PACKAGED_AUTOMATIC_SUMMARY_CANARY'||$job()['status']!=='completed'){throw new RuntimeException('Installed asynchronous automatic summary did not complete.');}
 $reply();if(strtotime($job()['available_at'].' UTC')<time()+590){throw new RuntimeException('Installed automatic generation ignored the minimum interval.');}
 $services->get('knowledge')->command($topic,'edit','PACKAGED_AUTOMATIC_HUMAN_LOCK_CANARY',$actor);$locked=$store->record('summary:'.$topic);
 $ready();$services->queue->run(1);
 if($provider->calls!==1||$store->record('summary:'.$topic)!==$locked){throw new RuntimeException('Installed automatic delayed work changed a human summary lock/body.');}
 $services->get('knowledge')->command($topic,'unlock','',$actor);$reply();$ready();$settings['ai_enabled']=false;update_option('fi_settings',$settings);
 $services->queue->run(1);
 if($provider->calls!==1||$services->get('knowledge')->summary($topic,$actor)['answer']!=='PACKAGED_AUTOMATIC_HUMAN_LOCK_CANARY'){throw new RuntimeException('Installed delayed automation ignored current AI revocation or changed human text.');}
 echo "Installed automatic summaries: native reply threshold, topic-only coalesced job, no publication-path provider work, quiet-period delay, asynchronous generation, minimum interval, human lock/body preservation and delayed AI-consent revocation passed.\n";
}finally{
 update_option('fi_settings',array('ai_enabled'=>false,'summary_auto_enabled'=>false));
 if($topic){$store->execute("DELETE FROM {$store->table('records')} WHERE record_key=%s",array('summary:'.$topic));$store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",array('summary_auto:'.$topic));}
 foreach(array_reverse($owned) as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$actor){throw new RuntimeException('Refusing unowned automatic fixture content.');}wp_delete_post($id,true);}
 remove_filter('fi_text_provider',$replacement);
 if($before===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$before);}
 delete_option($option);
}
