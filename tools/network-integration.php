<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET'; $_SERVER['HTTP_HOST']='network.example.test';
require dirname(__DIR__).'/.runtime/network-wordpress/wp-load.php';
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Bootstrap\Lifecycle;
use ForumIntelligence\Infrastructure\Database\Schema;
if (!is_multisite()) { throw new RuntimeException('Requires an isolated multisite test installation.'); }
$user=(int)get_user_by('login','network_admin')->ID; wp_set_current_user($user);
$root=new Services(); $root->register();
$verifyKnowledge=static function(Services $service,int $source,int $actor): void {
 $missingReview=new stdClass();$reviewSettings=get_option('fi_settings',$missingReview);
 $settings=array_replace($reviewSettings===$missingReview?array():$reviewSettings,array('knowledge_review_notifications'=>true,'ai_enabled'=>false));$settings['modules']['knowledge']=true;$settings['modules']['notifications']=true;update_option('fi_settings',$settings);
 $article=0;$previous=$GLOBALS['post']??null;$previousPassword=(string)get_post_field('post_password',$source);
 $label='NETWORK_KNOWLEDGE_SITE_'.get_current_blog_id().'_CANARY';
 try {
  $service->get('knowledge')->command($source,'recommend',$label.'_RECOMMENDATION',$actor);
  $recommendations=$service->get('knowledge')->recommendations($actor);
  $recommended=array_values(array_filter($recommendations['items'],static fn(array $item):bool=>$item['topic']===$source));
  if(count($recommended)!==1||$recommended[0]['reason']!==$label.'_RECOMMENDATION'){throw new RuntimeException('Retained moderator recommendation queue used another site.');}
  wp_update_post(array('ID'=>$source,'post_password'=>'owned-network-recommendation-password'));
  if(array_filter($service->get('knowledge')->recommendations($actor)['items'],static fn(array $item):bool=>$item['topic']===$source)){throw new RuntimeException('Current-site recommendation reused revoked native source access.');}
  wp_update_post(array('ID'=>$source,'post_password'=>$previousPassword));
  $service->get('knowledge')->command($source,'edit',$label,$actor);
  $article=(int)$service->get('knowledge')->command($source,'draft','',$actor)['article_id'];
  $recommendation=$service->store->record('knowledge_recommendation:'.$source);
  if(!$recommendation||$recommendation['kind']!=='knowledge_recommendation_drafted'||$recommendation['data']['article_id']!==$article){throw new RuntimeException('Retained recommendation conversion used another site or left an active note.');}
  $comparison=$service->get('knowledge')->comparison($article,$actor);$provenance=get_post_meta($article,'_fi_provenance',true);
  if($comparison['changes']||!isset($provenance['source_text_hashes'][$source])||!hash_equals(ForumIntelligence\Domain\Knowledge\SourceComparison::textHash(get_post($source)),$provenance['source_text_hashes'][$source])||!(new ForumIntelligence\Infrastructure\Database\AtomicStorage($service->store))->ready()){throw new RuntimeException('Retained knowledge comparison or transactional metadata used another site.');}
  if(!user_can($actor,'edit_post',$article)){throw new RuntimeException('Current-site native knowledge editor was denied.');}
  wp_update_post(array('ID'=>$article,'post_status'=>'publish'));$GLOBALS['post']=get_post($article);
  if(!str_contains(apply_filters('the_content',get_post($article)->post_content),$label)){throw new RuntimeException('Current-site native knowledge display was denied.');}
  wp_update_post(array('ID'=>$source,'post_password'=>'owned-network-knowledge-password'));
  try{$service->get('knowledge')->comparison($article,$actor);throw new RuntimeException('Protected current-site source retained comparison access.');}catch(ForumIntelligence\Domain\Failure $failure){if(!in_array($failure->status,array(403,404),true)){throw $failure;}}
  if(user_can($actor,'edit_post',$article)||apply_filters('the_content',get_post($article)->post_content)!==''||get_the_title($article)!==''){throw new RuntimeException('Current-site knowledge reused stale source access.');}
  wp_update_post(array('ID'=>$source,'post_password'=>$previousPassword));
  if(!user_can($actor,'edit_post',$article)||!str_contains(get_post($article)->post_content,$label)){throw new RuntimeException('Current-site knowledge failed to restore native editing/content.');}
  $knowledge=$service->get('knowledge');$revision=new ForumIntelligence\Domain\Knowledge\SourceRevision($service->store);
  if($revision->version($source)<=0||!$knowledge->reviewState($article,$actor)['stale']){throw new RuntimeException('Retained current-site knowledge service lost native discussion revision invalidation.');}
  $summaryBefore=$service->store->record('summary:'.$source);$bodyBefore=get_post($article)->post_content;
  $store=$service->store;$jobKey='knowledge_review:'.$source.':0';
  $job=$store->row("SELECT * FROM {$store->table('jobs')} WHERE kind='knowledge_review' AND job_key=%s",array($jobKey));
  if(!$job||json_decode($job['payload'],true)!==array('topic'=>$source,'cursor'=>0)){throw new RuntimeException('Retained source review alert service lost its current-site topic-only job.');}
  $store->execute("UPDATE {$store->table('jobs')} SET available_at='2000-01-01 00:00:00' WHERE kind='knowledge_review' AND job_key=%s",array($jobKey));$service->queue->run(1);
  $inbox=$service->get('notifications')->inbox($actor,1,false,'knowledge_review');
  if(count($inbox['items'])!==1||(int)$inbox['items'][0]['object_id']!==$article||$inbox['items'][0]['title']!=='Knowledge article needs source review'||!str_contains($inbox['items'][0]['url'],'post='.$article)){throw new RuntimeException('Retained editor inbox used another site source, article or review link.');}
  wp_update_post(array('ID'=>$source,'post_password'=>'owned-network-notification-password'));
  if($service->get('notifications')->inbox($actor,1,false,'knowledge_review')['items']){throw new RuntimeException('Current-site review inbox reused revoked native source access.');}
  wp_update_post(array('ID'=>$source,'post_password'=>$previousPassword));
  $summaryBefore=$service->store->record('summary:'.$source);
  $knowledge->verifyArticle($article,$actor);$current=$knowledge->reviewState($article,$actor);$provenance=get_post_meta($article,'_fi_provenance',true);
  if($knowledge->comparison($article,$actor)['changes']||!isset($provenance['source_text_hashes'][$source])||$provenance['solution_version']!==$service->solutions->state($source,$actor)['version']){throw new RuntimeException('Current-site verification did not renew exact text or accepted-selection comparison baseline.');}
  if($current['stale']||!$current['verified_at']||$provenance['discussion_revision']!==$revision->version($source)||$provenance['fingerprint_version']!==3||get_post($article)->post_content!==$bodyBefore||$service->store->record('summary:'.$source)!==$summaryBefore){throw new RuntimeException('Retained source review service used stale site tables or changed human article/summary text.');}

  if($service->get('notifications')->inbox($actor,1,false,'knowledge_review')['items']){throw new RuntimeException('Verified current-site article retained a stale review alert.');}
  $knowledge->command($source,'recommend',$label.'_RENEWED',$actor);
  $knowledge->command($source,'unrecommend','',$actor);
  if($service->store->record('knowledge_recommendation:'.$source)['kind']!=='knowledge_recommendation_withdrawn'||get_post($article)->post_content!==$bodyBefore){throw new RuntimeException('Current-site recommendation withdrawal changed authored content or another site.');}
 }finally {
  $settings['knowledge_review_notifications']=false;update_option('fi_settings',$settings);
  if($article){$service->store->execute("DELETE FROM {$service->store->table('notifications')} WHERE kind='knowledge_review' AND object_id=%d",array($article));}
  $service->store->execute("DELETE FROM {$service->store->table('jobs')} WHERE kind='knowledge_review' AND job_key LIKE %s",array('knowledge_review:'.$source.':%'));
  if($reviewSettings===$missingReview){delete_option('fi_settings');}else{update_option('fi_settings',$reviewSettings);}
  $GLOBALS['post']=$previous;wp_update_post(array('ID'=>$source,'post_password'=>$previousPassword));
  if($article){wp_delete_post($article,true);}
  $service->store->execute("DELETE FROM {$service->store->table('records')} WHERE record_key=%s",array('summary:'.$source));
  $service->store->execute("DELETE FROM {$service->store->table('records')} WHERE record_key=%s AND kind IN ('knowledge_recommendation','knowledge_recommendation_withdrawn','knowledge_recommendation_drafted')",array('knowledge_recommendation:'.$source));
 }
};


