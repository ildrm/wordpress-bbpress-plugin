<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8898'){throw new RuntimeException('Use only the isolated browser installation.');}
$mode=$argv[1]??'';
$base=realpath(ABSPATH.'wp-content');
$directory=ABSPATH.'wp-content/mu-plugins';
if(!$base){throw new RuntimeException('Missing isolated content directory.');}
if(!is_dir($directory) && !mkdir($directory,0777,true)){throw new RuntimeException('Cannot create owned fixture directory.');}
$resolved=realpath($directory);
if(!$resolved || !str_starts_with(str_replace('\\','/',$resolved).'/',str_replace('\\','/',$base).'/')){throw new RuntimeException('Refusing fixture path outside isolated content.');}
$file=$directory.'/fi-owned-rich-mentions.php';
$content=<<<'FIXTURE'
<?php
// Owned isolated mention-editor compatibility fixture; never shipped.
defined('ABSPATH') || exit;
add_filter('bbp_after_get_the_content_parse_args',static function($args){
if(get_option('home')==='http://127.0.0.1:8898' && get_option('fi_e2e_rich_mentions')){$args['tinymce']=true;}
return $args;
});
FIXTURE;
$content.="\n";
if(is_file($file) && file_get_contents($file)!==$content){throw new RuntimeException('Refusing to replace an unrelated editor fixture.');}
if($mode==='enable'){
    if(get_option('fi_e2e_rich_mentions',false)!==false){throw new RuntimeException('An editor fixture already owns this option.');}
    if(file_put_contents($file,$content)!==strlen($content)){throw new RuntimeException('Cannot write owned fixture.');}
    add_option('fi_e2e_rich_mentions','fi-mention-editor-v1','',false);
}elseif($mode==='restore'){
    if(get_option('fi_e2e_rich_mentions')!=='fi-mention-editor-v1'){throw new RuntimeException('No owned editor fixture to restore.');}
    delete_option('fi_e2e_rich_mentions');
    if(is_file($file) && !unlink($file)){throw new RuntimeException('Cannot remove owned fixture.');}
}else{throw new RuntimeException('Choose enable or restore.');}
echo "Owned editor fixture ".$mode." complete.\n";
