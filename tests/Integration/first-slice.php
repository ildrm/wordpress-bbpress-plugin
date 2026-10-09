<?php
declare(strict_types=1);
// Run using WP-CLI eval-file against the isolated test database.
require_once dirname(__DIR__,2).'/autoload.php';
use ForumIntelligence\Infrastructure\Database\Store;
use ForumIntelligence\Domain\Solutions\Solutions;
use ForumIntelligence\Integration\BbPress\Access;
use ForumIntelligence\Domain\Failure;

$checks=0;
$assert=static function (bool $condition,string $label) use (&$checks): void {
    ++$checks; if (!$condition) { throw new RuntimeException('FAIL: '.$label); }
};
$deny=static function (callable $operation,int $status,string $label) use ($assert): void {
    try { $operation(); } catch (Failure $failure) { $assert($failure->status===$status,$label); return; }
    throw new RuntimeException('FAIL: '.$label.' did not deny');
};
$makeUser=static function (string $name,string $forumRole): int {
    $old=get_user_by('login',$name);
    $id=$old?$old->ID:wp_insert_user(['user_login'=>$name,'user_pass'=>'local-test-only','role'=>'subscriber']);
    bbp_set_user_role($id,$forumRole); return (int)$id;
};
$author=$makeUser('fi_author',bbp_get_participant_role());
$other=$makeUser('fi_other',bbp_get_participant_role());
$spectator=$makeUser('fi_spectator',bbp_get_spectator_role());
$moderator=$makeUser('fi_moderator',bbp_get_moderator_role());
wp_set_current_user($author);
$forum=bbp_insert_forum(['post_title'=>'Public integration forum']);
$private=bbp_insert_forum(['post_title'=>'Private integration forum','post_status'=>'private']);
$hidden=bbp_insert_forum(['post_title'=>'Hidden integration forum','post_status'=>'hidden']);
$topic=bbp_insert_topic(['post_title'=>'Integration question','post_author'=>$author,'post_parent'=>$forum],['forum_id'=>$forum]);
$reply=bbp_insert_reply(['post_content'=>'An answer','post_author'=>$other,'post_parent'=>$topic],['topic_id'=>$topic,'forum_id'=>$forum]);
$topic2=bbp_insert_topic(['post_title'=>'Another question','post_author'=>$other,'post_parent'=>$forum],['forum_id'=>$forum]);
$reply2=bbp_insert_reply(['post_content'=>'Different topic answer','post_author'=>$other,'post_parent'=>$topic2],['topic_id'=>$topic2,'forum_id'=>$forum]);
$privateTopic=bbp_insert_topic(['post_title'=>'Restricted secret','post_parent'=>$private],['forum_id'=>$private]);
$hiddenTopic=bbp_insert_topic(['post_title'=>'Hidden secret','post_parent'=>$hidden],['forum_id'=>$hidden]);
$access=new Access(); $store=new Store(); $solutions=new Solutions($store,$access);
$assert($access->canRead($topic,0),'public anonymous read');
$assert(!$access->canRead($privateTopic,0),'private anonymous denied');
$assert(!$access->canRead($hiddenTopic,$other),'hidden participant denied');
$assert($access->canRead($hiddenTopic,$moderator),'hidden moderator read');
$deny(fn()=>$solutions->change($topic,$reply,'accept',0),403,'anonymous mutation');
$deny(fn()=>$solutions->change($topic,$reply,'accept',$other),403,'non-owner mutation');
$deny(fn()=>$solutions->change($topic,$reply,'accept',$spectator),403,'spectator mutation');
$deny(fn()=>$solutions->change($topic,$reply2,'accept',$author),400,'cross-topic acceptance');
$state=$solutions->change($topic,$reply,'accept',$author,0);
$assert($state['primary_reply']===$reply && $state['solved'],'accepted solution');
$deny(fn()=>$solutions->change($topic,0,'reopen',$author,0),409,'optimistic conflict');
$state=$solutions->change($topic,0,'reopen',$moderator,$state['version']);
$assert(!$state['solved'] && !$state['answers'],'moderator reopen');
$solutions->change($topic,$reply,'accept',$author);
wp_trash_post($reply);
$assert(!$solutions->state($topic,$author)['answers'],'trashed answer invalidated');
wp_update_post(['ID'=>$topic,'post_password'=>'secret-test']);
$assert(!$access->canRead($topic,0),'password topic denied');
$deny(fn()=>$solutions->state($topic,0),404,'password solution response denied');
wp_update_post(['ID'=>$topic,'post_password'=>'']);
$events=$store->rows("SELECT * FROM {$store->table('events')} WHERE object_id=%d AND kind LIKE 'solution_%'",[$topic]);
$assert(count($events)>=4,'historical evidence retained');
$assert((int)get_option('fi_schema_version')===ForumIntelligence\Infrastructure\Database\Schema::VERSION,'schema installed');
(new ForumIntelligence\Infrastructure\Database\Schema())->install();
$assert((int)get_option('fi_schema_version')===ForumIntelligence\Infrastructure\Database\Schema::VERSION,'migration idempotency');
foreach ([$reply,$reply2,$topic,$topic2,$privateTopic,$hiddenTopic,$forum,$private,$hidden] as $id) { wp_delete_post($id,true); }
echo "First-slice integration: $checks checks passed\n";
