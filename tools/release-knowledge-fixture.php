<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/release-wordpress/wp-load.php';
if(DB_NAME!=='fi_release'||get_option('home')!=='http://127.0.0.1:8899'){throw new RuntimeException('Knowledge fixture requires the owned disposable release site.');}
foreach(array('fi_release_fixture','fi_notification_fixture','fi_uninstall_fixture','fi_saved_search_race','fi_vector_performance_fixture','fi_summary_fixture','fi_automatic_summary_fixture') as $key){if(get_option($key)){throw new RuntimeException('Finish the other owned release fixture first.');}}
$admin=get_user_by('login','release_admin');if(!$admin){throw new RuntimeException('Missing owned release administrator.');}
wp_set_current_user($admin->ID);$services=new ForumIntelligence\Bootstrap\Services();$services->register();
$option='fi_knowledge_fixture';$state=get_option($option,array());$command=$argv[1]??'state';
if($command==='seed'){
 if($state){throw new RuntimeException('Clean the previous knowledge fixture first.');}
 $missingSettings=new stdClass();$beforeSettings=get_option('fi_settings',$missingSettings);
 $state=array('settings_exists'=>$beforeSettings!==$missingSettings,'settings_before'=>$beforeSettings===$missingSettings?array():$beforeSettings,'ids'=>array(),'actor'=>(int)$admin->ID);update_option($option,$state,false);
 $remember=static function(int $id,string $name)use(&$state,$option):int{if($id<=0){throw new RuntimeException('Native fixture creation failed.');}$state[$name]=$id;$state['ids'][]=$id;update_option($option,$state,false);return $id;};
 $forum=$remember((int)bbp_insert_forum(array('post_title'=>'Owned native knowledge verification','post_author'=>$admin->ID)),'forum');
 $topic=$remember((int)bbp_insert_topic(array('post_title'=>'INSTALLED_KNOWLEDGE_TITLE_CANARY','post_content'=>'Owned knowledge source','post_author'=>$admin->ID,'post_parent'=>$forum),array('forum_id'=>$forum)),'topic');
 $reply=$remember((int)bbp_insert_reply(array('post_content'=>'Owned contributing knowledge source','post_parent'=>$topic,'post_author'=>$admin->ID),array('forum_id'=>$forum,'topic_id'=>$topic)),'reply');
 wp_update_post(wp_slash(array('ID'=>$reply,'post_content'=>"INSTALLED_SOURCE_COMPARE_OLD_CANARY فارسی C:\\owned\\path")));wp_save_post_revision($reply);$sourceRevisions=wp_get_post_revisions($reply);if(!$sourceRevisions){throw new RuntimeException('Missing owned native source revision baseline.');}$state['source_baseline']=(int)array_key_first($sourceRevisions);update_option($option,$state,false);
 $services->get('knowledge')->command($topic,'edit','INSTALLED_KNOWLEDGE_BODY_CANARY',(int)$admin->ID);
 $article=$remember((int)$services->get('knowledge')->command($topic,'draft','',(int)$admin->ID)['article_id'],'article');
 wp_update_post(array('ID'=>$article,'post_status'=>'publish'));
 wp_update_post(array('ID'=>$article,'post_content'=>'INSTALLED_KNOWLEDGE_REVIEWED_BODY_CANARY'));
 $revisions=wp_get_post_revisions($article);if(!$revisions){throw new RuntimeException('No native knowledge revisions.');}$state['revision']=(int)array_key_first($revisions);$state['topic_url']=get_permalink($topic);update_option($option,$state,false);
 $remember((int)wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_author'=>$admin->ID,'post_title'=>'Owned knowledge view','post_content'=>'[forum_intelligence_knowledge]')),'page');
 $remember((int)wp_insert_post(array('post_type'=>'page','post_status'=>'publish','post_author'=>$admin->ID,'post_title'=>'Owned editor review notifications','post_content'=>'[forum_intelligence_notifications]')),'inbox');
}elseif(!$state){throw new RuntimeException('Seed the owned knowledge fixture first.');}
elseif($command==='protect'){wp_update_post(array('ID'=>(int)$state['reply'],'post_password'=>'owned-browser-source-password'));}
elseif($command==='restore'){wp_update_post(array('ID'=>(int)$state['reply'],'post_password'=>''));}
elseif($command==='article-password'){wp_update_post(array('ID'=>(int)$state['article'],'post_password'=>'owned-browser-article-password'));}
elseif($command==='source-change'){wp_update_post(array('ID'=>(int)$state['reply'],'post_content'=>'INSTALLED_SOURCE_COMPARE_NEW_CANARY changed approach for human editorial review'));}
elseif($command==='clear-article-password'){wp_update_post(array('ID'=>(int)$state['article'],'post_password'=>''));}
elseif($command==='enable-review-alerts'){$settings=(array)get_option('fi_settings',array());$settings['knowledge_review_notifications']=true;$settings['ai_enabled']=false;$settings['modules']['knowledge']=true;$settings['modules']['notifications']=true;update_option('fi_settings',$settings);}
elseif($command==='source-change-for-alert'){wp_update_post(array('ID'=>(int)$state['reply'],'post_content'=>'Owned source changed again for a responsible-editor review alert'));}
elseif($command==='deliver-review-alerts'){$services->store->execute("UPDATE {$services->store->table('jobs')} SET available_at=%s WHERE kind='knowledge_review' AND job_key LIKE %s",array('2000-01-01 00:00:00','knowledge_review:'.$state['topic'].':%'));$services->queue->run(1);}
elseif($command==='recommend'){
 $settings=(array)get_option('fi_settings',array());$settings['knowledge_auto_draft_enabled']=false;$settings['summary_auto_enabled']=false;$settings['ai_enabled']=false;$settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
 $state['recommendation_original_body']=get_post((int)$state['article'])->post_content;
 $services->solutions->change((int)$state['topic'],(int)$state['reply'],'accept',(int)$state['actor']);
 $services->get('knowledge')->command((int)$state['topic'],'edit','INSTALLED_RECOMMENDATION_SUMMARY_CANARY',(int)$state['actor']);
 $services->get('knowledge')->command((int)$state['topic'],'recommend','INSTALLED_PRIVATE_RECOMMENDATION_NOTE_CANARY',(int)$state['actor']);update_option($option,$state,false);
}
elseif($command==='target'){
 $state['target_mode']=true;$state['target_original_body']=get_post((int)$state['article'])->post_content;update_option($option,$state,false);
 if(!function_exists('fi_owned_knowledge_target_fixture_register')){throw new RuntimeException('The owned native target acceptance helper is missing.');}
 do_action('fi_owned_target_fixture_register');
 $settings=(array)get_option('fi_settings',array());$settings['knowledge_auto_draft_enabled']=false;$settings['summary_auto_enabled']=false;$settings['ai_enabled']=false;$settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
 $services->get('knowledge')->command((int)$state['topic'],'edit','INSTALLED_ADAPTER_SUMMARY_CANARY',(int)$state['actor']);
}
elseif($command==='reactions'){
 if(isset($state['reaction_member'])){throw new RuntimeException('Owned reaction member already exists.');}
 $login='fi_owned_release_reactor_'.bin2hex(random_bytes(8));$member=wp_insert_user(array('user_login'=>$login,'user_pass'=>'isolated-reaction-only','role'=>'subscriber'));if(is_wp_error($member)||!$member){throw new RuntimeException('Could not create owned native reaction member.');}
 $state['reaction_member']=(int)$member;$state['reaction_login']=$login;update_option($option,$state,false);bbp_set_user_role((int)$member,bbp_get_participant_role());
 $recipientLogin='fi_owned_release_recipient_'.bin2hex(random_bytes(8));$recipient=wp_insert_user(array('user_login'=>$recipientLogin,'user_pass'=>'isolated-recipient-only','role'=>'subscriber'));if(is_wp_error($recipient)||!$recipient){throw new RuntimeException('Could not create owned native vote recipient.');}
 $state['reaction_recipient']=(int)$recipient;$state['reaction_recipient_login']=$recipientLogin;$state['reaction_original_author']=(int)get_post_field('post_author',(int)$state['reply'],'raw');update_option($option,$state,false);
 if($state['reaction_original_author']!==(int)$state['actor']){throw new RuntimeException('Refusing a foreign owned reaction reply.');}
 wp_update_post(array('ID'=>(int)$state['reply'],'post_author'=>(int)$recipient));
 $settings=(array)get_option('fi_settings',array());$settings['reactions']=array('helpful','thanks');$settings['modules']['engagement']=true;$settings['ai_enabled']=false;update_option('fi_settings',$settings);
}
elseif($command==='damage-summary'){
 $state['summary_article_bodies']=array();foreach(array_filter(array($state['article'],$state['target_article']??0))as $id){$post=get_post((int)$id);if(!$post||(int)$post->post_author!==(int)$state['actor']||(int)get_post_meta((int)$id,'_fi_source_topic',true)!==(int)$state['topic']){throw new RuntimeException('Refusing a foreign summary-recovery article.');}$state['summary_article_bodies'][(int)$id]=$post->post_content;}
 $state['summary_copy_ids']=array_map('intval',get_posts(array('post_type'=>array('fi_knowledge','fi_owned_kb'),'post_status'=>array('draft','publish'),'posts_per_page'=>10,'fields'=>'ids','meta_key'=>'_fi_source_topic','meta_value'=>(int)$state['topic'],'orderby'=>'ID','order'=>'ASC')));
 $services->store->execute("UPDATE {$services->store->table('records')} SET payload=%s,version=version+1 WHERE record_key=%s AND kind='summary' AND object_id=%d",array(wp_json_encode('INSTALLED_DAMAGED_SUMMARY_RECORD_CANARY'),'summary:'.$state['topic'],(int)$state['topic']));
 $state['summary_recovery_mode']=true;update_option($option,$state,false);
}
elseif($command==='cleanup'){
 $settings=(array)get_option('fi_settings',array());$settings['knowledge_review_notifications']=false;update_option('fi_settings',$settings);
 $services->store->execute("DELETE FROM {$services->store->table('notifications')} WHERE kind='knowledge_review' AND object_id=%d",array((int)$state['article']));
 $services->store->execute("DELETE FROM {$services->store->table('jobs')} WHERE kind='knowledge_review' AND job_key LIKE %s",array('knowledge_review:'.$state['topic'].':%'));

 $services->store->execute("DELETE FROM {$services->store->table('records')} WHERE record_key=%s",array('summary:'.$state['topic']));
 if(isset($state['reaction_recipient'])){
  $reply=get_post((int)$state['reply']);if($reply&&!in_array((int)$reply->post_author,array((int)$state['reaction_recipient'],(int)$state['reaction_original_author']),true)){throw new RuntimeException('Refusing foreign reaction source authorship.');}
  if($reply){wp_update_post(array('ID'=>(int)$reply->ID,'post_author'=>(int)$state['reaction_original_author']));}
 }
 foreach(array_reverse($state['ids']) as $id){
  $post=get_post((int)$id);if(!$post){continue;}
  if((int)$post->post_author!==(int)$state['actor']||!in_array($post->post_type,array('forum','topic','reply','fi_knowledge','page',...(!empty($state['target_mode'])?array('fi_owned_kb'):array())),true)){throw new RuntimeException('Refusing an unowned fixture post.');}
  wp_delete_post((int)$id,true);
 }
 if(isset($state['reaction_member'])){
  $member=get_userdata((int)$state['reaction_member']);if(!$member||$member->user_login!==$state['reaction_login']||!str_starts_with($member->user_login,'fi_owned_release_reactor_')){throw new RuntimeException('Refusing an unowned reaction fixture account.');}
  $services->store->execute("DELETE FROM {$services->store->table('signals')} WHERE user_id=%d AND object_id IN (%d,%d)",array((int)$member->ID,(int)$state['topic'],(int)$state['reply']));
  $credit='credit:'.hash('sha256','vote:'.$state['reply'].':'.$member->ID);$services->store->execute("DELETE FROM {$services->store->table('records')} WHERE record_key=%s AND kind='credit' AND object_id=%d",array($credit,(int)$state['reply']));
  $services->store->execute("DELETE FROM {$services->store->table('events')} WHERE actor_id=%d AND object_id IN (%d,%d)",array((int)$member->ID,(int)$state['topic'],(int)$state['reply']));
  require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user((int)$member->ID);
  if(isset($state['reaction_recipient'])){
   $recipient=get_userdata((int)$state['reaction_recipient']);if(!$recipient||$recipient->user_login!==$state['reaction_recipient_login']||!str_starts_with($recipient->user_login,'fi_owned_release_recipient_')){throw new RuntimeException('Refusing an unowned vote recipient.');}
   $services->store->execute("DELETE FROM {$services->store->table('records')} WHERE kind='credit_budget' AND owner_id=%d AND record_key LIKE %s",array((int)$recipient->ID,'credit_budget:'.$recipient->ID.':%'));wp_delete_user((int)$recipient->ID);
  }
 }
 if($state['settings_exists']??false){update_option('fi_settings',$state['settings_before']);}else{delete_option('fi_settings');}
 delete_option($option);
}elseif($command!=='state'){throw new RuntimeException('Unknown fixture command.');}
if($command==='state'&&isset($state['recommendation_original_body'])){
 $record=$services->store->record('knowledge_recommendation:'.$state['topic']);$copy=(int)($record['data']['article_id']??0);
 if($copy&&!in_array($copy,$state['ids'],true)){
  $post=get_post($copy);
  if(!$post||$post->post_type!=='fi_knowledge'||$post->post_status!=='draft'||(int)$post->post_author!==(int)$state['actor']||(int)get_post_meta($copy,'_fi_source_topic',true)!==(int)$state['topic']){throw new RuntimeException('Refusing an unexpected recommendation conversion copy.');}
  $state['ids'][]=$copy;$state['recommendation_article']=$copy;update_option($option,$state,false);
 }
}
if($command==='state'&&!empty($state['target_mode'])){
 $copies=get_posts(array('post_type'=>'fi_owned_kb','post_status'=>array('draft','publish'),'posts_per_page'=>2,'meta_key'=>'_fi_source_topic','meta_value'=>(int)$state['topic']));
 if(count($copies)>1){throw new RuntimeException('Unexpected duplicate owned adapted fixture articles.');}
 foreach($copies as $copy){
  if((int)$copy->post_author!==(int)$state['actor']||get_post_meta($copy->ID,'_fi_knowledge_target',true)!=='owned-release'){throw new RuntimeException('Refusing an unexpected adapted fixture copy.');}
  if(!in_array($copy->ID,$state['ids'],true)){$state['ids'][]=$copy->ID;}
  $state['target_article']=$copy->ID;update_option($option,$state,false);
 }
}
$view=$state;unset($view['settings_before'],$view['recommendation_original_body'],$view['target_original_body'],$view['summary_article_bodies']);
if($command!=='cleanup'&&!empty($state['target_mode'])){
 $view['target_original_body_preserved']=get_post((int)$state['article'])->post_content===$state['target_original_body'];
 $view['target_selected']=(get_option('fi_settings')['knowledge_target']??'fi_knowledge')==='owned-release';
 $view['target_body_valid']=!empty($state['target_article'])&&(str_contains(get_post((int)$state['target_article'])->post_content,'INSTALLED_ADAPTER_SUMMARY_CANARY')||str_contains(get_post((int)$state['target_article'])->post_content,'INSTALLED_ADAPTER_HUMAN_CANARY'));
}
if($command!=='cleanup'&&isset($state['recommendation_original_body'])){
 $view['original_body_preserved']=get_post((int)$state['article'])->post_content===$state['recommendation_original_body'];
 $view['recommendation_body_valid']=!empty($state['recommendation_article'])&&str_contains(get_post((int)$state['recommendation_article'])->post_content,'INSTALLED_RECOMMENDATION_SUMMARY_CANARY');
}
if($command!=='cleanup'&&!empty($state['summary_recovery_mode'])){
 $record=$services->store->record('summary:'.$state['topic']);$summary=$services->get('knowledge')->summary((int)$state['topic'],(int)$state['actor']);
 $view['summary_damage_preserved']=$record['data']==='INSTALLED_DAMAGED_SUMMARY_RECORD_CANARY';$view['summary_withheld']=$summary===null;
 $view['summary_repaired']=is_array($summary)&&$summary['answer']==='INSTALLED_SUMMARY_RECOVERY_CANARY فارسی'&&!empty($summary['manual_lock'])&&empty($summary['stale']);
 $view['summary_article_bodies_preserved']=true;foreach($state['summary_article_bodies']as $id=>$body){if(get_post((int)$id)->post_content!==$body){$view['summary_article_bodies_preserved']=false;}}
 $copies=array_map('intval',get_posts(array('post_type'=>array('fi_knowledge','fi_owned_kb'),'post_status'=>array('draft','publish'),'posts_per_page'=>10,'fields'=>'ids','meta_key'=>'_fi_source_topic','meta_value'=>(int)$state['topic'],'orderby'=>'ID','order'=>'ASC')));$view['summary_no_new_copy']=$copies===$state['summary_copy_ids'];
}
if($command!=='cleanup'&&isset($state['reaction_member'])){
 $view['owned_signal_values']=array();foreach($services->store->rows("SELECT kind,value FROM {$services->store->table('signals')} WHERE object_id=%d AND user_id=%d",array((int)$state['reply'],(int)$state['reaction_member']))as $row){$view['owned_signal_values'][$row['kind']]=(int)$row['value'];}
 $credit=$services->store->record('credit:'.hash('sha256','vote:'.$state['reply'].':'.$state['reaction_member']));$view['owned_vote_points']=(int)($credit['data']['points']??0);$view['owned_vote_subject_valid']=!$credit||(int)$credit['owner_id']===(int)get_post_field('post_author',(int)$state['reply'],'raw');
}
echo wp_json_encode(array('fixture'=>$view))."\n";
