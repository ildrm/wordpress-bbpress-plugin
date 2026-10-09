<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Domain\Failure;
use ForumIntelligence\Domain\Search\Duplicates;
use ForumIntelligence\Infrastructure\Search\LexicalSearch;
final class DuplicateFeedbackTest extends TestCase {
 private Services $services;private Duplicates $duplicates;private int $actor;private int $member;private int $forum;private int $topic;private array $owned=[];private array $hooks=[];private mixed $settings;private object $missing;private array $offers=[];
 protected function setUp():void{
  global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);$this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);update_option('fi_settings',['ai_enabled'=>false,'duplicate_threshold'=>0.25]);add_filter('pre_wp_mail',static fn()=>true);
  $this->actor=(int)wp_insert_user(['user_login'=>'fi_duplicates98_admin_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'administrator']);$this->member=(int)wp_insert_user(['user_login'=>'fi_duplicates98_member_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-only','role'=>'subscriber']);self::assertGreaterThan(0,$this->actor);self::assertGreaterThan(0,$this->member);bbp_set_user_role($this->actor,bbp_get_keymaster_role());bbp_set_user_role($this->member,bbp_get_participant_role());wp_set_current_user($this->actor);
  $this->services=new Services();$this->services->register();$this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned duplicate feedback forum','post_author'=>$this->actor]));$this->topic=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned duplicate browser cache invalidation','post_content'=>'Browser cache invalidation fix and existing solution.','post_parent'=>$this->forum,'post_author'=>$this->actor],['forum_id'=>$this->forum]));$this->duplicates=new Duplicates(new LexicalSearch($this->services->store,$this->services->access,$this->services->solutions),$this->services->store,$this->services->access);wp_set_current_user($this->member);
 }
 private function remember(int $id):int{self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id;}
 protected function tearDown():void{
  $s=$this->services->store;foreach($this->offers as $offer){foreach(['viewed','opened','dismissed']as $outcome){$s->execute("DELETE FROM {$s->table('limits')} WHERE bucket=%s",[hash('sha256','duplicate_feedback:'.$offer.':'.$outcome)]);}}
  foreach(['duplicates','duplicate_feedback']as $feature){$s->execute("DELETE FROM {$s->table('limits')} WHERE bucket=%s",[hash('sha256',$this->member.':'.$feature)]);}
  wp_set_current_user($this->actor);foreach(array_reverse($this->owned)as $id){$post=get_post($id);if($post&&(int)$post->post_author!==$this->actor)throw new RuntimeException('Refusing foreign duplicate fixture');wp_delete_post($id,true);}if($this->settings===$this->missing)delete_option('fi_settings');else update_option('fi_settings',$this->settings);require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->member);wp_delete_user($this->actor);wp_set_current_user(0);global $wp_filter;$wp_filter=$this->hooks;
 }
 private function offer():string{$result=$this->duplicates->suggest('Owned duplicate browser cache invalidation','UNSAVED_DUPLICATE_DRAFT_CANARY',$this->forum,'',[],$this->member);self::assertNotEmpty($result['items']);self::assertNotEmpty($result['offer']??'','Authenticated native suggestions must issue a bounded feedback offer.');$this->offers[]=$result['offer'];return $result['offer'];}
 private function metric(string $outcome):int{$s=$this->services->store;return(int)($s->row("SELECT count FROM {$s->table('metrics')} WHERE day=%s AND kind=%s",[gmdate('Y-m-d'),'duplicate_feedback_'.$outcome])['count']??0);}
 private function denied(callable $f,int $status):void{try{$f();self::fail('Invalid duplicate feedback must refuse.');}catch(Failure $e){self::assertSame($status,$e->status);}}
 public function testNativeOfferRecordsOnlyAggregateDismissalAndDeduplicatesReplay():void{
  $offer=$this->offer();$before=$this->metric('dismissed');self::assertSame(['recorded'=>true],$this->duplicates->feedback($offer,'dismissed',$this->member));self::assertSame(['recorded'=>false],$this->duplicates->feedback($offer,'dismissed',$this->member));self::assertSame($before+1,$this->metric('dismissed'));$s=$this->services->store;$claim=$s->row("SELECT * FROM {$s->table('limits')} WHERE bucket=%s",[hash('sha256','duplicate_feedback:'.$offer.':dismissed')]);self::assertNotNull($claim);self::assertSame(1,(int)$claim['count']);self::assertStringNotContainsString('UNSAVED_DUPLICATE_DRAFT_CANARY',wp_json_encode($claim));self::assertSame(['bucket','window_start','count'],array_keys($claim));
 }
 public function testOfferCannotTransferToAnotherActorOrAcceptUnissuedFeedback():void{
  $offer=$this->offer();$before=$this->metric('opened');$this->denied(fn()=>$this->duplicates->feedback($offer,'opened',$this->actor),400);$this->denied(fn()=>$this->duplicates->feedback($offer.'x','opened',$this->member),400);$this->denied(fn()=>$this->duplicates->feedback(str_repeat('x',5000),'opened',$this->member),400);$this->denied(fn()=>$this->duplicates->feedback($offer,'invented_success',$this->member),400);self::assertSame($before,$this->metric('opened'));
 }
 public function testRestrictedSourceRevocationWithholdsFeedbackAndKeepsCounters():void{
  $offer=$this->offer();$before=$this->metric('dismissed');wp_update_post(['ID'=>$this->topic,'post_password'=>'owned-duplicate-private']);$this->denied(fn()=>$this->duplicates->feedback($offer,'dismissed',$this->member),404);self::assertSame($before,$this->metric('dismissed'));
 }
 public function testEachAcknowledgedOutcomeCountsOnceAndOpenedRequiresAnOfferedTopic():void{
  $offer=$this->offer();foreach(['viewed','opened','dismissed']as $outcome){$before=$this->metric($outcome);$id=$outcome==='opened'?$this->topic:0;self::assertTrue($this->duplicates->feedback($offer,$outcome,$this->member,$id)['recorded']);self::assertFalse($this->duplicates->feedback($offer,$outcome,$this->member,$id)['recorded']);self::assertSame($before+1,$this->metric($outcome));}
  $this->denied(fn()=>$this->duplicates->feedback($offer,'opened',$this->member,$this->forum),400);
 }
 private function signed(array $data):string{$encoded=rtrim(strtr(base64_encode(wp_json_encode($data)),'+/','-_'),'=');return$encoded.'.'.hash_hmac('sha256',$encoded,wp_salt('nonce'));}
 private function decoded(string $offer):array{return json_decode(base64_decode(strtr(explode('.',$offer)[0],'-_','+/')),true);}
 public function testExpiredFutureMalformedAndCrossSiteSignedOffersCannotRecord():void{
  $offer=$this->offer();$data=$this->decoded($offer);$before=$this->metric('viewed');foreach([['issued'=>time()-901],['issued'=>time()+60],['site'=>get_current_blog_id()+1],['ids'=>[$this->topic,(string)$this->topic]],['ids'=>[$this->topic,$this->topic]],['ids'=>[]],['ids'=>array_fill(0,6,$this->topic)],['nonce'=>'invalid'],['version'=>2]]as $change){$this->denied(fn()=>$this->duplicates->feedback($this->signed(array_replace($data,$change)),'viewed',$this->member),400);}self::assertSame($before,$this->metric('viewed'));
 }
 public function testCurrentActorAndComposerRevocationRefuseAnExistingOffer():void{
  $offer=$this->offer();$before=$this->metric('dismissed');bbp_set_user_role($this->member,bbp_get_blocked_role());$this->denied(fn()=>$this->duplicates->feedback($offer,'dismissed',$this->member),403);bbp_set_user_role($this->member,bbp_get_participant_role());update_option('fi_settings',['ai_enabled'=>false,'modules'=>['composer'=>false]]);$this->denied(fn()=>$this->duplicates->feedback($offer,'dismissed',$this->member),403);self::assertSame($before,$this->metric('dismissed'));
 }
 public function testClosedForumAndMovedSuggestionInvalidateFeedback():void{
  $offer=$this->offer();bbp_close_forum($this->forum);$this->denied(fn()=>$this->duplicates->feedback($offer,'dismissed',$this->member),403);bbp_open_forum($this->forum);
  wp_set_current_user($this->actor);$other=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned duplicate move target','post_author'=>$this->actor]));wp_update_post(['ID'=>$this->topic,'post_parent'=>$other]);update_post_meta($this->topic,'_bbp_forum_id',$other);wp_set_current_user($this->member);$this->denied(fn()=>$this->duplicates->feedback($offer,'dismissed',$this->member),404);
 }
 public function testGuestShortAndEmptyResultsDoNotIssueAttributableOffers():void{
  wp_set_current_user(0);$result=$this->duplicates->suggest('Owned duplicate browser cache invalidation','',$this->forum,'',[],0);self::assertNotEmpty($result['items']);self::assertSame('',$result['offer']);self::assertSame([],$this->duplicates->suggest('short','',$this->forum,'',[],$this->member)['items']);$result=$this->duplicates->suggest('Unmatched aardvark quantum unrelated','',$this->forum,'',[],$this->member);self::assertSame([],$result['items']);self::assertSame('',$result['offer']);
 }
 public function testMetricFailureRollsBackClaimAndAllowsOneRealRetry():void{
  $offer=$this->offer();$before=$this->metric('dismissed');$filter=function(string $sql):string{if(str_contains($sql,'duplicate_feedback_dismissed')&&str_starts_with($sql,'INSERT INTO'))throw new Failure('owned_feedback_metric_failure','Owned native metric failure',503);return$sql;};add_filter('query',$filter,99);try{$this->denied(fn()=>$this->duplicates->feedback($offer,'dismissed',$this->member),503);}finally{remove_filter('query',$filter,99);}
  $s=$this->services->store;self::assertNull($s->row("SELECT * FROM {$s->table('limits')} WHERE bucket=%s",[hash('sha256','duplicate_feedback:'.$offer.':dismissed')]));self::assertSame($before,$this->metric('dismissed'));self::assertTrue($this->duplicates->feedback($offer,'dismissed',$this->member)['recorded']);self::assertSame($before+1,$this->metric('dismissed'));
 }
 public function testRegisteredFeedbackOperationUsesNativeRestAuthenticationAndBounds():void{
  do_action('rest_api_init');wp_set_current_user(0);$request=new WP_REST_Request('POST','/forum-intelligence/v1/actions/duplicate_feedback');$request->set_param('data',['offer'=>'invalid','outcome'=>'dismissed']);$response=rest_do_request($request);self::assertSame(401,$response->get_status());wp_set_current_user($this->member);$offer=$this->offer();$request=new WP_REST_Request('POST','/forum-intelligence/v1/actions/duplicate_feedback');$request->set_param('data',['offer'=>$offer,'outcome'=>'viewed']);$response=rest_do_request($request);self::assertSame(200,$response->get_status());self::assertSame(['recorded'=>true],$response->get_data());self::assertSame('private, no-store',$response->get_headers()['Cache-Control']);
 }

 public function testFeedbackQuotaRejectsWithoutAnOutcomeClaim():void{
  $offer=$this->offer();$s=$this->services->store;$before=$this->metric('viewed');$bucket=hash('sha256',$this->member.':duplicate_feedback');$window=intdiv(time(),60)*60;foreach([$window,$window+60]as $start){$s->execute("INSERT INTO {$s->table('limits')} (bucket,window_start,count) VALUES (%s,%d,90)",[$bucket,$start]);}$this->denied(fn()=>$this->duplicates->feedback($offer,'viewed',$this->member),429);self::assertSame($before,$this->metric('viewed'));self::assertNull($s->row("SELECT * FROM {$s->table('limits')} WHERE bucket=%s",[hash('sha256','duplicate_feedback:'.$offer.':viewed')]));
 }

}
