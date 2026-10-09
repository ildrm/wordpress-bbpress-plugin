<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
final class KnowledgeReviewNotificationTest extends TestCase {
    private Services $services;
    private int $actor;
    private int $editor;
    private int $forum;
    private int $topic;
    private int $reply;
    private int $article;
    private array $owned=[];
    private array $hooks=[];
    private mixed $settings;
    private object $missing;
    protected function setUp():void {
        global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);
        $this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);
        update_option('fi_settings',['ai_enabled'=>false,'knowledge_review_notifications'=>false]);
        $this->actor=(int)wp_insert_user(['user_login'=>'fi_review_actor_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-review-only','role'=>'administrator']);
        $this->editor=(int)wp_insert_user(['user_login'=>'fi_review_editor_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-editor-only','role'=>'editor']);
        self::assertGreaterThan(0,$this->actor);self::assertGreaterThan(0,$this->editor);
        bbp_set_user_role($this->actor,bbp_get_keymaster_role());bbp_set_user_role($this->editor,bbp_get_participant_role());wp_set_current_user($this->actor);
        $this->services=new Services();$this->services->register();
        $this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned article review forum','post_author'=>$this->actor]));
        $this->topic=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned source question','post_content'=>'Owned source context','post_author'=>$this->actor,'post_parent'=>$this->forum],['forum_id'=>$this->forum]));
        $this->reply=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned original source approach','post_author'=>$this->actor,'post_parent'=>$this->topic],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));
        $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','OWNED_ARTICLE_REVIEW_BODY_CANARY',$this->actor);
        $this->article=$this->remember((int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id']);
        wp_update_post(['ID'=>$this->article,'post_author'=>$this->editor]);
        self::assertTrue(user_can($this->editor,'edit_post',$this->article));
    }
    protected function tearDown():void {
        update_option('fi_settings',['ai_enabled'=>false,'knowledge_review_notifications'=>false]);wp_set_current_user($this->actor);
        foreach(array_reverse($this->owned) as $id){wp_delete_post($id,true);}
        $s=$this->services->store;
        $s->execute("DELETE FROM {$s->table('records')} WHERE record_key=%s",['summary:'.$this->topic]);
        $s->execute("DELETE FROM {$s->table('jobs')} WHERE kind='knowledge_review' AND job_key LIKE %s",['knowledge_review:'.$this->topic.':%']);
        $s->execute("DELETE FROM {$s->table('notifications')} WHERE user_id IN (%d,%d)",[$this->actor,$this->editor]);
        if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}
        require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->editor);wp_delete_user($this->actor);wp_set_current_user(0);
        global $wp_filter;$wp_filter=$this->hooks;
    }
    private function remember(int $id):int{self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id;}
    private function enable():void{update_option('fi_settings',['ai_enabled'=>false,'knowledge_review_notifications'=>true]);}
    private function change():void{wp_update_post(['ID'=>$this->reply,'post_content'=>'Changed review source '.bin2hex(random_bytes(5))]);}
    private function job():?array{return $this->services->store->row("SELECT * FROM {$this->services->store->table('jobs')} WHERE kind='knowledge_review' AND job_key=%s",['knowledge_review:'.$this->topic.':0']);}
    private function processAlert():void{
        $s=$this->services->store;
        $s->execute("UPDATE {$s->table('jobs')} SET available_at=%s WHERE kind='knowledge_review' AND job_key LIKE %s",['2000-01-01 00:00:00','knowledge_review:'.$this->topic.':%']);
        $this->services->queue->run(1);
    }
    private function notices():array{return $this->services->store->rows("SELECT * FROM {$this->services->store->table('notifications')} WHERE user_id=%d AND kind='knowledge_review'",[$this->editor]);}
    public function testNativeSourceChangeQueuesGenericOwnedEditorReviewAndVerifiedArticleClearsVisibility():void {
        $this->change();self::assertNull($this->job());self::assertSame([],$this->notices());
        $this->enable();$this->change();self::assertNotNull($this->job());self::assertSame([],$this->notices());
        self::assertSame(['topic'=>$this->topic,'cursor'=>0],json_decode($this->job()['payload'],true));
        $this->processAlert();$rows=$this->notices();self::assertCount(1,$rows);self::assertSame($this->article,(int)$rows[0]['object_id']);
        self::assertStringNotContainsString('OWNED_ARTICLE_REVIEW_BODY_CANARY',json_encode($rows));
        $notify=$this->services->get('notifications');$inbox=$notify->inbox($this->editor,1,false,'knowledge_review')['items'];self::assertCount(1,$inbox);
        self::assertSame('Knowledge article needs source review',$inbox[0]['title']);
        self::assertStringContainsString('post='.$this->article,$inbox[0]['url']);self::assertStringContainsString('action=edit',$inbox[0]['url']);
        (new ForumIntelligence\Domain\Knowledge\ReviewNotifications($this->services->store,$this->services->queue,$notify))->request($this->topic);
        $this->processAlert();self::assertCount(1,$this->notices());
        wp_set_current_user($this->editor);$this->services->get('knowledge')->verifyArticle($this->article,$this->editor);
        self::assertSame([],$notify->inbox($this->editor,1,false,'knowledge_review')['items']);
        self::assertSame(0,$notify->summary($this->editor)['unread']);
        self::assertStringContainsString('OWNED_ARTICLE_REVIEW_BODY_CANARY',get_post($this->article)->post_content);
    }
    public function testDelayedEditorAlertRenewsSourceOwnerAndPreferences():void {
        $this->enable();$this->change();self::assertNotNull($this->job());
        wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-delayed-source-password']);$this->processAlert();self::assertSame([],$this->notices());
        wp_update_post(['ID'=>$this->reply,'post_password'=>'']);$this->services->get('notifications')->savePreferences($this->editor,'off',['knowledge_review']);
        $this->change();$this->processAlert();self::assertSame([],$this->notices());
        $this->services->get('notifications')->savePreferences($this->editor,'off',[]);$this->change();
        wp_update_post(['ID'=>$this->article,'post_author'=>$this->actor]);$this->processAlert();self::assertSame([],$this->notices());
    }

    public function testNativeCallbackRevokesEditorAlertPolicyBeforeCreation():void {
        $this->enable();$this->change();$revoked=false;
        add_filter('fi_can_read_object',function(bool $allowed,int $object,int $user)use(&$revoked):bool{
            if(!$revoked&&$object===$this->topic&&$user===$this->editor){$revoked=true;update_option('fi_settings',['knowledge_review_notifications'=>false,'ai_enabled'=>false]);}
            return $allowed;
        },10,3);
        $this->processAlert();self::assertTrue($revoked);self::assertSame([],$this->notices());
    }
    public function testPersistedReviewAlertWithholdsCountsAndLinksAfterPrivacyOrOwnershipChanges():void {
        $this->enable();$this->change();$this->processAlert();self::assertCount(1,$this->notices());
        $notifications=$this->services->get('notifications');self::assertSame(1,$notifications->summary($this->editor)['unread']);
        wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-current-alert-source-password']);
        self::assertSame([],$notifications->inbox($this->editor,1,false,'knowledge_review')['items']);self::assertSame(0,$notifications->summary($this->editor)['unread']);
        wp_update_post(['ID'=>$this->reply,'post_password'=>'']);self::assertCount(1,$notifications->inbox($this->editor,1,false,'knowledge_review')['items']);
        wp_update_post(['ID'=>$this->article,'post_author'=>$this->actor]);
        self::assertSame([],$notifications->inbox($this->editor,1,false,'knowledge_review')['items']);self::assertSame(0,$notifications->summary($this->editor)['unread']);
    }
    public function testNativeEditorFanoutUsesBoundedKeysetContinuation():void {
        for($i=0;$i<25;++$i){
            $id=$this->remember((int)$this->services->get('knowledge')->command($this->topic,'draft','',$this->actor)['article_id']);
            wp_update_post(['ID'=>$id,'post_author'=>$this->editor]);
        }
        $this->enable();$this->change();$this->processAlert();self::assertCount(25,$this->notices());
        $this->processAlert();self::assertCount(26,$this->notices());
        self::assertCount(26,array_unique(array_column($this->notices(),'object_id')));
        foreach($this->notices() as $row){self::assertSame($this->editor,(int)$row['user_id']);self::assertStringNotContainsString('OWNED_ARTICLE_REVIEW_BODY_CANARY',json_encode($row));}
    }

    public function testQueuedEditorEmailRenewsVisibilityAndContainsOnlyGenericNativeEditorLinks():void {
        wp_update_user(['ID'=>$this->editor,'user_email'=>'owned_review_mail_'.bin2hex(random_bytes(5)).'@example.test']);
        $notifications=$this->services->get('notifications');$notifications->savePreferences($this->editor,'immediate',[]);
        $sent=[];add_filter('pre_wp_mail',static function($result,array $mail)use(&$sent){$sent[]=$mail;return true;},10,2);
        $this->enable();$this->change();$this->processAlert();
        wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-current-mail-source-password']);
        $store=$this->services->store;
        $force=fn()=>$store->execute("UPDATE {$store->table('jobs')} SET available_at=%s WHERE kind='digest' AND payload=%s",['2000-01-01 00:00:00',wp_json_encode(['user'=>$this->editor])]);
        $force();$this->services->queue->run(1);self::assertSame([],$sent);
        wp_update_post(['ID'=>$this->reply,'post_password'=>'']);$this->change();$this->processAlert();$force();$this->services->queue->run(1);
        self::assertCount(1,$sent);self::assertStringContainsString('post='.$this->article,$sent[0]['message']);
        self::assertStringContainsString('action=edit',$sent[0]['message']);
        self::assertStringNotContainsString('OWNED_ARTICLE_REVIEW_BODY_CANARY',$sent[0]['message']);
        self::assertStringNotContainsString('Owned source question',$sent[0]['message']);
        self::assertFalse(ForumIntelligence\Domain\Notifications\Notifications::important('knowledge_review'));
    }
}
