<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
$runtime=getenv('FI_RELEASE_WP_PATH')?:dirname(__DIR__).'/.runtime/release-wordpress';
require $runtime.'/wp-load.php';
if(DB_NAME!==(getenv('FI_RELEASE_DB')?:'fi_release')||get_option('home')!=='http://127.0.0.1:8899'||get_option('fi_vector_workload_fixture')||get_option('fi_release_fixture')||get_option('fi_notification_fixture')||get_option('fi_saved_search_race')||get_option('fi_knowledge_fixture')||get_option('fi_summary_fixture')||get_option('fi_vector_performance_fixture')||get_option('fi_automatic_summary_fixture')){throw new RuntimeException('Use the disposable release site after all other release fixtures are cleaned up.');}
require_once ABSPATH.'wp-admin/includes/plugin.php';
$plugin='forum-intelligence-for-bbpress/forum-intelligence-for-bbpress.php';
require_once WP_PLUGIN_DIR.'/forum-intelligence-for-bbpress/autoload.php';
$source=(new ReflectionClass(ForumIntelligence\Privacy\Uninstall::class))->getFileName();
if(!str_contains(str_replace('\\','/',$source),'/release-wordpress/wp-content/plugins/')){throw new RuntimeException('Load packaged uninstall code.');}
$admin=get_user_by('login','release_admin');wp_set_current_user($admin->ID);
$store=new ForumIntelligence\Infrastructure\Database\Store();
$action=$argv[1]??'seed';$fixture=get_option('fi_uninstall_fixture',array());
if($action==='seed'){
 if($fixture){throw new RuntimeException('Restore the prior uninstall fixture first.');}
 if(!is_dir(FI_PROTECTED_STORAGE_DIR)){
  $normalize=static fn(string $path): string=>strtolower(rtrim(str_replace('\\','/',$path),'/'));
  if($normalize(FI_PROTECTED_STORAGE_DIR)!==$normalize(sys_get_temp_dir().'/fi-protected-release-test')){throw new RuntimeException('Create and verify the configured isolated protected directory first.');}
  if(!mkdir(FI_PROTECTED_STORAGE_DIR,0700)){throw new RuntimeException('Could not provision the owned temporary storage fixture.');}
 }
 $settings=get_option('fi_settings',array());
 $missingUpgrade=new stdClass();$upgrade=get_option('fi_vector_cache_upgrade',$missingUpgrade);$upgradeExists=$upgrade!==$missingUpgrade;if(!$upgradeExists){$upgrade='';}
 $missingTemplate=new stdClass();$originalTemplate=get_option('fi_template_variants',$missingTemplate);$templateExists=$originalTemplate!==$missingTemplate;if(!$templateExists){$originalTemplate=array();}
 $templateCanary=array(array('id'=>'uninstall-owned','label'=>'Owned template','forum'=>0,'text'=>'OWNED_TEMPLATE_RETENTION_CANARY'));
 update_option('fi_template_variants',$templateCanary,false);
 $storage=new ForumIntelligence\Infrastructure\Storage\ProtectedLocalStorage(FI_PROTECTED_STORAGE_DIR);
 $temp=tempnam(sys_get_temp_dir(),'fi-uninstall-');file_put_contents($temp,'OWNED_PROTECTED_UNINSTALL_CANARY');
 $key=$storage->store($temp,'owned.txt');unlink($temp);$owned=$storage->path($key);
 $outside=FI_PROTECTED_STORAGE_DIR.'/unrelated-control-'.bin2hex(random_bytes(8));file_put_contents($outside,'UNRELATED_FILE_MUST_REMAIN');
 $forum=(int)bbp_insert_forum(array('post_title'=>'Uninstall native content must remain','post_author'=>$admin->ID));
 $store->execute("DELETE FROM {$store->table('records')} WHERE record_key IN ('uninstall-owned','uninstall-invalid')");
 $store->saveRecord('attachment','uninstall-owned',(int)$admin->ID,$forum,array('protected'=>true,'key'=>$key,'name'=>'owned.txt','mime'=>'text/plain','size'=>filesize($owned)));
 $metadata=array();
 foreach(array(ForumIntelligence\Domain\Notifications\Notifications::metaKey(),ForumIntelligence\Domain\Reputation\Badges::preferenceKey()) as $meta){
  $metadata[$meta]=get_user_meta($admin->ID,$meta,false);
  update_user_meta($admin->ID,$meta,'OWNED_METADATA_CANARY');
 }
 $unrelatedMeta='_other_plugin_fixture_'.bin2hex(random_bytes(6));update_user_meta($admin->ID,$unrelatedMeta,'UNRELATED_METADATA_MUST_REMAIN');
 update_post_meta($forum,'_fi_uninstall_test','OWNED_POST_METADATA_CANARY');
 update_post_meta($forum,'_other_plugin_test','UNRELATED_POST_METADATA_MUST_REMAIN');
 $topic=(int)bbp_insert_topic(array('post_title'=>'Owned uninstall source review topic','post_content'=>'Owned uninstall knowledge source','post_parent'=>$forum,'post_author'=>$admin->ID),array('forum_id'=>$forum));
 $services=new ForumIntelligence\Bootstrap\Services();$services->register();$knowledge=$services->get('knowledge');
 $reply=(int)bbp_insert_reply(array('post_content'=>'Owned uninstall accepted human answer','post_parent'=>$topic,'post_author'=>$admin->ID),array('forum_id'=>$forum,'topic_id'=>$topic));
 $services->solutions->change($topic,$reply,'accept',(int)$admin->ID);
 update_option('fi_uninstall_fixture',array('target_mode'=>true,'actor'=>(int)$admin->ID,'topic'=>$topic),false);
 if(!function_exists('fi_owned_knowledge_target_fixture_register')){throw new RuntimeException('Missing owned adapted uninstall acceptance helper.');}
 do_action('fi_owned_target_fixture_register');
 $targetSettings=array_replace($settings,array('ai_enabled'=>false,'summary_auto_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_target'=>'owned-release'));$targetSettings['modules']['knowledge']=true;update_option('fi_settings',$targetSettings);
 $knowledge->command($topic,'edit','OWNED_HUMAN_ARTICLE_RETENTION_CANARY',(int)$admin->ID);
 $article=(int)$knowledge->command($topic,'draft','',(int)$admin->ID)['article_id'];$knowledge->verifyArticle($article,(int)$admin->ID);
 $knowledge->command($topic,'recommend','OWNED_MODERATOR_NOTE_RETENTION_CANARY',(int)$admin->ID);
 $recommendationKey='knowledge_recommendation:'.$topic;$recommendation=$store->record($recommendationKey);
 if(!$recommendation||$recommendation['kind']!=='knowledge_recommendation'||$recommendation['data']['reason']!=='OWNED_MODERATOR_NOTE_RETENTION_CANARY'){throw new RuntimeException('Uninstall fixture lacks a genuine installed moderator recommendation.');}
 $targetBinding=get_post_meta($article,'_fi_knowledge_target',true);if(get_post_type($article)!=='fi_owned_kb'||$targetBinding!=='owned-release'){throw new RuntimeException('Uninstall fixture did not use the installed native adapter.');}
 $provenance=get_post_meta($article,'_fi_provenance',true);$revisionKey='knowledge_source_revision:'.$topic;$revision=$store->record($revisionKey);
 if(!$revision||$revision['kind']!=='knowledge_source_revision'||empty($provenance['verified_at'])){throw new RuntimeException('Uninstall fixture lacks genuine installed review provenance/counter.');}

 $automaticCanary=array('knowledge_target'=>'owned-release','knowledge_review_notifications'=>true,'knowledge_auto_draft_enabled'=>false,'knowledge_auto_draft_actor'=>(int)$admin->ID,'knowledge_auto_draft_min_replies'=>4,'knowledge_auto_draft_min_confirmations'=>1,'knowledge_auto_draft_debounce'=>180,'summary_auto_enabled'=>false,'summary_auto_actor'=>(int)$admin->ID,'summary_min_replies'=>25,'summary_debounce_seconds'=>900,'summary_min_interval'=>7200);
 $fixture=array('target_mode'=>true,'actor'=>(int)$admin->ID,'target_binding'=>$targetBinding,'reply'=>$reply,'recommendation_key'=>$recommendationKey,'recommendation'=>$recommendation,'automation_canary'=>$automaticCanary,'topic'=>$topic,'article'=>$article,'provenance'=>$provenance,'revision_key'=>$revisionKey,'revision'=>$revision,'owned'=>$owned,'outside'=>$outside,'forum'=>$forum,'settings'=>$settings,'metadata'=>$metadata,'unrelated_meta'=>$unrelatedMeta,'template_exists'=>$templateExists,'template_value'=>$originalTemplate,'template_canary'=>$templateCanary,'upgrade_exists'=>$upgradeExists,'upgrade_value'=>$upgrade,'upgrade_marker'=>bin2hex(random_bytes(8)));
 update_option('fi_uninstall_fixture',$fixture,false);update_option('fi_settings',array_replace($settings,$automaticCanary,array('delete_on_uninstall'=>false)));
 $reviewSettings=get_option('fi_settings');$reviewSettings['modules']['knowledge']=true;$reviewSettings['modules']['notifications']=true;update_option('fi_settings',$reviewSettings);
 wp_update_post(array('ID'=>$topic,'post_content'=>'Owned uninstall source changed after human verification'));
 $jobKey='knowledge_review:'.$topic.':0';$store->execute("UPDATE {$store->table('jobs')} SET available_at='2000-01-01 00:00:00' WHERE kind='knowledge_review' AND job_key=%s",array($jobKey));$services->queue->run(1);
 $notice=$store->row("SELECT * FROM {$store->table('notifications')} WHERE kind='knowledge_review' AND user_id=%d AND object_id=%d",array((int)$admin->ID,$article));
 if(!$notice){throw new RuntimeException('Owned installed review alert was not persisted for uninstall retention.');}
 $fixture['review_notice']=$notice;$fixture['revision']=$store->record($revisionKey);update_option('fi_uninstall_fixture',$fixture,false);
}elseif(!$fixture){throw new RuntimeException('Seed the fixture first.');}
elseif($action==='preserve'){
 if($store->record($fixture['recommendation_key'])!==$fixture['recommendation']){throw new RuntimeException('Moderator recommendation changed before uninstall.');}
 deactivate_plugins($plugin);update_option('fi_vector_cache_upgrade',$fixture['upgrade_marker'],false);uninstall_plugin($plugin);
 if(get_post_meta((int)$fixture['article'],'_fi_knowledge_target',true)!==$fixture['target_binding']){throw new RuntimeException('Default uninstall changed native adapter binding.');}
 if($store->record($fixture['recommendation_key'])!==$fixture['recommendation']||!get_post((int)$fixture['reply'])){throw new RuntimeException('Default uninstall changed the moderator note or authored accepted answer.');}
 foreach($fixture['automation_canary'] as $key=>$value){if((get_option('fi_settings')[$key]??null)!==$value){throw new RuntimeException('Default uninstall changed automatic-summary configuration.');}}
 if($store->row("SELECT * FROM {$store->table('notifications')} WHERE id=%d",array((int)$fixture['review_notice']['id']))!==$fixture['review_notice']){throw new RuntimeException('Default uninstall changed the persisted owned editor review alert.');}
 if(get_option('fi_vector_cache_upgrade')!==$fixture['upgrade_marker']){throw new RuntimeException('Default uninstall removed the pending owned vector upgrade marker.');}
 if(get_option('fi_template_variants')!==$fixture['template_canary']){throw new RuntimeException('Default uninstall removed contextual template configuration.');}
 if($store->record($fixture['revision_key'])!==$fixture['revision']||get_post_meta((int)$fixture['article'],'_fi_provenance',true)!==$fixture['provenance']||!str_contains(get_post((int)$fixture['article'])->post_content,'OWNED_HUMAN_ARTICLE_RETENTION_CANARY')){throw new RuntimeException('Default uninstall changed discussion counters, verification provenance or authored article text.');}
 foreach(array_keys($fixture['metadata']) as $meta){if(get_user_meta($admin->ID,$meta,true)!=='OWNED_METADATA_CANARY'){throw new RuntimeException('Default uninstall removed user metadata.');}}
 if(!is_file($fixture['owned'])||!$store->record('uninstall-owned')||!get_post($fixture['forum'])){throw new RuntimeException('Default uninstall removed retained data.');}
}elseif($action==='malformed'){
 update_option('fi_settings',array_replace($fixture['settings'],array('delete_on_uninstall'=>true)));
 $store->saveRecord('attachment','uninstall-invalid',(int)$admin->ID,(int)$fixture['forum'],array('protected'=>true,'key'=>'../unrelated-control','name'=>'bad'));
}elseif($action==='blocked'){
 $blocked=false;try{uninstall_plugin($plugin);}catch(ForumIntelligence\Domain\Failure $failure){$blocked=$failure->reason==='unsafe_storage_reference';}
 if(!$blocked||!$store->record('uninstall-invalid')||!is_file($fixture['outside'])){throw new RuntimeException('Unsafe storage must stop destructive table removal.');}
}elseif($action==='remove'){
 $store->execute("DELETE FROM {$store->table('records')} WHERE record_key=%s",array('uninstall-invalid'));
 // Prime the actual WordPress metadata caches in the same process as native uninstall.
 foreach(array_keys($fixture['metadata']) as $meta){get_user_meta($admin->ID,$meta,true);}
 get_post_meta((int)$fixture['forum'],'_fi_uninstall_test',true);
 get_post_meta((int)$fixture['article'],'_fi_provenance',true);
 uninstall_plugin($plugin);
 if(metadata_exists('post',(int)$fixture['article'],'_fi_knowledge_target')||get_post_meta((int)$fixture['article'],'_fi_knowledge_target',true)!==''){throw new RuntimeException('Explicit uninstall retained native adapter binding/cache.');}
 if(!get_post((int)$fixture['reply'])||get_post((int)$fixture['reply'])->post_content!=='Owned uninstall accepted human answer'){throw new RuntimeException('Explicit uninstall removed authored native accepted answer content.');}
 if(get_option('fi_settings',false)!==false){throw new RuntimeException('Explicit uninstall retained owned automatic-summary settings.');}
 if(get_option('fi_vector_cache_upgrade',false)!==false){throw new RuntimeException('Explicit uninstall retained the pending vector upgrade marker.');}
 if(get_option('fi_template_variants',false)!==false){throw new RuntimeException('Explicit uninstall retained contextual template configuration.');}
 if(metadata_exists('post',(int)$fixture['article'],'_fi_provenance')||get_post_meta((int)$fixture['article'],'_fi_provenance',true)!==''||metadata_exists('post',(int)$fixture['article'],'_fi_source_topic')||!str_contains(get_post((int)$fixture['article'])->post_content,'OWNED_HUMAN_ARTICLE_RETENTION_CANARY')||!get_post((int)$fixture['topic'])){throw new RuntimeException('Explicit uninstall retained owned provenance/cache or deleted authored native content.');}
 foreach(array_keys($fixture['metadata']) as $meta){if(metadata_exists('user',$admin->ID,$meta)||get_user_meta($admin->ID,$meta,true)!==''){throw new RuntimeException('Owned user metadata or its cache survived uninstall.');}}
 if(metadata_exists('post',(int)$fixture['forum'],'_fi_uninstall_test')||get_post_meta((int)$fixture['forum'],'_fi_uninstall_test',true)!==''){throw new RuntimeException('Owned post metadata or its cache survived uninstall.');}
 if(get_user_meta($admin->ID,$fixture['unrelated_meta'],true)!=='UNRELATED_METADATA_MUST_REMAIN'||get_post_meta((int)$fixture['forum'],'_other_plugin_test',true)!=='UNRELATED_POST_METADATA_MUST_REMAIN'){throw new RuntimeException('Unrelated metadata was removed.');}
 global $wpdb;
 foreach(ForumIntelligence\Infrastructure\Database\Schema::TABLES as $name){
  if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'fi_'.$name)))){throw new RuntimeException('Owned table remains: '.$name);}
 }
 if(is_file($fixture['owned'])||!is_file($fixture['outside'])||!get_post($fixture['forum'])||get_option('fi_schema_version',false)!==false){throw new RuntimeException('Explicit uninstall ownership or retention failed.');}
}elseif($action==='restore'){
 foreach(array('owned','outside') as $key){if(is_file($fixture[$key])){unlink($fixture[$key]);}}
 $result=activate_plugin($plugin);if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}
 (new ForumIntelligence\Infrastructure\Database\Schema())->install();update_option('fi_settings',$fixture['settings']);
 if($fixture['template_exists']){update_option('fi_template_variants',$fixture['template_value']);}else{delete_option('fi_template_variants');}
 if($fixture['upgrade_exists']){update_option('fi_vector_cache_upgrade',$fixture['upgrade_value'],false);}else{delete_option('fi_vector_cache_upgrade');}
 foreach($fixture['metadata'] as $meta=>$values){delete_user_meta($admin->ID,$meta);foreach($values as $value){add_user_meta($admin->ID,$meta,$value);}}
 delete_user_meta($admin->ID,$fixture['unrelated_meta']);
 foreach(array('article','reply','topic','forum') as $kind){$ownedPost=get_post((int)$fixture[$kind]);if($ownedPost&&(int)$ownedPost->post_author!==(int)$admin->ID){throw new RuntimeException('Refusing to remove unowned uninstall fixture content.');}wp_delete_post((int)$fixture[$kind],true);}delete_option('fi_uninstall_fixture');
}else{throw new RuntimeException('Unknown fixture stage.');}
echo "Packaged uninstall stage passed: ".$action."\n";
