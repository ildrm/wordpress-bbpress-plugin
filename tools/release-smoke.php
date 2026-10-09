<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
$runtime=getenv('FI_RELEASE_WP_PATH')?:dirname(__DIR__).'/.runtime/release-wordpress';
if(!is_file($runtime.'/wp-config.php')){throw new RuntimeException('Prepare a fresh isolated release WordPress installation first.');}
require $runtime.'/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8899'||DB_NAME!==(getenv('FI_RELEASE_DB')?:'fi_release')){throw new RuntimeException('Installed smoke requires the owned disposable release site/database.');}
$source=(new ReflectionClass(ForumIntelligence\Bootstrap\Plugin::class))->getFileName();
if(!str_contains(str_replace('\\','/',$source),str_replace('\\','/',$runtime).'/wp-content/plugins/forum-intelligence-for-bbpress/')){throw new RuntimeException('Smoke test is loading checkout code instead of packaged code.');}
$root=dirname($source,3);
$manifest=json_decode(file_get_contents($root.'/manifest.sha256.json'),true);
foreach($manifest as $path=>$expected){if(!is_file($root.'/'.$path)||!hash_equals($expected,hash_file('sha256',$root.'/'.$path))){throw new RuntimeException('Packaged file differs from its manifest: '.$path);}}
$licenses=json_decode(file_get_contents($root.'/third-party-licenses.json'),true);
if(count($licenses)!==count(json_decode(file_get_contents($root.'/composer.lock'),true)['packages'])){throw new RuntimeException('Dependency license inventory incomplete.');}
foreach($licenses as $dependency){foreach($dependency['files'] as $path=>$hash){if(!is_file($root.'/'.$path)||!hash_equals($hash,hash_file('sha256',$root.'/'.$path))){throw new RuntimeException('Upstream license/notice not preserved.');}}}
$installed=json_decode(file_get_contents($root.'/vendor/composer/installed.json'),true);
foreach($installed['packages'] as $package){if(isset($package['name'])&&preg_match('/^(phpunit|phpstan|squizlabs|wp-coding-standards|sebastian)\//',$package['name'])){throw new RuntimeException('Development dependency in runtime package.');}}
if(!class_exists(ForumIntelligence\Infrastructure\Push\Runtime::className('WebPush'))){throw new RuntimeException('Runtime push library missing.');}
$admin=get_user_by('login','release_admin');
if(!$admin){throw new RuntimeException('Use a disposable release_admin account.');}
wp_set_current_user($admin->ID);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();
$forum=(int)bbp_insert_forum(array('post_title'=>'Packaged smoke forum','post_author'=>$admin->ID));
$topic=(int)bbp_insert_topic(array('post_title'=>'Packaged search smoke','post_content'=>'Packaged runtime search smoke evidence','post_parent'=>$forum,'post_author'=>$admin->ID),array('forum_id'=>$forum));
$alertTopic=0;$alertId=0;$ragReader=0;
$missingTemplateOption=new stdClass();$oldTemplateVariants=get_option('fi_template_variants',$missingTemplateOption);
$reply=(int)bbp_insert_reply(array('post_content'=>'Packaged runtime accepted solution','post_parent'=>$topic,'post_author'=>$admin->ID),array('forum_id'=>$forum,'topic_id'=>$topic));
try {
 $state=$services->solutions->change($topic,$reply,'accept',$admin->ID);
 if($state['primary_reply']!==$reply||!$state['solved']){throw new RuntimeException('Packaged solution failed.');}
 $services->get('indexer')->index($topic);
 $results=$services->get('search')->search('packaged search smoke',array(),0);
 if(!in_array($topic,array_column($results['items'],'id'),true)){throw new RuntimeException('Packaged public search failed.');}
 bbp_hide_forum($forum);
 if($services->access->canRead($topic,0)){throw new RuntimeException('Packaged hidden forum leaked.');}
 $frontend=new ForumIntelligence\Frontend\Frontend($services->operations,$services->solutions,$services->access,$root.'/forum-intelligence-for-bbpress.php');
 $html=(new ForumIntelligence\Frontend\Hub($services,$frontend))->search();
 if(!str_contains($html,'Search the community')){throw new RuntimeException('Packaged frontend failed.');}
 $saved=$services->get('saved_searches');
 $keyword='packaged alert '.bin2hex(random_bytes(6));
 $saved->save($admin->ID,$keyword,array('forum'=>$forum));
 foreach($saved->listing($admin->ID) as $entry){if($entry['query']===$keyword){$alertId=$entry['id'];break;}}
 if(!$alertId){throw new RuntimeException('Packaged saved search was not owned and persisted.');}
 $saved->alerts($admin->ID,$alertId,true);
 bbp_publicize_forum($forum);
 $alertTopic=(int)bbp_insert_topic(array('post_title'=>$keyword,'post_content'=>$keyword.' evidence','post_parent'=>$forum,'post_author'=>0),array('forum_id'=>$forum));
 for($round=0;$round<10;$round++){
  $services->queue->run(100);
  $pending=$services->store->row("SELECT COUNT(*) AS n FROM {$services->store->table('jobs')} WHERE kind='saved_search_notify' AND JSON_EXTRACT(payload,'$.object')=%d AND status<>'completed'",array($alertTopic));
  if(!(int)$pending['n']){break;}
 }
 $delivered=$services->store->row("SELECT COUNT(*) AS n FROM {$services->store->table('notifications')} WHERE user_id=%d AND kind='saved_search' AND object_id=%d",array($admin->ID,$alertTopic));
 if((int)$delivered['n']!==1||(int)$pending['n']){throw new RuntimeException('Packaged opt-in local alert failed.');}

 $notifications=$services->get('notifications');
 $summary=$notifications->summary($admin->ID);
 if($summary['unread']<1){throw new RuntimeException('Packaged unread summary ignored its current local alert.');}
 $filtered=$notifications->inbox($admin->ID,1,false,'saved_search','unread');
 if(!in_array($alertTopic,array_map('intval',array_column($filtered['items'],'object_id')),true)){throw new RuntimeException('Packaged typed notification view failed.');}
 $mentions=$services->get('mentions');
 if(!$mentions || ForumIntelligence\Domain\Notifications\Mentions::names('<code>@hidden</code> @release_admin')!==array('release_admin')){throw new RuntimeException('Packaged mention fallback unavailable.');}
 if(!class_exists(ForumIntelligence\Domain\Notifications\NativeMentions::class) || has_action('bp_activity_before_save',[ForumIntelligence\Domain\Notifications\NativeMentions::class,'begin'])!==5){throw new RuntimeException('Packaged native mention guard unavailable.');}
 $setup=new ForumIntelligence\Admin\Onboarding($services,$frontend);$setup->register();
 if(!$setup->status((int)$admin->ID)['ready']){throw new RuntimeException('Packaged setup environment failed.');}
 if(!in_array('setup_defaults',$services->operations->names(),true)||!in_array('setup_index',$services->operations->names(),true)){throw new RuntimeException('Packaged setup actions unavailable.');}
 $templateRules=array(
  array('id'=>'packaged-global','label'=>'General packaged help','forum'=>0,'product'=>'Widget','text'=>'PACKAGED_GLOBAL_TEMPLATE'),
  array('id'=>'packaged-context','label'=>'Packaged support guidance','forum'=>$forum,'topic_type'=>'Support','product'=>'Widget','category'=>'Troubleshooting','text'=>"PACKAGED_CONTEXT_TEMPLATE\nObserved behavior:\nExpected behavior:"),
 );
 $services->operations->execute('template_variants',array('json'=>wp_json_encode($templateRules)),(int)$admin->ID);
 $template=$services->get('drafts')->template($forum,0,array('topic_type'=>'Support','product'=>'Widget','category'=>'Troubleshooting'));
 if($template['id']!=='packaged-context'||$template['owner']!==0||$template['blog']!==get_current_blog_id()||!str_contains($template['text'],'PACKAGED_CONTEXT_TEMPLATE')||count($template['templates'])!==2){throw new RuntimeException('Packaged contextual template matching or scope failed.');}
 bbp_hide_forum($forum);$deniedTemplate=false;
 try{$services->get('drafts')->template($forum,0,array('product'=>'Widget'));}catch(ForumIntelligence\Domain\Failure $failure){$deniedTemplate=$failure->status===404;}
 if(!$deniedTemplate){throw new RuntimeException('Packaged contextual template did not recheck current native access.');}
 bbp_publicize_forum($forum);
 if(!class_exists(ForumIntelligence\Frontend\ComposerTemplates::class)||!in_array('template_variants',$services->operations->names(),true)){throw new RuntimeException('Packaged contextual template controls unavailable.');}
 $restored=$services->operations->execute('offline_restore',array('object'=>$topic,'type'=>'reply','owner'=>(int)$admin->ID,'blog'=>get_current_blog_id(),'body'=>'<p>PACKAGED_RECOVERY_CANARY</p><script>alert(1)</script>'),(int)$admin->ID);
 if($restored['owner']!==(int)$admin->ID||!str_contains($restored['body'],'PACKAGED_RECOVERY_CANARY')||str_contains($restored['body'],'<script')||$services->get('drafts')->read($topic,(int)$admin->ID,'reply')!==null||!is_file($root.'/assets/build/offline-drafts.js')){throw new RuntimeException('Packaged encrypted-draft recovery or module unavailable.');}
 $preview=$services->operations->execute('composer_preview',array('object'=>$topic,'type'=>'reply','owner'=>(int)$admin->ID,'blog'=>get_current_blog_id(),'body'=>'<strong>PACKAGED_PREVIEW_CANARY</strong><a href="https://preview-never-fetch.example/">link</a><img src="https://preview-never-fetch.example/img">'),(int)$admin->ID);
 if(!str_contains($preview['html'],'<strong>PACKAGED_PREVIEW_CANARY</strong>')||str_contains($preview['html'],'href=')||str_contains($preview['html'],'src=')||!is_file($root.'/assets/build/composer-tools.js')){throw new RuntimeException('Packaged inert composer preview or module unavailable.');}
 $snapshot=$services->get('search')->search('packaged search smoke',array('type'=>'topic'),(int)$admin->ID)['items'];
 $snapshot=array_values(array_filter($snapshot,static fn(array $item): bool=>(int)$item['id']===$topic));
 if(count($snapshot)!==1||empty($snapshot[0]['source_proof'])){throw new RuntimeException('Packaged fallback snapshot proof unavailable.');}
 $fallbackKey='fi_result_'.$admin->ID.'_packaged-fallback';
 $priorGet=$_GET;
 set_transient($fallbackKey,array('state'=>'abstained','answer'=>'Packaged fallback','citations'=>array(),'topics'=>$snapshot,'composer_url'=>add_query_arg('fi_question','packaged search smoke',bbp_get_forums_url())),300);
 try {
  $services->solutions->change($topic,$reply,'remove',(int)$admin->ID);
  wp_update_post(array('ID'=>$topic,'post_title'=>'Current packaged search smoke','post_content'=>'packaged search smoke CURRENT_PACKAGED_FALLBACK_CANARY'));
  $_GET['fi_result']='packaged-fallback';
  $metrics=$services->store->rows("SELECT day,kind,count FROM {$services->store->table('metrics')} ORDER BY day,kind");
  $fallback=(new ForumIntelligence\Frontend\Hub($services,$frontend))->ask();
  if(!str_contains($fallback,'CURRENT_PACKAGED_FALLBACK_CANARY')||str_contains($fallback,'Packaged runtime accepted solution')||str_contains($fallback,'Packaged runtime search smoke evidence')||str_contains($fallback,'<strong>Solved</strong>')){throw new RuntimeException('Packaged delayed fallback used stale content or solution state.');}
  if($metrics!==$services->store->rows("SELECT day,kind,count FROM {$services->store->table('metrics')} ORDER BY day,kind")){throw new RuntimeException('Packaged fallback refresh recorded another search.');}
 } finally {$_GET=$priorGet;delete_transient($fallbackKey);}
 $rebuilds=(int)$services->store->row("SELECT COUNT(*) AS n FROM {$services->store->table('jobs')} WHERE kind='rebuild'")['n'];
 $nativeCount=get_post_meta($forum,'_bbp_topic_count',true);
 update_post_meta($forum,'_bbp_topic_count',(int)$nativeCount+1);
 update_post_meta($forum,'_bbp_topic_count',$nativeCount);
 if($rebuilds!==(int)$services->store->row("SELECT COUNT(*) AS n FROM {$services->store->table('jobs')} WHERE kind='rebuild'")['n']){throw new RuntimeException('Packaged forum bookkeeping amplified full rebuilds.');}
 $indexKey='index:'.$topic;
 $beforeMeta=(int)($services->store->row("SELECT revision FROM {$services->store->table('jobs')} WHERE job_key=%s",array($indexKey))['revision']??0);
 update_post_meta($topic,'_fi_packaged_index_metadata','PACKAGED_METADATA_CANARY');
 $addedMeta=(int)($services->store->row("SELECT revision FROM {$services->store->table('jobs')} WHERE job_key=%s",array($indexKey))['revision']??0);
 delete_post_meta($topic,'_fi_packaged_index_metadata');
 $removedMeta=(int)($services->store->row("SELECT revision FROM {$services->store->table('jobs')} WHERE job_key=%s",array($indexKey))['revision']??0);
 if($addedMeta<=$beforeMeta||$removedMeta<=$addedMeta){throw new RuntimeException('Packaged first/removed metadata invalidation unavailable.');}
 $ranked=$services->get('search')->search('packaged search smoke',array('forum'=>$forum,'explain'=>true),(int)$admin->ID);
 $ranked=array_values(array_filter($ranked['items'],static fn(array $item): bool=>(int)$item['id']===$topic));
 if(count($ranked)!==1||!isset($ranked[0]['ranking'])||$ranked[0]['score']!==array_sum($ranked[0]['ranking']['contributions'])){throw new RuntimeException('Packaged administrator ranking explanations failed.');}
 $publicRanked=$services->get('search')->search('packaged search smoke',array('forum'=>$forum,'explain'=>true),0);
 foreach($publicRanked['items'] as $item){if(isset($item['ranking'])){throw new RuntimeException('Packaged public response leaked ranking diagnostics.');}}

 $ragLogin='release_rag_'.bin2hex(random_bytes(8));
 $ragReader=wp_insert_user(array('user_login'=>$ragLogin,'user_email'=>$ragLogin.'@example.invalid','user_pass'=>wp_generate_password(32,true,true),'role'=>'subscriber'));
 if(is_wp_error($ragReader)){throw new RuntimeException('Could not create the owned grounding fixture reader.');}
 $ragReader=(int)$ragReader;bbp_set_user_role($ragReader,bbp_get_participant_role());
 $missingAiSettings=new stdClass();$oldAiSettings=get_option('fi_settings',$missingAiSettings);
 $ragGet=$_GET;$ragViewer=get_current_user_id();$ragKey='fi_result_'.$ragReader.'_packaged-answer';
 try {
  update_option('fi_settings',array_merge($oldAiSettings===$missingAiSettings?array():$oldAiSettings,array('semantic'=>false,'excluded_forums'=>array(),'evidence_threshold'=>0.7)));
  $services->solutions->change($topic,$reply,'accept',(int)$admin->ID);
  wp_update_post(array('ID'=>$topic,'post_content'=>'packaged search smoke author@example.test api_key=supersecretvalue'));
  $lexical=new ForumIntelligence\Infrastructure\Search\LexicalSearch($services->store,$services->access,$services->solutions);
  $provider=new class($topic) implements ForumIntelligence\Contracts\TextProvider {
   public array $sent=array();
   public function __construct(private int $source){}
   public function generate(string $instruction,array $evidence):array{$this->sent=$evidence;return array('answer'=>'Verified packaged answer','source_ids'=>array($this->source));}
  };
  $answerService=new ForumIntelligence\Domain\AI\Answers($lexical,$provider,$services->access,$services->store);
  $answer=$answerService->ask('packaged search smoke',$ragReader);
  if($answer['state']!=='answered'||!in_array($reply,array_column($provider->sent,'id'),true)||count($provider->sent)>12||str_contains(wp_json_encode($provider->sent),'author@example.test')||str_contains(wp_json_encode($provider->sent),'supersecretvalue')){throw new RuntimeException('Packaged bounded public grounding or shared adapter redaction failed.');}
  if(!$answerService->canPresent($answer,$ragReader)){throw new RuntimeException('Packaged generated answer proof is not current.');}
  set_transient($ragKey,$answer,300);wp_set_current_user($ragReader);$_GET['fi_result']='packaged-answer';
  $answerHub=new ForumIntelligence\Frontend\Hub($services,$frontend);
  if(!str_contains($answerHub->ask(),'Verified packaged answer')){throw new RuntimeException('Packaged current generated answer is not displayed.');}
  foreach($provider->sent as $item){if(mb_strlen($item['text'])>3000){throw new RuntimeException('Packaged evidence exceeds transfer bound.');}}
  $withdrawn=new class($topic,$reply,(int)$admin->ID,$services->solutions) implements ForumIntelligence\Contracts\TextProvider {
   public function __construct(private int $source,private int $reply,private int $reader,private ForumIntelligence\Domain\Solutions\Solutions $solutions){}
   public function generate(string $instruction,array $evidence):array{$this->solutions->change($this->source,$this->reply,'remove',$this->reader);return array('answer'=>'Withdrawn packaged answer','source_ids'=>array($this->source));}
  };
  $changed=(new ForumIntelligence\Domain\AI\Answers($lexical,$withdrawn,$services->access,$services->store))->ask('packaged search smoke',$ragReader);
  if($changed['state']!=='abstained'||$changed['reason']!=='evidence_changed'){throw new RuntimeException('Packaged uncited accepted grounding did not recheck current selection.');}
  if($answerService->canPresent($answer,$ragReader)||str_contains($answerHub->ask(),'Verified packaged answer')||!str_contains($answerHub->ask(),'Ask again for an updated result.')){throw new RuntimeException('Packaged delayed answer retained withdrawn uncited support.');}
  $services->solutions->change($topic,$reply,'accept',(int)$admin->ID);
  wp_update_post(array('ID'=>$topic,'post_title'=>'Unrelated packaged evidence','post_content'=>'api_key=groundquorum-bronzephase-walnutframe-harbormode'));
  foreach(array($topic,$reply,$alertTopic) as $id){$services->get('indexer')->index($id);}
  $secretQuestion='groundquorum bronzephase walnutframe harbormode';
  if(!ForumIntelligence\Domain\Search\Ranking::sufficient($lexical->search($secretQuestion,array(),(int)$admin->ID)['items'],0.7)){throw new RuntimeException('Packaged secret-only grounding fixture is not a qualifying local match.');}
  $blocked=new class($topic) implements ForumIntelligence\Contracts\TextProvider {
   public int $calls=0;
   public function __construct(private int $source){}
   public function generate(string $instruction,array $evidence):array{++$this->calls;return array('answer'=>'Unsupported packaged answer','source_ids'=>array($this->source));}
  };
  $unsupported=(new ForumIntelligence\Domain\AI\Answers($lexical,$blocked,$services->access,$services->store))->ask($secretQuestion,$ragReader);
  if($unsupported['state']!=='abstained'||$blocked->calls!==0){throw new RuntimeException('Packaged redacted transfer admitted unsupported generation.');}
 } finally {$_GET=$ragGet;wp_set_current_user($ragViewer);delete_transient($ragKey);if($oldAiSettings===$missingAiSettings){delete_option('fi_settings');}else{update_option('fi_settings',$oldAiSettings);}}


 $projectionCase=0;
 try {
  wp_update_post(array('ID'=>$topic,'post_content'=>'author@example.test api_key=supersecretvalue '.str_repeat('Ω',14000).' PACKAGED_UNSENT_TAIL'));
  $summaryProvider=new class($topic) implements ForumIntelligence\Contracts\TextProvider {
   public array $sent=array();public array $output;
   public function __construct(private int $source){$this->output=array('answer'=>'Verified packaged summary','source_ids'=>array($source));}
   public function generate(string $instruction,array $evidence):array{$this->sent=$evidence;return $this->output;}
  };
  $knowledge=new ForumIntelligence\Domain\Knowledge\Knowledge($services->store,$services->queue,$services->access,$summaryProvider);
  $nativeHash=ForumIntelligence\Infrastructure\Search\Indexer::hash($topic);
  $knowledge->generate($topic,(int)$admin->ID);
  if(count($summaryProvider->sent)>12||$knowledge->summary($topic,(int)$admin->ID)['sources'][$topic]!==$nativeHash){throw new RuntimeException('Packaged summary projection changed native provenance.');}
  foreach($summaryProvider->sent as $source){if(mb_strlen($source['text'])>3000){throw new RuntimeException('Packaged replacement summary exceeded its transfer bound.');}}
  foreach(array('author@example.test','supersecretvalue','PACKAGED_UNSENT_TAIL') as $secret){if(str_contains(wp_json_encode($summaryProvider->sent),$secret)){throw new RuntimeException('Packaged summary projection retained a sensitive or clipped canary.');}}
  $priorSummary=$services->store->record('summary:'.$topic);
  foreach(array(array(),array('answer'=>'<p></p>','source_ids'=>array($topic)),array('answer'=>'Summary','source_ids'=>array((string)$topic))) as $invalid){
   $summaryProvider->output=$invalid;$deniedSummary=false;
   try{$knowledge->generate($topic,(int)$admin->ID);}catch(ForumIntelligence\Domain\Failure $failure){$deniedSummary=$failure->status===503;}
   if(!$deniedSummary||$services->store->record('summary:'.$topic)!==$priorSummary){throw new RuntimeException('Packaged malformed summary replaced the current record.');}
  }
  $projectionCase=$services->store->insert('cases',array('object_id'=>$topic,'reporter_id'=>(int)$admin->ID,'reason'=>'spam','notes'=>'Owned packaged advisory fixture','created_at'=>$services->store->now(),'updated_at'=>$services->store->now()));
  $advisoryProvider=new class($topic) implements ForumIntelligence\Contracts\TextProvider {
   public array $sent=array();public array $output;
   public function __construct(private int $source){$this->output=array('answer'=>wp_json_encode(array('spam'=>0.1,'toxicity'=>0,'dangerous'=>0,'pii'=>0.1,'confidence'=>0.5,'explanation'=>'PACKAGED_ADVISORY_OLD_DETAIL_CANARY','recommendation'=>'human_review')),'source_ids'=>array($source));}
   public function generate(string $instruction,array $evidence):array{$this->sent=$evidence;return $this->output;}
  };
  $advisory=new ForumIntelligence\Domain\Moderation\Advisory($services->store,$services->queue,$services->access,$advisoryProvider);
  $advisory->generate($projectionCase,(int)$admin->ID);
  if(count($advisoryProvider->sent)!==1||mb_strlen($advisoryProvider->sent[0]['text'])>3000||get_post_status($topic)!=='publish'||!$advisory->view($projectionCase,(int)$admin->ID)['advisory_only']){throw new RuntimeException('Packaged advisory projection or human-only behavior failed.');}
  foreach(array('author@example.test','supersecretvalue','PACKAGED_UNSENT_TAIL') as $secret){if(str_contains(wp_json_encode($advisoryProvider->sent),$secret)){throw new RuntimeException('Packaged advisory projection retained a sensitive or clipped canary.');}}

  $advisoryAdminHtml=static function()use($root):string{
   global $wp_filter,$shortcode_tags,$wp_post_types;
   $query=$_GET;$level=ob_get_level();$hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);$shortcodes=$shortcode_tags;$types=$wp_post_types;
   $missing=new stdClass();$settings=get_option('fi_settings',$missing);
   $local=new ForumIntelligence\Infrastructure\AI\NullProvider();$provider=static fn($current)=>$local;
   try{
    update_option('fi_settings',array_merge($settings===$missing?array():$settings,array('ai_enabled'=>true,'semantic'=>false)));
    add_filter('fi_text_provider',$provider);add_filter('fi_embedding_provider',$provider);
    $screen=new ForumIntelligence\Bootstrap\Services();$screen->register();
    $screenFrontend=new ForumIntelligence\Frontend\Frontend($screen->operations,$screen->solutions,$screen->access,$root.'/forum-intelligence-for-bbpress.php');
    $_GET=array('tab'=>'moderation','status'=>'new');ob_start();(new ForumIntelligence\Admin\Admin($screen,$screenFrontend))->render();return (string)ob_get_clean();
   }finally{
    $_GET=$query;while(ob_get_level()>$level){ob_end_clean();}
    $wp_filter=$hooks;$shortcode_tags=$shortcodes;$wp_post_types=$types;
    if($settings===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$settings);}
   }
  };
  if(!str_contains($advisoryAdminHtml(),'PACKAGED_ADVISORY_OLD_DETAIL_CANARY')){throw new RuntimeException('Packaged native moderation HTML did not render the current advisory.');}
  $priorAdvisory=$services->store->record('moderation_advisory:'.$projectionCase);
  foreach(array(array(),array('answer'=>true,'source_ids'=>array($topic)),array('answer'=>str_repeat(' ',20001).'{}','source_ids'=>array($topic))) as $invalid){
   $advisoryProvider->output=$invalid;$deniedAdvisory=false;
   try{$advisory->generate($projectionCase,(int)$admin->ID);}catch(ForumIntelligence\Domain\Failure $failure){$deniedAdvisory=$failure->status===503;}
   if(!$deniedAdvisory||$services->store->record('moderation_advisory:'.$projectionCase)!==$priorAdvisory){throw new RuntimeException('Packaged malformed advisory replaced its record.');}
  }

  $reviewRole=bbp_get_user_role($ragReader);bbp_set_user_role($ragReader,bbp_get_moderator_role());
  try {
   $revokedProvider=new class($topic,$ragReader) implements ForumIntelligence\Contracts\TextProvider {
    public function __construct(private int $source,private int $actor){}
    public function generate(string $instruction,array $evidence):array{bbp_set_user_role($this->actor,bbp_get_participant_role());return array('answer'=>'REVOKED_PACKAGED_SUMMARY_CANARY','source_ids'=>array($this->source));}
   };
   $revokedKnowledge=new ForumIntelligence\Domain\Knowledge\Knowledge($services->store,$services->queue,$services->access,$revokedProvider);
   $deniedRevoked=false;
   try{$revokedKnowledge->generate($topic,$ragReader);}catch(ForumIntelligence\Domain\Failure $failure){$deniedRevoked=$failure->status===403;}
   if(!$deniedRevoked||$services->store->record('summary:'.$topic)!==$priorSummary){throw new RuntimeException('Packaged revoked actor replaced the current summary.');}
  } finally {bbp_set_user_role($ragReader,$reviewRole);}
  $reassignedProvider=new class($topic,$alertTopic,$projectionCase,$services->store) implements ForumIntelligence\Contracts\TextProvider {
   public function __construct(private int $source,private int $replacement,private int $case,private ForumIntelligence\Infrastructure\Database\Store $store){}
   public function generate(string $instruction,array $evidence):array{
    $this->store->execute("UPDATE {$this->store->table('cases')} SET object_id=%d WHERE id=%d",array($this->replacement,$this->case));
    return array('answer'=>wp_json_encode(array('spam'=>0.1,'toxicity'=>0,'dangerous'=>0,'pii'=>0.1,'confidence'=>0.5,'explanation'=>'Reassigned case canary','recommendation'=>'human_review')),'source_ids'=>array($this->source));
   }
  };
  $reassignedAdvisory=new ForumIntelligence\Domain\Moderation\Advisory($services->store,$services->queue,$services->access,$reassignedProvider);
  $topicPassword=(string)get_post_field('post_password',$topic);
  try {
   $deniedReassigned=false;
   try{$reassignedAdvisory->generate($projectionCase,(int)$admin->ID);}catch(ForumIntelligence\Domain\Failure $failure){$deniedReassigned=$failure->status===409;}
   if(!$deniedReassigned||$services->store->record('moderation_advisory:'.$projectionCase)!==$priorAdvisory){throw new RuntimeException('Packaged reassigned case replaced the original-source advisory.');}
   wp_update_post(array('ID'=>$topic,'post_password'=>'owned-packaged-source-protection'));
   if($services->access->canRead($topic,(int)$admin->ID)||!$services->access->canRead($alertTopic,(int)$admin->ID)||$reassignedAdvisory->view($projectionCase,(int)$admin->ID)!==null){throw new RuntimeException('Packaged reassigned case displayed unreadable old source detail.');}

   $reassignedHtml=$advisoryAdminHtml();
   if(str_contains($reassignedHtml,'PACKAGED_ADVISORY_OLD_DETAIL_CANARY')||!str_contains($reassignedHtml,'Request AI advisory review')){throw new RuntimeException('Packaged native moderation HTML retained old-source detail or lost the current review action.');}
  } finally {
   wp_update_post(array('ID'=>$topic,'post_password'=>$topicPassword));
   $services->store->execute("UPDATE {$services->store->table('cases')} SET object_id=%d WHERE id=%d",array($topic,$projectionCase));
  }
 } finally {
  $services->store->execute("DELETE FROM {$services->store->table('records')} WHERE record_key=%s",array('summary:'.$topic));
  if($projectionCase){$services->store->execute("DELETE FROM {$services->store->table('records')} WHERE record_key=%s",array('moderation_advisory:'.$projectionCase));$services->store->execute("DELETE FROM {$services->store->table('cases')} WHERE id=%d AND object_id=%d",array($projectionCase,$topic));}
 }

 $vectorSettingsMissing=new stdClass();$vectorSettings=get_option('fi_settings',$vectorSettingsMissing);
 try {
  update_option('fi_settings',array_merge($vectorSettings===$vectorSettingsMissing?array():$vectorSettings,array('semantic'=>true,'excluded_forums'=>array())));
  foreach(array(1.0e308,1.0e-300,PHP_FLOAT_MAX,PHP_FLOAT_MIN) as $scale){
   if(abs(ForumIntelligence\Domain\Search\Ranking::cosine(array($scale,$scale),array($scale,$scale))-1.0)>1.0e-12||abs(ForumIntelligence\Domain\Search\Ranking::cosine(array($scale,$scale),array(-$scale,-$scale))+1.0)>1.0e-12){throw new RuntimeException('Packaged cosine lost finite scale or direction.');}
  }
  $vectors=new ForumIntelligence\Infrastructure\Search\LocalVectorStore($services->store);
  $embedding=new class implements ForumIntelligence\Contracts\EmbeddingProvider {
   public int $calls=0;public array $sent=array();
   public function embed(string $text):array{++$this->calls;$this->sent[]=$text;return array(1.0e308,1.0e308);}
  };
  $vectorIndexer=new ForumIntelligence\Infrastructure\Search\Indexer($services->store,$services->queue,$services->access,$embedding,$vectors);
  $vectorIndexer->index($topic);$vectorIndexer->index($reply);
  if($embedding->calls!==2){throw new RuntimeException('Packaged semantic fixture did not embed both current sources.');}

  if(mb_strlen($embedding->sent[0])>12000){throw new RuntimeException('Packaged replacement index embedding exceeded its transfer bound.');}
  foreach(array('author@example.test','supersecretvalue','PACKAGED_UNSENT_TAIL') as $secret){if(str_contains($embedding->sent[0],$secret)){throw new RuntimeException('Packaged index embedding retained a sensitive or clipped canary.');}}
  $queryEmbedding=new class implements ForumIntelligence\Contracts\EmbeddingProvider {
   public array $sent=array();
   public function embed(string $text):array{$this->sent[]=$text;return array(1.0e308,1.0e308);}
  };
  $queryLocal=new ForumIntelligence\Infrastructure\Search\LexicalSearch($services->store,$services->access,$services->solutions);
  $queryHybrid=new ForumIntelligence\Infrastructure\Search\HybridSearch($queryLocal,$queryEmbedding,$vectors,$services->access,$services->solutions,$services->store);
  $queryHybrid->search('author@example.test api_key=supersecretvalue '.str_repeat('Ω',600).' PACKAGED_QUERY_TAIL',array(),(int)$admin->ID);
  if(count($queryEmbedding->sent)!==1||mb_strlen($queryEmbedding->sent[0])>500){throw new RuntimeException('Packaged replacement query embedding exceeded its transfer bound.');}
  foreach(array('author@example.test','supersecretvalue','PACKAGED_QUERY_TAIL') as $secret){if(str_contains($queryEmbedding->sent[0],$secret)){throw new RuntimeException('Packaged query embedding retained a sensitive or clipped canary.');}}
  $vectorIndexer->index($topic);
  if($embedding->calls!==2){throw new RuntimeException('Packaged valid current vector was regenerated.');}
  $hash=ForumIntelligence\Infrastructure\Search\Indexer::hash($topic);
  $model=(string)(get_option('fi_settings',array())['embedding_model']??'text-embedding-3-small');
  $beforeVector=$services->store->row("SELECT vector,model,vector_binary FROM {$services->store->table('chunks')} WHERE source_id=%d",array($topic));
  if(!is_string($beforeVector['vector_binary'])||!str_contains($beforeVector['vector_binary'],"\0")||!ForumIntelligence\Domain\Search\PreparedVector::fromFrame($beforeVector['vector_binary'],$beforeVector['vector'])){throw new RuntimeException('Packaged compiled frame did not survive actual binary storage.');}
  $vectors->put($topic,str_repeat('0',64),$model,array(1,-1));
  if($beforeVector!==$services->store->row("SELECT vector,model,vector_binary FROM {$services->store->table('chunks')} WHERE source_id=%d",array($topic))){throw new RuntimeException('Packaged stale put changed canonical or compiled geometry.');}
  foreach(array(null,'PACKAGED_DAMAGED_VECTOR_FRAME') as $frame){
   if($frame===null){$services->store->execute("UPDATE {$services->store->table('chunks')} SET vector_binary=NULL WHERE source_id=%d",array($topic));}
   else{$services->store->execute("UPDATE {$services->store->table('chunks')} SET vector_binary=UNHEX(%s) WHERE source_id=%d",array(bin2hex($frame),$topic));}
   $fallbackBefore=$services->store->row("SELECT vector,model,vector_binary FROM {$services->store->table('chunks')} WHERE source_id=%d",array($topic));
   $fallback=$vectors->nearest(array(-1.0e308,-1.0e308),100);
   if(!isset($fallback[$topic])||abs($fallback[$topic]+1.0)>1e-12||$fallbackBefore!==$services->store->row("SELECT vector,model,vector_binary FROM {$services->store->table('chunks')} WHERE source_id=%d",array($topic))){throw new RuntimeException('Packaged legacy/damaged frame fallback lost direction or wrote during retrieval.');}
  }
  $services->store->execute("UPDATE {$services->store->table('chunks')} SET vector_binary=UNHEX(%s) WHERE source_id=%d",array(bin2hex($beforeVector['vector_binary']),$topic));
  $rejected=false;
  try{$vectors->put($topic,$hash,$model,array('key'=>1));}catch(InvalidArgumentException $error){$rejected=true;}
  if(!$rejected||$beforeVector!==$services->store->row("SELECT vector,model,vector_binary FROM {$services->store->table('chunks')} WHERE source_id=%d",array($topic))){throw new RuntimeException('Packaged malformed vector put changed the current cache.');}
  $queries=$GLOBALS['wpdb']->num_queries;
  if($vectors->nearest(array('key'=>1),100)!==array()||$GLOBALS['wpdb']->num_queries!==$queries){throw new RuntimeException('Packaged malformed query loaded vector candidates.');}
  foreach(array('true','{"key":1}','[1,null]',wp_json_encode(array_fill(0,4097,1))) as $corrupt){
   $services->store->execute("UPDATE {$services->store->table('chunks')} SET vector=%s WHERE source_id=%d",array($corrupt,$reply));
   $nearest=$vectors->nearest(array(-1.0e308,-1.0e308),100);
   if(isset($nearest[$reply])||!isset($nearest[$topic])||abs($nearest[$topic]+1.0)>1.0e-12){throw new RuntimeException('Packaged corrupt vector blocked healthy current direction.');}
   $beforeCalls=$embedding->calls;$vectorIndexer->index($reply);
   if($embedding->calls!==$beforeCalls+1){throw new RuntimeException('Packaged malformed current cache was not repaired.');}
   $vectorIndexer->index($reply);
   if($embedding->calls!==$beforeCalls+1){throw new RuntimeException('Packaged repaired current cache was regenerated.');}
  }
  bbp_privatize_forum($forum);$beforeCalls=$embedding->calls;$vectorIndexer->index($topic);$vectorIndexer->index($reply);
  if($embedding->calls!==$beforeCalls||$services->store->row("SELECT vector FROM {$services->store->table('chunks')} WHERE source_id=%d",array($topic))['vector']!=='[]'){throw new RuntimeException('Packaged restricted vector repair made a provider call or retained its public vector.');}
  bbp_publicize_forum($forum);
 } finally {if($vectorSettings===$vectorSettingsMissing){delete_option('fi_settings');}else{update_option('fi_settings',$vectorSettings);}bbp_publicize_forum($forum);}



 $standalonePost=$GLOBALS['post']??null;
 try {
  unset($GLOBALS['post']);
  if(apply_filters('the_title','PACKAGED_STANDALONE_TITLE')!=='PACKAGED_STANDALONE_TITLE'||!str_contains(apply_filters('the_content','PACKAGED_STANDALONE_BODY'),'PACKAGED_STANDALONE_BODY')||apply_filters('get_the_excerpt','PACKAGED_STANDALONE_EXCERPT',null)!=='PACKAGED_STANDALONE_EXCERPT'){throw new RuntimeException('Packaged native knowledge filters altered standalone WordPress rendering.');}
 }finally{$GLOBALS['post']=$standalonePost;}

 $article=0;$articlePost=$GLOBALS['post']??null;$articleViewer=get_current_user_id();
 $articleReplyPassword=(string)get_post_field('post_password',$reply);
 try {
  $nativeKnowledge=$services->get('knowledge');
  $nativeKnowledge->command($topic,'edit','PACKAGED_NATIVE_KNOWLEDGE_BODY_CANARY',(int)$admin->ID);
  $article=(int)$nativeKnowledge->command($topic,'draft','',(int)$admin->ID)['article_id'];
  foreach(array('read_post','edit_post','delete_post') as $cap){if(!user_can($admin->ID,$cap,$article)){throw new RuntimeException('Packaged source-bound human draft lost native editorial rights.');}}
  wp_update_post(array('ID'=>$article,'post_status'=>'publish','post_title'=>'PACKAGED_NATIVE_KNOWLEDGE_TITLE_CANARY','post_excerpt'=>'PACKAGED_NATIVE_KNOWLEDGE_EXCERPT_CANARY'));
  $GLOBALS['post']=get_post($article);
  if(!str_contains(apply_filters('the_content',get_post($article)->post_content),'PACKAGED_NATIVE_KNOWLEDGE_BODY_CANARY')||!str_contains($nativeKnowledge->articles(),'PACKAGED_NATIVE_KNOWLEDGE_BODY_CANARY')){throw new RuntimeException('Packaged readable human article was withheld.');}
  wp_update_post(array('ID'=>$article,'post_content'=>'PACKAGED_NATIVE_KNOWLEDGE_REVISED_CANARY'));
  $revisions=wp_get_post_revisions($article);
  if(!$revisions){throw new RuntimeException('Packaged native article revisions were not created.');}
  $revision=(int)array_key_first($revisions);
  wp_update_post(array('ID'=>$reply,'post_password'=>'owned-packaged-knowledge-source-protection'));
  foreach(array($article,$revision) as $view){
   foreach(array('read_post','edit_post','delete_post') as $cap){if(user_can($admin->ID,$cap,$view)){throw new RuntimeException('Packaged article/revision authorization retained an unreadable contributing source.');}}
   $GLOBALS['post']=get_post($view);
   if(apply_filters('the_content',get_post($view)->post_content)!==''||get_the_excerpt($view)!==''||get_the_title($view)!==''){throw new RuntimeException('Packaged native article/revision body, excerpt or title exposed restricted knowledge.');}
  }
  if(str_contains($nativeKnowledge->articles(),'PACKAGED_NATIVE_KNOWLEDGE_')){throw new RuntimeException('Packaged knowledge shortcode exposed source-protected knowledge.');}
  wp_update_post(array('ID'=>$reply,'post_password'=>$articleReplyPassword));
  if(!user_can($admin->ID,'edit_post',$article)||!str_contains(get_post($article)->post_content,'PACKAGED_NATIVE_KNOWLEDGE_REVISED_CANARY')){throw new RuntimeException('Packaged restored source failed to retain the human article and editorial rights.');}
  wp_update_post(array('ID'=>$article,'post_password'=>'owned-packaged-article-password'));$GLOBALS['post']=get_post($article);
  if(!user_can($admin->ID,'edit_post',$article)||apply_filters('the_content',get_post($article)->post_content)!==''||get_the_title($article)!==''||str_contains(get_the_excerpt($article),'PACKAGED_NATIVE_KNOWLEDGE_')||str_contains($nativeKnowledge->articles(),'PACKAGED_NATIVE_KNOWLEDGE_')){throw new RuntimeException('Packaged own-article password handling exposed content or removed native editing rights.');}
  wp_update_post(array('ID'=>$article,'post_password'=>'','post_status'=>'draft'));$GLOBALS['post']=get_post($article);wp_set_current_user(0);
  if(apply_filters('the_content',get_post($article)->post_content)!==''||get_the_excerpt($article)!==''||get_the_title($article)!==''){throw new RuntimeException('Packaged anonymous native draft rendering exposed knowledge.');}
  wp_set_current_user($admin->ID);
  if(!str_contains(apply_filters('the_content',get_post($article)->post_content),'PACKAGED_NATIVE_KNOWLEDGE_REVISED_CANARY')){throw new RuntimeException('Packaged draft rendering lost its authorized editor.');}
  wp_update_post(array('ID'=>$article,'post_status'=>'publish'));delete_post_meta($article,'_fi_provenance');$GLOBALS['post']=get_post($article);
  if(user_can($admin->ID,'edit_post',$article)||apply_filters('the_content',get_post($article)->post_content)!==''||get_the_excerpt($article)!==''||get_the_title($article)!==''){throw new RuntimeException('Packaged legacy/unbound knowledge failed to withhold incomplete provenance.');}
 } finally {
  wp_set_current_user($articleViewer);$GLOBALS['post']=$articlePost;
  wp_update_post(array('ID'=>$reply,'post_password'=>$articleReplyPassword));
  if($article){wp_delete_post($article,true);}
  $services->store->execute("DELETE FROM {$services->store->table('records')} WHERE record_key=%s",array('summary:'.$topic));
 }

 $auditBefore=(int)($services->store->row("SELECT MAX(id) AS n FROM {$services->store->table('events')}")['n']??0);
 $configuration=(new ForumIntelligence\Admin\Admin($services,$frontend))->exportConfiguration((int)$admin->ID);
 $exportEvent=$services->store->row("SELECT actor_id,payload FROM {$services->store->table('events')} WHERE id>%d AND kind='configuration_exported'",array($auditBefore));
 if(!$exportEvent || (int)$exportEvent['actor_id']!==(int)$admin->ID || $exportEvent['payload']!=='[]'){throw new RuntimeException('Packaged export audit failed.');}
 $center=new ForumIntelligence\Frontend\NotificationCenter($services,$frontend);
 $centerHtml=$center->render();
 if(!str_contains($centerHtml,'Notification category')||!str_contains($centerHtml,'Delete notification')){throw new RuntimeException('Packaged native notification center failed.');}
 $indicator=$center->indicator(array('url'=>home_url('/')));
 if(!str_contains($indicator,'data-fi-unread-label')||str_contains($indicator,'unread notification')){throw new RuntimeException('Packaged indicator embedded a private unread count.');}
 (new ForumIntelligence\Infrastructure\Database\Schema())->install();
 if((int)get_option('fi_schema_version')!==ForumIntelligence\Infrastructure\Database\Schema::VERSION){throw new RuntimeException('Idempotent migration failed.');}
 ForumIntelligence\Bootstrap\Lifecycle::deactivate();
 if(wp_next_scheduled('fi_queue_tick')){throw new RuntimeException('Deactivation left queue schedules.');}
 ForumIntelligence\Bootstrap\Lifecycle::activate();
 echo count($manifest)." file hashes, production dependencies, actual package classes, solution/search/permission/frontend/local alert/notification center, mention fallback, encrypted-draft authorization/module, inert composer preview, current delayed fallback evidence, bounded native metadata invalidation, contextual template matching/privacy, administrator-only additive ranking diagnostics, public bounded/redacted AI grounding and in-flight accepted-state checks, owner-bound delayed generated-answer proofs/current-status rendering, replacement summary/advisory/index/query redaction and bounds, strict malformed-output record preservation, revoked summary actor and reassigned/unreadable advisory source rejection and native moderation HTML withholding, standalone rendering and native knowledge source-bound editorial/revision permissions and body/excerpt/title/password/draft/legacy rendering, compiled binary frame integrity/legacy fallback/read-only retrieval/stale atomic writes, stable extreme vector direction, malformed query/write/cache admission and current/restricted reindex repair, export audit, migration and lifecycle checks passed.\n";
} finally {
 if($oldTemplateVariants===$missingTemplateOption){delete_option('fi_template_variants');}else{update_option('fi_template_variants',$oldTemplateVariants);}
 if($ragReader){$services->store->execute("DELETE FROM {$services->store->table('limits')} WHERE bucket=%s",array(hash('sha256',$ragReader.':ask')));require_once ABSPATH.'wp-admin/includes/user.php';if(!wp_delete_user($ragReader)){throw new RuntimeException('Owned grounding fixture reader was not removed.');}}
 if($alertId){$services->get('saved_searches')->remove($admin->ID,$alertId);}
 if($alertTopic){$services->store->execute("DELETE FROM {$services->store->table('notifications')} WHERE user_id=%d AND object_id=%d AND kind='saved_search'",array($admin->ID,$alertTopic));}
 foreach(array($alertTopic,$reply,$topic,$forum) as $id){if($id){wp_delete_post($id,true);}}
}
