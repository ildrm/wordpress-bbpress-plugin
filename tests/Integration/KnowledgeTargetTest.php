<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Contracts\TextProvider;
use ForumIntelligence\Domain\Failure;
final class KnowledgeTargetTest extends TestCase {
 private Services $services;
 private int $actor;
 private int $member;
 private int $forum;
 private int $topic;
 private int $reply;
 private array $owned=[];
 private array $hooks=[];
 private mixed $settings;
 private object $missing;
 private object $provider;
 private string $targetType='fi_owned_kb';
 protected function setUp():void {
  global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);
  $this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_review_notifications'=>false]);
  add_filter('pre_wp_mail',static fn()=>true);
  $this->actor=(int)wp_insert_user(['user_login'=>'fi_target_actor_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'administrator']);
  $this->member=(int)wp_insert_user(['user_login'=>'fi_target_member_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'subscriber']);
  self::assertGreaterThan(0,$this->actor);self::assertGreaterThan(0,$this->member);
  bbp_set_user_role($this->actor,bbp_get_keymaster_role());bbp_set_user_role($this->member,bbp_get_participant_role());wp_set_current_user($this->actor);
  $this->provider=new class implements TextProvider {public int $calls=0;public function generate(string $instruction,array $evidence):array{++$this->calls;throw new RuntimeException('No provider work belongs to native target conversion.');}};
  add_filter('fi_text_provider',fn()=>$this->provider);
  register_post_type($this->targetType,['label'=>'Owned editorial target','public'=>false,'publicly_queryable'=>false,'exclude_from_search'=>true,'show_in_rest'=>false,'show_ui'=>true,'has_archive'=>false,'map_meta_cap'=>true,'supports'=>['title','editor','revisions','author']]);
  add_filter('fi_knowledge_targets',fn(array $targets):array=>$targets+['owned'=>new ForumIntelligence\Domain\Knowledge\NativeKnowledgeTarget($this->targetType,'Owned editorial target')]);
  $this->services=new Services();$this->services->register();
  $this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned target forum','post_author'=>$this->actor]));
  [$this->topic,$this->reply]=$this->solved('Owned target source');
 }
 private function remember(int $id):int{self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id;}
 private function solved(string $title):array {
  $topic=$this->remember((int)bbp_insert_topic(['post_title'=>$title,'post_content'=>'Owned public problem','post_author'=>$this->actor,'post_parent'=>$this->forum],['forum_id'=>$this->forum]));
  $reply=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned accepted solution','post_author'=>$this->actor,'post_parent'=>$topic],['topic_id'=>$topic,'forum_id'=>$this->forum]));
  $this->services->solutions->change($topic,$reply,'accept',$this->actor);return [$topic,$reply];
 }
 protected function tearDown():void {
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_review_notifications'=>false]);wp_set_current_user($this->actor);
  foreach(array_reverse($this->owned)as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$this->actor){throw new RuntimeException('Refusing foreign target fixture content.');}wp_delete_post($id,true);}
  $store=$this->services->store;
  $store->execute("DELETE FROM {$store->table('jobs')} WHERE (kind='knowledge_review' AND job_key LIKE %s) OR (kind='knowledge_auto_draft' AND job_key=%s)",['knowledge_review:'.$this->topic.':%','knowledge_auto_draft:'.$this->topic]);
  $store->execute("DELETE FROM {$store->table('notifications')} WHERE user_id=%d AND kind='knowledge_review'",[$this->actor]);
  foreach($this->owned as $id){$store->execute("DELETE FROM {$store->table('records')} WHERE record_key IN (%s,%s,%s)",['summary:'.$id,'knowledge_recommendation:'.$id,'knowledge_source_revision:'.$id]);}
  if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}
  require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->member);wp_delete_user($this->actor);wp_set_current_user(0);unregister_post_type($this->targetType);global $wp_filter;$wp_filter=$this->hooks;
 }

