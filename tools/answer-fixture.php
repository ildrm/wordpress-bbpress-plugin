<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8898'||DB_NAME!==(getenv('FI_TEST_DB')?:'fi_test')){throw new RuntimeException('Answer fixture requires the owned disposable browser site/database.');}
$key='fi_e2e_answer_fixture';$action=$argv[1]??'';$state=get_option($key,[]);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();
$owned=static function(array $state):void{
 if(($state['version']??0)!==1||!preg_match('/^[a-f0-9]{16}$/D',(string)($state['owner']??''))){throw new RuntimeException('Unknown answer fixture ownership.');}
 foreach((array)$state['posts'] as $id){if(get_post((int)$id)&&get_post_meta((int)$id,'_fi_e2e_answer_fixture',true)!==$state['owner']){throw new RuntimeException('Answer fixture source is not owned.');}}
};
if($action==='cleanup'){
 if(!$state){echo "{}\n";exit;}
 $owned($state);delete_transient('fi_result_'.$state['reader'].'_'.$state['token']);
 foreach(array_reverse($state['posts']) as $id){if(get_post($id)){wp_delete_post($id,true);}}
 delete_option($key);echo "{}\n";exit;
}
if($action==='withdraw'){
 $owned($state);wp_set_current_user($state['reader']);
 $services->solutions->change($state['topic'],$state['reply'],'remove',$state['reader']);
 echo "{}\n";exit;
}
if($action!=='seed'||$state){throw new RuntimeException('Answer fixture action is invalid or overlaps an existing fixture.');}
$author=get_user_by('login','e2e_author');$member=get_user_by('login','e2e_member');
if(!$author||!$member){throw new RuntimeException('Seed the ordinary owned browser accounts first.');}
$state=['version'=>1,'owner'=>bin2hex(random_bytes(8)),'reader'=>(int)$author->ID,'posts'=>[],'token'=>'answer-e2e-'.bin2hex(random_bytes(8))];
if(!add_option($key,$state,'','no')){throw new RuntimeException('Answer fixture could not claim its ownership option.');}
$remember=static function(int $id)use(&$state,$key):int{
 if($id<=0){throw new RuntimeException('Answer fixture native source creation failed.');}
 update_post_meta($id,'_fi_e2e_answer_fixture',$state['owner']);$state['posts'][]=$id;update_option($key,$state,false);return $id;
};
try{
 wp_set_current_user($author->ID);
 $forum=$remember((int)bbp_insert_forum(['post_title'=>'Owned generated-answer browser forum','post_author'=>$author->ID]));
 $topic=$remember((int)bbp_insert_topic(['post_title'=>'Answerproofsource cache invalidation','post_content'=>'Current public answer support.','post_parent'=>$forum,'post_author'=>$author->ID],['forum_id'=>$forum]));
 $reply=$remember((int)bbp_insert_reply(['post_content'=>'Current accepted evidence.','post_parent'=>$topic,'post_author'=>$member->ID],['forum_id'=>$forum,'topic_id'=>$topic]));
 $page=$remember((int)wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Owned generated answer review','post_content'=>'[forum_intelligence_ask]']));
 $state['topic']=$topic;$state['reply']=$reply;update_option($key,$state,false);
 $services->solutions->change($topic,$reply,'accept',(int)$author->ID);
 $services->get('indexer')->index($topic);
 $search=new ForumIntelligence\Infrastructure\Search\LexicalSearch($services->store,$services->access,$services->solutions);
 $provider=new class($topic) implements ForumIntelligence\Contracts\TextProvider{
  public function __construct(private int $topic){}
  public function generate(string $instruction,array $evidence):array{return ['answer'=>'GENERATED_BROWSER_SUPPORT_CANARY','source_ids'=>[$this->topic]];}
 };
 $result=(new ForumIntelligence\Domain\AI\Answers($search,$provider,$services->access,$services->store))->ask('answerproofsource cache invalidation',(int)$author->ID);
 if($result['state']!=='answered'||empty($result['grounding'])){throw new RuntimeException('Native answer fixture did not generate its current proof.');}
 set_transient('fi_result_'.$author->ID.'_'.$state['token'],$result,300);
 echo wp_json_encode(['url'=>add_query_arg('fi_result',$state['token'],get_permalink($page)),'topic_url'=>get_permalink($topic)])."\n";
}catch(Throwable $failure){
 delete_transient('fi_result_'.$state['reader'].'_'.$state['token']);
 foreach(array_reverse($state['posts']) as $id){if(get_post($id)&&get_post_meta($id,'_fi_e2e_answer_fixture',true)===$state['owner']){wp_delete_post($id,true);}}
 delete_option($key);throw $failure;
}
