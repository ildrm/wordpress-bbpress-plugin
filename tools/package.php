<?php
declare(strict_types=1);
/** Build an isolated installable review package; does not publish or change the source vendor. */
$root=dirname(__DIR__);
$name='forum-intelligence-for-bbpress';
$stage=$root.'/.runtime/package-'.$name.'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
if(file_exists($stage)){throw new RuntimeException('Package staging already exists; choose a clean checkout or move the verified previous stage.');}
mkdir($stage,0755,true);
$copy=static function(string $from,string $to) use(&$copy): void {
 if(is_link($from)){throw new RuntimeException('Unexpected symbolic link in distribution input.');}
 if(is_dir($from)){if(!is_dir($to)){mkdir($to,0755,true);}foreach(new DirectoryIterator($from) as $item){if(!$item->isDot()){$copy($item->getPathname(),$to.'/'.$item->getFilename());}}return;}
 if(!copy($from,$to)){throw new RuntimeException('Distribution copy failed.');}
};
foreach(array('src','assets/build','assets/icons','languages','autoload.php','forum-intelligence-for-bbpress.php','uninstall.php','composer.json','composer.lock','README.md','readme.txt','LICENSE','THIRD_PARTY.md') as $path){$destination=$stage.'/'.$path;if(!is_dir(dirname($destination))){mkdir(dirname($destination),0755,true);}$copy($root.'/'.$path,$destination);}
mkdir($stage.'/docs',0755,true);
foreach(array('ARCHITECTURE','DATA_MODEL','SECURITY','PRIVACY','EXTENDING','ADMIN_GUIDE','ASSUMPTIONS','REST_API','OPERATIONS','VERIFICATION','REQUIREMENTS','DEVELOPMENT','OPTIMIZED_PROMPT','ORIGINAL_PROMPT','FINAL_REVIEW') as $document){$copy($root.'/docs/'.$document.'.md',$stage.'/docs/'.$document.'.md');}
$copy($root.'/docs/adr',$stage.'/docs/adr');
// Ship every local documentation target referenced by the distribution.
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS)) as $document){
 if(!$document->isFile()||strtolower($document->getExtension())!=='md'){continue;}
 preg_match_all('/\\[[^\\]]*\\]\\(([^)]+)\\)/',file_get_contents($document->getPathname()),$links);
 foreach($links[1] as $target){
  $target=trim($target);
  if($target===''||$target[0]==='#'||preg_match('/^[a-z][a-z0-9+.-]*:/i',$target)){continue;}
  $target=rawurldecode(trim(preg_split('/[#?]/',$target,2)[0],'<>'));
  $resolved=realpath(dirname($document->getPathname()).'/'.$target);
  $contained=$resolved&&str_starts_with(str_replace(DIRECTORY_SEPARATOR,'/',$resolved),str_replace(DIRECTORY_SEPARATOR,'/',realpath($stage)).'/');
  if(!$contained||!is_file($resolved)){throw new RuntimeException('Unpackaged local documentation target: '.$target);}
 }
}

$phar=getenv('FI_COMPOSER_PHAR');
$command=$phar?array(PHP_BINARY,$phar):array('composer');
$command=array_merge($command,array('install','--no-dev','--prefer-dist','--no-scripts','--no-plugins','--optimize-autoloader','--no-interaction'));
$process=proc_open($command,array(0=>STDIN,1=>STDOUT,2=>STDERR),$pipes,$stage);
if(!is_resource($process)||proc_close($process)!==0){throw new RuntimeException('Production dependency install failed; do not distribute the staging directory.');}
$installed=json_decode(file_get_contents($stage.'/vendor/composer/installed.json'),true);
$lock=json_decode(file_get_contents($stage.'/composer.lock'),true);
$expected=array_column($lock['packages'],'name');$actual=array_column($installed['packages'],'name');sort($expected);sort($actual);
if($expected!==$actual){throw new RuntimeException('Production dependency inventory differs from the lock.');}
require_once __DIR__.'/license-inventory.php';
$licenses=fi_runtime_license_inventory($stage.'/vendor',$lock['packages']);
$scoper=proc_open(array(PHP_BINARY,$root.'/tools/scope-vendor.php',$stage),array(0=>STDIN,1=>STDOUT,2=>STDERR),$pipes,$root);
if(!is_resource($scoper)||proc_close($scoper)!==0){throw new RuntimeException('Do not distribute unscoped runtime dependencies.');}
if($licenses!==fi_runtime_license_inventory($stage.'/vendor',$lock['packages'])){throw new RuntimeException('Scoping modified or removed upstream licenses/notices.');}
file_put_contents($stage.'/third-party-licenses.json',json_encode($licenses,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
$paths=array();
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage,FilesystemIterator::SKIP_DOTS)) as $file){
 if($file->isLink()){throw new RuntimeException('Symbolic link in package.');}
 if($file->isFile()){$relative=str_replace('\\','/',substr($file->getPathname(),strlen($stage)+1));$paths[$relative]=$file->getPathname();}
}
ksort($paths);
$manifest=array();
foreach($paths as $relative=>$path){$manifest[$relative]=hash_file('sha256',$path);}
file_put_contents($stage.'/manifest.sha256.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
$paths['manifest.sha256.json']=$stage.'/manifest.sha256.json';ksort($paths);
if(!is_dir($root.'/dist')){mkdir($root.'/dist',0755,true);}
$target=$root.'/dist/'.$name.'-0.1.0-review.zip';
$temporary=$root.'/dist/'.$name.'-'.bin2hex(random_bytes(8)).'.zip';$zip=new ZipArchive();
if($zip->open($temporary,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true){throw new RuntimeException('Could not create ZIP.');}
foreach($paths as $relative=>$path){
 $entry=$name.'/'.$relative;
 $zip->addFile($path,$entry);$zip->setMtimeName($entry,315532800);
 $zip->setExternalAttributesName($entry,ZipArchive::OPSYS_UNIX,0100644<<16);
}
if(!$zip->close()){throw new RuntimeException('Could not finish ZIP.');}
if(!rename($temporary,$target)){throw new RuntimeException('Could not atomically replace the ZIP; close readers and retry. The previous artifact remains unchanged.');}
file_put_contents($target.'.sha256',hash_file('sha256',$target).'  '.basename($target)."\n");
echo count($paths)." production files, ".count($actual)." runtime dependencies packaged at ".$target."\n";
