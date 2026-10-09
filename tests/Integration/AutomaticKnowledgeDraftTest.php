<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Contracts\TextProvider;
/** Native asynchronous behavior; provider calls never occur on the publication path. */
final class AutomaticKnowledgeDraftTest extends TestCase {
    private Services $services;
    private int $actor;
    private int $forum;
    private int $topic;
    private array $owned=[];
    private array $ownedUsers=[];
    private array $hooks=[];
    private mixed $settings;
    private object $missing;
    private object $provider;
    protected function setUp(): void {
        global $wp_filter;
        $this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);
        $this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);
        update_option('fi_settings',['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false]);
        $this->actor=(int)wp_insert_user(['user_login'=>'fi_auto_draft_'.bin2hex(random_bytes(8)),'user_pass'=>'isolated-native-auto-summary','role'=>'subscriber']);
        self::assertGreaterThan(0,$this->actor);bbp_set_user_role($this->actor,bbp_get_moderator_role());wp_set_current_user($this->actor);
        $this->provider=new class implements TextProvider {
            public int $calls=0;public bool $fail=false;public $during=null;
            public function generate(string $instruction,array $evidence):array { ++$this->calls;if($this->during){($this->during)();}if($this->fail){throw new ForumIntelligence\Domain\Failure('owned_provider_failure','secret fixture details must not enter jobs',503);}return ['answer'=>'OWNED_AUTOMATIC_SUMMARY_CANARY','source_ids'=>array_column($evidence,'id')]; }
        };
        add_filter('fi_text_provider',fn()=>$this->provider);
        $this->services=new Services();$this->services->register();
        $this->forum=$this->remember((int)bbp_insert_forum(['post_title'=>'Owned automatic summary forum','post_author'=>$this->actor]));
        $this->topic=$this->remember((int)bbp_insert_topic(['post_title'=>'Owned automatic summary question','post_content'=>'Public original context','post_parent'=>$this->forum,'post_author'=>$this->actor],['forum_id'=>$this->forum]));
    }
    protected function tearDown(): void {
        update_option('fi_settings',['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>false]);
        wp_set_current_user($this->actor);
        foreach($this->articles() as $article){if((int)$article->post_author!==$this->actor&&!in_array($article->ID,$this->owned,true)){throw new RuntimeException('Refusing foreign automatic article fixture cleanup.');}$this->owned[]=$article->ID;}
        foreach(array_reverse($this->owned) as $id){wp_delete_post($id,true);}
        $store=$this->services->store;
        $store->execute("DELETE FROM {$store->table('records')} WHERE object_id=%d",[$this->topic]);
        $store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='knowledge_auto_draft' AND job_key=%s",['knowledge_auto_draft:'.$this->topic]);
        if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}
        require_once ABSPATH.'wp-admin/includes/user.php';foreach($this->ownedUsers as $ownedUser){wp_delete_user($ownedUser);}wp_delete_user($this->actor);wp_set_current_user(0);
        global $wp_filter;$wp_filter=$this->hooks;
    }
    private function remember(int $id):int { self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id; }
    private function reply(string $text='A public approach'):int {
        return $this->remember((int)bbp_insert_reply(['post_content'=>$text,'post_parent'=>$this->topic,'post_author'=>$this->actor],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));
    }
    private function enable():void {
        wp_get_current_user()->add_cap('edit_posts');
        update_option('fi_settings',['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>true,'knowledge_auto_draft_actor'=>$this->actor,'knowledge_auto_draft_min_replies'=>3,'knowledge_auto_draft_min_confirmations'=>0,'knowledge_auto_draft_debounce'=>120]);
    }
    private function job():?array { return $this->services->store->row("SELECT * FROM {$this->services->store->table('jobs')} WHERE kind='knowledge_auto_draft' AND job_key=%s",['knowledge_auto_draft:'.$this->topic]); }
    private function ready():void {
        $s=$this->services->store;$past='2000-01-01 00:00:00';
        $s->execute("UPDATE {$s->table('jobs')} SET available_at=%s WHERE kind='knowledge_auto_draft' AND job_key=%s",[$past,'knowledge_auto_draft:'.$this->topic]);
        $s->execute("UPDATE {$s->table('records')} SET updated_at=%s WHERE record_key=%s",[$past,'knowledge_source_revision:'.$this->topic]);
    }
    private function currentSolvedSummary():void {
        $this->reply();$this->reply();$answer=$this->reply();
        $this->services->solutions->change($this->topic,$answer,'accept',$this->actor);
        $this->services->get('knowledge')->command($this->topic,'edit','OWNED_HUMAN_AUTO_DRAFT_CANARY',$this->actor);
    }
    private function articles():array {
        return get_posts(['post_type'=>'fi_knowledge','post_status'=>['draft','publish','pending','private','future','trash'],'posts_per_page'=>-1,'meta_key'=>'_fi_source_topic','meta_value'=>$this->topic]);
    }
    public function testCurrentSolvedHumanSummaryQueuesOnlyADelayedDraftAndNeverCallsProvider():void {
        $this->enable();$this->currentSolvedSummary();
        $job=$this->job();self::assertNotNull($job);self::assertSame(['topic'=>$this->topic],json_decode($job['payload'],true));
        self::assertSame([],$this->articles());self::assertSame(0,$this->provider->calls);
        self::assertGreaterThanOrEqual(time()+115,strtotime($job['available_at'].' UTC'));
        $summary=$this->services->store->record('summary:'.$this->topic);
        $this->ready();$this->services->queue->run(1);
        $articles=$this->articles();self::assertCount(1,$articles);$article=$articles[0];$this->owned[]=$article->ID;
        self::assertSame('draft',$article->post_status);self::assertSame($this->actor,(int)$article->post_author);
        self::assertStringContainsString('OWNED_HUMAN_AUTO_DRAFT_CANARY',$article->post_content);
        self::assertSame($summary,$this->services->store->record('summary:'.$this->topic));self::assertSame(0,$this->provider->calls);
        $automatic=new ForumIntelligence\Domain\Knowledge\AutoDraft($this->services->store,$this->services->queue,$this->services->access,$this->services->get('knowledge'));
        $automatic->consider($this->topic);$automatic->run($this->topic);self::assertCount(1,$this->articles());
    }
    public function testDelayedDraftRenewsCurrentPolicyAcceptedStateAndSourcePrivacy():void {
        $this->enable();$this->currentSolvedSummary();self::assertNotNull($this->job());$this->ready();
        $settings=get_option('fi_settings');$settings['knowledge_auto_draft_enabled']=false;update_option('fi_settings',$settings);
        $this->services->queue->run(1);self::assertSame([],$this->articles());
        $this->enable();$this->services->get('knowledge')->command($this->topic,'edit','Current human text',$this->actor);$this->ready();
        $this->services->solutions->change($this->topic,0,'reopen',$this->actor);$this->services->queue->run(1);self::assertSame([],$this->articles());
        $answer=(int)get_posts(['post_type'=>bbp_get_reply_post_type(),'post_parent'=>$this->topic,'fields'=>'ids','posts_per_page'=>1])[0];
        $this->services->solutions->change($this->topic,$answer,'accept',$this->actor);
        $this->services->get('knowledge')->command($this->topic,'edit','Current human text',$this->actor);$this->ready();
        wp_update_post(['ID'=>$this->topic,'post_password'=>'owned-auto-draft-protection']);
        $this->services->queue->run(1);self::assertSame([],$this->articles());self::assertSame(0,$this->provider->calls);
    }
    public function testCurrentExistingHumanArticleAndConfiguredConfirmationThresholdSuppressDrafts():void {
        $this->enable();$settings=get_option('fi_settings');$settings['knowledge_auto_draft_min_confirmations']=1;update_option('fi_settings',$settings);
        $this->currentSolvedSummary();self::assertNull($this->job());self::assertSame([],$this->articles());
        $this->enable();$knowledge=$this->services->get('knowledge');
        $id=(int)$knowledge->command($this->topic,'draft','',$this->actor)['article_id'];$this->owned[]=$id;
        wp_update_post(['ID'=>$id,'post_content'=>'Existing independently edited human article']);
        $knowledge->command($this->topic,'edit','Updated human summary',$this->actor);
        self::assertNull($this->job());self::assertCount(1,$this->articles());self::assertSame('Existing independently edited human article',get_post($id)->post_content);
    }
    public function testPolicyRevokedDuringNativeArticleInsertionRollsBackOnlyTheNewDraft():void {
        $this->enable();$this->currentSolvedSummary();$this->ready();
        $revoked=false;
        add_filter('wp_insert_post_data',function(array $data)use(&$revoked):array {
            if($data['post_type']==='fi_knowledge'){ $revoked=true;$settings=get_option('fi_settings');$settings['knowledge_auto_draft_enabled']=false;update_option('fi_settings',$settings); }
            return $data;
        });
        $before=$this->services->store->record('summary:'.$this->topic);$policyBefore=get_option('fi_settings');
        $this->services->queue->run(1);self::assertTrue($revoked);
        self::assertSame($policyBefore,get_option('fi_settings'));self::assertSame([],$this->articles());
        self::assertSame($before,$this->services->store->record('summary:'.$this->topic));
        self::assertSame('retrying',$this->job()['status']);self::assertStringNotContainsString('OWNED_HUMAN',$this->job()['error_code']);
        self::assertSame(0,$this->provider->calls);
    }
    public function testDefaultsAndStaleOrRevokedEditorNeverCreateDrafts():void {
        self::assertFalse(ForumIntelligence\Admin\Admin::defaultSettings()['knowledge_auto_draft_enabled']);
        $this->currentSolvedSummary();self::assertNull($this->job());
        $this->enable();$automatic=new ForumIntelligence\Domain\Knowledge\AutoDraft($this->services->store,$this->services->queue,$this->services->access,$this->services->get('knowledge'));
        $automatic->consider($this->topic);self::assertNotNull($this->job());$this->ready();
        wp_get_current_user()->remove_cap('edit_posts');$this->services->queue->run(1);self::assertSame([],$this->articles());
        $this->enable();$reply=$this->reply('A new reply makes the retained summary stale');
        $automatic->consider($this->topic);self::assertSame('completed',$this->job()['status']);
        self::assertTrue($this->services->get('knowledge')->summary($this->topic,$this->actor)['stale']);self::assertSame([],$this->articles());
    }
    public function testNativeConfigurationBoundsRequireModeratorEditingRightsAndRoundTripWithoutAiConsent():void {
        $this->enable();wp_get_current_user()->add_cap('manage_options');
        $admin=new ForumIntelligence\Admin\Admin($this->services,new ForumIntelligence\Frontend\Frontend($this->services->operations,$this->services->solutions,$this->services->access,dirname(__DIR__,2).'/forum-intelligence-for-bbpress.php'));$admin->register();
        $input=['ai_enabled'=>false,'knowledge_auto_draft_enabled'=>true,'knowledge_auto_draft_actor'=>$this->actor,'knowledge_auto_draft_min_replies'=>-4,'knowledge_auto_draft_min_confirmations'=>-4,'knowledge_auto_draft_debounce'=>-4];
        $clean=$admin->sanitize($input);self::assertTrue($clean['knowledge_auto_draft_enabled']);self::assertFalse($clean['ai_enabled']);
        self::assertSame(1,$clean['knowledge_auto_draft_min_replies']);self::assertSame(0,$clean['knowledge_auto_draft_min_confirmations']);self::assertSame(60,$clean['knowledge_auto_draft_debounce']);
        $large=$admin->sanitize(array_replace($input,['knowledge_auto_draft_min_replies'=>99999,'knowledge_auto_draft_min_confirmations'=>99999,'knowledge_auto_draft_debounce'=>99999]));
        self::assertSame(5000,$large['knowledge_auto_draft_min_replies']);self::assertSame(1000,$large['knowledge_auto_draft_min_confirmations']);self::assertSame(86400,$large['knowledge_auto_draft_debounce']);
        self::assertFalse($admin->sanitize(array_replace($input,['knowledge_auto_draft_actor'=>0]))['knowledge_auto_draft_enabled']);
        add_filter('sanitize_option_fi_settings',[$admin,'sanitize']);
        try {
            update_option('fi_settings',$clean);$config=$admin->exportConfiguration($this->actor);
            self::assertSame($this->actor,$config['settings']['knowledge_auto_draft_actor']);
            self::assertSame(['imported'=>true],$this->services->operations->execute('config_import',['reviewed'=>1,'json'=>wp_json_encode($config)],$this->actor));
            self::assertTrue(get_option('fi_settings')['knowledge_auto_draft_enabled']);self::assertFalse(get_option('fi_settings')['ai_enabled']);
        }finally{remove_filter('sanitize_option_fi_settings',[$admin,'sanitize']);}
    }
    public function testNativeConfirmationAdmissionAndWithdrawalAreRenewedBeforeConversion():void {
        $this->enable();$settings=get_option('fi_settings');$settings['knowledge_auto_draft_min_confirmations']=1;update_option('fi_settings',$settings);
        $this->currentSolvedSummary();self::assertNull($this->job());
        $reader=(int)wp_insert_user(['user_login'=>'fi_confirm_draft_'.bin2hex(random_bytes(8)),'user_pass'=>'isolated-owned-confirmation','role'=>'subscriber']);self::assertGreaterThan(0,$reader);$this->ownedUsers[]=$reader;bbp_set_user_role($reader,bbp_get_participant_role());
        $reply=$this->services->solutions->state($this->topic,$this->actor)['primary_reply'];
        $engagement=$this->services->get('engagement');$engagement->cast($reply,$reader,'confirm',1);
        self::assertNotNull($this->job());$this->ready();
        $engagement->cast($reply,$reader,'confirm',0);$this->services->queue->run(1);self::assertSame([],$this->articles());
        $engagement->cast($reply,$reader,'confirm',1);self::assertSame('queued',$this->job()['status']);$this->ready();$this->services->queue->run(1);
        $articles=$this->articles();self::assertCount(1,$articles);self::assertSame('draft',$articles[0]->post_status);self::assertSame(0,$this->provider->calls);
    }
    private function nativeInsertionMutation(array $changes):void {
        $this->enable();$this->currentSolvedSummary();$this->ready();$inserted=0;
        add_filter('wp_insert_post_data',static function(array $data)use($changes):array{return $data['post_type']==='fi_knowledge'?array_replace($data,$changes):$data;});
        add_action('wp_after_insert_post',function(int $id,WP_Post $post)use(&$inserted):void{if($post->post_type==='fi_knowledge'){$inserted=$id;$this->owned[]=$id;}},10,2);
        $this->services->queue->run(1);self::assertGreaterThan(0,$inserted);
        self::assertSame([],$this->articles());self::assertNull(get_post($inserted));self::assertFalse(metadata_exists('post',$inserted,'_fi_provenance'));
        self::assertSame('retrying',$this->job()['status']);self::assertSame('automation_changed',$this->job()['error_code']);self::assertSame(0,$this->provider->calls);
    }
    public function testNativeInsertionCannotChangeTheConfiguredDraftOwner():void {$this->nativeInsertionMutation(['post_author'=>0]);}
    public function testNativeInsertionCannotPublishAnAutomaticDraft():void {$this->nativeInsertionMutation(['post_status'=>'publish']);}
    public function testNativeSourceMutationRollbackRestoresSourceAndMetadataCaches():void {
        $this->enable();$this->currentSolvedSummary();$this->ready();$before=get_post($this->topic)->post_content;$revision=$this->services->store->record('knowledge_source_revision:'.$this->topic);
        add_filter('wp_insert_post_data',function(array $data):array{
            if($data['post_type']==='fi_knowledge'){wp_update_post(['ID'=>$this->topic,'post_content'=>'NATIVE_CALLBACK_MUTATED_SOURCE_CANARY']);}
            return $data;
        });
        $this->services->queue->run(1);self::assertSame([],$this->articles());
        self::assertSame($before,get_post($this->topic)->post_content);self::assertSame($revision,$this->services->store->record('knowledge_source_revision:'.$this->topic));
        self::assertSame('retrying',$this->job()['status']);self::assertSame(0,$this->provider->calls);
    }
    public function testAutomaticConversionRefusesActualNonTransactionalNativeSourceTables():void {
        $this->enable();$this->currentSolvedSummary();$this->ready();
        global $wpdb;$scoped=clone $wpdb;$token='fi_draft_engine_'.bin2hex(random_bytes(8));
        $posts=$wpdb->prefix.$token.'_posts';$meta=$wpdb->prefix.$token.'_postmeta';
        foreach([$posts,$meta] as $table){self::assertMatchesRegularExpression('/^[a-zA-Z0-9_]+$/D',$table);}
        try {
            $wpdb->query("CREATE TABLE $posts LIKE {$wpdb->posts}");$wpdb->query("CREATE TABLE $meta LIKE {$wpdb->postmeta}");
            $wpdb->query("ALTER TABLE $posts ENGINE=MyISAM");
            $ids=array_map('intval',$this->owned);$wpdb->query("INSERT INTO $posts SELECT * FROM {$wpdb->posts} WHERE ID IN (".implode(',',$ids).")");
            $scoped->posts=$posts;$scoped->postmeta=$meta;
            $store=new ForumIntelligence\Infrastructure\Database\Store($scoped);$queue=new ForumIntelligence\Infrastructure\Queue\Queue($store);
            $health=new ForumIntelligence\Health\Health($store);self::assertSame('requires_innodb',$health->status()['automatic_draft_storage']);
            $health->register();$tests=apply_filters('site_status_tests',[]);self::assertSame('recommended',($tests['direct']['forum_intelligence']['test'])()['status']);
            $settings=(array)get_option('fi_settings');$settings['knowledge_auto_draft_enabled']=false;update_option('fi_settings',$settings);self::assertSame('not_required',$health->status()['automatic_draft_storage']);
            $settings['knowledge_auto_draft_enabled']=true;$settings['modules']['knowledge']=false;update_option('fi_settings',$settings);self::assertSame('not_required',$health->status()['automatic_draft_storage']);
            $settings['modules']['knowledge']=true;update_option('fi_settings',$settings);
            self::assertTrue((new ForumIntelligence\Infrastructure\Database\AtomicStorage($this->services->store))->ready());$this->ready();
            $automatic=new ForumIntelligence\Domain\Knowledge\AutoDraft($store,$queue,$this->services->access,$this->services->get('knowledge'));$automatic->register();
            $queue->run(1);self::assertSame([],$this->articles());self::assertSame('retrying',$this->job()['status']);
            self::assertSame('transactional_storage_required',$this->job()['error_code']);self::assertSame(0,$this->provider->calls);
        }finally {
            $wpdb->query("DROP TABLE IF EXISTS $posts");$wpdb->query("DROP TABLE IF EXISTS $meta");
        }
    }
}
