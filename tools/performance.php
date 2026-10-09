<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
$runtime=getenv('FI_RELEASE_WP_PATH')?:dirname(__DIR__).'/.runtime/release-wordpress';
require $runtime.'/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8899'||get_option('fi_release_fixture')||get_option('fi_uninstall_fixture')){throw new RuntimeException('Benchmark only the disposable isolated release site without active fixtures.');}
$admin=get_user_by('login','release_admin');wp_set_current_user($admin->ID);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$store=$services->store;
$run=bin2hex(random_bytes(6));$objects=array();$report=array('dataset'=>array('topics'=>1002,'synthetic_signal_rows'=>5000),'samples'=>array());
$forum=(int)bbp_insert_forum(array('post_title'=>'Benchmark crowded '.$run,'post_author'=>$admin->ID));$objects[]=$forum;
$targetForum=(int)bbp_insert_forum(array('post_title'=>'Benchmark selected '.$run,'post_author'=>$admin->ID));$objects[]=$targetForum;
$hiddenForum=(int)bbp_insert_forum(array('post_title'=>'Benchmark hidden '.$run,'post_status'=>'hidden','post_author'=>$admin->ID));$objects[]=$hiddenForum;
try{
 for($i=0;$i<1002;$i++){
  $selected=$i===1000;$secret=$i===1001;$which=$secret?$hiddenForum:($selected?$targetForum:$forum);
  $content=$selected?'filterneedle '.str_repeat('configuration operations context ',20):str_repeat('filterneedle ',10);
  $id=(int)wp_insert_post(array('post_type'=>bbp_get_topic_post_type(),'post_status'=>'publish','post_title'=>($secret?'HIDDEN_CANARY_':'Benchmark ').$run.' filterneedle '.$i,'post_content'=>$content,'post_parent'=>$which,'post_author'=>$admin->ID),true,false);
  if(!$id){throw new RuntimeException('Fixture post creation failed.');}
  $objects[]=$id;update_post_meta($id,'_bbp_forum_id',$which);update_post_meta($id,'_bbp_reply_count',0);
  $services->get('indexer')->index($id);
  if($selected){$target=$id;}
  if($secret){$hidden=$id;}
  if($i && $i%200===0){echo 'Prepared '.$i." topics.\n";}
  if($i<200){
   // Synthetic unique signals exercise aggregation volume, not participant authorization.
   for($j=0;$j<25;$j++){
    $store->insert('signals',array('object_id'=>$id,'user_id'=>1000000+$j,'kind'=>'vote','value'=>1,'created_at'=>$store->now(),'updated_at'=>$store->now()));
   }
  }
 }
 echo "Benchmark fixtures prepared: 1,002 native topics and 5,000 synthetic signal rows.\n";
 $raw=$store->rows("SELECT source_id FROM {$store->table('chunks')} WHERE MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) ORDER BY MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) DESC LIMIT 500",array('filterneedle','filterneedle'));
 if(in_array($target,array_map('intval',array_column($raw,'source_id')),true)){throw new RuntimeException('Dataset did not reproduce the candidate starvation condition.');}
 global $wpdb;
 $newSearch=static fn()=>new ForumIntelligence\Infrastructure\Search\LexicalSearch($store,$services->access,new ForumIntelligence\Domain\Solutions\Solutions($store,$services->access));
 wp_cache_flush();$search=$newSearch();
 foreach(array('cold','warm_1','warm_2','warm_3','warm_4','warm_5') as $name){
  $start=microtime(true);$before=$wpdb->num_queries;
  $result=$search->search('filterneedle',array('forum'=>$targetForum,'type'=>'topic'),0);
  $report['samples'][]=array('name'=>$name,'milliseconds'=>round((microtime(true)-$start)*1000,2),'queries'=>$wpdb->num_queries-$before);
  if(array_column($result['items'],'id')!==array($target)||$result['has_more']){throw new RuntimeException('Selected forum was starved or exposed an incorrect count.');}
 }
 $start=microtime(true);$before=$wpdb->num_queries;
 $global=$newSearch()->search('filterneedle',array('per_page'=>30),0);
 $report['samples'][]=array('name'=>'global_500_candidates','milliseconds'=>round((microtime(true)-$start)*1000,2),'queries'=>$wpdb->num_queries-$before);
 if(in_array($hidden,array_column($global['items'],'id'),true)||str_contains(wp_json_encode($global),'HIDDEN_CANARY_')){throw new RuntimeException('Benchmark restricted source leaked.');}
 $report['candidate_bound']=500;
 $report['forum_filter_before_bound']=true;
 $report['hidden_source_excluded']=true;
 $report['plans']=$store->rows("EXPLAIN SELECT source_id FROM {$store->table('chunks')} WHERE forum_id=%d AND source_type=%s AND MATCH(content) AGAINST (%s IN NATURAL LANGUAGE MODE) LIMIT 500",array($targetForum,bbp_get_topic_post_type(),'filterneedle'));
 $report['versions']=array('wordpress'=>get_bloginfo('version'),'bbpress'=>bbp_get_version(),'php'=>PHP_VERSION);
 $report['timestamp']=gmdate('c');
 file_put_contents(dirname(__DIR__).'/.runtime/performance-report.json',wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
 echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
 foreach($report['samples'] as $sample){if($sample['queries']>120||$sample['milliseconds']>1000){throw new RuntimeException('Portable local search exceeded the declared 120-query/1,000-ms development budget.');}}
}finally{
 $removed=0; foreach(array_reverse($objects) as $id){$store->execute("DELETE FROM {$store->table('signals')} WHERE object_id=%d",array($id));$store->execute("DELETE FROM {$store->table('chunks')} WHERE source_id=%d",array($id));wp_delete_post($id,true);if(++$removed%200===0){echo 'Cleaned '.$removed." owned fixture posts.\n";}}
 // Remove only pending source jobs for IDs created and deleted by this fixture. Preserve leased jobs and other sources.
 if($objects){
  $marks=implode(',',array_fill(0,count($objects),'%d'));
  $store->execute("DELETE FROM {$store->table('jobs')} WHERE status IN ('queued','retrying') AND ((kind='index' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.id')) AS UNSIGNED) IN ($marks)) OR (kind IN ('notify','saved_search_notify','reputation_source') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.object')) AS UNSIGNED) IN ($marks)))",array(...$objects,...$objects));
 }
}