$verifyAutomaticScope=static function(Services $service,int $source,int $actor):void{
 $missing=new stdClass();$before=get_option('fi_settings',$missing);$owned=array();$store=$service->store;
 $automatic=new ForumIntelligence\Domain\Knowledge\Automation($store,$service->queue,$service->access,$service->get('knowledge'));
 try{
  $settings=array_replace($before===$missing?array():$before,array('ai_enabled'=>true,'summary_auto_enabled'=>true,'summary_auto_actor'=>$actor,'summary_min_replies'=>3,'summary_debounce_seconds'=>120,'summary_min_interval'=>600));
  $settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
  $forum=(int)bbp_get_topic_forum_id($source);
  for($i=0;$i<3;++$i){$owned[]=(int)bbp_insert_reply(array('post_content'=>'OWNED_AUTOMATIC_SCOPE_'.get_current_blog_id(),'post_parent'=>$source,'post_author'=>$actor),array('topic_id'=>$source,'forum_id'=>$forum));}
  $job=$store->row("SELECT * FROM {$store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",array('summary_auto:'.$source));
  if(!$job||json_decode($job['payload'],true)!==array('topic'=>$source)||strtotime($job['available_at'].' UTC')<time()+115){throw new RuntimeException('Retained automatic service did not schedule a current-site topic-only delayed job.');}
  $store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",array('summary_auto:'.$source));
  $settings['summary_auto_enabled']=false;update_option('fi_settings',$settings);$automatic->consider($source);
  if($store->row("SELECT id FROM {$store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",array('summary_auto:'.$source))){throw new RuntimeException('Retained automatic service reused stale site policy.');}
 }finally{
  update_option('fi_settings',array('ai_enabled'=>false,'summary_auto_enabled'=>false));
  foreach(array_reverse($owned) as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$actor){throw new RuntimeException('Refusing an unowned automatic network fixture reply.');}wp_delete_post($id,true);}
  $store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",array('summary_auto:'.$source));
  if($before===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$before);}
 }
};

