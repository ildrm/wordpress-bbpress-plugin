<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8898'||DB_NAME!==(getenv('FI_TEST_DB')?:'fi_test')){throw new RuntimeException('Browser seeding requires the owned disposable test site and database.');}
update_option('home','http://127.0.0.1:8898');
update_option('siteurl','http://127.0.0.1:8898');
update_option('permalink_structure','');
$make=static function (string $login,string $role): int {
    $user=get_user_by('login',$login);
    $id=$user?$user->ID:wp_insert_user(['user_login'=>$login,'user_pass'=>'test-browser-only','role'=>'subscriber']);
    if(!get_userdata($id)->user_email){wp_update_user(['ID'=>$id,'user_email'=>$login.'@example.test']);}
    wp_set_password('test-browser-only',$id); bbp_set_user_role($id,$role); return (int)$id;
};
$author=$make('e2e_author',bbp_get_participant_role()); $member=$make('e2e_member',bbp_get_participant_role());
$admin=$make('e2e_admin',bbp_get_keymaster_role());$adminUser=get_userdata($admin);$adminUser->set_role('administrator');bbp_set_user_role($admin,bbp_get_keymaster_role());
$mod=$make('e2e_mod',bbp_get_moderator_role()); wp_set_current_user($mod);
$privacy=new ForumIntelligence\Privacy\Privacy(new ForumIntelligence\Infrastructure\Database\Store());
foreach([$author,$member] as $testUser){$user=get_userdata($testUser);for($batch=0;$batch<20;$batch++){if($privacy->erase($user->user_email)['done']){break;}}}
$oldFile=dirname(__DIR__).'/.runtime/e2e.json';
$old=is_file($oldFile)?json_decode(file_get_contents($oldFile),true):[];
if(!empty($old['forum'])&&get_post_meta((int)$old['forum'],'_fi_e2e_fixture',true)==='1'){$ownedTemplates=(array)get_option('fi_templates',[]);unset($ownedTemplates[(int)$old['forum']]);update_option('fi_templates',$ownedTemplates,false);}
foreach(['reply','private_topic','topic','forum','hidden','search','hub','messages'] as $key){$id=(int)($old[$key]??0);if($id && get_post_meta($id,'_fi_e2e_fixture',true)==='1'){wp_delete_post($id,true);}}
$forum=bbp_insert_forum(['post_title'=>'Browser test support','post_author'=>$mod]);
$hidden=bbp_insert_forum(['post_title'=>'Restricted browser fixture','post_status'=>'hidden','post_author'=>$mod]);
$topic=bbp_insert_topic(['post_parent'=>$forum,'post_author'=>$author,'post_title'=>'Browser cache invalidation question','post_content'=>'How should PHP cache invalidation work after updates?'],['forum_id'=>$forum]);
$reply=bbp_insert_reply(['post_parent'=>$topic,'post_author'=>$member,'post_content'=>'Delete the transient after updating the source.'],['forum_id'=>$forum,'topic_id'=>$topic]);
$privateTopic=bbp_insert_topic(['post_parent'=>$hidden,'post_title'=>'Browser cache invalidation CANARY_HIDDEN','post_content'=>'Never expose CANARY_HIDDEN in search.'],['forum_id'=>$hidden]);
$search=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Community search','post_content'=>'[forum_intelligence_search][forum_intelligence_ask]']);
$settings=get_option('fi_settings',array());update_option('fi_settings',array_replace_recursive($settings,array('modules'=>array('messaging'=>true,'composer'=>true,'badges'=>true))));
$templates=(array)get_option('fi_templates',array());$templates[$forum]="Problem:\nExpected behavior:\nActual behavior:";update_option('fi_templates',$templates,false);
$messages=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Community messages','post_content'=>'[forum_intelligence_messages]']);
$hub=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Community hub','post_content'=>'[forum_intelligence_hub]']);
wp_update_post(['ID'=>$hub,'post_content'=>'[forum_intelligence_unread url="'.esc_url(get_permalink($hub)).'"][forum_intelligence_hub]']);
$notificationStore=new ForumIntelligence\Infrastructure\Database\Store();
foreach([['reply',$topic,'2020-01-01 12:00:00'],['mention',$topic,'2020-01-02 12:00:00'],['reply',$privateTopic,'2020-01-03 12:00:00']] as [$kind,$source,$created]){
    $notificationStore->insert('notifications',['user_id'=>$member,'object_id'=>$source,'kind'=>$kind,'event_key'=>'e2e-center:'.$source.':'.$kind,'created_at'=>$created]);
}
foreach([$forum,$hidden,$topic,$reply,$privateTopic,$search,$hub,$messages] as $id){update_post_meta($id,'_fi_e2e_fixture','1');}
$browserServices=new ForumIntelligence\Bootstrap\Services();$browserServices->register();
foreach([$topic,$reply,$privateTopic] as $source){$browserServices->get('indexer')->index((int)$source);}
$fixture=['admin'=>$admin,'member'=>$member,'author'=>$author,'messages'=>$messages,'messages_url'=>get_permalink($messages),'forum_url'=>get_permalink($forum),'hidden'=>$hidden,'forum'=>$forum,'topic'=>$topic,'reply'=>$reply,'private_topic'=>$privateTopic,'search'=>$search,'hub'=>$hub,'topic_url'=>get_permalink($topic),'search_url'=>get_permalink($search),'hub_url'=>get_permalink($hub)];
file_put_contents(dirname(__DIR__).'/.runtime/e2e.json',wp_json_encode($fixture,JSON_PRETTY_PRINT));
echo "Browser fixtures prepared.\n";
