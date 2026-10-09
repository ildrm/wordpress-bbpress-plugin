<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/release-wordpress/wp-load.php';
if(DB_NAME!=='fi_release'||get_option('home')!=='http://127.0.0.1:8899'){throw new RuntimeException('Use the owned isolated release site.');}
$origin=str_replace('\\','/',(new ReflectionClass(ForumIntelligence\Domain\Knowledge\AutoDraft::class))->getFileName());
if(!str_contains($origin,'/.runtime/release-wordpress/wp-content/plugins/forum-intelligence-for-bbpress/')){throw new RuntimeException('Load actual installed auto-draft code.');}
foreach(array('fi_automatic_summary_fixture','fi_summary_fixture','fi_knowledge_fixture','fi_release_fixture','fi_uninstall_fixture','fi_notification_fixture','fi_saved_search_race','fi_vector_performance_fixture')as $key){if(get_option($key)){throw new RuntimeException('Finish other owned release fixtures first.');}}
$missing=new stdClass();$before=get_option('fi_settings',$missing);$owned=array();$topics=array();$users=array();$option='fi_automatic_summary_fixture';
$provider=new class implements ForumIntelligence\Contracts\TextProvider{
 public int $calls=0;
 public function generate(string $instruction,array $evidence):array{++$this->calls;throw new RuntimeException('Local conversion must not call any provider.');}
};
$replacement=static fn()=>$provider;add_filter('fi_text_provider',$replacement);
$mail=static fn()=>true;add_filter('pre_wp_mail',$mail);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$store=$services->store;
$created=wp_insert_user(array('user_login'=>'fi_installed_draft_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-local-draft-verification','role'=>'editor'));if(is_wp_error($created)||$created<=0){throw new RuntimeException('Owned native moderator creation failed.');}$actor=(int)$created;$users[]=$actor;bbp_set_user_role($actor,bbp_get_moderator_role());wp_set_current_user($actor);
if($actor<=0||!user_can($actor,'moderate')||!user_can($actor,'edit_posts')){throw new RuntimeException('Owned draft moderator creation failed.');}
update_option($option,array('actor'=>$actor,'ids'=>array()),false);
$remember=static function(int $id)use(&$owned,$option,$actor):int{if($id<=0){throw new RuntimeException('Owned native draft fixture failed.');}$owned[]=$id;update_option($option,array('actor'=>$actor,'ids'=>$owned),false);return $id;};
$articles=static fn(int $topic):array=>get_posts(array('post_type'=>'fi_knowledge','post_status'=>array('draft','pending','publish','private','future','trash'),'posts_per_page'=>-1,'meta_key'=>'_fi_source_topic','meta_value'=>$topic));
$job=static fn(int $topic):?array=>$store->row("SELECT * FROM {$store->table('jobs')} WHERE kind='knowledge_auto_draft' AND job_key=%s",array('knowledge_auto_draft:'.$topic));
$ready=static function(int $topic)use($store):void{
 $store->execute("UPDATE {$store->table('jobs')} SET available_at='2000-01-01 00:00:00' WHERE kind='knowledge_auto_draft' AND job_key=%s",array('knowledge_auto_draft:'.$topic));
 $store->execute("UPDATE {$store->table('records')} SET updated_at='2000-01-01 00:00:00' WHERE record_key=%s",array('knowledge_source_revision:'.$topic));
};
$insertion=null;$capturedHook=null;
try{
 $settings=array_replace($before===$missing?array():$before,array('ai_enabled'=>false,'summary_auto_enabled'=>false,'knowledge_review_notifications'=>false,'knowledge_auto_draft_enabled'=>true,'knowledge_auto_draft_actor'=>$actor,'knowledge_auto_draft_min_replies'=>3,'knowledge_auto_draft_min_confirmations'=>1,'knowledge_auto_draft_debounce'=>120));$settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
 $forum=$remember((int)bbp_insert_forum(array('post_title'=>'Owned installed automatic draft forum','post_author'=>$actor)));
 $make=static function()use($remember,$forum,$actor,$services,&$topics):array{
  $topic=$remember((int)bbp_insert_topic(array('post_title'=>'Owned installed solved source','post_content'=>'Owned original problem','post_parent'=>$forum,'post_author'=>$actor),array('forum_id'=>$forum)));$topics[]=$topic;
  $reply=0;for($i=0;$i<3;++$i){$reply=$remember((int)bbp_insert_reply(array('post_content'=>'Owned published solution approach','post_parent'=>$topic,'post_author'=>$actor),array('topic_id'=>$topic,'forum_id'=>$forum)));}
  $services->solutions->change($topic,$reply,'accept',$actor);$services->get('knowledge')->command($topic,'edit','PACKAGED_HUMAN_AUTO_DRAFT_CANARY',$actor);return array($topic,$reply);
 };
 [$topic,$reply]=$make();if($job($topic)||$articles($topic)||$provider->calls){throw new RuntimeException('Auto-draft ignored native confirmations or performed inline work.');}
 $created=wp_insert_user(array('user_login'=>'fi_installed_confirm_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-confirmation-fixture','role'=>'subscriber'));if(is_wp_error($created)||$created<=0){throw new RuntimeException('Owned confirmation reader creation failed.');}$reader=(int)$created;$users[]=$reader;bbp_set_user_role($reader,bbp_get_participant_role());
 $services->get('engagement')->cast($reply,$reader,'confirm',1);$queued=$job($topic);
 if(!$queued||json_decode($queued['payload'],true)!==array('topic'=>$topic)||strtotime($queued['available_at'].' UTC')<time()+115||$articles($topic)||$provider->calls){throw new RuntimeException('Installed native draft admission failed topic-only async/quiet criteria.');}
 $snapshot=$store->record('summary:'.$topic);$ready($topic);$services->queue->run(1);$copies=$articles($topic);
 if(count($copies)!==1||$copies[0]->post_status!=='draft'||(int)$copies[0]->post_author!==$actor||!str_contains($copies[0]->post_content,'PACKAGED_HUMAN_AUTO_DRAFT_CANARY')||get_post_meta($copies[0]->ID,'_fi_source_topic',true)!==(string)$topic||$store->record('summary:'.$topic)!==$snapshot||$provider->calls){throw new RuntimeException('Installed conversion lost draft/owner/source/human/no-provider invariants.');}
 $remember($copies[0]->ID);$article=$copies[0]->ID;
 wp_update_post(array('ID'=>$article,'post_content'=>'PACKAGED_EXISTING_HUMAN_ARTICLE_CANARY'));
 $automatic=new ForumIntelligence\Domain\Knowledge\AutoDraft($store,$services->queue,$services->access,$services->get('knowledge'));$automatic->run($topic);$automatic->consider($topic);
 if(count($articles($topic))!==1||get_post($article)->post_content!=='PACKAGED_EXISTING_HUMAN_ARTICLE_CANARY'){throw new RuntimeException('Installed automatic replay overwrote or duplicated a human article.');}
 [$second,$secondReply]=$make();$services->get('engagement')->cast($secondReply,$reader,'confirm',1);$ready($second);$settings['knowledge_auto_draft_enabled']=false;update_option('fi_settings',$settings);$services->queue->run(1);
 if($articles($second)){throw new RuntimeException('Installed delayed conversion reused disabled policy.');}
 $settings['knowledge_auto_draft_enabled']=true;update_option('fi_settings',$settings);$services->get('knowledge')->command($second,'edit','Current owned summary',$actor);$ready($second);wp_update_post(array('ID'=>$second,'post_password'=>'owned-auto-draft-private'));$services->queue->run(1);
 if($articles($second)){throw new RuntimeException('Installed delayed conversion reused public source access.');}
 wp_update_post(array('ID'=>$second,'post_password'=>''));$services->get('knowledge')->command($second,'edit','Current owned summary',$actor);$ready($second);
 $insertion=static function(array $data):array{if($data['post_type']==='fi_knowledge'){$settings=get_option('fi_settings');$settings['knowledge_auto_draft_enabled']=false;update_option('fi_settings',$settings);}return $data;};add_filter('wp_insert_post_data',$insertion);
 $services->queue->run(1);
 if($articles($second)||$job($second)['status']!=='retrying'||$job($second)['error_code']!=='automation_changed'||$provider->calls){throw new RuntimeException('Installed native insertion policy revocation did not roll back the owned new draft.');}
 remove_filter('wp_insert_post_data',$insertion);$insertion=null;
 foreach(array(array('post_author'=>0),array('post_status'=>'publish'))as $mutation){
  $settings['knowledge_auto_draft_enabled']=true;update_option('fi_settings',$settings);$services->get('knowledge')->command($second,'edit','Current owned summary',$actor);$ready($second);$inserted=0;
  $capturedHook=static function(int $id,WP_Post $post)use(&$inserted,$remember):void{if($post->post_type==='fi_knowledge'){$inserted=$remember($id);}};add_action('wp_after_insert_post',$capturedHook,10,2);
  $insertion=static fn(array $data):array=>$data['post_type']==='fi_knowledge'?array_replace($data,$mutation):$data;add_filter('wp_insert_post_data',$insertion);
  $services->queue->run(1);
  if($inserted<=0||$articles($second)||get_post($inserted)!==null||metadata_exists('post',$inserted,'_fi_provenance')||$job($second)['status']!=='retrying'||$job($second)['error_code']!=='automation_changed'){throw new RuntimeException('Installed native owner/publication mutation verification: '.wp_json_encode(array('mutation'=>$mutation,'inserted'=>$inserted,'articles'=>array_map(static fn(WP_Post $post):int=>$post->ID,$articles($second)),'post_exists'=>get_post($inserted)!==null,'meta_exists'=>metadata_exists('post',$inserted,'_fi_provenance'),'status'=>$job($second)['status'],'error_code'=>$job($second)['error_code'])));}
  remove_filter('wp_insert_post_data',$insertion);$insertion=null;remove_action('wp_after_insert_post',$capturedHook,10);$capturedHook=null;
 }
 $settings['knowledge_auto_draft_enabled']=true;update_option('fi_settings',$settings);$services->get('knowledge')->command($second,'edit','Current owned summary',$actor);$ready($second);
 $sourceBefore=get_post($second)->post_content;$settingsBefore=get_option('fi_settings');
 $insertion=static function(array $data)use($second):array{if($data['post_type']==='fi_knowledge'){wp_update_post(array('ID'=>$second,'post_content'=>'OWNED_INSTALLED_NATIVE_SOURCE_ROLLBACK_CANARY'));update_post_meta($second,'_fi_owned_rollback_fixture','OWNED_NATIVE_META_ROLLBACK_CANARY');}return $data;};add_filter('wp_insert_post_data',$insertion);$services->queue->run(1);
 if($articles($second)||get_post($second)->post_content!==$sourceBefore||metadata_exists('post',$second,'_fi_owned_rollback_fixture')||get_option('fi_settings')!==$settingsBefore){throw new RuntimeException('Installed native source/options/meta caches did not restore current database state after rollback.');}
 remove_filter('wp_insert_post_data',$insertion);$insertion=null;
 echo "Installed automatic knowledge drafts: current solved/native confirmation criteria, topic-only delayed/no-inline conversion, human draft owner/provenance, no provider calls/publication, replay/existing human article preservation, delayed policy/source revocation and insertion policy/owner/publication rollback with native source/metadata/policy cache restoration passed.\n";
}finally{
 if($capturedHook){remove_action('wp_after_insert_post',$capturedHook,10);}if($insertion){remove_filter('wp_insert_post_data',$insertion);}update_option('fi_settings',array('ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false));
 foreach($topics as $topic){
  foreach($articles($topic)as $post){if((int)$post->post_author!==$actor){throw new RuntimeException('Refusing an unowned auto-draft fixture article.');}$owned[]=$post->ID;}
  $store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='knowledge_auto_draft' AND job_key=%s",array('knowledge_auto_draft:'.$topic));
  $store->execute("DELETE FROM {$store->table('records')} WHERE record_key IN (%s,%s)",array('summary:'.$topic,'knowledge_source_revision:'.$topic));
 }
 foreach(array_reverse(array_unique($owned))as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$actor){throw new RuntimeException('Refusing unowned draft fixture content.');}wp_delete_post($id,true);}
 require_once ABSPATH.'wp-admin/includes/user.php';foreach($users as $user){if($user>0){$ownedUser=get_userdata($user);if($ownedUser&&!str_starts_with($ownedUser->user_login,'fi_installed_draft_')&&!str_starts_with($ownedUser->user_login,'fi_installed_confirm_')){throw new RuntimeException('Refusing unowned automatic draft fixture account removal.');}wp_delete_user($user);}}wp_set_current_user(0);
 remove_filter('fi_text_provider',$replacement);remove_filter('pre_wp_mail',$mail);
 if($before===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$before);}delete_option($option);
}
