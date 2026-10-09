<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Domain\Failure;
final class NativeVotePolicyTest extends TestCase {
 private Services $services;private int $actor;private int $member;private int $forum;private int $topic;private int $reply;private array $owned=[];private array $hooks=[];private mixed $settings;private object $missing;
 protected function setUp():void{
  global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);$this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);update_option('fi_settings',['ai_enabled'=>false]);add_filter('pre_wp_mail',static fn()=>true);
  $this->actor=(int)wp_insert_user(['user_login'=>'fi_vote97_admin_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'administrator']);$this->member=(int)wp_insert_user(['user_login'=>'fi_vote97_member_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'subscriber']);self::assertGreaterThan(0,$this->actor);self::assertGreaterThan(0,$this->member);bbp_set_user_role($this->actor,bbp_get_keymaster_role());bbp_set_user_role($this->member,bbp_get_participant_role());wp_set_current_user($this->actor);
  $this->services=new Services();$this->services->register();$this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned vote policy forum','post_author'=>$this->actor]));$this->topic=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned vote policy source','post_content'=>'Owned public problem','post_parent'=>$this->forum,'post_author'=>$this->actor],['forum_id'=>$this->forum]));$this->reply=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned public helpful approach','post_parent'=>$this->topic,'post_author'=>$this->actor],['forum_id'=>$this->forum,'topic_id'=>$this->topic]));
 }
 private function remember(int $id):int{self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id;}
 protected function tearDown():void{
  update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->actor);foreach(array_reverse($this->owned)as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$this->actor){throw new RuntimeException('Refusing foreign reaction fixture content.');}wp_delete_post($id,true);}
  if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->member);wp_delete_user($this->actor);wp_set_current_user(0);global $wp_filter;$wp_filter=$this->hooks;
 }
 private function denied(callable $operation,int|array $status=400):void{try{$operation();self::fail('Disabled or malformed reactions must refuse a mutation.');}catch(Failure $failure){self::assertContains($failure->status,(array)$status);}}

 private function duringTransaction(callable $mutation,callable $operation):void{
  $changed=false;$filter=function(bool $allowed,int $id,int $user)use(&$changed,$mutation):bool{global $wpdb;if($id===$this->reply&&$user===$this->member&&!$changed&&(int)$wpdb->get_var('SELECT @@in_transaction')===1){$changed=true;$mutation();}return $allowed;};add_filter('fi_can_read_object',$filter,99,3);try{$operation();}finally{remove_filter('fi_can_read_object',$filter,99);}self::assertTrue($changed,'The native callback must execute inside the real transaction.');
 }
 private function emptySignal():void{
  $s=$this->services->store;self::assertSame([],$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));self::assertSame([],$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]));
 }
 public function testDownvotePolicyRevokedInsideNativeReadCannotWriteOrDeductCredits():void{
  $settings=['downvotes'=>true,'ai_enabled'=>false];update_option('fi_settings',$settings);wp_set_current_user($this->member);
  $this->duringTransaction(fn()=>update_option('fi_settings',['downvotes'=>false,'ai_enabled'=>false]),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',-1)));$this->emptySignal();self::assertSame($settings,get_option('fi_settings'));
 }
 public function testSelfVotingRevokedInsideNativeReadCannotWrite():void{
  wp_update_post(['ID'=>$this->reply,'post_author'=>$this->member]);try{$settings=['self_voting'=>true,'ai_enabled'=>false];update_option('fi_settings',$settings);wp_set_current_user($this->member);
   $this->duringTransaction(fn()=>update_option('fi_settings',['self_voting'=>false,'ai_enabled'=>false]),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1)));$this->emptySignal();self::assertSame($settings,get_option('fi_settings'));
  }finally{wp_update_post(['ID'=>$this->reply,'post_author'=>$this->actor]);}
 }
 public function testNativeReplyAuthorshipChangedInsideReadCannotCreditThePreviousAuthor():void{
  update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->member);try{
   $this->duringTransaction(fn()=>wp_update_post(['ID'=>$this->reply,'post_author'=>$this->member]),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1),409));$this->emptySignal();self::assertSame($this->actor,(int)get_post_field('post_author',$this->reply));
  }finally{wp_update_post(['ID'=>$this->reply,'post_author'=>$this->actor]);}
 }
 public function testAcceptedAnswerWithdrawnInsideReadCannotReceiveConfirmationOrCredits():void{
  update_option('fi_settings',['ai_enabled'=>false]);$this->services->solutions->change($this->topic,$this->reply,'accept',$this->actor);wp_set_current_user($this->member);$s=$this->services->store;
  $before=$s->row("SELECT * FROM {$s->table('solutions')} WHERE topic_id=%d",[$this->topic]);$credits=$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]);
  $this->duringTransaction(fn()=>$this->services->solutions->change($this->topic,$this->reply,'remove',$this->actor),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'confirm',1)));
  self::assertSame([],$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));self::assertSame($before,$s->row("SELECT * FROM {$s->table('solutions')} WHERE topic_id=%d",[$this->topic]));self::assertSame($credits,$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]));self::assertSame([$this->reply],$this->services->solutions->state($this->topic,$this->member)['answers']);
 }
 public function testSeparateStoresShareTheNativeTransactionBoundaryWithoutEarlyCommit():void{
  $s=$this->services->store;$other=new ForumIntelligence\Infrastructure\Database\Store();$key='owned_vote97:'.$this->reply;self::assertNull($s->record($key));try{
   try{$s->transaction(function()use($s,$other,$key):void{$s->saveRecord('owned_vote97',$key,$this->actor,$this->reply,['stage'=>'outer']);$other->transaction(fn()=>$other->saveRecord('owned_vote97',$key,$this->actor,$this->reply,['stage'=>'inner']));throw new Failure('owned_vote97_abort','Owned rollback fixture',503);});self::fail('The owned transaction must throw.');}catch(Failure $failure){self::assertSame('owned_vote97_abort',$failure->reason);}
   self::assertNull($s->record($key),'A second Store must not commit an active native transaction on the same database connection.');
  }finally{$s->execute("DELETE FROM {$s->table('records')} WHERE kind='owned_vote97' AND record_key=%s AND object_id=%d",[$key,$this->reply]);}
 }
 public function testCaughtNestedFailureRestoresItsWritesWhileOuterTransactionCanCommit():void{
  $s=$this->services->store;$key='owned_vote97:'.$this->reply;try{
   $s->transaction(function()use($s,$key):void{$s->saveRecord('owned_vote97',$key,$this->actor,$this->reply,['stage'=>'outer']);try{$s->transaction(function()use($s,$key):void{$s->saveRecord('owned_vote97',$key,$this->actor,$this->reply,['stage'=>'failed-inner']);throw new Failure('owned_nested_abort','Owned inner failure',503);});self::fail('The owned nested operation must throw.');}catch(Failure $failure){self::assertSame('owned_nested_abort',$failure->reason);}});
   self::assertSame(['stage'=>'outer'],$s->record($key)['data'],'A caught nested failure must not commit partial inner writes.');
  }finally{$s->execute("DELETE FROM {$s->table('records')} WHERE kind='owned_vote97' AND record_key=%s AND object_id=%d",[$key,$this->reply]);}
 }
 public function testPresentationAuthorFilterCannotChangeNativeVoteAuthorityOrCreditOwner():void{
  wp_set_current_user($this->member);update_option('fi_settings',['ai_enabled'=>false]);$filter=fn($author)=>$this->member;add_filter('post_author',$filter,99);try{
   self::assertSame($this->member,(int)get_post_field('post_author',$this->reply));self::assertSame($this->actor,(int)get_post_field('post_author',$this->reply,'raw'));
   $this->services->get('engagement')->cast($this->reply,$this->member,'vote',1);$credit=$this->services->store->record('credit:'.hash('sha256','vote:'.$this->reply.':'.$this->member));self::assertNotNull($credit);self::assertSame($this->actor,(int)$credit['owner_id']);self::assertSame(2,$credit['data']['points']);
  }finally{remove_filter('post_author',$filter,99);}
 }
 public function testModuleDisabledDuringNativePermissionRenewalCannotWriteThroughRetainedService():void{
  $settings=['ai_enabled'=>false,'modules'=>['engagement'=>true]];update_option('fi_settings',$settings);wp_set_current_user($this->member);
  $this->duringTransaction(fn()=>update_option('fi_settings',['ai_enabled'=>false,'modules'=>['engagement'=>false]]),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1),503));$this->emptySignal();self::assertSame($settings,get_option('fi_settings'));
 }
 public function testReplyMovedDuringNativeReadRefusesAndRestoresOriginalTopicBinding():void{
  $other=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned new vote source binding','post_parent'=>$this->forum,'post_author'=>$this->actor],['forum_id'=>$this->forum]));update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->member);
  $this->duringTransaction(function()use($other):void{wp_update_post(['ID'=>$this->reply,'post_parent'=>$other]);update_post_meta($this->reply,'_bbp_topic_id',$other);},fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1),409));$this->emptySignal();self::assertSame($this->topic,(int)bbp_get_reply_topic_id($this->reply));self::assertSame($this->topic,(int)get_post_field('post_parent',$this->reply,'raw'));
 }
 public function testSignalCreditAndAuditRollbackTogetherAndRecoverAfterStorageRestoration():void{
  $s=$this->services->store;update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->member);$table=$s->table('events');$backup=$table.'_owned_vote97_rollback';self::assertNull($s->row('SHOW TABLES LIKE %s',[$backup]));$db=$GLOBALS['wpdb'];$errors=$db->suppress_errors(true);$moved=false;
  try{$s->execute("RENAME TABLE $table TO $backup");$moved=true;$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1),503);$this->emptySignal();}
  finally{if($moved){$s->execute("RENAME TABLE $backup TO $table");}$db->suppress_errors($errors);}
  $this->services->get('engagement')->cast($this->reply,$this->member,'vote',1);self::assertSame(2,$s->record('credit:'.hash('sha256','vote:'.$this->reply.':'.$this->member))['data']['points']);$events=$s->rows("SELECT * FROM {$s->table('events')} WHERE kind='signal_changed' AND object_id=%d AND actor_id=%d",[$this->reply,$this->member]);self::assertCount(1,$events);$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1);self::assertSame($events,$s->rows("SELECT * FROM {$s->table('events')} WHERE kind='signal_changed' AND object_id=%d AND actor_id=%d",[$this->reply,$this->member]));$this->services->get('engagement')->cast($this->reply,$this->member,'vote',0);self::assertSame(0,$s->record('credit:'.hash('sha256','vote:'.$this->reply.':'.$this->member))['data']['points']);
 }
 public function testMalformedAcceptedBindingsAndNativeTopicCannotReceiveConfirmation():void{
  update_option('fi_settings',['ai_enabled'=>false]);$this->services->solutions->change($this->topic,$this->reply,'accept',$this->actor);$s=$this->services->store;$before=$s->row("SELECT * FROM {$s->table('solutions')} WHERE topic_id=%d",[$this->topic]);wp_set_current_user($this->member);try{
   foreach(['scalar',[(string)$this->reply],['reply'=>$this->reply],[$this->topic],array_fill(0,11,$this->reply)]as $answers){$s->execute("UPDATE {$s->table('solutions')} SET answers=%s WHERE topic_id=%d",[wp_json_encode($answers),$this->topic]);$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'confirm',1));self::assertSame([],$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));}
   $s->execute("UPDATE {$s->table('solutions')} SET answers=%s WHERE topic_id=%d",[wp_json_encode([$this->topic]),$this->topic]);$this->denied(fn()=>$this->services->get('engagement')->cast($this->topic,$this->member,'confirm',1));
  }finally{$s->execute("UPDATE {$s->table('solutions')} SET answers=%s WHERE topic_id=%d",[$before['answers'],$this->topic]);$this->services->solutions->forget($this->topic);}
 }
 private function duringSignalQuery(string $needle,callable $mutation,callable $operation):void{
  $changed=false;$filter=function(string $sql)use(&$changed,$needle,$mutation):string{global $wpdb;if(!$changed&&str_contains($sql,$needle)&&(int)$wpdb->get_var('SELECT @@in_transaction')===1){$changed=true;$mutation();}return $sql;};add_filter('query',$filter,99);try{$operation();}finally{remove_filter('query',$filter,99);}self::assertTrue($changed,'The native SQL callback must execute while the vote transaction is active.');
 }
 public function testSourceProtectedDuringAcceptedRowLockCannotLeaveAConfirmation():void{
  update_option('fi_settings',['ai_enabled'=>false]);$this->services->solutions->change($this->topic,$this->reply,'accept',$this->actor);wp_set_current_user($this->member);$s=$this->services->store;$credits=$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]);try{
   $this->duringSignalQuery('SELECT answers,solved',fn()=>wp_update_post(['ID'=>$this->topic,'post_password'=>'owned-late-query-password']),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'confirm',1),404));self::assertSame([],$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));self::assertSame($credits,$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]));self::assertSame('',get_post($this->topic)->post_password);
  }finally{wp_update_post(['ID'=>$this->topic,'post_password'=>'']);}
 }
 public function testAuthorChangedDuringAcceptedRowLockCannotCreditThePriorAuthor():void{
  update_option('fi_settings',['ai_enabled'=>false]);$this->services->solutions->change($this->topic,$this->reply,'accept',$this->actor);wp_set_current_user($this->member);try{
   $this->duringSignalQuery('SELECT answers,solved',fn()=>wp_update_post(['ID'=>$this->reply,'post_author'=>0]),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'confirm',1),409));self::assertSame([],$this->services->store->rows("SELECT * FROM {$this->services->store->table('signals')} WHERE object_id=%d",[$this->reply]));self::assertSame($this->actor,(int)get_post_field('post_author',$this->reply,'raw'));
  }finally{wp_update_post(['ID'=>$this->reply,'post_author'=>$this->actor]);}
 }
 public function testDownvotePolicyRevokedDuringSignalRowLockCannotDeductPoints():void{
  $settings=['downvotes'=>true,'ai_enabled'=>false];update_option('fi_settings',$settings);wp_set_current_user($this->member);$this->duringSignalQuery('SELECT value FROM '.$this->services->store->table('signals'),fn()=>update_option('fi_settings',['downvotes'=>false,'ai_enabled'=>false]),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',-1)));$this->emptySignal();self::assertSame($settings,get_option('fi_settings'));
 }
 public function testReactionPolicyRevokedDuringSignalRowLockCannotPersistAppreciation():void{
  $settings=['reactions'=>['like'],'ai_enabled'=>false];update_option('fi_settings',$settings);wp_set_current_user($this->member);$this->duringSignalQuery('SELECT value FROM '.$this->services->store->table('signals'),fn()=>update_option('fi_settings',['reactions'=>[],'ai_enabled'=>false]),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'reaction_like',1)));$this->emptySignal();self::assertSame($settings,get_option('fi_settings'));
 }
 private function afterFinalSourceRead(callable $mutation,callable $operation):void{
  $armed=false;$reads=0;$arm=function(string $sql)use(&$armed):string{global $wpdb;if(str_contains($sql,'SELECT value FROM '.$this->services->store->table('signals'))&&(int)$wpdb->get_var('SELECT @@in_transaction')===1){$armed=true;}return $sql;};$read=function(bool $allowed,int $id,int $user)use(&$armed,&$reads,$mutation):bool{if($armed&&$id===$this->reply&&$user===$this->member&&++$reads===2){$mutation();}return $allowed;};add_filter('query',$arm,99);add_filter('fi_can_read_object',$read,99,3);try{$operation();}finally{remove_filter('query',$arm,99);remove_filter('fi_can_read_object',$read,99);}self::assertGreaterThanOrEqual(2,$reads);
 }
 public function testParentPasswordChangedByFinalReadCallbackCannotPersistAVote():void{
  update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->member);try{
   $this->afterFinalSourceRead(fn()=>wp_update_post(['ID'=>$this->topic,'post_password'=>'owned-final-source-password']),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1),[404,409]));$this->emptySignal();self::assertSame('',get_post($this->topic)->post_password);
  }finally{wp_update_post(['ID'=>$this->topic,'post_password'=>'']);}
 }
 public function testActorMadeSpectatorByFinalReadCallbackCannotPersistAVote():void{
  update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->member);try{
   $this->afterFinalSourceRead(fn()=>bbp_set_user_role($this->member,bbp_get_spectator_role()),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1),403));$this->emptySignal();self::assertSame(bbp_get_participant_role(),bbp_get_user_role($this->member));
  }finally{bbp_set_user_role($this->member,bbp_get_participant_role());}
 }
 public function testGlobalCurrentUserRoleCacheIsRefreshedAfterNativeRollback():void{
  update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->member);self::assertTrue(current_user_can('publish_replies'));
  $this->duringTransaction(fn()=>wp_get_current_user()->set_role(bbp_get_spectator_role()),fn()=>$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'vote',1),403));$this->emptySignal();self::assertSame(bbp_get_participant_role(),bbp_get_user_role($this->member));self::assertTrue(current_user_can('publish_replies'),'The restored native current-user object must match rolled-back role storage.');
 }
}
