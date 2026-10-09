<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
$release=getenv('FI_RELEASE_E2E')==='1';
require dirname(__DIR__).'/.runtime/'.($release?'release-wordpress':'wordpress').'/wp-load.php';
if(get_option('home')!==($release?'http://127.0.0.1:8899':'http://127.0.0.1:8898')||DB_NAME!==($release?'fi_release':'fi_test')){throw new RuntimeException('Duplicate fixture requires the owned disposable source site.');}
add_filter('pre_wp_mail',static fn()=>true);
$command=$argv[1]??'state';$key='fi_duplicate_fixture';$state=get_option($key,[]);
$s=new ForumIntelligence\Infrastructure\Database\Store();
if($command==='seed'){
 if($state)throw new RuntimeException('An owned duplicate fixture is already active.');
 $missing=new stdClass();$settings=get_option('fi_settings',$missing);$state=['settings_exists'=>$settings!==$missing,'settings'=>$settings===$missing?null:$settings,'owned'=>[],'user'=>0,'login'=>'fi_owned_duplicate_'.bin2hex(random_bytes(8))];update_option($key,$state,false);
 update_option('fi_settings',['ai_enabled'=>false,'author_acceptance'=>true,'duplicate_threshold'=>0.25,'modules'=>['composer'=>true,'knowledge'=>false,'notifications'=>false]],false);
 $user=wp_insert_user(['user_login'=>$state['login'],'user_pass'=>'isolated-duplicate-only','role'=>'subscriber']);if(is_wp_error($user))throw new RuntimeException('Owned duplicate user failed.');$state['user']=(int)$user;update_option($key,$state,false);bbp_set_user_role($state['user'],bbp_get_participant_role());wp_set_current_user($state['user']);
 $remember=static function(int $id)use(&$state,$key):int{if($id<=0)throw new RuntimeException('Owned native source failed.');$state['owned'][]=$id;update_option($key,$state,false);return$id;};
 $state['forum']=$remember((int)bbp_insert_forum(['post_title'=>'Owned duplicate browser forum','post_author'=>$state['user']]));update_option($key,$state,false);
 $state['topic']=$remember((int)bbp_insert_topic(['post_title'=>'Browser duplicate cache invalidation question','post_content'=>'Existing cache invalidation discussion with a practical solution.','post_parent'=>$state['forum'],'post_author'=>$state['user']],['forum_id'=>$state['forum']]));update_option($key,$state,false);
 $state['reply']=$remember((int)bbp_insert_reply(['post_content'=>'OWNED_DUPLICATE_ACCEPTED_PREVIEW: Delete the expired transient.','post_parent'=>$state['topic'],'post_author'=>$state['user']],['forum_id'=>$state['forum'],'topic_id'=>$state['topic']]));update_option($key,$state,false);
 $services=new ForumIntelligence\Bootstrap\Services();$services->register();$services->solutions->change($state['topic'],$state['reply'],'accept',$state['user']);
 $state['forum_url']=get_permalink($state['forum']);$state['topic_url']=get_permalink($state['topic']);update_option($key,$state,false);
}
if(in_array($command,['protect','restore'],true)){
 if(empty($state['topic'])||(int)get_post_field('post_author',$state['topic'],'raw')!==(int)$state['user'])throw new RuntimeException('Not the owned duplicate source.');
 wp_update_post(['ID'=>$state['topic'],'post_password'=>$command==='protect'?'owned-duplicate-only':'']);
}
if($command==='cleanup'&&$state){
 foreach(array_reverse($state['owned'])as $id){$post=get_post($id);if($post&&(int)$post->post_author!==(int)$state['user'])throw new RuntimeException('Refusing foreign duplicate fixture post.');wp_delete_post($id,true);}
 if(!empty($state['user'])){$user=get_userdata($state['user']);if($user&&($user->user_login!==$state['login']||!str_starts_with($user->user_login,'fi_owned_duplicate_')))throw new RuntimeException('Refusing foreign duplicate user.');require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($state['user']);}
 foreach(['duplicates','duplicate_feedback']as $feature)$s->execute("DELETE FROM {$s->table('limits')} WHERE bucket=%s",[hash('sha256',($state['user']??0).':'.$feature)]);
 if($state['settings_exists'])update_option('fi_settings',$state['settings'],false);else delete_option('fi_settings');delete_option($key);$state=[];
}
if($state){$state['metrics']=$s->rows("SELECT kind,count FROM {$s->table('metrics')} WHERE day=%s AND kind IN ('duplicate_feedback_viewed','duplicate_feedback_opened','duplicate_feedback_dismissed')",[gmdate('Y-m-d')]);}
unset($state['settings'],$state['owned']);echo wp_json_encode(['fixture'=>$state],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
