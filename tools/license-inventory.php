<?php
declare(strict_types=1);
/** Build-only license inventory. Preserve upstream bytes through namespace transformation. */
function fi_runtime_license_inventory(string $vendor,array $packages): array {
    $inventory=array();
    foreach($packages as $package){
        $name=(string)$package['name'];
        if(!preg_match('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D',$name)){throw new RuntimeException('Unexpected dependency name.');}
        $directory=$vendor.'/'.$name;
        if(!is_dir($directory)||is_link($directory)){throw new RuntimeException('Missing dependency directory: '.$name);}
        $files=array();
        foreach(new DirectoryIterator($directory) as $file){
            if($file->isFile()&&preg_match('/^(?:COPYING|LICEN[CS]E|NOTICE)(?:[._-].*)?$/i',$file->getFilename())){
                if($file->isLink()){throw new RuntimeException('Linked license file.');}
                $files['vendor/'.$name.'/'.$file->getFilename()]=hash_file('sha256',$file->getPathname());
            }
        }
        if(!$files){throw new RuntimeException('Dependency license/notice file missing: '.$name);}
        ksort($files);
        $inventory[$name]=array('licenses'=>$package['license']??array(),'files'=>$files);
    }
    ksort($inventory);
    return $inventory;
}
