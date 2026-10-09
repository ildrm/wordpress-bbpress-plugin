<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/.runtime/wordpress/wp-load.php';
if(get_option('home')!=='http://127.0.0.1:8898'){throw new RuntimeException('Only the disposable browser installation is allowed.');}
$mode=$argv[1]??'';$state=get_option('fi_e2e_template_fixture',false);
if($mode==='enable'){
 if($state!==false){throw new RuntimeException('Another template fixture owns this option.');}
 $fixture=json_decode(file_get_contents(dirname(__DIR__).'/.runtime/e2e.json'),true);
 if(!$fixture||get_post_meta((int)$fixture['forum'],'_fi_e2e_fixture',true)!=='1'||get_post_meta((int)$fixture['hidden'],'_fi_e2e_fixture',true)!=='1'){throw new RuntimeException('Seed the owned browser sources first.');}
 $state=['owner'=>'fi-context-template-v1','exists'=>get_option('fi_template_variants',false)!==false,'value'=>get_option('fi_template_variants',[]),'forum'=>(int)$fixture['forum']];
 add_option('fi_e2e_template_fixture',$state,'',false);
 try{
  $admin=(int)get_user_by('login','fi_admin')->ID;wp_set_current_user($admin);
  $rules=[
   ['id'=>'e2e-support','label'=>'Support guidance','forum'=>0,'topic_type'=>'Support','text'=>"SUPPORT_TEMPLATE_CANARY\nExpected behavior:"],
   ['id'=>'e2e-widget','label'=>'Widget support','forum'=>(int)$fixture['forum'],'topic_type'=>'Support','product'=>'Widget','text'=>"WIDGET_TEMPLATE_CANARY\nExpected behavior:\nProduct version:"],
   ['id'=>'e2e-troubleshooting','label'=>'Widget troubleshooting','forum'=>(int)$fixture['forum'],'topic_type'=>'Support','product'=>'Widget','category'=>'Troubleshooting','text'=>"CONTEXT_TEMPLATE_CANARY متن 😀\nProblem:\nSteps to reproduce:"],
   ['id'=>'e2e-private','label'=>'PRIVATE_TEMPLATE_LABEL_CANARY','forum'=>(int)$fixture['hidden'],'product'=>'PRIVATE_TEMPLATE_PRODUCT_CANARY','text'=>'PRIVATE_TEMPLATE_BODY_CANARY'],
  ];
  (new ForumIntelligence\Domain\Composer\Templates(new ForumIntelligence\Integration\BbPress\Access()))->configure($admin,wp_json_encode($rules));
 }catch(Throwable $failure){if($state['exists']){update_option('fi_template_variants',$state['value'],false);}else{delete_option('fi_template_variants');}delete_option('fi_e2e_template_fixture');throw $failure;}
}elseif($mode==='restore'){
 if(!is_array($state)||($state['owner']??'')!=='fi-context-template-v1'){throw new RuntimeException('No owned template fixture to restore.');}
 if($state['exists']){update_option('fi_template_variants',$state['value'],false);}else{delete_option('fi_template_variants');}
 delete_option('fi_e2e_template_fixture');
}else{throw new RuntimeException('Use enable or restore.');}
echo "Owned contextual template fixture ".$mode." complete.\n";
