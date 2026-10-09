<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
$runtime=getenv('FI_RELEASE_WP_PATH')?:dirname(__DIR__).'/.runtime/release-wordpress';
// Deliberately collide with names another plugin can load before this plugin.
foreach(array('GuzzleHttp\\Client','GuzzleHttp\\HandlerStack','Minishlink\\WebPush\\WebPush') as $legacy){
 class_alias(get_class(new class{}),$legacy);
}
require_once $runtime.'/wp-content/plugins/forum-intelligence-for-bbpress/autoload.php';
$vapid=ForumIntelligence\Infrastructure\Push\Runtime::className('VAPID');
if(!str_starts_with($vapid,'ForumIntelligenceVendor\\')){throw new RuntimeException('This release has unscoped dependencies.');}
$keys=$vapid::createVapidKeys();define('FI_VAPID_PUBLIC_KEY',$keys['publicKey']);define('FI_VAPID_PRIVATE_KEY',$keys['privateKey']);define('FI_VAPID_SUBJECT','mailto:release@example.test');
require $runtime.'/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8899'){throw new RuntimeException('Use the disposable release site.');}
$admin=get_user_by('login','release_admin');wp_set_current_user($admin->ID);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$store=$services->store;
$push=new ForumIntelligence\Domain\Notifications\Push($store,$services->queue,$services->access,$services->get('following'));
$push->register();
$forum=(int)bbp_insert_forum(array('post_title'=>'Scoped push verification','post_author'=>$admin->ID));
$topic=(int)bbp_insert_topic(array('post_title'=>'PRIVATE_PUSH_TITLE_CANARY','post_content'=>'PRIVATE_PUSH_TEXT_CANARY','post_parent'=>$forum,'post_author'=>$admin->ID),array('forum_id'=>$forum));
$device=$vapid::createVapidKeys();$endpoint='https://fcm.googleapis.com/fcm/send/fi-release-crypto';
$push->subscribe((int)$admin->ID,array('endpoint'=>$endpoint,'keys'=>array('p256dh'=>$device['publicKey'],'auth'=>rtrim(strtr(base64_encode(random_bytes(16)),'+/','-_'),'='))));
$response='ForumIntelligenceVendor\\GuzzleHttp\\Psr7\\Response';$mockClass='ForumIntelligenceVendor\\GuzzleHttp\\Handler\\MockHandler';$stackClass='ForumIntelligenceVendor\\GuzzleHttp\\HandlerStack';$middleware='ForumIntelligenceVendor\\GuzzleHttp\\Middleware';
$history=array();$mock=new $mockClass(array(new $response(201),new $response(410)));$stack=$stackClass::create($mock);$stack->push($middleware::history($history));
$filter=static fn(array $options): array=>$options+array('handler'=>$stack);
add_filter('fi_push_client_options',$filter);
try{
 $push->send(array('user'=>(int)$admin->ID,'object'=>$topic,'kind'=>'reply'));
 if(count($history)!==1){throw new RuntimeException('Scoped push transport did not send.');}
 $request=$history[0]['request'];$body=(string)$request->getBody();
 if($request->getHeaderLine('Content-Encoding')!=='aes128gcm'||strlen($body)<86||str_contains($body,'PRIVATE_PUSH_')||!$request->getHeaderLine('Authorization')){throw new RuntimeException('Scoped Web Push payload/authentication failed.');}
 $push->send(array('user'=>(int)$admin->ID,'object'=>$topic,'kind'=>'reply'));
 if($store->record('push:'.$admin->ID.':'.hash('sha256',$endpoint))){throw new RuntimeException('Scoped expiry cleanup failed.');}
 echo "Packaged push passed: prefixed runtime, preloaded namespace conflicts, VAPID, encrypted generic bytes, HTTP 201/410 and device cleanup.\n";
}finally{
 remove_filter('fi_push_client_options',$filter);
 wp_delete_post($topic,true);wp_delete_post($forum,true);
 $store->execute("DELETE FROM {$store->table('records')} WHERE record_key=%s",array('push:'.$admin->ID.':'.hash('sha256',$endpoint)));
}
