<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8898'){throw new RuntimeException('Use only the isolated browser installation.');}
$id=(int)($argv[1]??0);$title=$argv[2]??'';$author=get_user_by('login','e2e_author');
if(!$id && $author && preg_match('/^Owned mention fixture [0-9]{13}$/D',$title)){
    $candidates=get_posts(['post_type'=>bbp_get_topic_post_type(),'post_status'=>'any','title'=>$title,'author'=>$author->ID,'numberposts'=>2,'fields'=>'ids']);
    if(count($candidates)>1){throw new RuntimeException('Ambiguous owned fixture; refusing deletion.');}
    $id=(int)($candidates[0]??0);
}
$post=$id?get_post($id):null;
if(!$post){exit;}
$forum=(int)bbp_get_topic_forum_id($id);
if(!$author||$post->post_type!==bbp_get_topic_post_type()||$post->post_title!==$title||!str_starts_with($title,'Owned mention fixture ')||(int)$post->post_author!==(int)$author->ID||get_post_meta($forum,'_fi_e2e_fixture',true)!=='1'){throw new RuntimeException('Refusing to delete unrelated native content.');}
wp_set_current_user(get_user_by('login','fi_admin')->ID);
$store=new ForumIntelligence\Infrastructure\Database\Store();
$store->execute("DELETE FROM {$store->table('notifications')} WHERE object_id=%d AND kind IN ('reply','topic','mention')",[$id]);
wp_delete_post($id,true);
echo "Owned mention topic removed.\n";
