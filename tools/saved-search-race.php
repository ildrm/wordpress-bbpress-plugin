<?php
declare(strict_types=1);
/** Own disposable, actual packaged saved-search consumer versus privacy erasure. */
$_SERVER['REQUEST_METHOD']='GET';
define('DISABLE_WP_CRON',true);
require dirname(__DIR__).'/.runtime/release-wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8899'||get_option('fi_notification_fixture')||get_option('fi_release_fixture')||get_option('fi_uninstall_fixture')){throw new RuntimeException('Use only the clean disposable release installation.');}
$admin=get_user_by('login','release_admin');if(!$admin){throw new RuntimeException('Missing owned release administrator.');}
wp_set_current_user($admin->ID);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$store=$services->store;
$saved=$services->get('saved_searches');
if(!$saved||!str_contains(str_replace('\\','/',(new ReflectionClass($saved))->getFileName()),'/release-wordpress/wp-content/plugins/')){throw new RuntimeException('Load actual packaged saved-search code.');}
$stage=$argv[1]??'';$fixture=get_option('fi_saved_search_race',array());
if($stage==='seed'){
 if($fixture){throw new RuntimeException('Restore the prior fixture first.');}
 $token=bin2hex(random_bytes(8));
 $fixture=array('token'=>$token,'user'=>0,'forum'=>0,'topic'=>0);
 update_option('fi_saved_search_race',$fixture,false);
 $id=wp_insert_user(array('user_login'=>'fi_race_'.$token,'user_pass'=>bin2hex(random_bytes(24)),'user_email'=>'fi_race_'.$token.'@example.test','role'=>'subscriber'));
 if(is_wp_error($id)){throw new RuntimeException('Owned fixture member failed.');}
 $fixture['user']=(int)$id;update_option('fi_saved_search_race',$fixture,false);bbp_set_user_role($id,bbp_get_participant_role());
 $fixture['forum']=(int)bbp_insert_forum(array('post_title'=>'Owned privacy race '.$token,'post_author'=>$admin->ID));update_option('fi_saved_search_race',$fixture,false);
 $query='privacy race '.$token;
 $saved->save($id,$query,array('forum'=>$fixture['forum']));$saved->alerts($id,$saved->listing($id)[0]['id'],true);
 $fixture['topic']=(int)bbp_insert_topic(array('post_title'=>$query,'post_content'=>$query.' public evidence','post_parent'=>$fixture['forum'],'post_author'=>$admin->ID),array('forum_id'=>$fixture['forum']));update_option('fi_saved_search_race',$fixture,false);
 echo json_encode($fixture)."\n";exit;
}
if(!$fixture||!preg_match('/^[a-f0-9]{16}$/D',$fixture['token'])){throw new RuntimeException('Seed the owned fixture first.');}
$prefix=dirname(__DIR__).'/.runtime/saved-search-race-'.$fixture['token'];
if($stage==='consumer'){
 $paused=false;
 add_filter('posts_pre_query',static function($posts,\WP_Query $query)use($fixture,$prefix,&$paused){
  if(!$paused&&array_map('intval',(array)$query->get('post__in'))===array((int)$fixture['topic'])){
   $paused=true;file_put_contents($prefix.'.ready','1');$deadline=microtime(true)+20;
   while(!is_file($prefix.'.release')){if(microtime(true)>$deadline){throw new RuntimeException('Owned race release timed out.');}usleep(10000);}
  }
  return $posts;
 },10,2);
 $services->queue->run(100);
 if(!$paused){throw new RuntimeException('Actual saved-search matching was not reached.');}
 echo "Actual consumer completed.\n";
}elseif($stage==='erase'){
 file_put_contents($prefix.'.erasing','1');
 $result=(new ForumIntelligence\Privacy\Privacy($store))->erase(get_userdata($fixture['user'])->user_email);
 if(!$result['items_removed']){throw new RuntimeException('Erasure did not report removed data.');}
 echo "Actual erasure completed.\n";
}elseif($stage==='probe'){
 $waiting=$store->row("SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w INNER JOIN information_schema.INNODB_TRX t ON t.trx_id=w.requesting_trx_id WHERE t.trx_query LIKE %s",array('%DELETE FROM '.$store->table('records').' WHERE owner_id='.(int)$fixture['user'].'%'));
 echo (int)$waiting['n']."\n";
}elseif($stage==='verify'){
 $queries=$store->row("SELECT COUNT(*) AS n FROM {$store->table('records')} WHERE owner_id=%d AND kind='saved_search'",array($fixture['user']));
 $notifications=$store->row("SELECT COUNT(*) AS n FROM {$store->table('notifications')} WHERE user_id=%d",array($fixture['user']));
 if((int)$queries['n']||(int)$notifications['n']){throw new RuntimeException('Concurrent erasure left a saved query or raced delivery.');}
 echo "Concurrent actual packaged consumer/erasure: zero saved queries and zero raced notifications.\n";
}elseif($stage==='restore'){
 require_once ABSPATH.'wp-admin/includes/user.php';
 if($fixture['user']){
  foreach(array('records'=>'owner_id','links'=>'user_id','notifications'=>'user_id')as$table=>$column){$store->execute("DELETE FROM {$store->table($table)} WHERE $column=%d",array($fixture['user']));}
  $store->execute("DELETE FROM {$store->table('limits')} WHERE bucket IN (%s,%s)",array(hash('sha256',$fixture['user'].':saved_search'),hash('sha256',$fixture['user'].':saved_search_alerts')));
  wp_delete_user((int)$fixture['user']);
 }
 foreach(array('topic','forum')as$key){if($fixture[$key]){wp_delete_post((int)$fixture[$key],true);}}
 foreach(array('.ready','.erasing','.release')as$suffix){if(is_file($prefix.$suffix)){unlink($prefix.$suffix);}}
 delete_option('fi_saved_search_race');
 echo "Owned concurrent fixture restored.\n";
}else{throw new RuntimeException('Use seed, consumer, erase, probe, verify or restore.');}