 public function testSelectedNativeEditorialAdapterCreatesUnpublishedSourceBoundDraftWithoutProviderWork():void {
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);
  $knowledge=$this->services->get('knowledge');$text="OWNED_TARGET_HUMAN_BODY فارسی C:\\owned\\path";
  $knowledge->command($this->topic,'edit',$text,$this->actor);$summary=$this->services->store->record('summary:'.$this->topic);$provenance=$knowledge->summary($this->topic,$this->actor);
  $id=$this->remember((int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id']);
  self::assertSame($this->targetType,get_post_type($id));self::assertSame('draft',get_post_status($id));self::assertSame($this->actor,(int)get_post_field('post_author',$id));
  self::assertSame(wpautop(esc_html($text)),get_post_field('post_content',$id));self::assertSame($this->topic,(int)get_post_meta($id,'_fi_source_topic',true));
  self::assertSame($provenance,get_post_meta($id,'_fi_provenance',true));self::assertSame('owned',get_post_meta($id,'_fi_knowledge_target',true));
  self::assertSame($summary,$this->services->store->record('summary:'.$this->topic));self::assertSame(0,$this->provider->calls);
  self::assertTrue(user_can($this->actor,'edit_post',$id));self::assertSame([],$knowledge->comparison($id,$this->actor)['changes']);
 }
 public function testUnavailableSelectedAdapterDoesNotSilentlyCreateADefaultArticle():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_TARGET_SUMMARY',$this->actor);
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'missing-owned-adapter']);
  try{$id=$this->remember((int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id']);self::fail('Missing configured target must refuse conversion.');}
  catch(Failure $failure){self::assertSame('target_unavailable',$failure->reason);self::assertSame(409,$failure->status);}
  self::assertSame([],get_posts(['post_type'=>['fi_knowledge',$this->targetType],'post_status'=>'any','meta_key'=>'_fi_source_topic','meta_value'=>$this->topic]));
 }
 public function testPublicOrRestEnabledTargetsCannotReceiveCopiedDiscussionContent():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_TARGET_SUMMARY',$this->actor);
  foreach(['public','show_in_rest']as $property){
   $type=get_post_type_object($this->targetType);$before=$type->$property;$type->$property=true;
   update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);
   try{try{$this->remember((int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id']);self::fail('Incompatible native target must refuse conversion.');}
   catch(Failure $failure){self::assertSame('target_unavailable',$failure->reason);self::assertSame(409,$failure->status);}}
   finally{$type->$property=$before;}
  }
  self::assertSame(0,$this->provider->calls);
 }

 private function customDraft(string $body='OWNED_ADAPTED_BODY_CANARY'):int {
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit',$body,$this->actor);
  return $this->remember((int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id']);
 }
 public function testAdaptedNativeBodiesRevisionsAndSourceReviewRenewPrivacyWithoutChangingUnrelatedPosts():void {
  $id=$this->customDraft();$knowledge=$this->services->get('knowledge');
  $normal=$this->remember((int)wp_insert_post(['post_type'=>$this->targetType,'post_status'=>'publish','post_author'=>$this->actor,'post_title'=>'OWNED_UNRELATED_TARGET_TITLE','post_content'=>'OWNED_UNRELATED_TARGET_BODY']));
  wp_update_post(['ID'=>$id,'post_status'=>'publish','post_excerpt'=>'OWNED_ADAPTED_EXCERPT_CANARY']);self::assertSame('OWNED_ADAPTED_EXCERPT_CANARY',get_the_excerpt($id));$previous=$GLOBALS['post']??null;
  try{
   $GLOBALS['post']=get_post($id);self::assertStringContainsString('OWNED_ADAPTED_BODY_CANARY',apply_filters('the_content',get_post($id)->post_content));
   self::assertStringContainsString('OWNED_ADAPTED_BODY_CANARY',$knowledge->articles());
   wp_update_post(['ID'=>$id,'post_content'=>'OWNED_HUMAN_ADAPTED_REVISION']);$revisions=wp_get_post_revisions($id);self::assertNotEmpty($revisions);$revision=(int)array_key_first($revisions);
   wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-adapted-source-password']);
   self::assertFalse(user_can($this->actor,'edit_post',$id));self::assertFalse(user_can($this->actor,'read_post',$revision));
   self::assertSame('',apply_filters('the_content',get_post($id)->post_content));self::assertSame('',get_the_title($id));self::assertSame('',get_the_excerpt($id));
   self::assertStringNotContainsString('OWNED_HUMAN_ADAPTED_REVISION',$knowledge->articles());
   $GLOBALS['post']=get_post($normal);self::assertSame(wpautop('OWNED_UNRELATED_TARGET_BODY'),apply_filters('the_content',get_post($normal)->post_content));self::assertSame('OWNED_UNRELATED_TARGET_TITLE',get_the_title($normal));
   wp_update_post(['ID'=>$this->reply,'post_password'=>'']);self::assertTrue(user_can($this->actor,'edit_post',$id));
   self::assertTrue($knowledge->reviewState($id,$this->actor)['stale']);$body=get_post($id)->post_content;
   $knowledge->verifyArticle($id,$this->actor);self::assertFalse($knowledge->reviewState($id,$this->actor)['stale']);self::assertSame($body,get_post($id)->post_content);
   $box=new ForumIntelligence\Domain\Knowledge\Editorial($knowledge);$before=$_POST;$_POST=['fi_knowledge_reviewed'=>'1','fi_knowledge_review_nonce'=>wp_create_nonce('fi_knowledge_review_'.$id)];
   try{wp_update_post(['ID'=>$this->topic,'post_content'=>'Owned source changed after adapted verification']);$box->register();wp_update_post(['ID'=>$id,'post_content'=>$body]);self::assertFalse($knowledge->reviewState($id,$this->actor)['stale']);}
   finally{$_POST=$before;}
  }finally{$GLOBALS['post']=$previous;}
 }
 public function testAdapterSelectionChangesAndRemovalKeepHumanStorageAndNativeSourceGuards():void {
  $id=$this->customDraft();$knowledge=$this->services->get('knowledge');$body=get_post($id)->post_content;
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'fi_knowledge']);self::assertTrue(user_can($this->actor,'edit_post',$id));self::assertFalse($knowledge->reviewState($id,$this->actor)['stale']);
  $unregistered=static fn(array $targets):array=>array_intersect_key($targets,['fi_knowledge'=>true]);add_filter('fi_knowledge_targets',$unregistered,99);
  $previous=$GLOBALS['post']??null;
  try{$GLOBALS['post']=get_post($id);self::assertFalse(user_can($this->actor,'edit_post',$id));self::assertSame('',apply_filters('the_content',$body));self::assertSame($body,get_post($id)->post_content);}
  finally{remove_filter('fi_knowledge_targets',$unregistered,99);$GLOBALS['post']=$previous;}
  self::assertTrue(user_can($this->actor,'edit_post',$id));self::assertSame($body,get_post($id)->post_content);
 }
 public function testNativeCustomCreationCapabilitiesCannotBeReplacedByModeratorPermission():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_TARGET_CAPABILITY_BODY',$this->actor);
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);$type=get_post_type_object($this->targetType);$before=$type->cap->create_posts;$type->cap->create_posts='fi_owned_missing_create_cap';
  try{self::assertFalse($knowledge->canDraft($this->actor));try{$knowledge->command($this->topic,'draft','',$this->actor);self::fail('Native custom creation permission is required.');}catch(Failure $failure){self::assertSame(403,$failure->status);}}
  finally{$type->cap->create_posts=$before;}
 }
 public function testAdapterPolicyMutationRollsBackCopyAndRecommendationAndRefreshesCurrentPolicy():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_TARGET_ROLLBACK_BODY',$this->actor);
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);$knowledge->command($this->topic,'recommend','OWNED_TARGET_ROLLBACK_NOTE',$this->actor);$before=$this->services->store->record('knowledge_recommendation:'.$this->topic);
  $changed=false;$filter=function(array $post)use(&$changed):array{if(!$changed&&$post['post_type']===$this->targetType){$changed=true;update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'fi_knowledge']);}return $post;};add_filter('wp_insert_post_data',$filter);
  try{try{$knowledge->command($this->topic,'draft','',$this->actor);self::fail('Target mutation must refuse the new copy.');}catch(Failure $failure){self::assertSame(409,$failure->status);}self::assertTrue($changed);}
  finally{remove_filter('wp_insert_post_data',$filter);}
  self::assertSame('owned',get_option('fi_settings')['knowledge_target']);self::assertSame($before,$this->services->store->record('knowledge_recommendation:'.$this->topic));
  self::assertSame([],get_posts(['post_type'=>[$this->targetType,'fi_knowledge'],'post_status'=>'any','meta_key'=>'_fi_source_topic','meta_value'=>$this->topic]));
  $id=$this->remember((int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id']);self::assertSame($this->targetType,get_post_type($id));self::assertSame('knowledge_recommendation_drafted',$this->services->store->record('knowledge_recommendation:'.$this->topic)['kind']);self::assertSame('',get_post_meta($id,'_fi_draft_token',true));
 }
 public function testNativeTargetSelectionAndReviewedConfigurationTransferAreAdministratorOwned():void {
  $frontend=new ForumIntelligence\Frontend\Frontend($this->services->operations,$this->services->solutions,$this->services->access,dirname(__DIR__,2).'/forum-intelligence-for-bbpress.php');$admin=new ForumIntelligence\Admin\Admin($this->services,$frontend);$admin->register();
  self::assertSame('fi_knowledge',ForumIntelligence\Admin\Admin::defaultSettings()['knowledge_target']);
  $input=['knowledge_target'=>'owned','ai_enabled'=>false,'knowledge_auto_draft_enabled'=>true,'knowledge_auto_draft_actor'=>$this->actor,'modules'=>['knowledge'=>true]];
  $clean=$admin->sanitize($input);self::assertSame('owned',$clean['knowledge_target']);self::assertTrue($clean['knowledge_auto_draft_enabled']);update_option('fi_settings',$clean);
  $export=$admin->exportConfiguration($this->actor);self::assertSame('owned',$export['settings']['knowledge_target']);
  self::assertSame(['imported'=>true],$this->services->operations->execute('config_import',['reviewed'=>1,'json'=>wp_json_encode($export)],$this->actor));
  $before=get_option('fi_settings');$export['settings']['knowledge_target']='missing-owned-adapter';
  try{$this->services->operations->execute('config_import',['reviewed'=>1,'json'=>wp_json_encode($export)],$this->actor);self::fail('Unavailable imported target must be rejected.');}catch(Failure $failure){self::assertSame(400,$failure->status);}self::assertSame($before,get_option('fi_settings'));
  wp_set_current_user($this->member);self::assertSame($before,$admin->sanitize($input));wp_set_current_user($this->actor);
  require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/template.php';
  $method=new ReflectionMethod($admin,'settings');ob_start();try{$method->invoke($admin);$html=(string)ob_get_contents();}finally{ob_end_clean();}self::assertStringContainsString('for="fi-knowledge-target"',$html);self::assertMatchesRegularExpression('/<option value="owned"\\s+selected=/',$html);
 }

 public function testAutomaticDraftsUseCurrentTargetAndSuppressCopiesAfterTargetSwitch():void {
  $settings=['ai_enabled'=>false,'knowledge_target'=>'owned','knowledge_auto_draft_enabled'=>true,'knowledge_auto_draft_actor'=>$this->actor,'knowledge_auto_draft_min_replies'=>1,'knowledge_auto_draft_min_confirmations'=>0,'knowledge_auto_draft_debounce'=>60];update_option('fi_settings',$settings);
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_ADAPTED_AUTOMATIC_BODY',$this->actor);
  $store=$this->services->store;$job=$store->row("SELECT * FROM {$store->table('jobs')} WHERE kind='knowledge_auto_draft' AND job_key=%s",['knowledge_auto_draft:'.$this->topic]);self::assertNotNull($job);self::assertSame(['topic'=>$this->topic],json_decode($job['payload'],true));
  $store->execute("UPDATE {$store->table('records')} SET updated_at='2000-01-01 00:00:00' WHERE record_key=%s",['knowledge_source_revision:'.$this->topic]);
  $automatic=new ForumIntelligence\Domain\Knowledge\AutoDraft($store,$this->services->queue,$this->services->access,$knowledge);$automatic->run($this->topic);
  $copies=get_posts(['post_type'=>$this->targetType,'post_status'=>'draft','posts_per_page'=>2,'meta_key'=>'_fi_source_topic','meta_value'=>$this->topic]);self::assertCount(1,$copies);$id=$this->remember($copies[0]->ID);$body=$copies[0]->post_content;self::assertSame($this->actor,(int)$copies[0]->post_author);
  $settings['knowledge_target']='fi_knowledge';update_option('fi_settings',$settings);$automatic->run($this->topic);
  self::assertSame([],get_posts(['post_type'=>'fi_knowledge','post_status'=>'any','meta_key'=>'_fi_source_topic','meta_value'=>$this->topic]));self::assertSame($body,get_post($id)->post_content);self::assertSame(0,$this->provider->calls);
 }
 public function testResponsibleEditorAlertsUseAdaptedArticlesAndRenewCurrentSourcePrivacy():void {
  $id=$this->customDraft();$settings=get_option('fi_settings');$settings['knowledge_review_notifications']=true;update_option('fi_settings',$settings);$store=$this->services->store;
  wp_update_post(['ID'=>$this->topic,'post_content'=>'Owned adapted source changed for editor alert']);
  $jobKey='knowledge_review:'.$this->topic.':0';$job=$store->row("SELECT * FROM {$store->table('jobs')} WHERE kind='knowledge_review' AND job_key=%s",[$jobKey]);self::assertNotNull($job);self::assertSame(['topic'=>$this->topic,'cursor'=>0],json_decode($job['payload'],true));
  $store->execute("UPDATE {$store->table('jobs')} SET available_at='2000-01-01 00:00:00' WHERE kind='knowledge_review' AND job_key=%s",[$jobKey]);$this->services->queue->run(1);
  $notifications=$this->services->get('notifications');$inbox=$notifications->inbox($this->actor,1,false,'knowledge_review');self::assertCount(1,$inbox['items']);self::assertSame($id,(int)$inbox['items'][0]['object_id']);self::assertStringContainsString('post='.$id,$inbox['items'][0]['url']);self::assertStringNotContainsString('OWNED_ADAPTED_BODY_CANARY',wp_json_encode($inbox));
  wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-adapted-alert-source-password']);self::assertSame([],$notifications->inbox($this->actor,1,false,'knowledge_review')['items']);wp_update_post(['ID'=>$this->reply,'post_password'=>'']);
  $this->services->get('knowledge')->verifyArticle($id,$this->actor);self::assertSame([],$notifications->inbox($this->actor,1,false,'knowledge_review')['items']);
 }
 public function testManualAdaptedCopyRejectsChangedOwnerAndPublicationWithoutLeavingNativeRows():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_TARGET_MUTATION_BODY',$this->actor);update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);
  foreach(['post_author'=>$this->member,'post_status'=>'publish']as $property=>$value){
   $created=0;$mutate=function(array $post)use($property,$value):array{if($post['post_type']===$this->targetType){$post[$property]=$value;}return $post;};$capture=function(int $id,WP_Post $post,bool $update)use(&$created):void{if(!$update&&$post->post_type===$this->targetType){$created=$id;}};add_filter('wp_insert_post_data',$mutate);add_action('save_post',$capture,1,3);
   try{try{$knowledge->command($this->topic,'draft','',$this->actor);self::fail('Unexpected owner/publication must reject conversion.');}catch(Failure $failure){self::assertSame(503,$failure->status);}self::assertGreaterThan(0,$created);self::assertNull(get_post($created));self::assertSame([],get_post_meta($created));}
   finally{remove_filter('wp_insert_post_data',$mutate);remove_action('save_post',$capture,1);}
  }
  self::assertSame(0,$this->provider->calls);
 }

 public function testNativeCallbackExceptionDoesNotLeaveAnInsertedDraftInWordPressCaches():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_ADAPTER_CALLBACK_BODY',$this->actor);update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);
  $created=0;$fail=function(int $id,WP_Post $post,bool $update)use(&$created):void{if(!$update&&$post->post_type===$this->targetType){$created=$id;throw new RuntimeException('OWNED_CALLBACK_EXCEPTION_CANARY');}};add_action('save_post',$fail,10,3);
  try{try{$knowledge->command($this->topic,'draft','',$this->actor);self::fail('Native insertion callback failure must reject conversion.');}catch(Failure $failure){self::assertSame('draft_failed',$failure->reason);self::assertSame(503,$failure->status);self::assertStringNotContainsString('OWNED_CALLBACK_EXCEPTION_CANARY',$failure->getMessage());self::assertGreaterThan(0,$created);}self::assertNull(get_post($created));self::assertSame([],get_post_meta($created));}
  finally{remove_action('save_post',$fail,10);}
 }

 public function testInvalidAdapterReturnStillRefreshesTheActualCreatedPostCache():void {
  $adapter=new class($this->targetType) implements ForumIntelligence\Contracts\KnowledgeTarget {
   public int $created=0;public function __construct(private string $type){}public function postType():string{return $this->type;}public function label():string{return 'Owned invalid-return target';}
   public function createDraft(array $post):int{$this->created=(new ForumIntelligence\Domain\Knowledge\NativeKnowledgeTarget($this->type,'Owned target'))->createDraft($post);return 0;}
  };
  $replace=static function(array $targets)use($adapter):array{$targets['owned']=$adapter;return $targets;};add_filter('fi_knowledge_targets',$replace,99);
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_BAD_ADAPTER_RETURN_BODY',$this->actor);update_option('fi_settings',['ai_enabled'=>false,'knowledge_target'=>'owned']);
  try{try{$knowledge->command($this->topic,'draft','',$this->actor);self::fail('An invalid returned ID must fail conversion.');}catch(Failure $failure){self::assertSame(503,$failure->status);}self::assertGreaterThan(0,$adapter->created);self::assertNull(get_post($adapter->created));self::assertSame([],get_post_meta($adapter->created));}
  finally{remove_filter('fi_knowledge_targets',$replace,99);}
 }

 public function testExistingNativeArticleIsRestoredWhenAnAdapterReusesItsId():void {
  $existing=$this->customDraft('OWNED_OLD_ADAPTER_HUMAN_BODY');$before=get_post($existing)->post_content;$provenance=get_post_meta($existing,'_fi_provenance',true);$binding=get_post_meta($existing,'_fi_knowledge_target',true);
  $adapter=new class($this->targetType,$existing) implements ForumIntelligence\Contracts\KnowledgeTarget {
   public function __construct(private string $type,private int $existing){}public function postType():string{return $this->type;}public function label():string{return 'Owned existing-ID adapter';}
   public function createDraft(array $post):int{$post['ID']=$this->existing;return (new ForumIntelligence\Domain\Knowledge\NativeKnowledgeTarget($this->type,'Owned target'))->createDraft($post);}
  };
  $replace=static function(array $targets)use($adapter):array{$targets['owned']=$adapter;return $targets;};add_filter('fi_knowledge_targets',$replace,99);
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_REUSED_ADAPTER_NEW_SUMMARY',$this->actor);
  try{try{$knowledge->command($this->topic,'draft','',$this->actor);self::fail('A target must create a new native article.');}catch(Failure $failure){self::assertSame(503,$failure->status);}self::assertSame($before,get_post($existing)->post_content);self::assertSame($provenance,get_post_meta($existing,'_fi_provenance',true));self::assertSame($binding,get_post_meta($existing,'_fi_knowledge_target',true));self::assertSame('',get_post_meta($existing,'_fi_draft_token',true));self::assertSame($this->actor,(int)get_post($existing)->post_author);}
  finally{remove_filter('fi_knowledge_targets',$replace,99);}
 }
}
