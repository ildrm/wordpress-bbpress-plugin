<?php
declare(strict_types=1);
$runtime=getenv('FI_WP_PATH') ?: dirname(__DIR__).'/.runtime/wordpress';
if (!is_file($runtime.'/wp-load.php')) { fwrite(STDERR,"Set FI_WP_PATH to an isolated WordPress installation.\n"); exit(1); }
require $runtime.'/wp-load.php';
require dirname(__DIR__).'/tests/Integration/first-slice.php';
