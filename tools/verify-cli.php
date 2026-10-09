<?php
declare(strict_types=1);
$archive=new Phar(dirname(__DIR__).'/.runtime/wp-cli.phar');
$source=null;
foreach (new RecursiveIteratorIterator($archive) as $file) {
    if ($file->getFilename()==='class-wp-cli.php') { $source=file_get_contents($file->getPathname()); break; }
}
if (!is_string($source)) { throw new RuntimeException('WP-CLI source was not found.'); }
foreach (['add_command','line','success'] as $method) {
    if (!preg_match('/public static function '.preg_quote($method,'/').'\\s*\\(/',$source)) { throw new RuntimeException('Unverified WP-CLI method: '.$method); }
}
file_put_contents(dirname(__DIR__).'/.runtime/wp-cli-class.php',$source);
echo "Verified WP-CLI APIs and extracted their real source for static analysis.\n";
