<?php
declare(strict_types=1);
$_SERVER['REQUEST_METHOD']='GET';
require dirname(__DIR__).'/vendor/autoload.php';
if (PHP_OS_FAMILY==='Windows' && !getenv('OPENSSL_CONF') && is_file('C:/xampp/php/extras/ssl/openssl.cnf')) { putenv('OPENSSL_CONF=C:/xampp/php/extras/ssl/openssl.cnf'); }
$keys=Minishlink\WebPush\VAPID::createVapidKeys();
define('FI_AI_API_KEY','test-only-provider-key-never-use-in-production');
define('FI_VAPID_PUBLIC_KEY',$keys['publicKey']); define('FI_VAPID_PRIVATE_KEY',$keys['privateKey']); define('FI_VAPID_SUBJECT','mailto:test@example.test');
$runtime=getenv('FI_WP_PATH') ?: dirname(__DIR__).'/.runtime/wordpress';
if (!is_file($runtime.'/wp-load.php')) { throw new RuntimeException('Set FI_WP_PATH to an isolated WordPress test installation.'); }
require $runtime.'/wp-load.php';
