<?php
declare(strict_types=1);
/** Three-process, owned installed-package fixture; never a production benchmark command. */
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/release-wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8899'||get_option('fi_release_fixture')||get_option('fi_uninstall_fixture')||get_option('fi_saved_search_race')){throw new RuntimeException('Use only the clean disposable release installation.');}
$admin=get_user_by('login','release_admin');if(!$admin){throw new RuntimeException('Missing owned release administrator.');}
wp_set_current_user($admin->ID);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();
$notifications=$services->get('notifications');$following=$services->get('following');$store=$services->store;
if(!$notifications||!str_contains(str_replace('\\','/',(new ReflectionClass($notifications))->getFileName()),'/release-wordpress/wp-content/plugins/')){throw new RuntimeException('Load actual packaged notification code.');}
$stage=$argv[1]??'';$fixture=get_option('fi_notification_fixture',array());
if($stage==='seed'){
 if($fixture){throw new RuntimeException('Restore the prior notification fixture first.');}
 $token=bin2hex(random_bytes(8));
 $forum=(int)bbp_insert_forum(array('post_title'=>'Owned fanout fixture '.$token,'post_author'=>$admin->ID));
 $topic=(int)bbp_insert_topic(array('post_title'=>'Owned fanout topic '.$token,'post_author'=>$admin->ID,'post_parent'=>$forum),array('forum_id'=>$forum));
 $reply=(int)bbp_insert_reply(array('post_content'=>'Notification fixture content must not leave this site.','post_author'=>$admin->ID,'post_parent'=>$topic),array('topic_id'=>$topic,'forum_id'=>$forum));
 $fixture=array('users'=>array(),'forum'=>$forum,'topic'=>$topic,'reply'=>$reply,'event'=>'fixture-fanout:'.$token);
 update_option('fi_notification_fixture',$fixture,false);
 for($i=0;$i<201;$i++){
  $id=wp_insert_user(array('user_login'=>'fi_fanout_'.$token.'_'.$i,'user_pass'=>bin2hex(random_bytes(24)),'user_email'=>'fi_fanout_'.$token.'_'.$i.'@example.test','role'=>'subscriber'));
  if(is_wp_error($id)){throw new RuntimeException('Owned fixture user creation failed.');}
  $fixture['users'][]=(int)$id;update_option('fi_notification_fixture',$fixture,false);bbp_set_user_role($id,bbp_get_participant_role());
  $following->set($id,$topic,'topic','follow',true);
  if($i<3){$following->set($id,$topic,'topic','mute',true);}
  elseif($i<6){$notifications->savePreferences($id,'off',array('reply'));}
  elseif($i<9){bbp_set_user_role($id,bbp_get_blocked_role());}
 }
 echo "Seeded 201 owned followers with nine current delivery exclusions.\n";
}elseif(!$fixture){throw new RuntimeException('Seed the owned fixture first.');}
elseif($stage==='run'){
 global $wpdb;$queries=$wpdb->num_queries;$start=microtime(true);
 $notifications->request((int)$fixture['reply'],(int)$admin->ID,'reply',$fixture['event']);
 for($round=0;$round<10;$round++){
  $services->queue->run(100);
  $pending=$store->row("SELECT COUNT(*) AS n FROM {$store->table('jobs')} WHERE kind='notify' AND job_key LIKE %s AND status<>'completed'",array('notify:'.$fixture['event'].':%'));
  if(!(int)$pending['n']){break;}
 }
 $count=$store->row("SELECT COUNT(*) AS n FROM {$store->table('notifications')} WHERE event_key=%s",array($fixture['event']));
 if((int)$count['n']!==192||(int)$pending['n']){throw new RuntimeException('Bounded fanout mismatch: '.json_encode(array('delivered'=>(int)$count['n'],'pending'=>(int)$pending['n'],'jobs'=>$store->rows("SELECT kind,status,error_code,attempts FROM {$store->table('jobs')} WHERE kind='notify' AND job_key LIKE %s",array('notify:'.$fixture['event'].':%')))));}
 $elapsed=round((microtime(true)-$start)*1000,2);$queryCount=$wpdb->num_queries-$queries;
 for($i=0;$i<9;$i++){if($notifications->inbox((int)$fixture['users'][$i])['items']){throw new RuntimeException('A muted or blocked fixture received an inbox item.');}}
 bbp_hide_forum((int)$fixture['forum']);
 if($notifications->inbox((int)$fixture['users'][9])['items']){throw new RuntimeException('A delivered notification leaked after source privacy changed.');}
 bbp_publicize_forum((int)$fixture['forum']);
 $notifications->request((int)$fixture['reply'],(int)$admin->ID,'reply',$fixture['event']);
 for($round=0;$round<10;$round++){
  $services->queue->run(100);
  $pending=$store->row("SELECT COUNT(*) AS n FROM {$store->table('jobs')} WHERE kind='notify' AND job_key LIKE %s AND status<>'completed'",array('notify:'.$fixture['event'].':%'));
  if(!(int)$pending['n']){break;}
 }
 $repeat=$store->row("SELECT COUNT(*) AS n FROM {$store->table('notifications')} WHERE event_key=%s",array($fixture['event']));
 if((int)$repeat['n']!==192||(int)$pending['n']){throw new RuntimeException('Retry duplicated or stranded a notification.');}
 echo json_encode(array('followers'=>201,'delivered'=>192,'excluded'=>9,'cold_fanout_ms'=>$elapsed,'cold_fanout_queries'=>$queryCount,'retry_duplicate_count'=>0,'current_hidden_inbox_count'=>0),JSON_PRETTY_PRINT)."\n";
}elseif($stage==='restore'){
 if(!preg_match('/^fixture-fanout:([a-f0-9]{16})$/',$fixture['event'],$match)){throw new RuntimeException('Invalid owned fixture identity.');}
 foreach($fixture['users'] as $index=>$id){
  $user=get_userdata((int)$id);
  if($user&&$user->user_login!=='fi_fanout_'.$match[1].'_'.$index){throw new RuntimeException('Refusing to delete an unrelated account.');}
 }
 foreach(array('forum','topic') as $kind){
  $post=get_post((int)$fixture[$kind]);
  $title=$kind==='forum'?'Owned fanout fixture ':'Owned fanout topic ';
  if($post&&($post->post_title!==$title.$match[1]||(int)$post->post_author!==(int)$admin->ID)){throw new RuntimeException('Refusing to delete unrelated native content.');}
 }
 $reply=get_post((int)$fixture['reply']);
 if($reply&&((int)$reply->post_parent!==(int)$fixture['topic']||(int)$reply->post_author!==(int)$admin->ID)){throw new RuntimeException('Refusing to delete an unrelated native reply.');}
 require_once ABSPATH.'wp-admin/includes/user.php';
 foreach($fixture['users'] as $id){
  $store->execute("DELETE FROM {$store->table('links')} WHERE user_id=%d",array($id));
  $store->execute("DELETE FROM {$store->table('notifications')} WHERE user_id=%d",array($id));
  $store->execute("DELETE FROM {$store->table('limits')} WHERE bucket=%s",array(hash('sha256',$id.':follow')));
  wp_delete_user((int)$id);
 }
 $store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='notify' AND job_key LIKE %s",array('notify:'.$fixture['event'].':%'));
 foreach(array('reply','topic','forum') as $key){wp_delete_post((int)$fixture[$key],true);}
 delete_option('fi_notification_fixture');
 echo "Owned notification fixture restored.\n";
}else{throw new RuntimeException('Use seed, run or restore.');}
