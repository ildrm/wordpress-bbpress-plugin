<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
if(!defined('DISABLE_WP_CRON')){define('DISABLE_WP_CRON',true);}
$runtime=getenv('FI_RELEASE_WP_PATH')?:dirname(__DIR__).'/.runtime/release-wordpress';
require $runtime.'/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8899'||DB_NAME!==(getenv('FI_RELEASE_DB')?:'fi_release')||wp_using_ext_object_cache()){throw new RuntimeException('Vector workload requires the owned isolated release database with a local object cache.');}
foreach(array('fi_release_fixture','fi_uninstall_fixture','fi_notification_fixture','fi_saved_search_race') as $key){if(get_option($key)){throw new RuntimeException('Finish the other owned release fixtures first.');}}
$source=(new ReflectionClass(ForumIntelligence\Bootstrap\Plugin::class))->getFileName();
if(!str_contains(str_replace('\\','/',$source),str_replace('\\','/',$runtime).'/wp-content/plugins/forum-intelligence-for-bbpress/')){throw new RuntimeException('Workload must load installed package code.');}
$admin=get_user_by('login','release_admin');if(!$admin){throw new RuntimeException('Missing owned release administrator.');}
wp_set_current_user((int)$admin->ID);
$services=new ForumIntelligence\Bootstrap\Services();$services->register();$store=$services->store;
$option='fi_vector_workload_fixture';$command=$argv[1]??'measure';
$fixture=get_option($option);
$vector=static fn():array=>array_map(static fn(int $i):float=>sin($i+1)*0.5,range(0,4095));
$owned=static function(array $data)use($admin):array{
 if(!preg_match('/^[a-f0-9]{12}$/D',(string)($data['run']??''))||!is_array($data['objects']??null)||!array_is_list($data['objects'])||count($data['objects'])>1003){throw new RuntimeException('Invalid owned workload descriptor.');}
 foreach($data['objects'] as $id){
  if(!is_int($id)||$id<=0){throw new RuntimeException('Invalid owned source ID.');}
  $post=get_post($id);
  if($post&&((int)$post->post_author!==(int)$admin->ID||get_post_meta($id,'_fi_vector_workload_owner',true)!==$data['run']||!str_contains($post->post_title,$data['run'])||!in_array($post->post_type,array(bbp_get_forum_post_type(),bbp_get_topic_post_type()),true))){throw new RuntimeException('Workload source ownership mismatch.');}
 }
 return $data['objects'];
};
$cleanup=static function(array $data)use($owned,$store,$option):void{
 $ids=$owned($data);
 foreach(array_reverse($ids) as $number=>$id){
  $store->execute("DELETE FROM {$store->table('chunks')} WHERE source_id=%d",array($id));
  if(get_post($id)&&!wp_delete_post($id,true)){throw new RuntimeException('Owned workload source deletion failed.');}
  if(($number+1)%200===0){echo 'Cleaned '.($number+1)." owned vector sources.\n";}
 }
 if($ids){
  $marks=implode(',',array_fill(0,count($ids),'%d'));
  $store->execute("DELETE FROM {$store->table('jobs')} WHERE status IN ('queued','retrying') AND ((kind='index' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.id')) AS UNSIGNED) IN ($marks)) OR (kind IN ('notify','saved_search_notify','reputation_source') AND CAST(JSON_UNQUOTE(JSON_EXTRACT(payload,'$.object')) AS UNSIGNED) IN ($marks)))",array(...$ids,...$ids));
  if((int)$store->row("SELECT COUNT(*) AS n FROM {$store->table('chunks')} WHERE source_id IN ($marks)",$ids)['n']){throw new RuntimeException('Owned workload vector rows remain.');}
 }
 if((int)$store->row("SELECT COUNT(*) AS n FROM {$store->wordpressTable('postmeta')} WHERE meta_key=%s AND meta_value=%s",array('_fi_vector_workload_owner',$data['run']))['n']){throw new RuntimeException('Owned workload metadata remains.');}
 delete_option($option);echo "Owned vector sources, cache rows, pending source jobs and fixture option cleaned.\n";
};
if($command==='cleanup'){if(is_array($fixture)){$cleanup($fixture);}else{echo "No owned vector workload fixture.\n";}exit;}
if($command==='seed'){
 if($fixture){throw new RuntimeException('Preserve or clean the existing owned workload first.');}
 $fixture=array('run'=>bin2hex(random_bytes(6)),'objects'=>array(),'topics'=>array(),'status'=>'preparing');
 if(!add_option($option,$fixture,'',false)){throw new RuntimeException('Owned workload fixture lock failed.');}
 $missing=new stdClass();$settings=get_option('fi_settings',$missing);
 $model=(string)(($settings===$missing?array():$settings)['embedding_model']??'text-embedding-3-small');
 $track=static function(int $id,bool $topic)use(&$fixture,$option):void{
  if($id<=0||!get_post($id)){throw new RuntimeException('Owned native workload source creation failed.');}
  update_post_meta($id,'_fi_vector_workload_owner',$fixture['run']);$fixture['objects'][]=$id;if($topic){$fixture['topics'][]=$id;}update_option($option,$fixture,false);
 };
 try{
  update_option('fi_settings',array_merge($settings===$missing?array():$settings,array('semantic'=>false,'excluded_forums'=>array())));
  $forum=(int)bbp_insert_forum(array('post_title'=>'Vector workload '.$fixture['run'],'post_author'=>(int)$admin->ID));$track($forum,false);
  $fixture['forum']=$forum;$fixture['model']=$model;update_option($option,$fixture,false);
  $provider=new class implements ForumIntelligence\Contracts\EmbeddingProvider{public function embed(string $text):array{throw new RuntimeException('Workload must never invoke external embedding.');}};
  $vectors=new ForumIntelligence\Infrastructure\Search\LocalVectorStore($store);
  $indexer=new ForumIntelligence\Infrastructure\Search\Indexer($store,$services->queue,$services->access,$provider,$vectors);
  $base=$vector();
  for($i=0;$i<1002;$i++){
   $id=wp_insert_post(array('post_type'=>bbp_get_topic_post_type(),'post_status'=>'publish','post_title'=>'Vector '.$fixture['run'].' '.$i,'post_content'=>'Owned native vector direction and current-source workload.','post_parent'=>$forum,'post_author'=>(int)$admin->ID),true,false);
   if(is_wp_error($id)){throw new RuntimeException('Owned native vector source insertion failed.');}
   $id=(int)$id;$track($id,true);update_post_meta($id,'_bbp_forum_id',$forum);update_post_meta($id,'_bbp_reply_count',0);
   $indexer->index($id);$values=$i===997?array_map(static fn(float $x):float=>-$x,$base):($i===996?array_map(static fn(float $x):float=>$x*1.0e-300,$base):$base);
   $vectors->put($id,ForumIntelligence\Infrastructure\Search\Indexer::hash($id),$model,$values);
   if($i&&$i%200===0){echo 'Prepared '.$i." owned 4,096-component sources.\n";}
  }
  $ids=$fixture['topics'];
  $store->execute("UPDATE {$store->table('chunks')} SET vector='true' WHERE source_id=%d",array($ids[1001]));
  $store->execute("UPDATE {$store->table('chunks')} SET model=%s WHERE source_id=%d",array('wrong-owned-workload-model',$ids[1000]));
  wp_update_post(array('ID'=>$ids[999],'post_content'=>'Changed owned source after its vector was cached.'));
  wp_update_post(array('ID'=>$ids[998],'post_password'=>'owned-vector-source-protection'));
  $fixture['status']='ready';update_option($option,$fixture,false);
  echo "Prepared 1,002 native topics / 4,096 components, including stale/model/corrupt/protected direction fixtures. No external provider calls.\n";
 }catch(Throwable $failure){$cleanup($fixture);throw $failure;}
 finally{if($settings===$missing){delete_option('fi_settings');}else{update_option('fi_settings',$settings);}}
 exit;
}
if(!in_array($command,array('measure','legacy','compile'),true)||!is_array($fixture)||($fixture['status']??'')!=='ready'||count($fixture['topics']??array())!==1002){throw new RuntimeException('Seed the complete owned vector fixture before measuring.');}
if(in_array($command,array('legacy','compile'),true)){
 if(!class_exists(ForumIntelligence\Domain\Search\PreparedVector::class)){throw new RuntimeException('Cache comparison requires the installed compiled-cache version.');}
 $ownedIds=$owned($fixture);$topics=$fixture['topics'];
 if(!array_is_list($topics)){throw new RuntimeException('Invalid owned topic descriptor.');}
 foreach($topics as $id){if(!is_int($id)||!in_array($id,$ownedIds,true)||get_post_type($id)!==bbp_get_topic_post_type()){throw new RuntimeException('Refuse touching an unowned vector comparison row.');}}
 $snapshot=static function()use($topics,$store):string{
  $digest=hash_init('sha256');
  foreach(array_chunk($topics,50) as $part){
   $marks=implode(',',array_fill(0,count($part),'%d'));
   foreach($store->rows("SELECT source_id,forum_id,source_type,content_hash,visibility,content,vector,model,updated_at FROM {$store->table('chunks')} WHERE source_id IN ($marks) ORDER BY source_id",$part) as $row){
    hash_update($digest,(string)wp_json_encode($row)."\0".ForumIntelligence\Infrastructure\Search\Indexer::hash((int)$row['source_id'])."\n");
   }
  }
  return hash_final($digest);
 };
 $beforeSnapshot=$snapshot();
 if($command==='compile'&&is_string($fixture['comparison_snapshot']??null)&&!hash_equals($fixture['comparison_snapshot'],$beforeSnapshot)){throw new RuntimeException('Owned canonical/native workload data changed between cache comparison modes.');}
 foreach(array_chunk($topics,50) as $batch){
  $marks=implode(',',array_fill(0,count($batch),'%d'));$table=$store->table('chunks');
  if($command==='legacy'){$store->execute("UPDATE $table SET vector_binary=NULL WHERE source_id IN ($marks)",$batch);continue;}
  foreach($store->rows("SELECT source_id,vector FROM $table WHERE source_id IN ($marks)",$batch) as $row){
   $frame=ForumIntelligence\Domain\Search\PreparedVector::encode($row['vector']);
   if($frame===null){$store->execute("UPDATE $table SET vector_binary=NULL WHERE source_id=%d AND BINARY vector=BINARY %s",array((int)$row['source_id'],$row['vector']));}
   else{$store->execute("UPDATE $table SET vector_binary=UNHEX(%s) WHERE source_id=%d AND BINARY vector=BINARY %s",array(bin2hex($frame),(int)$row['source_id'],$row['vector']));}
  }
 }
 if(!hash_equals($beforeSnapshot,$snapshot())){throw new RuntimeException('Cache comparison unexpectedly changed canonical/native workload data.');}
 $fixture['comparison_snapshot']=$beforeSnapshot;
 $fixture['representation']=$command==='legacy'?'json':'compiled';update_option($option,$fixture,false);
 echo "Only owned fixture cache representation changed to ".$fixture['representation']."; canonical JSON, source/model/hash/native data preserved. No provider calls.\n";exit;
}
if((string)(get_option('fi_settings',array())['embedding_model']??'text-embedding-3-small')!==$fixture['model']){throw new RuntimeException('Owned workload model namespace changed.');}
$ids=$fixture['topics'];$base=$vector();$vectors=new ForumIntelligence\Infrastructure\Search\LocalVectorStore($store);global $wpdb;
$report=array('fixture_run'=>$fixture['run'],'cache_representation'=>$fixture['representation']??'as-seeded','dataset'=>array('topics'=>1002,'dimensions'=>4096,'portable_candidate_bound'=>1000),'samples'=>array(),'memory_limit'=>ini_get('memory_limit'),'timestamp'=>gmdate('c'),'versions'=>array('wordpress'=>get_bloginfo('version'),'php'=>PHP_VERSION,'bbpress'=>bbp_get_version()));
foreach(array('cold_parallel'=>$base,'warm_parallel'=>$base,'warm_opposite'=>array_map(static fn(float $x):float=>-$x,$base)) as $name=>$query){
 $before=$wpdb->num_queries;$start=microtime(true);$nearest=$vectors->nearest($query,100);
 $report['samples'][]=array('name'=>$name,'milliseconds'=>round((microtime(true)-$start)*1000,2),'queries'=>$wpdb->num_queries-$before,'returned'=>count($nearest),'peak_mebibytes'=>round(memory_get_peak_usage(true)/1048576,2));
 if(count($nearest)!==100){throw new RuntimeException('Owned workload did not preserve the native return bound.');}
 foreach(array($ids[0],$ids[1],$ids[998],$ids[999],$ids[1000],$ids[1001]) as $excluded){if(isset($nearest[$excluded])){throw new RuntimeException('Vector workload admitted an outside-bound, protected, stale, wrong-model or corrupt source.');}}
 foreach($nearest as $id=>$score){if(!is_float($score)||!is_finite($score)||$score<-1||$score>1){throw new RuntimeException('Vector workload produced invalid similarity.');}}
 if($name==='warm_opposite'&&((int)array_key_first($nearest)!==$ids[997]||abs($nearest[$ids[997]]-1.0)>1.0e-12)){throw new RuntimeException('Vector workload lost opposite direction ordering.');}
 if($name!=='warm_opposite'){foreach($nearest as $score){if(abs($score-1.0)>1.0e-12){throw new RuntimeException('Vector workload lost parallel direction.');}}}
}
$report['current_source_and_bound_checks']=true;
file_put_contents(dirname(__DIR__).'/.runtime/vector-workload-report.json',wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo wp_json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
