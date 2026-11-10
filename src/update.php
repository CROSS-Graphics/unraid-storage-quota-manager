<?php
declare(strict_types=1);
namespace SQM;

/** Native Plugin Manager cache only: no remote checks and no invented update flag. */
function cachedPluginUpdate(string $installedPath='/var/log/plugins/quota-manager.plg', string $cacheDir='/tmp/plugins', string $os=''): array {
    $none=['available'=>false];
    if (!function_exists('simplexml_load_file') || !is_file($installedPath) || filesize($installedPath)>4194304) return $none;
    $prior=libxml_use_internal_errors(true);
    try {
        $installed=simplexml_load_file($installedPath,'SimpleXMLElement',LIBXML_NONET);
        if(!$installed || (string)$installed['name']!=='quota-manager')return $none;
        $url=(string)$installed['pluginURL'];
        if(!filter_var($url,FILTER_VALIDATE_URL) || strtolower((string)parse_url($url,PHP_URL_SCHEME))!=='https')return $none;
        $name=basename((string)parse_url($url,PHP_URL_PATH));
        if(!preg_match('/^[A-Za-z0-9_.-]+\.plg$/D',$name))return $none;
        $candidate=$cacheDir.'/'.$name;
        if(!is_file($candidate) || is_link($candidate) || filesize($candidate)>4194304)return $none;
        $new=simplexml_load_file($candidate,'SimpleXMLElement',LIBXML_NONET);
        if(!$new || (string)$new['name']!=='quota-manager')return $none;
        $version=(string)$new['version'];$current=(string)$installed['version'];
        if($version===''||$current===''||strcmp($version,$current)<=0)return $none;
        if($os==='')$os=@parse_ini_file('/etc/unraid-version')['version']??'';
        if($os==='')return $none;
        if((string)$new['min']!==''&&version_compare($os,(string)$new['min'],'<'))return $none;
        if((string)$new['max']!==''&&version_compare($os,(string)$new['max'],'>'))return $none;
        return ['available'=>true,'version'=>$version];
    } catch(\Throwable $e){return $none;}
    finally{libxml_clear_errors();libxml_use_internal_errors($prior);}
}
