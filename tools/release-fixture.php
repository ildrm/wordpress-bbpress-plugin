<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
$root=getenv('FI_RELEASE_WP_PATH')?:dirname(__DIR__).'/.runtime/release-wordpress';
require $root.'/wp-load.php';
$admin=get_user_by('login','release_admin');
if(!$admin || get_option('home')!=='http://127.0.0.1:8899'||get_option('fi_notification_fixture')||get_option('fi_uninstall_fixture')||get_option('fi_saved_search_race')){throw new RuntimeException('This fixture requires the disposable local release site.');}
wp_set_current_user($admin->ID);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$store=$services->store;
$action=$argv[1]??'state';
$fixture=get_option('fi_release_fixture',array());
if($action==='seed'){
 if($fixture){throw new RuntimeException('Clean up the prior release fixture before seeding.');}
 if(!is_dir(FI_PROTECTED_STORAGE_DIR)){
  $normalize=static fn(string $path): string=>strtolower(rtrim(str_replace('\\','/',$path),'/'));
  if($normalize(FI_PROTECTED_STORAGE_DIR)!==$normalize(sys_get_temp_dir().'/fi-protected-release-test')){throw new RuntimeException('Create and verify the configured isolated protected directory first.');}
  if(!mkdir(FI_PROTECTED_STORAGE_DIR,0700)){throw new RuntimeException('Could not provision the owned temporary storage fixture.');}
 }

 $settings=get_option('fi_settings',array());update_option('fi_settings',array_replace_recursive($settings,array('ai_enabled'=>false,'modules'=>array('attachments'=>true,'messaging'=>true))));
 $forum=(int)bbp_insert_forum(array('post_title'=>'Protected upload verification','post_author'=>$admin->ID));
 $topic=(int)bbp_insert_topic(array('post_title'=>'Actual multipart upload test','post_content'=>'Private file verification fixture','post_parent'=>$forum,'post_author'=>$admin->ID),array('forum_id'=>$forum));
 $fixture=array('forum'=>$forum,'topic'=>$topic,'admin'=>(int)$admin->ID,'url'=>get_permalink($topic),'settings'=>$settings);
 update_option('fi_release_fixture',$fixture,false);
 $store->execute("DELETE FROM {$store->table('limits')} WHERE bucket=%s",array(hash('sha256',$admin->ID.':attachment')));
}elseif(!$fixture){throw new RuntimeException('Seed the isolated fixture first.');}
elseif($action==='hide'){bbp_hide_forum((int)$fixture['forum']);}
elseif($action==='show'){bbp_publicize_forum((int)$fixture['forum']);}
elseif($action==='templates'){
 if(array_key_exists('template_exists',$fixture)){throw new RuntimeException('Contextual template fixture is already owned.');}
 $missingTemplate=new stdClass();$previousTemplate=get_option('fi_template_variants',$missingTemplate);
 $fixture['template_exists']=$previousTemplate!==$missingTemplate;$fixture['template_value']=$previousTemplate===$missingTemplate?array():$previousTemplate;
 update_option('fi_release_fixture',$fixture,false);
 $services->get('templates')->configure((int)$admin->ID,wp_json_encode(array(
  array('id'=>'installed-support','label'=>'Installed support guidance','forum'=>(int)$fixture['forum'],'topic_type'=>'Support','product'=>'Widget','category'=>'Troubleshooting','text'=>"INSTALLED_CONTEXT_TEMPLATE\nProblem:\nExpected behavior:"),
 )));
}
elseif($action==='reset_limits'){$store->execute("DELETE FROM {$store->table('limits')} WHERE bucket=%s",array(hash('sha256',$admin->ID.':attachment')));}
elseif($action==='erase'){(new ForumIntelligence\Privacy\Privacy($store))->erase($admin->user_email);}
elseif($action==='cleanup'){
 if(array_key_exists('template_exists',$fixture)){if($fixture['template_exists']){update_option('fi_template_variants',$fixture['template_value']);}else{delete_option('fi_template_variants');}}
 $privacy=new ForumIntelligence\Privacy\Privacy($store);
 for($i=0;$i<10;$i++){if($privacy->erase($admin->user_email)['done']){break;}}
 wp_delete_post((int)$fixture['topic'],true);wp_delete_post((int)$fixture['forum'],true);
 update_option('fi_settings',$fixture['settings']);delete_option('fi_release_fixture');
}
$files=isset($fixture['topic'])?$store->rows("SELECT id,payload FROM {$store->table('records')} WHERE kind='attachment' AND object_id=%d ORDER BY id",array($fixture['topic'])):array();
echo wp_json_encode(array('fixture'=>$fixture,'files'=>array_map(static function(array $row): array { $data=json_decode($row['payload'],true);return array('id'=>(int)$row['id'],'name'=>$data['name'],'path'=>FI_PROTECTED_STORAGE_DIR.DIRECTORY_SEPARATOR.$data['key']); },$files)))."\n";
