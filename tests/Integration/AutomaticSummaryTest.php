<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;
use ForumIntelligence\Bootstrap\Services;
use ForumIntelligence\Contracts\TextProvider;
/** Native asynchronous behavior; provider calls never occur on the publication path. */
final class AutomaticSummaryTest extends TestCase {
    private Services $services;
    private int $actor;
    private int $forum;
    private int $topic;
    private array $owned=[];
    private array $hooks=[];
    private mixed $settings;
    private object $missing;
    private object $provider;
    protected function setUp(): void {
        global $wp_filter;
        $this->hooks=array_map(static fn(WP_Hook $hook):WP_Hook=>clone $hook,$wp_filter);
        $this->missing=new stdClass();$this->settings=get_option('fi_settings',$this->missing);
        update_option('fi_settings',['ai_enabled'=>false,'summary_auto_enabled'=>false]);
        $this->actor=(int)wp_insert_user(['user_login'=>'fi_auto_'.bin2hex(random_bytes(8)),'user_pass'=>'isolated-native-auto-summary','role'=>'subscriber']);
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
        update_option('fi_settings',['ai_enabled'=>false,'summary_auto_enabled'=>false]);
        wp_set_current_user($this->actor);
        foreach(array_reverse($this->owned) as $id){wp_delete_post($id,true);}
        $store=$this->services->store;
        $store->execute("DELETE FROM {$store->table('records')} WHERE object_id=%d",[$this->topic]);
        $store->execute("DELETE FROM {$store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",['summary_auto:'.$this->topic]);
        if($this->settings===$this->missing){delete_option('fi_settings');}else{update_option('fi_settings',$this->settings);}
        require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($this->actor);wp_set_current_user(0);
        global $wp_filter;$wp_filter=$this->hooks;
    }
    private function remember(int $id):int { self::assertGreaterThan(0,$id);$this->owned[]=$id;return $id; }
    private function reply(string $text='A public approach'):int {
        return $this->remember((int)bbp_insert_reply(['post_content'=>$text,'post_parent'=>$this->topic,'post_author'=>$this->actor],['topic_id'=>$this->topic,'forum_id'=>$this->forum]));
    }
    private function enable():void {
        update_option('fi_settings',['ai_enabled'=>true,'summary_auto_enabled'=>true,'summary_auto_actor'=>$this->actor,'summary_min_replies'=>3,'summary_debounce_seconds'=>120,'summary_min_interval'=>600]);
    }
    private function job():?array { return $this->services->store->row("SELECT * FROM {$this->services->store->table('jobs')} WHERE kind='summary_auto' AND job_key=%s",['summary_auto:'.$this->topic]); }
    private function ready():void {
        $s=$this->services->store;$past=gmdate('Y-m-d H:i:s',time()-10000);
        $s->execute("UPDATE {$s->table('jobs')} SET available_at=%s WHERE kind='summary_auto' AND job_key=%s",[$past,'summary_auto:'.$this->topic]);
        $s->execute("UPDATE {$s->table('records')} SET updated_at=%s WHERE record_key=%s",[$past,'knowledge_source_revision:'.$this->topic]);
        $s->execute("UPDATE {$s->table('jobs')} SET available_at=%s WHERE kind<>'summary_auto' AND status IN ('queued','retrying')",[gmdate('Y-m-d H:i:s',time()+86400)]);
    }
    public function testNativeThresholdCoalescesChangesAndRunsOnlyAsynchronously():void {
        $this->enable();$this->reply();$this->reply();
        self::assertNull($this->job());self::assertSame(0,$this->provider->calls);
        $reply=$this->reply();$job=$this->job();self::assertNotNull($job);
        self::assertSame(['topic'=>$this->topic],json_decode($job['payload'],true));
        self::assertGreaterThanOrEqual(time()+115,strtotime($job['available_at'].' UTC'));
        wp_update_post(['ID'=>$reply,'post_content'=>'Changed before the quiet period']);
        $again=$this->job();self::assertSame($job['id'],$again['id']);self::assertGreaterThan((int)$job['revision'],(int)$again['revision']);
        self::assertSame(0,$this->provider->calls);$this->services->queue->run(1);self::assertSame(0,$this->provider->calls);
        $this->ready();$this->services->queue->run(1);self::assertSame(1,$this->provider->calls);
        self::assertSame('OWNED_AUTOMATIC_SUMMARY_CANARY',$this->services->get('knowledge')->summary($this->topic,$this->actor)['answer']);
        self::assertSame('completed',$this->job()['status']);
        $this->services->queue->run(1);self::assertSame(1,$this->provider->calls);
    }
    public function testDelayedJobRenewsSettingsActorAndSourceAccessWithoutProviderWork():void {
        $this->enable();for($i=0;$i<3;++$i){$this->reply();}
        self::assertNotNull($this->job());$this->ready();
        update_option('fi_settings',['ai_enabled'=>false,'summary_auto_enabled'=>true,'summary_auto_actor'=>$this->actor]);
        $this->services->queue->run(1);self::assertSame(0,$this->provider->calls);self::assertNull($this->services->store->record('summary:'.$this->topic));
        $this->enable();$this->reply();$this->ready();bbp_set_user_role($this->actor,bbp_get_participant_role());
        $this->services->queue->run(1);self::assertSame(0,$this->provider->calls);
        bbp_set_user_role($this->actor,bbp_get_moderator_role());$this->reply();$this->ready();
        wp_update_post(['ID'=>$this->topic,'post_password'=>'owned-delayed-source-password']);
        $this->services->queue->run(1);self::assertSame(0,$this->provider->calls);self::assertNull($this->services->store->record('summary:'.$this->topic));
    }
    public function testAutomaticChangesPreserveHumanLocksAndEnforceGenerationCooldown():void {
        $this->enable();for($i=0;$i<3;++$i){$this->reply();}$this->ready();$this->services->queue->run(1);
        self::assertSame(1,$this->provider->calls);
        $reply=$this->reply();$job=$this->job();self::assertGreaterThanOrEqual(time()+590,strtotime($job['available_at'].' UTC'));
        $this->ready();$this->services->queue->run(1);self::assertSame(1,$this->provider->calls);self::assertSame('queued',$this->job()['status']);
        $knowledge=$this->services->get('knowledge');$knowledge->command($this->topic,'edit','Human summary must remain',$this->actor);
        $before=$this->services->store->record('summary:'.$this->topic);$this->ready();$this->services->queue->run(1);
        self::assertSame(1,$this->provider->calls);self::assertSame($before,$this->services->store->record('summary:'.$this->topic));
        wp_update_post(['ID'=>$reply,'post_content'=>'Source changed while human lock remains']);self::assertSame('completed',$this->job()['status']);
        self::assertSame('Human summary must remain',$knowledge->summary($this->topic,$this->actor)['answer']);
    }

