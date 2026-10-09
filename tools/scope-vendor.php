<?php
declare(strict_types=1);
$workspace=dirname(__DIR__);
$stage=realpath($argv[1]??'');
$runtime=realpath($workspace.'/.runtime');
if(!$stage||!$runtime||dirname($stage)!==$runtime||!preg_match('/^package-forum-intelligence-for-bbpress-[0-9]{14}-[a-f0-9]{8}$/',basename($stage))){throw new RuntimeException('Scope only a verified package staging directory.');}
$phar=getenv('FI_SCOPER_PHAR')?:$runtime.'/php-scoper.phar';
if(!is_file($phar)||!hash_equals('170fb84bd3390defb30f99f7dc39c9a89d10c29973accc26f31c00abc5b25933',hash_file('sha256',$phar))){throw new RuntimeException('Obtain verified PHP-Scoper 0.18.19 before packaging.');}
$output=$runtime.'/scoped-vendor-'.basename($stage);
if(file_exists($output)){throw new RuntimeException('Use a fresh staging directory for scoping.');}
$run=static function(array $command,string $directory): void {
 $process=proc_open($command,array(0=>STDIN,1=>STDOUT,2=>STDERR),$pipes,$directory);
 if(!is_resource($process)||proc_close($process)!==0){throw new RuntimeException('Dependency scoping failed.');}
};
$run(array(PHP_BINARY,$phar,'add-prefix','vendor','composer.json','composer.lock','--config='.$workspace.'/tools/scoper.inc.php','--output-dir='.$output,'--no-interaction' ,'--quiet'),$stage);
$composer=getenv('FI_COMPOSER_PHAR');
$command=$composer?array(PHP_BINARY,$composer):array('composer');
$run(array_merge($command,array('dump-autoload','--no-dev','--no-scripts','--no-plugins','--optimize','--no-interaction')),$output);
$original=$runtime.'/raw-vendor-'.basename($stage);
if(file_exists($original)||!rename($stage.'/vendor',$original)||!rename($output.'/vendor',$stage.'/vendor')){throw new RuntimeException('Could not install the scoped vendor into the fresh package stage.');}
file_put_contents($stage.'/vendor/fi-scoped.json',json_encode(array('prefix'=>'ForumIntelligenceVendor','tool'=>'humbug/php-scoper','version'=>'0.18.19','sha256'=>hash_file('sha256',$phar)),JSON_PRETTY_PRINT)."\n");
echo "Runtime dependency namespaces isolated.\n";
