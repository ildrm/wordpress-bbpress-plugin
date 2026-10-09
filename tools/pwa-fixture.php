<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8898'){throw new RuntimeException('Requires the owned local browser site.');}
$path=WPMU_PLUGIN_DIR.'/fi-e2e-foreign-worker.php';
$fixture=get_option('fi_pwa_fixture',array());
$action=$argv[1]??'';
$code=<<<'PHP'
<?php
// Owned PWA fixture: never distributed.
add_action('init',static function(): void {
 if(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)!=='/fi-e2e-foreign-worker.js'){return;}
 nocache_headers();header('Content-Type: application/javascript');header('Service-Worker-Allowed: /');
 echo "self.addEventListener('install',()=>self.skipWaiting());self.addEventListener('activate',event=>event.waitUntil(self.clients.claim()));";
 exit;
},0);
PHP;
if($action==='seed'){
 if($fixture||is_file($path)){throw new RuntimeException('Restore the previous PWA fixture first.');}
 if(!is_dir(WPMU_PLUGIN_DIR)&&!mkdir(WPMU_PLUGIN_DIR,0755)){throw new RuntimeException('Could not create owned MU fixture directory.');}
 $settings=get_option('fi_settings',array());update_option('fi_pwa_fixture',array('settings'=>$settings),false);
 file_put_contents($path,$code);
 update_option('fi_settings',array_replace_recursive($settings,array('modules'=>array('pwa'=>true))));
}elseif($action==='cleanup'&&$fixture){
 if(is_file($path)){if(file_get_contents($path)!==$code){throw new RuntimeException('Unexpected MU fixture content; retaining it for inspection.');}unlink($path);}
 update_option('fi_settings',$fixture['settings']);delete_option('fi_pwa_fixture');
}else{throw new RuntimeException('Unknown/unseeded PWA fixture action.');}
echo 'Owned PWA fixture '.$action."\n";