    public function testNativeDefaultsAndRestrictedSourcesNeverScheduleAutomaticWork():void {
        for($i=0;$i<3;++$i){$this->reply();}self::assertNull($this->job());
        $this->enable();bbp_privatize_forum($this->forum);$this->reply();self::assertNull($this->job());self::assertSame(0,$this->provider->calls);
        bbp_publicize_forum($this->forum);
        $this->services->get('knowledge')->command($this->topic,'disable','',$this->actor);$this->reply();
        self::assertNull($this->job());self::assertSame(0,$this->provider->calls);
    }
    public function testAiConsentRevokedByNativeAccessCallbackCancelsBeforeCustomProviderWork():void {
        $this->enable();for($i=0;$i<3;++$i){$this->reply();}$this->ready();
        $revoked=false;
        add_filter('fi_can_read_object',function(bool $allowed,int $object)use(&$revoked):bool{
            if(!$revoked&&$object===$this->topic){$revoked=true;$settings=get_option('fi_settings');$settings['ai_enabled']=false;update_option('fi_settings',$settings);}
            return $allowed;
        },10,2);
        $this->services->queue->run(1);self::assertTrue($revoked);self::assertSame(0,$this->provider->calls);
        self::assertNull($this->services->store->record('summary:'.$this->topic));
    }
    public function testConfigurationRevokedDuringProviderWorkPreservesSummaryAndRetryDetailsAreBounded():void {
        $this->enable();for($i=0;$i<3;++$i){$this->reply();}$this->ready();
        $this->provider->during=static function():void {$settings=get_option('fi_settings');$settings['summary_auto_enabled']=false;update_option('fi_settings',$settings);};
        $this->services->queue->run(1);self::assertSame(1,$this->provider->calls);self::assertNull($this->services->store->record('summary:'.$this->topic));
        $this->provider->during=null;$this->enable();$this->reply();$this->ready();$this->provider->fail=true;
        $this->services->queue->run(1);self::assertSame('retrying',$this->job()['status']);self::assertSame('owned_provider_failure',$this->job()['error_code']);
        self::assertStringNotContainsString('secret fixture details',json_encode($this->job()));
        self::assertNull($this->services->store->record('summary:'.$this->topic));
        $this->provider->fail=false;$this->ready();$this->services->queue->run(1);self::assertSame('completed',$this->job()['status']);
        self::assertSame('OWNED_AUTOMATIC_SUMMARY_CANARY',$this->services->get('knowledge')->summary($this->topic,$this->actor)['answer']);
    }