$verifyAutomaticDraftScope=static function(Services $service,int $parentForum,int $actor):void{
 $missing=new stdClass();$before=get_option('fi_settings',$missing);$owned=array();$source=0;$article=0;$store=$service->store;
 try{
  $settings=array_replace($before===$missing?array():$before,array('ai_enabled'=>false,'summary_auto_enabled'=>false,'knowledge_review_notifications'=>false,'knowledge_auto_draft_enabled'=>true,'knowledge_auto_draft_actor'=>$actor,'knowledge_auto_draft_min_replies'=>3,'knowledge_auto_draft_min_confirmations'=>0,'knowledge_auto_draft_debounce'=>120));$settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
  $source=(int)bbp_insert_topic(array('post_title'=>'Owned automatic draft current-site scope','post_content'=>'Native public problem','post_parent'=>$parentForum,'post_author'=>$actor),array('forum_id'=>$parentForum));$owned[]=$source;
  $reply=0;for($i=0;$i<3;++$i){$reply=(int)bbp_insert_reply(array('post_content'=>'Owned automatic draft scope solution','post_parent'=>$source,'post_author'=>$actor),array('topic_id'=>$source,'forum_id'=>$parentForum));$owned[]=$reply;}
  $service->solutions->change($source,$reply,'accept',$actor);$service->get('knowledge')->command($source,'edit','OWNED_DRAFT_SITE_'.get_current_blog_id().'_CANARY',$actor);
  $key='knowledge_auto_draft:'.$source;$job=$store->row("SELECT * FROM {$store->table('jobs')} WHERE kind='knowledge_auto_draft' AND job_key=%s",array($key));
  if(!$job||json_decode($job['payload'],true)!==array('topic'=>$source)||strtotime($job['available_at'].' UTC')<time()+115){throw new RuntimeException('Retained auto-draft service lost current-site native criteria/payload/quiet period.');}
  $store->execute("UPDATE {$store->table('jobs')} SET available_at='2000-01-01 00:00:00' WHERE kind='knowledge_auto_draft' AND job_key=%s",array($key));
  $store->execute("UPDATE {$store->table('records')} SET updated_at='2000-01-01 00:00:00' WHERE record_key=%s",array('knowledge_source_revision:'.$source));
  $summary=$store->record('summary:'.$source);$service->queue->run(1);
  $copies=get_posts(array('post_type'=>'fi_knowledge','post_status'=>'draft','posts_per_page'=>2,'meta_key'=>'_fi_source_topic','meta_value'=>$source));
  if(count($copies)!==1||$copies[0]->post_content!==wpautop(esc_html('OWNED_DRAFT_SITE_'.get_current_blog_id().'_CANARY'))||(int)$copies[0]->post_author!==$actor||$store->record('summary:'.$source)!==$summary){throw new RuntimeException('Retained auto-draft service copied another site, published, changed human summary or lost current native owner.');}
  $article=$copies[0]->ID;$owned[]=$article;
  $automatic=new ForumIntelligence\Domain\Knowledge\AutoDraft($store,$service->queue,$service->access,$service->get('knowledge'));$automatic->run($source);
  if(count(get_posts(array('post_type'=>'fi_knowledge','post_status'=>'draft','posts_per_page'=>2,'meta_key'=>'_fi_source_topic','meta_value'=>$source)))!==1){throw new RuntimeException('Retained current-site auto-draft replay duplicated an existing article.');}
 }finally{
  update_option('fi_settings',array('ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false));
  if($source){
   $copies=get_posts(array('post_type'=>'fi_knowledge','post_status'=>array('draft','publish','pending','private','future','trash'),'posts_per_page'=>-1,'meta_key'=>'_fi_source_topic','meta_value'=>$source));
   foreach($copies as $copy){$owned[]=$copy->ID;}
   $store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='knowledge_auto_draft' AND job_key=%s",array('knowledge_auto_draft:'.$source));
  }
  foreach(array_reverse(array_unique($owned))as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$actor){throw new RuntimeException('Refusing unowned network automatic draft content.');}wp_delete_post($id,true);}
  if($source){$store->execute("DELETE FROM {$store->table('records')} WHERE record_key IN (%s,%s)",array('summary:'.$source,'knowledge_source_revision:'.$source));}
  if($before===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$before);}
 }
};


