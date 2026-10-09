<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Contracts\TextProvider;
use ForumIntelligence\Domain\Failure;
final class KnowledgeRecommendationTest extends TestCase {
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
 protected function setUp():void {
  global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);
  $this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_review_notifications'=>false]);
  add_filter('pre_wp_mail',static fn()=>true);
  $this->actor=(int)wp_insert_user(['user_login'=>'fi_recommend_actor_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'administrator']);
  $this->member=(int)wp_insert_user(['user_login'=>'fi_recommend_member_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'subscriber']);
  self::assertGreaterThan(0,$this->actor);self::assertGreaterThan(0,$this->member);
  bbp_set_user_role($this->actor,bbp_get_keymaster_role());bbp_set_user_role($this->member,bbp_get_participant_role());wp_set_current_user($this->actor);
  $this->provider=new class implements TextProvider {public int $calls=0;public function generate(string $instruction,array $evidence):array{++$this->calls;throw new RuntimeException('No provider work belongs to a recommendation.');}};
  add_filter('fi_text_provider',fn()=>$this->provider);
  $this->services=new Services();$this->services->register();
  $this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned recommendation forum','post_author'=>$this->actor]));
  [$this->topic,$this->reply]=$this->solved('Owned recommendation source');
 }
 private function remember(int $id):int{self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id;}
 private function solved(string $title):array {
  $topic=$this->remember((int)bbp_insert_topic(['post_title'=>$title,'post_content'=>'Owned public problem','post_author'=>$this->actor,'post_parent'=>$this->forum],['forum_id'=>$this->forum]));
  $reply=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned accepted solution','post_author'=>$this->actor,'post_parent'=>$topic],['topic_id'=>$topic,'forum_id'=>$this->forum]));
  $this->services->solutions->change($topic,$reply,'accept',$this->actor);return [$topic,$reply];
 }
 protected function tearDown():void {
  update_option('fi_settings',['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_review_notifications'=>false]);wp_set_current_user($this->actor);
  foreach(array_reverse($this->owned)as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$this->actor){throw new RuntimeException('Refusing foreign recommendation fixture content.');}wp_delete_post($id,true);}
  $store=$this->services->store;
  foreach($this->owned as $id){$store->execute("DELETE FROM {$store->table('records')} WHERE record_key IN (%s,%s,%s)",['summary:'.$id,'knowledge_recommendation:'.$id,'knowledge_source_revision:'.$id]);}
  if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}
  require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->member);wp_delete_user($this->actor);wp_set_current_user(0);global $wp_filter;$wp_filter=$this->hooks;
 }
 public function testRecommendationsAreModeratorOwnedLocalAndPreserveHumanSummaryAndDrafts():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_RECOMMENDATION_HUMAN_SUMMARY',$this->actor);
  $before=$this->services->store->record('summary:'.$this->topic);
  $knowledge->command($this->topic,'recommend',"Useful solution فارسی C:\\owned\\path <script>unsafe</script>",$this->actor);
  $record=$this->services->store->record('knowledge_recommendation:'.$this->topic);
  self::assertSame('knowledge_recommendation',$record['kind']);self::assertSame($this->actor,(int)$record['owner_id']);self::assertSame($before['data']['sources'],$record['data']['sources']);
  self::assertStringNotContainsString('<script>',$record['data']['reason']);self::assertStringContainsString('فارسی',$record['data']['reason']);
  self::assertSame($before,$this->services->store->record('summary:'.$this->topic));
  $page=$knowledge->recommendations($this->actor);self::assertCount(1,$page['items']);self::assertSame($this->topic,$page['items'][0]['topic']);self::assertFalse($page['items'][0]['stale']);
  $knowledge->command($this->topic,'recommend','A refined human review note',$this->actor);
  self::assertSame($record['id'],$this->services->store->record('knowledge_recommendation:'.$this->topic)['id']);self::assertSame('A refined human review note',$knowledge->recommendations($this->actor)['items'][0]['reason']);
  self::assertSame([] ,get_posts(['post_type'=>'fi_knowledge','post_status'=>'any','meta_key'=>'_fi_source_topic','meta_value'=>$this->topic]));
  self::assertNull($this->services->store->row("SELECT id FROM {$this->services->store->table('jobs')} WHERE kind IN ('summary','summary_auto','knowledge_auto_draft') AND (job_key=%s OR job_key LIKE %s)",['knowledge_auto_draft:'.$this->topic,'summary:'.$this->topic.':%']));
  self::assertSame(0,$this->provider->calls);
 }
 public function testCurrentRecordedSourcePrivacyAndExplicitWithdrawalRenewTheReviewQueue():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'recommend','OWNED_PRIVATE_RECOMMENDATION_REASON_CANARY',$this->actor);
  wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-recommendation-source-password']);self::assertSame([],$knowledge->recommendations($this->actor)['items']);
  wp_update_post(['ID'=>$this->reply,'post_password'=>'']);$page=$knowledge->recommendations($this->actor);self::assertCount(1,$page['items']);self::assertTrue($page['items'][0]['stale']);
  $knowledge->command($this->topic,'unrecommend','',$this->actor);self::assertSame([],$knowledge->recommendations($this->actor)['items']);self::assertSame('knowledge_recommendation_withdrawn',$this->services->store->record('knowledge_recommendation:'.$this->topic)['kind']);
  $knowledge->command($this->topic,'recommend','A renewed human recommendation',$this->actor);self::assertCount(1,$knowledge->recommendations($this->actor)['items']);
 }
 public function testRecommendationsRequireCurrentModeratorAndSolvedAcceptedNativeTopics():void {
  $knowledge=$this->services->get('knowledge');
  try{$knowledge->command($this->topic,'recommend','Forbidden participant recommendation',$this->member);self::fail('Participant recommendation must fail.');}catch(Failure $failure){self::assertSame(403,$failure->status);}
  try{$knowledge->recommendations($this->member);self::fail('Participant queue access must fail.');}catch(Failure $failure){self::assertSame(403,$failure->status);}
  $this->services->solutions->change($this->topic,0,'reopen',$this->actor);
  try{$knowledge->command($this->topic,'recommend','Unsolved discussion',$this->actor);self::fail('Unsolved recommendations must fail.');}catch(Failure $failure){self::assertSame(409,$failure->status);}
  foreach([$this->forum,$this->reply]as $invalid){foreach(['edit','draft','regenerate','recommend']as $command){try{$knowledge->command($invalid,$command,'Invalid source',$this->actor);self::fail('Native knowledge commands require a topic.');}catch(Failure $failure){self::assertSame(404,$failure->status);}}}
  self::assertSame(0,$this->provider->calls);
 }
 public function testActualDraftConversionArchivesTheRecommendationAndPreservesHumanContent():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_RECOMMENDATION_TO_DRAFT_BODY',$this->actor);$knowledge->command($this->topic,'recommend','Human recommendation for editorial conversion',$this->actor);
  $article=$this->remember((int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id']);
  $record=$this->services->store->record('knowledge_recommendation:'.$this->topic);self::assertSame('knowledge_recommendation_drafted',$record['kind']);self::assertSame($article,$record['data']['article_id']);
  self::assertSame('draft',get_post_status($article));self::assertStringContainsString('OWNED_RECOMMENDATION_TO_DRAFT_BODY',get_post($article)->post_content);self::assertSame([],$knowledge->recommendations($this->actor)['items']);self::assertSame(0,$this->provider->calls);
  wp_update_post(['ID'=>$article,'post_content'=>'Owned human article edits after conversion']);$knowledge->command($this->topic,'recommend','Review the updated source again',$this->actor);self::assertSame('Owned human article edits after conversion',get_post($article)->post_content);
 }
 public function testDeletedSourceRemovesOnlyItsOwnRecommendationBinding():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'recommend','Owned recommendation deletion scope',$this->actor);
  [$other]=$this->solved('Owned second recommendation source');$knowledge->command($other,'recommend','Owned surviving recommendation',$this->actor);
  wp_delete_post($this->topic,true);self::assertNull($this->services->store->record('knowledge_recommendation:'.$this->topic));self::assertNotNull($this->services->store->record('knowledge_recommendation:'.$other));
 }

 public function testKeysetQueueIsBoundedAndHidesCurrentlyRestrictedSourceNotes():void {
  $knowledge=$this->services->get('knowledge');$topics=[];
  for($i=0;$i<26;++$i){[$topic]=$this->solved('Owned paged recommendation '.$i);$topics[]=$topic;$knowledge->command($topic,'recommend','OWNED_RECOMMENDATION_PAGE_'.$i,$this->actor);$this->services->store->execute("DELETE FROM {$this->services->store->table('limits')} WHERE bucket=%s",[hash('sha256',$this->actor.':solution')]);}
  wp_update_post(['ID'=>$topics[25],'post_password'=>'owned-paged-source-password']);
  $first=$knowledge->recommendations($this->actor);self::assertCount(24,$first['items']);self::assertNotNull($first['next']);self::assertStringNotContainsString('OWNED_RECOMMENDATION_PAGE_25',wp_json_encode($first));
  $second=$knowledge->recommendations($this->actor,$first['next']);self::assertCount(1,$second['items']);self::assertNull($second['next']);
  self::assertSame([] ,array_intersect(array_column($first['items'],'id'),array_column($second['items'],'id')));
 }
 public function testLateSourcePrivacyMutationRollsBackOnlyTheCurationWriteAndRefreshesNativeCache():void {
  $knowledge=$this->services->get('knowledge');$changed=false;$table=$this->services->store->table('records');
  add_filter('query',function(string $sql)use(&$changed,$table):string{if(!$changed&&str_starts_with($sql,'INSERT INTO '.$table)&&str_contains($sql,"'knowledge_recommendation'")){$changed=true;wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-late-recommendation-password']);}return $sql;});
  try{$knowledge->command($this->topic,'recommend','Owned recommendation during privacy change',$this->actor);self::fail('Changed source must reject recommendation write.');}catch(Failure $failure){self::assertContains($failure->status,[403,404,409]);}
  self::assertTrue($changed);self::assertNull($this->services->store->record('knowledge_recommendation:'.$this->topic));self::assertSame('',get_post($this->reply)->post_password);
  $knowledge->command($this->topic,'recommend','Owned recommendation after cache recovery',$this->actor);self::assertCount(1,$knowledge->recommendations($this->actor)['items']);
 }
 public function testLateModeratorRevocationWithholdsTheQueueAndNativeAdminNotes():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'recommend','OWNED_LATE_RECOMMENDATION_REASON_CANARY',$this->actor);$revoked=false;
  add_filter('post_type_link',function(string $url,WP_Post $post)use(&$revoked):string{if($post->ID===$this->topic){$revoked=true;}return $url;},999,2);
  add_filter('map_meta_cap',function(array $caps,string $cap,int $user)use(&$revoked):array{return $revoked&&$cap==='moderate'&&$user===$this->actor?['do_not_allow']:$caps;},999,3);
  try{$knowledge->recommendations($this->actor);self::fail('Revoked moderator must receive no queue.');}catch(Failure $failure){self::assertSame(403,$failure->status);}
  self::assertTrue($revoked);self::assertFalse(user_can($this->actor,'moderate'));
  $admin=new ForumIntelligence\Admin\Admin($this->services,new ForumIntelligence\Frontend\Frontend($this->services->operations,$this->services->solutions,$this->services->access,dirname(__DIR__,2).'/forum-intelligence-for-bbpress.php'));
  ob_start();(new ReflectionMethod($admin,'knowledge'))->invoke($admin);$html=ob_get_clean();self::assertStringNotContainsString('OWNED_LATE_RECOMMENDATION_REASON_CANARY',$html);
 }
 public function testArchivedReasonStaysOwnedByItsAuthorForActualPrivacyErasure():void {
  wp_update_user(['ID'=>$this->member,'user_email'=>'fi_recommend_privacy_'.$this->member.'@example.invalid']);bbp_set_user_role($this->member,bbp_get_keymaster_role());
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'recommend','Owned note written by another moderator',$this->member);
  $knowledge->command($this->topic,'unrecommend','',$this->actor);$record=$this->services->store->record('knowledge_recommendation:'.$this->topic);
  self::assertSame($this->member,(int)$record['owner_id']);
  $body=get_post($this->topic)->post_content;(new ForumIntelligence\Privacy\Privacy($this->services->store))->erase('fi_recommend_privacy_'.$this->member.'@example.invalid',1);
  self::assertNull($this->services->store->record('knowledge_recommendation:'.$this->topic));self::assertSame($body,get_post($this->topic)->post_content);
 }
 public function testOversizedNotesAndDisabledModuleRejectBeforeCurationStorage():void {
  $knowledge=$this->services->get('knowledge');
  try{$knowledge->command($this->topic,'recommend',str_repeat('x',2001),$this->actor);self::fail('Oversized note must fail.');}catch(Failure $failure){self::assertSame(413,$failure->status);}
  self::assertNull($this->services->store->record('knowledge_recommendation:'.$this->topic));
  $settings=(array)get_option('fi_settings');$settings['modules']['knowledge']=false;update_option('fi_settings',$settings);
  try{$knowledge->command($this->topic,'recommend','Disabled module note',$this->actor);self::fail('Disabled module must fail.');}catch(Failure $failure){self::assertSame(503,$failure->status);}
  try{$knowledge->recommendations($this->actor);self::fail('Disabled module queue must fail.');}catch(Failure $failure){self::assertSame(503,$failure->status);}
 }
 public function testNativeAdminEscapesStoredNotesAndSourceProtectionWithholdsThePanelRow():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','Owned summary for recommendation UI',$this->actor);$knowledge->command($this->topic,'recommend','Owned readable note',$this->actor);
  $record=$this->services->store->record('knowledge_recommendation:'.$this->topic);$data=$record['data'];$data['reason']='<img src=x onerror="OWNED_RECOMMENDATION_XSS_CANARY">';
  $this->services->store->saveRecord('knowledge_recommendation',$record['record_key'],$this->actor,$this->topic,$data);
  $admin=new ForumIntelligence\Admin\Admin($this->services,new ForumIntelligence\Frontend\Frontend($this->services->operations,$this->services->solutions,$this->services->access,dirname(__DIR__,2).'/forum-intelligence-for-bbpress.php'));
  ob_start();(new ReflectionMethod($admin,'knowledge'))->invoke($admin);$html=ob_get_clean();self::assertStringNotContainsString('<img src=x',$html);self::assertStringContainsString('&lt;img',$html);self::assertStringContainsString('Create editorial draft',$html);self::assertStringContainsString('Withdraw recommendation',$html);
  wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-ui-note-source-password']);ob_start();(new ReflectionMethod($admin,'knowledge'))->invoke($admin);$html=ob_get_clean();self::assertStringNotContainsString('OWNED_RECOMMENDATION_XSS_CANARY',$html);
 }

 public function testRecommendationCannotReplaceAnUnreadableExistingSourceBinding():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'recommend','OWNED_PREVIOUS_BOUND_NOTE_CANARY',$this->actor);
  $record=$this->services->store->record('knowledge_recommendation:'.$this->topic);
  $replacement=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned current replacement solution','post_author'=>$this->actor,'post_parent'=>$this->topic],['forum_id'=>$this->forum,'topic_id'=>$this->topic]));
  $this->services->solutions->change($this->topic,$replacement,'accept',$this->actor);
  [$other]=$this->solved('Owned moved-source question');
  wp_update_post(['ID'=>$this->reply,'post_parent'=>$other]);update_post_meta($this->reply,'_bbp_topic_id',$other);
  wp_update_post(['ID'=>$other,'post_password'=>'owned-moved-source-password']);
  self::assertSame([],$knowledge->recommendations($this->actor)['items']);
  try{$knowledge->command($this->topic,'recommend','An unauthorized replacement of the hidden note',$this->actor);self::fail('Unreadable existing source binding must not be overwritten.');}catch(Failure $failure){self::assertSame(404,$failure->status);}
  self::assertSame($record['data'],$this->services->store->record('knowledge_recommendation:'.$this->topic)['data']);
 }
 public function testARecommendationNeverOverwritesAnUnrelatedRecordKind():void {
  $store=$this->services->store;$store->saveRecord('owned_unrelated_control','knowledge_recommendation:'.$this->topic,$this->actor,$this->topic,['text'=>'OWNED_UNRELATED_RECORD_CONTROL']);
  $before=$store->record('knowledge_recommendation:'.$this->topic);
  try{$this->services->get('knowledge')->command($this->topic,'recommend','Unsafe record-key collision',$this->actor);self::fail('Unrelated kind must be retained.');}catch(Failure $failure){self::assertSame(409,$failure->status);}
  self::assertSame($before,$store->record('knowledge_recommendation:'.$this->topic));
 }

 public function testFailedReplacementRefreshesPreviousSourcesOutsideTheCurrentWindow():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'recommend','OWNED_OLD_SOURCE_CACHE_NOTE',$this->actor);$before=$this->services->store->record('knowledge_recommendation:'.$this->topic);
  $replacement=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned current source replacement','post_author'=>$this->actor,'post_parent'=>$this->topic],['forum_id'=>$this->forum,'topic_id'=>$this->topic]));$this->services->solutions->change($this->topic,$replacement,'accept',$this->actor);
  [$other]=$this->solved('Owned current moved source');wp_update_post(['ID'=>$this->reply,'post_parent'=>$other]);update_post_meta($this->reply,'_bbp_topic_id',$other);
  $changed=false;$table=$this->services->store->table('records');
  add_filter('query',function(string $sql)use(&$changed,$table):string {if(!$changed&&str_starts_with($sql,'INSERT INTO '.$table)&&str_contains($sql,"'knowledge_recommendation'")){$changed=true;wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-previous-source-cache-password']);}return $sql;});
  try{$knowledge->command($this->topic,'recommend','Rejected note replacement',$this->actor);self::fail('Previous bound-source revocation must reject replacement.');}catch(Failure $failure){self::assertContains($failure->status,[403,404,409]);}
  self::assertTrue($changed);self::assertSame($before,$this->services->store->record('knowledge_recommendation:'.$this->topic));self::assertSame('',get_post($this->reply)->post_password);self::assertCount(1,$knowledge->recommendations($this->actor)['items']);
 }
 public function testMalformedRecommendationPayloadsAreWithheldWithoutTypeErrors():void {
  $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'recommend','Owned legacy metadata shape',$this->actor);
  $store=$this->services->store;$store->execute("UPDATE {$store->table('records')} SET payload=%s WHERE record_key=%s",['"malformed legacy value"','knowledge_recommendation:'.$this->topic]);
  self::assertSame([],$knowledge->recommendations($this->actor)['items']);
  foreach(['recommend','unrecommend']as $command){try{$knowledge->command($this->topic,$command,'A replacement must not repair unknown data silently',$this->actor);self::fail('Malformed binding must be withheld.');}catch(Failure $failure){self::assertSame(404,$failure->status);}}
  self::assertSame('malformed legacy value',$store->record('knowledge_recommendation:'.$this->topic)['data']);
 }
}
