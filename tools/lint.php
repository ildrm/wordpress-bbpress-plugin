<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$excluded=array('vendor','node_modules','.runtime','dist','.git','.agents','.codex','.aws','.phpstan-cache');
$directories=new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS);
$filtered=new RecursiveCallbackFilterIterator($directories,static function(SplFileInfo $file)use($root,$excluded):bool{
    if($file->isLink()){return false;}
    $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
    return !in_array(explode('/',$relative)[0],$excluded,true);
});
$iterator=new RecursiveIteratorIterator($filtered);
$count=0;
foreach($iterator as $file){
    if(!$file->isFile()||$file->getExtension()!=='php'){continue;}
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1',$output,$status);
    if($status!==0){echo implode("\n",$output);exit(1);}
    $output=[];++$count;
}
echo "PHP syntax: $count files passed\n";
