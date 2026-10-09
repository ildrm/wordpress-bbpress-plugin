<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Contracts\TextProvider;
use ForumIntelligence\Domain\Failure;
final class KnowledgeSummaryRecordTest extends TestCase {
 private Services $services;private int $actor;private int $forum;private int $topic;private int $reply;private array $owned=[];private array $hooks=[];private mixed $settings;private object $missing;private object $provider;
 protected function setUp():void {
  global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);$this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);
  update_option('fi_settings',['ai_enabled'=>false,'summary_auto_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_review_notifications'=>false]);add_filter('pre_wp_mail',static fn()=>true);
  $this->actor=(int)wp_insert_user(['user_login'=>'fi_summary_record_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'administrator']);self::assertGreaterThan(0,$this->actor);bbp_set_user_role($this->actor,bbp_get_keymaster_role());wp_set_current_user($this->actor);
  $this->provider=new class implements TextProvider {public int $calls=0;public $during=null;public function generate(string $instruction,array $evidence):array{++$this->calls;if($this->during){($this->during)();}return ['answer'=>'OWNED_SUMMARY_PROVIDER_RESULT','source_ids'=>array_column($evidence,'id')];}};
  add_filter('fi_text_provider',fn()=>$this->provider);$this->services=new Services();$this->services->register();
  $this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned stored summary forum','post_author'=>$this->actor]));
  $this->topic=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned stored summary source','post_content'=>'Owned public original context','post_author'=>$this->actor,'post_parent'=>$this->forum],['forum_id'=>$this->forum]));
  $this->reply=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned public approach','post_author'=>$this->actor,'post_parent'=>$this->topic],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));
 }
 private function remember(int $id):int{self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id;}
 protected function tearDown():void {
  update_option('fi_settings',['ai_enabled'=>false,'summary_auto_enabled'=>false,'knowledge_auto_draft_enabled'=>false,'knowledge_review_notifications'=>false]);wp_set_current_user($this->actor);
  foreach(array_reverse($this->owned)as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$this->actor){throw new RuntimeException('Refusing foreign stored-summary fixture content.');}wp_delete_post($id,true);}
  $s=$this->services->store;foreach($this->owned as $id){$s->execute("DELETE FROM {$s->table('records')} WHERE record_key IN (%s,%s,%s)",['summary:'.$id,'knowledge_source_revision:'.$id,'knowledge_recommendation:'.$id]);}
  $s->execute("DELETE FROM {$s->table('jobs')} WHERE kind IN ('summary','summary_auto','knowledge_auto_draft','knowledge_review') AND JSON_EXTRACT(payload,'$.topic')=%d",[$this->topic]);
  if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->actor);wp_set_current_user(0);global $wp_filter;$wp_filter=$this->hooks;
 }
 private function key():string{return 'summary:'.$this->topic;}
 private function seed(bool $unlock=false):array{$k=$this->services->get('knowledge');$k->command($this->topic,'edit','OWNED_PRIOR_HUMAN_SUMMARY',$this->actor);if($unlock){$k->command($this->topic,'unlock','',$this->actor);}return $this->services->store->record($this->key());}
 private function payload(mixed $data):void{$s=$this->services->store;$s->execute("UPDATE {$s->table('records')} SET payload=%s,version=version+1 WHERE record_key=%s",[wp_json_encode($data),$this->key()]);}
 private function denied(callable $operation,int $status=409):void{try{$operation();self::fail('A rejected stored-summary operation must fail safely.');}catch(Failure $failure){self::assertSame($status,$failure->status);}}
 public function testScalarStoredSummaryIsWithheldAndNeverConvertedOrSentToAProvider():void {
  $k=$this->services->get('knowledge');foreach(['OWNED_SCALAR_RECORD_CANARY',0,false,null,[]]as $payload){$this->seed();$this->payload($payload);$before=$this->services->store->record($this->key());
  self::assertNull($k->summary($this->topic,$this->actor));$this->denied(fn()=>$k->command($this->topic,'draft','',$this->actor));$this->denied(fn()=>$k->generate($this->topic,$this->actor));self::assertSame(0,$this->provider->calls);self::assertSame($before,$this->services->store->record($this->key()));}
 }
 public function testMalformedNativeSummaryProvenanceAndAnswerAreWithheldWithoutStorageMutation():void {
  $row=$this->seed();$data=$row['data'];$cases=[];
  $bad=$data;$bad['sources']=[];$cases[]=$bad;$bad=$data;unset($bad['sources'][$this->topic]);$cases[]=$bad;
  $bad=$data;$bad['sources'][$this->topic]='invalid-hash';$cases[]=$bad;$bad=$data;$bad['answer']=['OWNED_ARRAY_BODY_CANARY'];$cases[]=$bad;
  $bad=$data;$bad['fingerprint']=[];$cases[]=$bad;$bad=$data;$bad['manual_lock']='false';$cases[]=$bad;
  $bad=$data;$bad['sources']=array_fill_keys(range(1,65),str_repeat('a',64));$cases[]=$bad;
  foreach($cases as $case){$this->payload($case);$before=$this->services->store->record($this->key());self::assertNull($this->services->get('knowledge')->summary($this->topic,$this->actor));self::assertSame($before,$this->services->store->record($this->key()));}self::assertSame(0,$this->provider->calls);
 }
 public function testWrongKindOrObjectSummaryCollisionCannotBeReadCorrectedOrGenerated():void {
  $row=$this->seed(true);$s=$this->services->store;$k=$this->services->get('knowledge');
  foreach(['kind'=>'owned_unrelated_record','object_id'=>$this->reply]as $field=>$value){
   $s->execute("UPDATE {$s->table('records')} SET kind=%s,object_id=%d WHERE record_key=%s",[$field==='kind'?$value:'summary',$field==='object_id'?$value:$this->topic,$this->key()]);
   $before=$s->record($this->key());self::assertNull($k->summary($this->topic,$this->actor));$this->denied(fn()=>$k->command($this->topic,'edit','OWNED_COLLISION_REPLACEMENT',$this->actor));$this->denied(fn()=>$k->generate($this->topic,$this->actor));self::assertSame($before,$s->record($this->key()));
  }self::assertSame(0,$this->provider->calls);
 }
 public function testOnlyExplicitModeratorEditRepairsAKnownScalarSummary():void {
  $this->seed();$this->payload(123);$before=$this->services->store->record($this->key());$k=$this->services->get('knowledge');
  foreach(['unlock','regenerate','inaccurate','disable']as $command){$this->denied(fn()=>$k->command($this->topic,$command,'',$this->actor));self::assertSame($before,$this->services->store->record($this->key()));}
  $k->command($this->topic,'edit','OWNED_EXPLICIT_SUMMARY_REPAIR',$this->actor);$summary=$k->summary($this->topic,$this->actor);self::assertSame('OWNED_EXPLICIT_SUMMARY_REPAIR',$summary['answer']);self::assertTrue($summary['manual_lock']);self::assertFalse($summary['stale']);self::assertSame(0,$this->provider->calls);
 }
 public function testPriorUnreadableSourceOutsideCurrentEvidenceCannotBeReplacedByManualCorrection():void {
  $row=$this->seed();$data=$row['data'];$data['sources'][$this->reply]=ForumIntelligence\Infrastructure\Search\Indexer::hash($this->reply);$this->payload($data);
  for($i=0;$i<55;++$i){$this->remember((int)bbp_insert_reply(['post_content'=>'Owned newer public reply '.$i,'post_author'=>$this->actor,'post_parent'=>$this->topic],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));}
  wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-prior-summary-password']);$before=$this->services->store->record($this->key());$k=$this->services->get('knowledge');
  self::assertNull($k->summary($this->topic,$this->actor));$this->denied(fn()=>$k->command($this->topic,'edit','OWNED_REPLACED_HIDDEN_BINDING',$this->actor),404);self::assertSame($before,$this->services->store->record($this->key()));self::assertSame(0,$this->provider->calls);
 }
 public function testHumanEditThenExplicitUnlockDuringProviderWorkRetainsTheNewHumanRecord():void {
  $this->seed(true);$k=$this->services->get('knowledge');$changed=null;$this->provider->during=function()use($k,&$changed):void{$k->command($this->topic,'edit','OWNED_CONCURRENT_HUMAN_SUMMARY',$this->actor);$k->command($this->topic,'unlock','',$this->actor);$changed=$this->services->store->record($this->key());};
  $this->denied(fn()=>$k->generate($this->topic,$this->actor));self::assertSame(1,$this->provider->calls);self::assertSame($changed,$this->services->store->record($this->key()));self::assertSame('OWNED_CONCURRENT_HUMAN_SUMMARY',$k->summary($this->topic,$this->actor)['answer']);
 }
 public function testACompetingFirstGenerationIsNotReplacedByAnOlderCompletion():void {
  $k=$this->services->get('knowledge');$changed=null;$this->provider->during=function()use(&$changed):void{$innerProvider=new class implements TextProvider{public function generate(string $instruction,array $evidence):array{return ['answer'=>'OWNED_NEWER_GENERATION_SUMMARY','source_ids'=>array_column($evidence,'id')];}};$inner=new ForumIntelligence\Domain\Knowledge\Knowledge($this->services->store,$this->services->queue,$this->services->access,$innerProvider);$inner->generate($this->topic,$this->actor);$changed=$this->services->store->record($this->key());};
  $this->denied(fn()=>$k->generate($this->topic,$this->actor));self::assertSame($changed,$this->services->store->record($this->key()));self::assertSame('OWNED_NEWER_GENERATION_SUMMARY',$k->summary($this->topic,$this->actor)['answer']);
 }
 public function testMalformedRecordIntroducedDuringProviderWorkCannotBeOverwritten():void {
  $this->seed(true);$changed=null;$this->provider->during=function()use(&$changed):void{$this->payload('OWNED_IN_FLIGHT_SCALAR_CANARY');$changed=$this->services->store->record($this->key());};
  $this->denied(fn()=>$this->services->get('knowledge')->generate($this->topic,$this->actor));self::assertSame($changed,$this->services->store->record($this->key()));self::assertNull($this->services->get('knowledge')->summary($this->topic,$this->actor));
 }
 public function testGeneratedSummaryAndAuditRollBackTogetherAndRecoverAfterStorageRestoration():void {
  $before=$this->seed(true);$s=$this->services->store;$table=$s->table('events');$backup=$table.'_summary_integrity_rollback';self::assertNull($s->row('SHOW TABLES LIKE %s',[$backup]));$db=$GLOBALS['wpdb'];$errors=$db->suppress_errors(true);$moved=false;
  try{$s->execute("RENAME TABLE $table TO $backup");$moved=true;$this->denied(fn()=>$this->services->get('knowledge')->generate($this->topic,$this->actor),503);self::assertSame($before,$s->record($this->key()));}
  finally{if($moved){$s->execute("RENAME TABLE $backup TO $table");}$db->suppress_errors($errors);}
  $this->services->get('knowledge')->generate($this->topic,$this->actor);self::assertSame('OWNED_SUMMARY_PROVIDER_RESULT',$this->services->get('knowledge')->summary($this->topic,$this->actor)['answer']);self::assertSame(2,$this->provider->calls);
 }

 public function testNativeSourceCallbackCannotOverwriteAConcurrentModeratorRecord():void {
  $this->seed();$changed=null;$once=false;$filter=function(bool $allowed,int $id)use(&$once,&$changed):bool{if(!$once&&$id===$this->reply){$once=true;$data=$this->services->store->record($this->key())['data'];$data['answer']='OWNED_CONCURRENT_NATIVE_MODERATOR_RECORD';$this->payload($data);$changed=$this->services->store->record($this->key());}return $allowed;};add_filter('fi_can_read_object',$filter,10,2);
  try{$this->denied(fn()=>$this->services->get('knowledge')->command($this->topic,'edit','OWNED_OBSOLETE_MANUAL_WRITE',$this->actor));self::assertTrue($once);self::assertSame($changed,$this->services->store->record($this->key()));}
  finally{remove_filter('fi_can_read_object',$filter,10);}
 }
 public function testNativeModeratorPermissionIsRenewedAfterStoredSourceCallbacks():void {
  get_userdata($this->actor)->set_role('subscriber');bbp_set_user_role($this->actor,bbp_get_moderator_role());wp_set_current_user($this->actor);self::assertTrue(user_can($this->actor,'moderate'));
  $before=$this->seed();$revoked=false;$read=function(bool $allowed,int $id)use(&$revoked):bool{if(!$revoked&&$id===$this->reply){$revoked=true;bbp_set_user_role($this->actor,bbp_get_participant_role());}return $allowed;};add_filter('fi_can_read_object',$read,10,2);
  try{$this->denied(fn()=>$this->services->get('knowledge')->command($this->topic,'edit','OWNED_REVOKED_MODERATOR_WRITE',$this->actor),403);self::assertTrue($revoked);self::assertFalse(user_can($this->actor,'moderate'));self::assertSame($before,$this->services->store->record($this->key()));}
  finally{remove_filter('fi_can_read_object',$read,10);}
 }
 public function testManualSummaryAndItsAuditRollBackTogetherAndRecover():void {
  $before=$this->seed();$s=$this->services->store;$table=$s->table('events');$backup=$table.'_manual_summary_integrity_rollback';self::assertNull($s->row('SHOW TABLES LIKE %s',[$backup]));$db=$GLOBALS['wpdb'];$errors=$db->suppress_errors(true);$moved=false;
  try{$s->execute("RENAME TABLE $table TO $backup");$moved=true;$this->denied(fn()=>$this->services->get('knowledge')->command($this->topic,'edit','OWNED_UNAUDITED_MANUAL_SUMMARY',$this->actor),503);self::assertSame($before,$s->record($this->key()));}
  finally{if($moved){$s->execute("RENAME TABLE $backup TO $table");}$db->suppress_errors($errors);}
  $this->services->get('knowledge')->command($this->topic,'edit','OWNED_AUDITED_MANUAL_RECOVERY',$this->actor);self::assertSame('OWNED_AUDITED_MANUAL_RECOVERY',$this->services->get('knowledge')->summary($this->topic,$this->actor)['answer']);
 }
}
