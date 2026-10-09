<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Domain\Failure;
final class ReactionConfigurationTest extends TestCase {
 private Services $services;private int $actor;private int $member;private int $forum;private int $topic;private int $reply;private array $owned=[];private array $hooks=[];private mixed $settings;private object $missing;
 protected function setUp():void{
  global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);$this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);update_option('fi_settings',['ai_enabled'=>false]);add_filter('pre_wp_mail',static fn()=>true);
  $this->actor=(int)wp_insert_user(['user_login'=>'fi_reaction_admin_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'administrator']);$this->member=(int)wp_insert_user(['user_login'=>'fi_reaction_member_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'subscriber']);self::assertGreaterThan(0,$this->actor);self::assertGreaterThan(0,$this->member);bbp_set_user_role($this->actor,bbp_get_keymaster_role());bbp_set_user_role($this->member,bbp_get_participant_role());wp_set_current_user($this->actor);
  $this->services=new Services();$this->services->register();$this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned reaction configuration forum','post_author'=>$this->actor]));$this->topic=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned reaction configuration source','post_content'=>'Owned public problem','post_parent'=>$this->forum,'post_author'=>$this->actor],['forum_id'=>$this->forum]));$this->reply=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned public helpful approach','post_parent'=>$this->topic,'post_author'=>$this->actor],['forum_id'=>$this->forum,'topic_id'=>$this->topic]));
 }
 private function remember(int $id):int{self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id;}
 protected function tearDown():void{
  update_option('fi_settings',['ai_enabled'=>false]);wp_set_current_user($this->actor);foreach(array_reverse($this->owned)as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$this->actor){throw new RuntimeException('Refusing foreign reaction fixture content.');}wp_delete_post($id,true);}
  if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->member);wp_delete_user($this->actor);wp_set_current_user(0);global $wp_filter;$wp_filter=$this->hooks;
 }
 private function frontend():ForumIntelligence\Frontend\Frontend{return new ForumIntelligence\Frontend\Frontend($this->services->operations,$this->services->solutions,$this->services->access,dirname(__DIR__,2).'/forum-intelligence-for-bbpress.php');}
 private function admin():ForumIntelligence\Admin\Admin{$admin=new ForumIntelligence\Admin\Admin($this->services,$this->frontend());$admin->register();return $admin;}
 private function render():string{
  wp_set_current_user($this->member);$filter=fn(int $id):int=>$this->reply;add_filter('bbp_get_reply_id',$filter,99);$hub=new ForumIntelligence\Frontend\Hub($this->services,$this->frontend());ob_start();try{$hub->engagement();return (string)ob_get_contents();}finally{ob_end_clean();remove_filter('bbp_get_reply_id',$filter,99);}
 }
 private function denied(callable $operation,int $status=400):void{try{$operation();self::fail('Disabled or malformed reactions must refuse a mutation.');}catch(Failure $failure){self::assertSame($status,$failure->status);}}
 public function testNativeSettingsExposeEveryEnabledReactionWithExplicitLabelsAndEmptySelection():void{
  update_option('fi_settings',['ai_enabled'=>false,'reactions'=>['thanks','helpful']]);$admin=$this->admin();require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/template.php';$method=new ReflectionMethod($admin,'settings');ob_start();try{$method->invoke($admin);$html=(string)ob_get_contents();}finally{ob_end_clean();}
  $dom=new DOMDocument();$errors=libxml_use_internal_errors(true);try{$dom->loadHTML('<?xml encoding="UTF-8">'.$html);}finally{libxml_clear_errors();libxml_use_internal_errors($errors);}$xpath=new DOMXPath($dom);
  self::assertSame(4,$xpath->query('//input[@type="checkbox" and @name="fi_settings[reactions][]"]')->length);
  foreach(['like','helpful','insightful','thanks']as $id){$nodes=$xpath->query('//input[@name="fi_settings[reactions][]" and @value="'.$id.'"]');self::assertSame(1,$nodes->length);$node=$nodes->item(0);self::assertNotSame('',$node->getAttribute('id'));self::assertSame(1,$xpath->query('//label[@for="'.$node->getAttribute('id').'"]')->length);self::assertSame(in_array($id,['helpful','thanks'],true),$node->hasAttribute('checked'));}
  $clean=$admin->sanitize(['ai_enabled'=>false,'reactions_present'=>1]);self::assertSame([],$clean['reactions']);self::assertArrayNotHasKey('reactions_present',$clean);
  $clean=$admin->sanitize(['reactions'=>['thanks','unknown','like','thanks',['nested']],'ai_enabled'=>false]);self::assertSame(['like','thanks'],$clean['reactions']);
 }
 public function testNativeFrontendReflectsOnlyCurrentEnabledReactionsAndKeepsHelpfulVotesSeparate():void{
  update_option('fi_settings',['ai_enabled'=>false,'reactions'=>['helpful','thanks']]);$html=$this->render();
  self::assertStringContainsString('value="reaction_helpful"',$html);self::assertStringContainsString('value="reaction_thanks"',$html);self::assertStringNotContainsString('value="reaction_like"',$html);self::assertStringNotContainsString('value="reaction_insightful"',$html);self::assertStringContainsString('value="vote"',$html);
  update_option('fi_settings',['ai_enabled'=>false,'reactions'=>[]]);$html=$this->render();self::assertStringNotContainsString('value="reaction_',$html);self::assertStringContainsString('value="vote"',$html);
 }
 public function testMalformedStoredReactionSelectionWithholdsControlsAndDoesNotCrashOrWriteSignals():void{
  wp_set_current_user($this->member);$service=$this->services->get('engagement');$s=$this->services->store;
  foreach(['like',false,123,['unknown']]as $selection){update_option('fi_settings',['ai_enabled'=>false,'reactions'=>$selection]);self::assertStringNotContainsString('value="reaction_',$this->render());$before=$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]);$this->denied(fn()=>$service->cast($this->reply,$this->member,'reaction_like',1));self::assertSame($before,$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));}
 }
 public function testDisabledOwnedReactionCanBeWithdrawnWithoutReenablingItOrChangingReputation():void{
  update_option('fi_settings',['ai_enabled'=>false,'reactions'=>['thanks']]);wp_set_current_user($this->member);$service=$this->services->get('engagement');$service->cast($this->reply,$this->member,'reaction_thanks',1);$s=$this->services->store;$ledger=$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]);
  update_option('fi_settings',['ai_enabled'=>false,'reactions'=>[]]);$html=$this->render();self::assertStringContainsString('value="reaction_thanks"',$html);self::assertStringContainsString('value="0"',$html);self::assertStringContainsString('Remove Thanks reaction',$html);
  $service->cast($this->reply,$this->member,'reaction_thanks',0);$row=$s->row("SELECT value FROM {$s->table('signals')} WHERE object_id=%d AND user_id=%d AND kind='reaction_thanks'",[$this->reply,$this->member]);self::assertSame(0,(int)$row['value']);$this->denied(fn()=>$service->cast($this->reply,$this->member,'reaction_thanks',1));self::assertSame($ledger,$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]));self::assertStringNotContainsString('value="reaction_',$this->render());
 }
 public function testReviewedConfigurationRoundTripsEmptySelectionAndPreservesPartialSelection():void{
  $admin=$this->admin();update_option('fi_settings',['ai_enabled'=>false,'reactions'=>['thanks']]);
  self::assertSame(['thanks'],$admin->sanitize(['ai_enabled'=>false])['reactions']);
  foreach([[],['like','helpful','insightful','thanks']]as $selection){$clean=$admin->sanitize(['ai_enabled'=>false,'reactions'=>$selection]);update_option('fi_settings',$clean);$config=$admin->exportConfiguration($this->actor);self::assertSame($selection,$config['settings']['reactions']);self::assertArrayNotHasKey('reactions_present',$config['settings']);unset($config['fields'],$config['templates'],$config['template_variants']);update_option('fi_settings',['reactions'=>['thanks']]);$this->services->operations->execute('config_import',['reviewed'=>true,'json'=>wp_json_encode($config)],$this->actor);self::assertSame($selection,get_option('fi_settings')['reactions']);}
  wp_set_current_user($this->member);$before=get_option('fi_settings');self::assertSame($before,$admin->sanitize(['reactions'=>['thanks']]));$this->denied(fn()=>$this->services->operations->execute('config_import',['reviewed'=>true,'json'=>wp_json_encode($config)],$this->member),403);self::assertSame($before,get_option('fi_settings'));
 }
 public function testDisabledUnownedUnknownAndOversizedSelectionsCannotCreateSignalHistory():void{
  $service=$this->services->get('engagement');$s=$this->services->store;wp_set_current_user($this->member);update_option('fi_settings',['reactions'=>[]]);
  foreach([['reaction_thanks',0],['reaction_unknown',0],['reaction_unknown',1],['like',1]]as [$kind,$value]){$this->denied(fn()=>$service->cast($this->reply,$this->member,$kind,$value));}
  update_option('fi_settings',['reactions'=>array_fill(0,17,'like')]);self::assertStringNotContainsString('value="reaction_',$this->render());$this->denied(fn()=>$service->cast($this->reply,$this->member,'reaction_like',1));self::assertSame([],$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));
 }
 public function testReactionPolicyRevokedDuringNativePermissionRenewalRollsBackAndClearsOptionCache():void{
  $service=$this->services->get('engagement');$s=$this->services->store;wp_set_current_user($this->member);$settings=['ai_enabled'=>false,'reactions'=>['like']];update_option('fi_settings',$settings);$reads=0;
  $filter=function(bool $allowed,int $id,int $user)use(&$reads):bool{if($id===$this->reply&&$user===$this->member&&++$reads===2){update_option('fi_settings',['ai_enabled'=>false,'reactions'=>[]]);}return $allowed;};add_filter('fi_can_read_object',$filter,99,3);try{$this->denied(fn()=>$service->cast($this->reply,$this->member,'reaction_like',1));}finally{remove_filter('fi_can_read_object',$filter,99);}
  self::assertGreaterThanOrEqual(2,$reads);self::assertSame([],$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));self::assertSame($settings,get_option('fi_settings'));
 }
 public function testBlockedMemberAndPasswordProtectedSourcesCannotWithdrawRetainedReactions():void{
  $service=$this->services->get('engagement');$s=$this->services->store;wp_set_current_user($this->member);update_option('fi_settings',['reactions'=>['thanks']]);$service->cast($this->reply,$this->member,'reaction_thanks',1);update_option('fi_settings',['reactions'=>[]]);$before=$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]);
  bbp_set_user_role($this->member,bbp_get_blocked_role());$this->denied(fn()=>$service->cast($this->reply,$this->member,'reaction_thanks',0),404);self::assertSame('',$this->render());bbp_set_user_role($this->member,bbp_get_participant_role());wp_update_post(['ID'=>$this->topic,'post_password'=>'owned-reaction-source-password']);$this->denied(fn()=>$service->cast($this->reply,$this->member,'reaction_thanks',0),404);self::assertSame('',$this->render());self::assertSame($before,$s->rows("SELECT * FROM {$s->table('signals')} WHERE object_id=%d",[$this->reply]));wp_update_post(['ID'=>$this->topic,'post_password'=>'']);$service->cast($this->reply,$this->member,'reaction_thanks',0);
 }
 public function testHelpfulReactionAwardsNoCreditsAndHelpfulVoteRetainsIndependentCredits():void{
  wp_set_current_user($this->member);update_option('fi_settings',['ai_enabled'=>false,'reactions'=>['helpful'],'vote_weight'=>2]);$service=$this->services->get('engagement');$s=$this->services->store;$before=$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]);$service->cast($this->reply,$this->member,'reaction_helpful',1);self::assertSame($before,$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]));$service->cast($this->reply,$this->member,'vote',1);$ledger=$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]);self::assertCount(1,$ledger);$payload=json_decode($ledger[0]['payload'],true);self::assertSame(2,$payload['points']);$service->cast($this->reply,$this->member,'reaction_helpful',0);self::assertSame($ledger,$s->rows("SELECT * FROM {$s->table('records')} WHERE kind='credit' AND object_id=%d",[$this->reply]));
 }
 public function testNativeSourceProtectedDuringPermissionCallbackCannotAcquireReaction():void{
  wp_set_current_user($this->member);update_option('fi_settings',['reactions'=>['like']]);$reads=0;$filter=function(bool $allowed,int $id,int $user)use(&$reads):bool{if($id===$this->reply&&$user===$this->member&&++$reads===2){wp_update_post(['ID'=>$this->topic,'post_password'=>'owned-inflight-password']);}return $allowed;};add_filter('fi_can_read_object',$filter,99,3);try{$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'reaction_like',1),404);}finally{remove_filter('fi_can_read_object',$filter,99);}
  self::assertSame([],$this->services->store->rows("SELECT * FROM {$this->services->store->table('signals')} WHERE object_id=%d",[$this->reply]));self::assertSame('',get_post($this->topic)->post_password);
 }
 public function testNativeActorRoleRevokedDuringSubmissionRefusesAndRestoresRolledBackRoleCache():void{
  wp_set_current_user($this->member);update_option('fi_settings',['reactions'=>['like']]);$reads=0;$filter=function(bool $allowed,int $id,int $user)use(&$reads):bool{if($id===$this->reply&&$user===$this->member&&++$reads===2){bbp_set_user_role($this->member,bbp_get_spectator_role());}return $allowed;};add_filter('fi_can_read_object',$filter,99,3);try{$this->denied(fn()=>$this->services->get('engagement')->cast($this->reply,$this->member,'reaction_like',1),403);}finally{remove_filter('fi_can_read_object',$filter,99);}
  self::assertSame([],$this->services->store->rows("SELECT * FROM {$this->services->store->table('signals')} WHERE object_id=%d",[$this->reply]));self::assertSame(bbp_get_participant_role(),bbp_get_user_role($this->member));self::assertTrue(user_can($this->member,'publish_replies'));
 }
 public function testConfiguredSelfReactionsAreVisibleAndRemainWithdrawableAfterSelfVotingIsDisabled():void{
  wp_update_post(['ID'=>$this->reply,'post_author'=>$this->member]);try{
   update_option('fi_settings',['reactions'=>['thanks'],'self_voting'=>true]);self::assertStringContainsString('value="reaction_thanks"',$this->render());$service=$this->services->get('engagement');$service->cast($this->reply,$this->member,'reaction_thanks',1);update_option('fi_settings',['reactions'=>['thanks'],'self_voting'=>false]);$html=$this->render();self::assertStringContainsString('Remove Thanks reaction',$html);self::assertStringNotContainsString('value="vote"',$html);self::assertStringNotContainsString('Helpful reaction',$html);$service->cast($this->reply,$this->member,'reaction_thanks',0);self::assertStringNotContainsString('value="reaction_',$this->render());$this->denied(fn()=>$service->cast($this->reply,$this->member,'reaction_thanks',1)); 
  }finally{wp_update_post(['ID'=>$this->reply,'post_author'=>$this->actor]);}
 }
}
