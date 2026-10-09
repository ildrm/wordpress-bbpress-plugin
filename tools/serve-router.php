<?php
declare(strict_types=1);
$root=dirname(__DIR__).'/.runtime/wordpress';
$path=rawurldecode((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
// The development junction points into the project. Never expose its internals.
if (preg_match('~/(?:\.runtime|\.git|node_modules)(?:/|$)~',$path) || preg_match('~/wp-content/plugins/forum-intelligence-for-bbpress/(?:vendor|tests|tools|docs)(?:/|$)~',$path) || str_contains($path,'..')) { http_response_code(404); exit; }
$file=realpath($root.$path);
if ($path!=='/' && $file && is_file($file)) { return false; }
$_SERVER['SCRIPT_FILENAME']=$root.'/index.php';
require $root.'/index.php';
