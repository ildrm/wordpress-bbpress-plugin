<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$definitions=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/.runtime/bbpress'));
foreach ($iterator as $file) {
    if ($file->getExtension()!=='php') { continue; }
    preg_match_all('/function\\s+(bbp_\\w+)\\s*\\(/',file_get_contents($file->getPathname()),$matches);
    foreach ($matches[1] as $name) { $definitions[$name]=true; }
}
$missing=[]; $used=[];
$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
foreach ($iterator as $file) {
    if ($file->getExtension()!=='php') { continue; }
    preg_match_all('/\\b(bbp_\\w+)\\s*\\(/',file_get_contents($file->getPathname()),$matches);
    foreach ($matches[1] as $name) { $used[$name]=true; if (!isset($definitions[$name])) { $missing[$name]=$file->getPathname(); } }
}
if ($missing) { echo json_encode($missing,JSON_PRETTY_PRINT)."\n"; exit(1); }
echo 'Verified '.count($used)." bbPress function symbols against downloaded source.\n";
