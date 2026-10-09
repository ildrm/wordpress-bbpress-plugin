<?php
declare(strict_types=1);
/** Provision only a new disposable test installation; never overwrite wp-config.php. */
$root=dirname(__DIR__);
$directory=$root.'/.runtime/wordpress';
if(is_file($directory.'/wp-config.php')){fwrite(STDERR,"Refusing to overwrite an existing test configuration. Set up a new checkout or use the existing isolated site.\n");exit(1);}
$cli=$root.'/.runtime/wp-cli.phar';
if(!is_file($cli)){throw new RuntimeException('Download official WP-CLI to .runtime/wp-cli.phar first.');}
$run=static function(array $args) use($cli,$directory): void {
 $command=array_merge([PHP_BINARY,$cli,'--path='.$directory,'--allow-root'],$args);
 $process=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes);
 if(!is_resource($process)||proc_close($process)!==0){throw new RuntimeException('Isolated WordPress setup command failed.');}
};
$version=getenv('FI_WP_VERSION')?:'7.1';
$run(['core','download','--version='.$version]);
$run(['core','verify-checksums']);
$run(['config','create','--dbname='.(getenv('FI_TEST_DB')?:'fi_test'),'--dbuser='.(getenv('FI_TEST_DB_USER')?:'root'),'--dbpass='.(getenv('FI_TEST_DB_PASSWORD')?:''),'--dbhost='.(getenv('FI_TEST_DB_HOST')?:'127.0.0.1:3306')]);
$run(['config','set','DISABLE_WP_CRON','true','--raw']);
$run(['config','set','WP_DEBUG','true','--raw']);
$run(['core','install','--url=http://127.0.0.1:8898','--title=Forum Intelligence Test','--admin_user=fi_admin','--admin_password=isolated-test-only','--admin_email=admin@example.test','--skip-email']);
$run(['plugin','install','bbpress','--version=2.6.19','--activate']);
$run(['plugin','install','buddypress','--version=14.5.2','--activate']);
$link=$directory.'/wp-content/plugins/forum-intelligence-for-bbpress';
if(!symlink($root,$link)){throw new RuntimeException('Link the checkout into the isolated plugins directory; Windows may require a directory junction.');}
$run(['plugin','activate','forum-intelligence-for-bbpress']);
$bbpress=$directory.'/wp-content/plugins/bbpress';
if(!is_dir($root.'/.runtime/bbpress')&&!symlink($bbpress,$root.'/.runtime/bbpress')){throw new RuntimeException('Link native bbPress source for static analysis.');}
if(!is_dir($root.'/.runtime/bp-source')){mkdir($root.'/.runtime/bp-source',0755,true);}
if(!is_dir($root.'/.runtime/bp-source/buddypress')&&!symlink($directory.'/wp-content/plugins/buddypress',$root.'/.runtime/bp-source/buddypress')){throw new RuntimeException('Link native BuddyPress source for static analysis.');}
$run(['eval-file',$root.'/tools/setup-buddypress.php']);
echo "Isolated test site ready.\n";