    public function testNativeSettingsBoundAutomaticPolicyAndRoundTripReviewedConfiguration():void {
        $defaults=ForumIntelligence\Admin\Admin::defaultSettings();
        self::assertFalse($defaults['summary_auto_enabled']);self::assertSame(0,$defaults['summary_auto_actor']);self::assertSame(20,$defaults['summary_min_replies']);
        wp_get_current_user()->add_cap('manage_options',true);
        $front=new ForumIntelligence\Frontend\Frontend($this->services->operations,$this->services->solutions,$this->services->access,dirname(__DIR__,2).'/forum-intelligence-for-bbpress.php');
        $admin=new ForumIntelligence\Admin\Admin($this->services,$front);$admin->register();
        $input=['ai_enabled'=>true,'summary_auto_enabled'=>true,'summary_auto_actor'=>$this->actor,'summary_min_replies'=>1,'summary_debounce_seconds'=>1,'summary_min_interval'=>1,'modules'=>['knowledge'=>true]];
        $clean=$admin->sanitize($input);
        self::assertTrue($clean['summary_auto_enabled']);self::assertSame(3,$clean['summary_min_replies']);self::assertSame(60,$clean['summary_debounce_seconds']);self::assertSame(300,$clean['summary_min_interval']);
        self::assertFalse($admin->sanitize(array_replace($input,['ai_enabled'=>false]))['summary_auto_enabled']);
        self::assertFalse($admin->sanitize(array_replace($input,['summary_auto_actor'=>0]))['summary_auto_enabled']);
        add_filter('sanitize_option_fi_settings',[$admin,'sanitize']);
        try {
            update_option('fi_settings',$clean);$config=$admin->exportConfiguration($this->actor);
            self::assertSame($this->actor,$config['settings']['summary_auto_actor']);self::assertSame(3,$config['settings']['summary_min_replies']);
            self::assertSame(['imported'=>true],$this->services->operations->execute('config_import',['reviewed'=>1,'json'=>wp_json_encode($config)],$this->actor));
            self::assertTrue(get_option('fi_settings')['summary_auto_enabled']);self::assertSame(300,get_option('fi_settings')['summary_min_interval']);
        }finally {remove_filter('sanitize_option_fi_settings',[$admin,'sanitize']);}
    }
}