$verifyAdaptedScope=static function(Services $service,int $source,int $actor):void{
 $site=get_current_blog_id();$key='network-owned-'.$site;$missing=new stdClass();$before=get_option('fi_settings',$missing);$article=0;$previous=$GLOBALS['post']??null;$password=(string)get_post_field('post_password',$source);
 if(post_type_exists('fi_network_kb')){throw new RuntimeException('Refusing a pre-existing network adapter fixture type.');}
 register_post_type('fi_network_kb',array('label'=>'Owned network editorial type','public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_in_rest'=>false,'show_ui'=>true,'has_archive'=>false,'map_meta_cap'=>true,'supports'=>array('title','editor','revisions','author')));
 $adapter=static fn(array $targets):array=>$targets+array($key=>new ForumIntelligence\Domain\Knowledge\NativeKnowledgeTarget('fi_network_kb','Owned network target'));add_filter('fi_knowledge_targets',$adapter);
 try{
  $settings=array_replace($before===$missing?array():$before,array('ai_enabled'=>false,'summary_auto_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_review_notifications'=>false,'knowledge_target'=>$key));$settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
  $knowledge=$service->get('knowledge');$label='NETWORK_ADAPTER_SITE_'.$site.'_CANARY';$knowledge->command($source,'edit',$label,$actor);$article=(int)$knowledge->command($source,'draft','',$actor)['article_id'];
  if(get_post_type($article)!=='fi_network_kb'||get_post_status($article)!=='draft'||get_post_meta($article,'_fi_knowledge_target',true)!==$key||(int)get_post_meta($article,'_fi_source_topic',true)!==$source||!str_contains(get_post($article)->post_content,$label)||$knowledge->comparison($article,$actor)['changes']){throw new RuntimeException('Retained adapter service used another site target/source/body or comparison.');}
  $settings['knowledge_target']='fi_knowledge';update_option('fi_settings',$settings);wp_update_post(array('ID'=>$article,'post_status'=>'publish'));$GLOBALS['post']=get_post($article);
  if(!user_can($actor,'edit_post',$article)||!str_contains(apply_filters('the_content',get_post($article)->post_content),$label)){throw new RuntimeException('Selection change removed current-site adapted human editing/display.');}
  wp_update_post(array('ID'=>$source,'post_password'=>'owned-network-adapter-password'));
  if(user_can($actor,'edit_post',$article)||apply_filters('the_content',get_post($article)->post_content)!==''||get_the_title($article)!==''){throw new RuntimeException('Retained adapted article ignored revoked current-site sources.');}
  wp_update_post(array('ID'=>$source,'post_password'=>$password));$body=get_post($article)->post_content;$knowledge->verifyArticle($article,$actor);
  if($knowledge->reviewState($article,$actor)['stale']||get_post($article)->post_content!==$body){throw new RuntimeException('Retained adapted source verification changed human body or used another site.');}
  $store=$service->store;$summaryKey='summary:'.$source;$canonical=$store->record($summaryKey);
  if(!$canonical||$canonical['kind']!=='summary'||(int)$canonical['object_id']!==$source){throw new RuntimeException('Missing current-site native summary fixture binding.');}
  $store->execute("UPDATE {$store->table('records')} SET payload=%s,version=version+1 WHERE record_key=%s AND kind='summary' AND object_id=%d",array(wp_json_encode('NETWORK_SCALAR_SUMMARY_SITE_'.$site),$summaryKey,$source));
  $damaged=$store->record($summaryKey);if($knowledge->summary($source,$actor)!==null){throw new RuntimeException('Retained current-site summary exposed damaged record data.');}
  $denied=false;try{$knowledge->command($source,'draft','',$actor);}catch(ForumIntelligence\Domain\Failure $failure){$denied=$failure->status===409;}
  if(!$denied||$store->record($summaryKey)!==$damaged){throw new RuntimeException('Retained current-site damaged summary was converted or mutated.');}
  $knowledge->command($source,'edit',$label.'_REPAIRED',$actor);$repaired=$knowledge->summary($source,$actor);
  if(!$repaired||$repaired['answer']!==$label.'_REPAIRED'||empty($repaired['manual_lock'])||$repaired['stale']||get_post($article)->post_content!==$body){throw new RuntimeException('Retained current-site explicit scalar repair lost native provenance/lock or altered human article storage.');}
  $store->execute("UPDATE {$store->table('records')} SET object_id=%d,version=version+1 WHERE record_key=%s",array($article,$summaryKey));$foreign=$store->record($summaryKey);
  $denied=false;try{$knowledge->command($source,'edit',$label.'_BAD_BINDING',$actor);}catch(ForumIntelligence\Domain\Failure $failure){$denied=$failure->status===409;}
  if(!$denied||$knowledge->summary($source,$actor)!==null||$store->record($summaryKey)!==$foreign){throw new RuntimeException('Retained current-site summary collision was exposed or overwritten.');}
 }finally{
  if($article){$post=get_post($article);if($post&&(int)$post->post_author!==$actor){throw new RuntimeException('Refusing foreign adapted network content.');}wp_delete_post($article,true);}
  $GLOBALS['post']=$previous;wp_update_post(array('ID'=>$source,'post_password'=>$password));remove_filter('fi_knowledge_targets',$adapter);unregister_post_type('fi_network_kb');
  $service->store->execute("DELETE FROM {$service->store->table('records')} WHERE record_key=%s",array('summary:'.$source));
  if($before===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$before);}
 }
};

