<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
final class KnowledgeSourceComparisonTest extends TestCase {
    private Services $services;
    private int $actor;
    private int $editor;
    private int $forum;
    private int $topic;
    private int $reply;
    private int $article;
    private int $baseline;
    private array $owned=[];
    private array $hooks=[];
    private mixed $settings;
    private object $missing;
    protected function setUp():void {
        global $wp_filter;$this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);
        $this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);
        update_option('fi_settings',['ai_enabled'=>false,'knowledge_review_notifications'=>false]);
        $this->actor=(int)wp_insert_user(['user_login'=>'fi_compare_actor_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-review-only','role'=>'administrator']);
        $this->editor=(int)wp_insert_user(['user_login'=>'fi_compare_editor_'.bin2hex(random_bytes(8)),'user_pass'=>'owned-editor-only','role'=>'editor']);
        self::assertGreaterThan(0,$this->actor);self::assertGreaterThan(0,$this->editor);
        bbp_set_user_role($this->actor,bbp_get_keymaster_role());bbp_set_user_role($this->editor,bbp_get_participant_role());wp_set_current_user($this->actor);
        $this->services=new Services();$this->services->register();
        $this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned article review forum','post_author'=>$this->actor]));
        $this->topic=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned source question','post_content'=>'Owned source context','post_author'=>$this->actor,'post_parent'=>$this->forum],['forum_id'=>$this->forum]));
        $this->reply=$this->remember((int)bbp_insert_reply(['post_content'=>'Owned original source approach','post_author'=>$this->actor,'post_parent'=>$this->topic],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));
        wp_update_post(wp_slash(['ID'=>$this->reply,'post_content'=>"OLD_SOURCE_COMPARE_CANARY فارسی C:\\\\owned\\\\path <b>before</b>\nA recorded original approach"]));
        wp_save_post_revision($this->reply);$revisions=wp_get_post_revisions($this->reply);self::assertNotEmpty($revisions);$this->baseline=(int)array_key_first($revisions);
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
    public function testExactRecordedRevisionMatchesCurrentTextAndNativeComparisonRights():void {
        $knowledge=$this->services->get('knowledge');$provenance=get_post_meta($this->article,'_fi_provenance',true);
        self::assertArrayHasKey('source_text_hashes',$provenance);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$provenance['source_text_hashes'][$this->reply]);
        $body=get_post($this->article)->post_content;
        wp_update_post(wp_slash(['ID'=>$this->reply,'post_content'=>"NEW_SOURCE_COMPARE_CANARY فارسی C:\\owned\\path <b>after</b>\nA changed solution approach"]));wp_save_post_revision($this->reply);
        $result=$knowledge->comparison($this->article,$this->actor);self::assertTrue($result['stale']);self::assertCount(1,$result['changes']);
        $change=$result['changes'][0];self::assertSame($this->reply,$change['source_id']);self::assertSame('text_changed',$change['kind']);self::assertTrue($change['baseline_available']);
        parse_str((string)parse_url($change['revision_url'],PHP_URL_QUERY),$query);self::assertSame((string)$this->baseline,$query['from']);
        $current=(int)$query['revision'];self::assertSame($this->reply,(int)get_post($current)->post_parent);
        require_once ABSPATH.'wp-admin/includes/revision.php';$diff=wp_get_revision_ui_diff($this->reply,$this->baseline,$current);
        self::assertStringContainsString('OLD_SOURCE_COMPARE_CANARY',wp_json_encode($diff));self::assertStringContainsString('NEW_SOURCE_COMPARE_CANARY',wp_json_encode($diff));
        self::assertSame($body,get_post($this->article)->post_content);
        $knowledge->verifyArticle($this->article,$this->actor);self::assertSame([],$knowledge->comparison($this->article,$this->actor)['changes']);
    }
    public function testLegacyBaselineNeverInventsAnExactTextComparison():void {
        $provenance=get_post_meta($this->article,'_fi_provenance',true);unset($provenance['source_text_hashes']);update_post_meta($this->article,'_fi_provenance',wp_slash($provenance));
        wp_update_post(['ID'=>$this->reply,'post_content'=>'A changed source without an exact saved text baseline']);
        $result=$this->services->get('knowledge')->comparison($this->article,$this->actor);
        self::assertCount(1,$result['changes']);self::assertFalse($result['changes'][0]['baseline_available']);self::assertNull($result['changes'][0]['revision_url']);
    }
    public function testNativeSourceEditingAndCurrentPrivacyAreRequiredForRevisionLinks():void {
        wp_update_post(['ID'=>$this->reply,'post_content'=>'A currently public changed approach']);
        self::assertFalse(user_can($this->editor,'edit_post',$this->reply));
        $result=$this->services->get('knowledge')->comparison($this->article,$this->editor);
        self::assertCount(1,$result['changes']);self::assertNull($result['changes'][0]['revision_url']);
        wp_update_post(['ID'=>$this->reply,'post_password'=>'owned-comparison-source-password']);
        try{$this->services->get('knowledge')->comparison($this->article,$this->actor);self::fail('Restricted sources must withhold comparison.');}catch(ForumIntelligence\Domain\Failure $failure){self::assertContains($failure->status,[403,404]);}
        ob_start();(new ForumIntelligence\Domain\Knowledge\Editorial($this->services->get('knowledge')))->box(get_post($this->article));$html=ob_get_clean();
        self::assertStringNotContainsString('OLD_SOURCE_COMPARE_CANARY',$html);self::assertStringNotContainsString('NEW_SOURCE_COMPARE_CANARY',$html);self::assertStringNotContainsString('revision.php',$html);
    }

    public function testLateNativeSourceEditingRevocationRemovesRevisionPointers():void {
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned current text requiring a revision comparison']);wp_save_post_revision($this->reply);
        $revoked=false;
        add_filter('query',static function(string $sql)use(&$revoked):string {if(str_contains($sql,'AS bounded_revisions')){$revoked=true;}return $sql;});
        add_filter('map_meta_cap',function(array $caps,string $cap,int $user,array $args)use(&$revoked):array {return $revoked&&in_array($cap,['edit_post','edit_reply'],true)&&$user===$this->actor&&(int)($args[0]??0)===$this->reply?['do_not_allow']:$caps;},999,4);
        $result=$this->services->get('knowledge')->comparison($this->article,$this->actor);
        self::assertTrue($revoked);self::assertFalse(user_can($this->actor,'edit_post',$this->reply));self::assertCount(1,$result['changes']);self::assertNull($result['changes'][0]['revision_url']);self::assertFalse($result['changes'][0]['baseline_available']);
    }
    public function testConcurrentNativeProvenanceRenewalRejectsAStaleComparison():void {
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned current source for provenance race']);wp_save_post_revision($this->reply);
        $changed=false;
        add_filter('query',function(string $sql)use(&$changed):string {if(!$changed&&str_contains($sql,'AS bounded_revisions')){$changed=true;$data=get_post_meta($this->article,'_fi_provenance',true);unset($data['source_text_hashes']);update_post_meta($this->article,'_fi_provenance',wp_slash($data));}return $sql;});
        try{$this->services->get('knowledge')->comparison($this->article,$this->actor);self::fail('Concurrent provenance changes must reject the stale comparison.');}catch(ForumIntelligence\Domain\Failure $failure){self::assertSame(409,$failure->status);}
        self::assertTrue($changed);
    }
    public function testLateNativeSourceMutationRejectsComparisonBeforeReturningPointers():void {
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned current source before query callback']);wp_save_post_revision($this->reply);
        $changed=false;
        add_filter('query',function(string $sql)use(&$changed):string {if(!$changed&&str_contains($sql,'AS bounded_revisions')){$changed=true;wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned source mutated during comparison']);}return $sql;});
        try{$this->services->get('knowledge')->comparison($this->article,$this->actor);self::fail('Concurrent source changes must reject revision pointers.');}catch(ForumIntelligence\Domain\Failure $failure){self::assertSame(409,$failure->status);}
        self::assertTrue($changed);
    }
    public function testExcerptChangesWithinTheSameNativeModifiedSecondAreDetected():void {
        global $wpdb;$before=get_post($this->reply);
        wp_update_post(['ID'=>$this->reply,'post_excerpt'=>'Owned changed excerpt only']);
        $wpdb->update($wpdb->posts,['post_modified'=>$before->post_modified,'post_modified_gmt'=>$before->post_modified_gmt],['ID'=>$this->reply]);clean_post_cache($this->reply);wp_save_post_revision($this->reply);
        $result=$this->services->get('knowledge')->comparison($this->article,$this->actor);
        self::assertTrue($result['stale']);self::assertCount(1,$result['changes']);self::assertSame('text_changed',$result['changes'][0]['kind']);self::assertTrue($result['changes'][0]['baseline_available']);
    }
    public function testRevisionLookupBoundsAndPrunedHistoryHaveAnExplicitFallback():void {
        for($i=0;$i<21;++$i){wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned changed revision '.$i]);wp_save_post_revision($this->reply);}
        $result=$this->services->get('knowledge')->comparison($this->article,$this->actor);
        self::assertCount(1,$result['changes']);self::assertSame('text_changed',$result['changes'][0]['kind']);self::assertNull($result['changes'][0]['revision_url']);self::assertFalse($result['changes'][0]['baseline_available']);
    }
    public function testAutosaveIsNeverAnExactRecordedBaseline():void {
        $baseline=get_post($this->baseline);
        $this->remember((int)wp_insert_post(wp_slash(['post_type'=>'revision','post_status'=>'inherit','post_parent'=>$this->reply,'post_name'=>$this->reply.'-autosave-v1','post_author'=>$this->actor,'post_title'=>$baseline->post_title,'post_content'=>$baseline->post_content,'post_excerpt'=>$baseline->post_excerpt])));
        wp_delete_post_revision($this->baseline);
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned current source after original revision removal']);wp_save_post_revision($this->reply);
        $result=$this->services->get('knowledge')->comparison($this->article,$this->actor);
        self::assertCount(1,$result['changes']);self::assertNull($result['changes'][0]['revision_url']);self::assertFalse($result['changes'][0]['baseline_available']);
    }
    public function testChangedSourcesAndRevisionQueriesAreBoundedAndNeverCopyBodies():void {
        $sources=[$this->reply];
        for($i=0;$i<20;++$i){$sources[]=$this->remember((int)bbp_insert_reply(['post_content'=>'OWNED_COMPARE_BOUND_ORIGINAL_'.$i,'post_author'=>$this->actor,'post_parent'=>$this->topic],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));wp_save_post_revision(end($sources));}
        $knowledge=$this->services->get('knowledge');$knowledge->verifyArticle($this->article,$this->actor);
        foreach($sources as $i=>$source){wp_update_post(['ID'=>$source,'post_content'=>'OWNED_COMPARE_BOUND_CURRENT_'.$i]);wp_save_post_revision($source);}
        $queries=0;add_filter('query',static function(string $sql)use(&$queries):string{if(str_contains($sql,'AS bounded_revisions')){++$queries;}return $sql;});
        $result=$knowledge->comparison($this->article,$this->actor);
        self::assertCount(20,$result['changes']);self::assertSame(1,$result['remaining']);self::assertSame(10,$queries);
        $serialized=wp_json_encode($result);self::assertStringNotContainsString('OWNED_COMPARE_BOUND_',$serialized);self::assertStringNotContainsString('OWNED_ARTICLE_REVIEW_BODY_CANARY',$serialized);
        ob_start();(new ForumIntelligence\Domain\Knowledge\Editorial($knowledge))->box(get_post($this->article));$html=ob_get_clean();
        self::assertStringContainsString('More sources changed.',$html);self::assertStringNotContainsString('OWNED_COMPARE_BOUND_',$html);
    }

    public function testAcceptedSelectionAndMetadataChangesAreDistinguishedFromSourceText():void {
        $knowledge=$this->services->get('knowledge');
        $this->services->solutions->change($this->topic,$this->reply,'accept',$this->actor);
        $result=$knowledge->comparison($this->article,$this->actor);self::assertTrue($result['solution_changed']);self::assertSame([],$result['changes']);self::assertTrue($result['stale']);
        $knowledge->verifyArticle($this->article,$this->actor);
        global $wpdb;$wpdb->update($wpdb->posts,['post_modified_gmt'=>'2001-01-01 01:02:03'],['ID'=>$this->reply]);clean_post_cache($this->reply);
        $result=$knowledge->comparison($this->article,$this->actor);self::assertFalse($result['solution_changed']);self::assertCount(1,$result['changes']);self::assertSame('metadata_changed',$result['changes'][0]['kind']);self::assertNull($result['changes'][0]['revision_url']);
    }
    public function testWindowChangesAndEditsOutsideTheCurrentWindowDoNotInventTextDiffs():void {
        for($i=0;$i<51;++$i){$this->remember((int)bbp_insert_reply(['post_content'=>'Owned later window reply '.$i,'post_author'=>$this->actor,'post_parent'=>$this->topic],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));}
        $knowledge=$this->services->get('knowledge');$result=$knowledge->comparison($this->article,$this->actor);
        self::assertCount(20,$result['changes']);self::assertSame(31,$result['remaining']);self::assertSame($this->reply,$result['changes'][0]['source_id']);self::assertSame('left_window',$result['changes'][0]['kind']);self::assertSame('added',$result['changes'][1]['kind']);
        $knowledge->verifyArticle($this->article,$this->actor);
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned changed original reply outside the recent window']);
        $result=$knowledge->comparison($this->article,$this->actor);self::assertTrue($result['stale']);self::assertTrue($result['outside_window']);self::assertSame([],$result['changes']);self::assertFalse($result['solution_changed']);
    }
    public function testDisabledNativeRevisionsHaveNoFabricatedComparisonLink():void {
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned changed source when revisions are disabled']);wp_save_post_revision($this->reply);
        add_filter('wp_revisions_to_keep',fn(int $count,WP_Post $post):int=>$post->ID===$this->reply?0:$count,20,2);
        $queries=0;add_filter('query',static function(string $sql)use(&$queries):string{if(str_contains($sql,'AS bounded_revisions')){++$queries;}return $sql;});
        $result=$this->services->get('knowledge')->comparison($this->article,$this->actor);
        self::assertCount(1,$result['changes']);self::assertNull($result['changes'][0]['revision_url']);self::assertFalse($result['changes'][0]['baseline_available']);self::assertSame(0,$queries);
    }

    public function testNativeSourceDeletionDuringRevisionPermissionCheckFailsClosed():void {
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned changed source before deletion callback']);wp_save_post_revision($this->reply);
        $deleted=false;
        add_filter('map_meta_cap',function(array $caps,string $cap,int $user,array $args)use(&$deleted):array{if(!$deleted&&in_array($cap,['edit_post','edit_reply'],true)&&$user===$this->actor&&(int)($args[0]??0)===$this->reply){$deleted=true;wp_delete_post($this->reply,true);}return $caps;},999,4);
        try{$this->services->get('knowledge')->comparison($this->article,$this->actor);self::fail('Deleted source must withhold comparison.');}catch(ForumIntelligence\Domain\Failure $failure){self::assertContains($failure->status,[403,404,409]);}
        self::assertTrue($deleted);self::assertNull(get_post($this->reply));
    }
    public function testUnavailableNativeRevisionDigestsHaveAnExactHistoryFallback():void {
        wp_update_post(['ID'=>$this->reply,'post_content'=>'Owned changed source with unavailable native digest']);wp_save_post_revision($this->reply);
        add_filter('query',static function(string $sql):string {return str_contains($sql,'AS bounded_revisions')?str_replace("SHA2(CONCAT(LENGTH(post_title),':',post_title,LENGTH(post_content),':',post_content,LENGTH(post_excerpt),':',post_excerpt),256)",'NULL',$sql):$sql;});
        $result=$this->services->get('knowledge')->comparison($this->article,$this->actor);
        self::assertCount(1,$result['changes']);self::assertSame('text_changed',$result['changes'][0]['kind']);self::assertNull($result['changes'][0]['revision_url']);self::assertFalse($result['changes'][0]['baseline_available']);
    }
}
