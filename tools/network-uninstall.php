<?php
declare(strict_types=1);
define('WP_DISABLE_FATAL_ERROR_HANDLER',true);ini_set('display_errors','1');ini_set('log_errors','1');ini_set('error_log',dirname(__DIR__).'/.runtime/network-uninstall-errors.log');
$_SERVER['REQUEST_METHOD']='GET';$_SERVER['HTTP_HOST']='network.example.test';
require dirname(__DIR__).'/.runtime/network-wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
if(!is_multisite()||get_network()->domain!=='network.example.test'||array_map('intval',get_sites(array('fields'=>'ids','number'=>3)))!==array(1,2)){throw new RuntimeException('Requires the owned, exactly two-site disposable network.');}
$actor=get_user_by('login','network_admin');if(!$actor){throw new RuntimeException('Disposable network administrator missing.');}
wp_set_current_user($actor->ID);
$plugin='forum-intelligence-for-bbpress/forum-intelligence-for-bbpress.php';
$action=$argv[1]??'';
$fixture=get_site_option('fi_network_uninstall_fixture',array());
if($action==='seed'){
 if($fixture){throw new RuntimeException('Restore the prior network fixture before seeding.');}
 ForumIntelligence\Bootstrap\Lifecycle::activate(true);
 foreach(array(1,2) as $blog){
  switch_to_blog($blog);
  try{
   bbp_set_user_role($actor->ID,bbp_get_keymaster_role());
   $settings=get_option('fi_settings',array());
   $forum=(int)bbp_insert_forum(array('post_title'=>'Network uninstall native retention','post_author'=>$actor->ID));
   update_post_meta($forum,'_fi_network_uninstall_control','retained-native');
   $store=new ForumIntelligence\Infrastructure\Database\Store();
   $store->saveRecord('draft','network-uninstall-control',(int)$actor->ID,$forum,array('text'=>'OWNED_NETWORK_STATE'));
   $preference=ForumIntelligence\Domain\Reputation\Badges::preferenceKey();$prior=get_user_meta($actor->ID,$preference,true);
   update_user_meta($actor->ID,$preference,true);
   update_option('fi_settings',array_replace($settings,array('delete_on_uninstall'=>$blog===1)));
   $fixture[$blog]=array('forum'=>$forum,'settings'=>$settings,'preference'=>$prior);
  }finally{restore_current_blog();}
 }
 update_site_option('fi_network_uninstall_fixture',$fixture);
}elseif(!$fixture){throw new RuntimeException('Seed the network fixture first.');}
elseif($action==='remove'){
 deactivate_plugins($plugin,false,true);uninstall_plugin($plugin);
 foreach(array(1,2) as $blog){
  switch_to_blog($blog);
  try{
   global $wpdb;
   foreach(ForumIntelligence\Infrastructure\Database\Schema::TABLES as $suffix){
    $exists=(bool)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'fi_'.$suffix)));
    if($exists!==($blog===2)){throw new RuntimeException('Per-site table retention failed.');}
   }
   if(!get_post($fixture[$blog]['forum'])){throw new RuntimeException('Native forum was removed.');}
   $metadata=get_post_meta($fixture[$blog]['forum'],'_fi_network_uninstall_control',true);
   if(($metadata==='retained-native')!==($blog===2)){throw new RuntimeException('Per-blog metadata cleanup failed.');}
   $preference=get_user_meta($actor->ID,ForumIntelligence\Domain\Reputation\Badges::preferenceKey(),true);
   if((bool)$preference!==($blog===2)){throw new RuntimeException('Global user metadata leaked cleanup across blogs.');}
   foreach(array('fi_queue_tick','fi_queue_soon','fi_retention_tick') as $hook){if(wp_next_scheduled($hook)){throw new RuntimeException('Network uninstall left owned schedules.');}}
   if($blog===2&&!(new ForumIntelligence\Infrastructure\Database\Store())->record('network-uninstall-control')){throw new RuntimeException('Default preservation lost site state.');}
  }finally{restore_current_blog();}
 }
 if(get_site_option('fi_network_enabled',false)!==false){throw new RuntimeException('Network activation option remains.');}
}elseif($action==='restore'){
 $result=activate_plugin($plugin,'',true);if(is_wp_error($result)){throw new RuntimeException($result->get_error_message());}
 ForumIntelligence\Bootstrap\Lifecycle::activate(true);
 foreach(array(1,2) as $blog){
  switch_to_blog($blog);
  try{
   (new ForumIntelligence\Infrastructure\Database\Schema())->install();
   update_option('fi_settings',$fixture[$blog]['settings']);
   $store=new ForumIntelligence\Infrastructure\Database\Store();$store->execute("DELETE FROM {$store->table('records')} WHERE record_key='network-uninstall-control'");
   wp_delete_post($fixture[$blog]['forum'],true);
   $key=ForumIntelligence\Domain\Reputation\Badges::preferenceKey();
   if($fixture[$blog]['preference']===''){delete_user_meta($actor->ID,$key);}else{update_user_meta($actor->ID,$key,$fixture[$blog]['preference']);}
  }finally{restore_current_blog();}
 }
 delete_site_option('fi_network_uninstall_fixture');
}else{throw new RuntimeException('Unknown network fixture stage.');}
echo 'Network uninstall stage passed: '.$action."\n";