$forum=(int)bbp_insert_forum(['post_title'=>'Root canary forum','post_author'=>$user]);
$topic=(int)bbp_insert_topic(['post_title'=>'ROOT_NETWORK_CANARY','post_content'=>'network canary','post_author'=>$user,'post_parent'=>$forum],['forum_id'=>$forum]);
$rootReply=0;
$missingTemplateOption=new stdClass();$rootTemplateBefore=get_option('fi_template_variants',$missingTemplateOption);
try{
$root->get('templates')->configure($user,wp_json_encode(array(array('id'=>'network-root','label'=>'Root guidance','forum'=>0,'product'=>'Root','text'=>'ROOT_TEMPLATE_SCOPE_CANARY'))));
$root->solutions->change($topic,0,'solve',$user);
$rootReply=(int)bbp_insert_reply(['post_content'=>'Current accepted network support.','post_parent'=>$topic,'post_author'=>$user],['forum_id'=>$forum,'topic_id'=>$topic]);
$root->solutions->change($topic,$rootReply,'accept',$user);
$verifyKnowledge($root,$topic,$user);$verifyAdaptedScope($root,$topic,$user);$verifyAutomaticScope($root,$topic,$user);$verifyAutomaticDraftScope($root,$forum,$user);
$root->get('indexer')->index($topic);
$rootVectors=new ForumIntelligence\Infrastructure\Search\LocalVectorStore($root->store);
$rootModel=(string)(get_option('fi_settings',[])['embedding_model']??'text-embedding-3-small');
$rootVectors->put($topic,ForumIntelligence\Infrastructure\Search\Indexer::hash($topic),$rootModel,[1,0]);
$rootFrame=$root->store->row("SELECT vector,vector_binary FROM {$root->store->table('chunks')} WHERE source_id=%d",[$topic]);
if(!ForumIntelligence\Domain\Search\PreparedVector::fromFrame($rootFrame['vector_binary'],$rootFrame['vector'])){throw new RuntimeException('Root compiled vector storage failed.');}
$recording=new class($topic) implements ForumIntelligence\Contracts\TextProvider{
 public function __construct(private int $source){}
 public function generate(string $instruction,array $evidence):array{return ['answer'=>'ROOT_GENERATED_ANSWER_CANARY','source_ids'=>[$this->source]];}
};
$rootAnswers=new ForumIntelligence\Domain\AI\Answers(new ForumIntelligence\Infrastructure\Search\LexicalSearch($root->store,$root->access,$root->solutions),$recording,$root->access,$root->store);
$generated=$rootAnswers->ask('network canary',$user);
if($generated['state']!=='answered'||!$rootAnswers->canPresent($generated,$user)){throw new RuntimeException('Root generated-answer proof is not current.');}
$root->store->saveRecord('draft','network-private',$user,$forum,['text'=>'ROOT_PRIVATE_CANARY']);
$snapshot=$root->get('search')->search('network canary',[], $user)['items'];
$snapshot=array_values(array_filter($snapshot,static fn(array $item): bool=>(int)$item['id']===$topic));
if(count($snapshot)!==1||empty($snapshot[0]['source_proof'])){throw new RuntimeException('Native network search snapshot unavailable.');}
$rootProof=ForumIntelligence\Infrastructure\Search\Presentation::proof($topic,$rootReply,$user);
$rootTable=$root->store->table('solutions');
switch_to_blog(2);
$secondTemplateBefore=get_option('fi_template_variants',$missingTemplateOption);$secondForum=0;$secondTopic=0;$secondReply=0;
try {
    Lifecycle::activate(); bbp_set_user_role($user,bbp_get_keymaster_role());
    $second=new Services(); $second->register();
    if($rootAnswers->canPresent($generated,$user)){throw new RuntimeException('Generated answer receipt reused across sites.');}
    $secondForum=(int)bbp_insert_forum(array('post_title'=>'Second contextual template scope','post_author'=>$user));
    $secondTopic=(int)bbp_insert_topic(['post_title'=>'Second compiled vector scope','post_content'=>'SECOND_VECTOR_SCOPE_CANARY','post_parent'=>$secondForum,'post_author'=>$user],['forum_id'=>$secondForum]);
    $secondReply=(int)bbp_insert_reply(array('post_content'=>'Owned second-site accepted support','post_parent'=>$secondTopic,'post_author'=>$user),array('forum_id'=>$secondForum,'topic_id'=>$secondTopic));
    $second->solutions->change($secondTopic,$secondReply,'accept',$user);
    $verifyKnowledge($root,$secondTopic,$user);$verifyAdaptedScope($root,$secondTopic,$user);$verifyAutomaticScope($root,$secondTopic,$user);$verifyAutomaticDraftScope($root,$secondForum,$user);
    $second->get('indexer')->index($secondTopic);
    $secondVectors=new ForumIntelligence\Infrastructure\Search\LocalVectorStore($second->store);
    $secondModel=(string)(get_option('fi_settings',[])['embedding_model']??'text-embedding-3-small');
    $secondVectors->put($secondTopic,ForumIntelligence\Infrastructure\Search\Indexer::hash($secondTopic),$secondModel,[0,1]);
    $secondDirection=$rootVectors->nearest([0,1],100);$rootDirectionHere=$rootVectors->nearest([1,0],100);
    if(!isset($secondDirection[$secondTopic],$rootDirectionHere[$secondTopic])||abs($secondDirection[$secondTopic]-1.0)>1e-12||abs($rootDirectionHere[$secondTopic])>1e-12){throw new RuntimeException('Retained vector service reused another site table/direction.');}
    if(get_option('fi_vector_cache_upgrade',false)){throw new RuntimeException('Second-site vector upgrade flag was not scheduled.');}
    if(str_contains(wp_json_encode($second->get('templates')->view($secondForum,$user)),'ROOT_TEMPLATE_SCOPE_CANARY')){throw new RuntimeException('Root template catalog leaked across sites.');}
    $second->get('templates')->configure($user,wp_json_encode(array(array('id'=>'network-second','label'=>'Second guidance','forum'=>0,'product'=>'Second','text'=>'SECOND_TEMPLATE_SCOPE_CANARY'))));
    $secondGuidance=$root->get('templates')->view($secondForum,$user,array('product'=>'Second'));
    if($secondGuidance['text']!=='SECOND_TEMPLATE_SCOPE_CANARY'||$secondGuidance['blog']!==2){throw new RuntimeException('Retained template service failed current-site isolation.');}
    if(hash_equals($rootProof,ForumIntelligence\Infrastructure\Search\Presentation::proof($topic,0,$user))){throw new RuntimeException('Search snapshot proof reused across sites.');}
    $current=(new ForumIntelligence\Infrastructure\Search\Presentation($second->store,$second->access))->refresh($snapshot,'network canary',$user);
    if(str_contains(wp_json_encode($current),'ROOT_NETWORK_CANARY')){throw new RuntimeException('Delayed fallback reused root display text on another site.');}
    if ($rootTable===$second->store->table('solutions')) { throw new RuntimeException('Table prefixes overlap'); }
    if ($second->store->record('network-private')) { throw new RuntimeException('Cross-site draft leakage'); }
    $result=$second->get('search')->search('network canary',[],0);
    if (str_contains(wp_json_encode($result),'ROOT_NETWORK_CANARY')) { throw new RuntimeException('Cross-site search leakage'); }
    if ((int)get_option('fi_schema_version')!==Schema::VERSION || !wp_next_scheduled('fi_queue_tick')) { throw new RuntimeException('Site provisioning failed'); }
} finally {
    if($secondTemplateBefore===$missingTemplateOption){delete_option('fi_template_variants');}else{update_option('fi_template_variants',$secondTemplateBefore);}
    if($secondReply){wp_delete_post($secondReply,true);}
    if($secondTopic){wp_delete_post($secondTopic,true);}
    if($secondForum){wp_delete_post($secondForum,true);}
    restore_current_blog();
}
$rootDirection=$rootVectors->nearest([1,0],100);
if(!isset($rootDirection[$topic])||abs($rootDirection[$topic]-1.0)>1e-12){throw new RuntimeException('Restored root compiled vector direction is incorrect.');}
if(!$rootAnswers->canPresent($generated,$user)){throw new RuntimeException('Restoring the root site did not restore its own generated-answer proof.');}
$rootGuidance=$root->get('templates')->view($forum,$user,array('product'=>'Root'));
if($rootGuidance['text']!=='ROOT_TEMPLATE_SCOPE_CANARY'||$rootGuidance['blog']!==1||str_contains(wp_json_encode($rootGuidance),'SECOND_TEMPLATE_SCOPE_CANARY')){throw new RuntimeException('Root template service retained another site catalog.');}
if (!$root->solutions->state($topic,$user)['solved']) { throw new RuntimeException('Switching blogs lost solution state'); }
$verifyKnowledge($root,$topic,$user);$verifyAdaptedScope($root,$topic,$user);$verifyAutomaticScope($root,$topic,$user);$verifyAutomaticDraftScope($root,$forum,$user);
Lifecycle::deactivate(true);
foreach ([1,2] as $site) {
    switch_to_blog($site);
    try { foreach (['fi_queue_tick','fi_queue_soon','fi_retention_tick'] as $hook) { if(wp_next_scheduled($hook))throw new RuntimeException('Network deactivation left cron jobs'); } }
    finally { restore_current_blog(); }
}
Lifecycle::activate(true); wp_delete_post($rootReply,true); wp_delete_post($topic,true); wp_delete_post($forum,true);
 }finally{
 if($rootTemplateBefore===$missingTemplateOption){delete_option('fi_template_variants');}else{update_option('fi_template_variants',$rootTemplateBefore);}
 foreach(array($rootReply,$topic,$forum) as $owned){if($owned && get_post($owned)){wp_delete_post($owned,true);}}
 $root->store->execute("DELETE FROM {$root->store->table('records')} WHERE record_key=%s",array('network-private'));
}
echo "Multisite: prefixes, schema 8 provisioning/compiled vector directions across retained site services, current-site strict scalar summary withholding/explicit repair/foreign record preservation and native adapter selection/body/owner/source privacy/human verification across retained services plus moderator recommendation privacy/conversion/withdrawal and native knowledge rights/rendering/restoration, discussion counters, explicit human source verification and current-site editor review inbox/privacy/verification across retained services, plus opt-in automatic draft criteria/native owner/unpublished/current-site source/human-summary/replay isolation and automatic topic-job/current-policy isolation across retained services and cross-site template/search/draft/delayed-fallback isolation and site-bound search/generated-answer proofs, state cache and network deactivation passed\n";
