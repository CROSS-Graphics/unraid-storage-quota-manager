<?php
declare(strict_types=1);
require_once __DIR__.'/lib.php';
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
if (!function_exists('posix_geteuid') || posix_geteuid()!==0) { fwrite(STDERR,"Root required\n"); exit(1); }
$m=new SQM\Manager(new SQM\System());
try {
    switch($argv[1]??'') {
        case 'verify':
            $n=SQM\Manager::name($argv[2]??'');
            if (file_exists($m->runtime.'/blocked-'.$n)) exit(1);
            foreach ($m->load() as $s) if ($s['name']===$n && $s['phase']==='ready' && $s['share'] && !$s['detached'] && $m->mounted($s)) exit(0);
            exit(1);
        case 'start': $m->lifecycle(true); break;
        case 'stop': $m->lifecycle(false); break;
        case 'upgrade-stop': $m->upgradeStop(); break;
        case 'upgrade-resume': $m->upgradeResume(); break;
        case 'install':
            $m->locked(function() use($m) {
                $version=parse_ini_file('/etc/unraid-version')['version']??'0';
                if (version_compare($version,'7.0','<') || version_compare($version,'8.0','>=')) throw new SQM\Fault('Supported target: Unraid 7.x; other versions require validation');
                foreach(['findmnt','losetup','mkfs.ext4','e2fsck','blkid','fallocate','mount','umount','testparm','smbcontrol','stat','chown','pgrep'] as $cmd) $m->sys->run(['which',$cmd]);
                $old=is_file($m->extra)?file_get_contents($m->extra):'';
                if (is_link($m->extra)) throw new SQM\Fault('SMB Extras is a symlink');
                $backup=$m->config.'/smb-extra.before-install';
                if (!file_exists($backup)) SQM\Manager::atomic($backup,$old);
                SQM\Manager::atomic($m->runtime.'/shares.conf',SQM\Manager::EMPTY_SMB); chmod($m->runtime.'/shares.conf',0644);
                if (!str_contains($old,SQM\Manager::INCLUDE_LINE)) SQM\Manager::atomic($m->extra,rtrim($old)."\n\n# quota-manager managed include\n[global]\n".SQM\Manager::INCLUDE_LINE."\n");
                // Boot plugin installation precedes array/Samba initialization. If no
                // smb.conf exists yet, defer the effective include check until start/create.
                try {
                    if (is_file('/etc/samba/smb.conf')) $m->assertIntegration();
                } catch (Throwable $e) { SQM\Manager::atomic($m->extra,$old); throw $e; }
                $m->sys->reload();
            }); break;
        case 'uninstall':
            $m->lifecycle(false);
            $m->locked(function() use($m) {
                $text=file_get_contents($m->extra);
                $text=str_replace("# quota-manager managed include\n[global]\n".SQM\Manager::INCLUDE_LINE."\n",'', $text);
                SQM\Manager::atomic($m->extra,$text);
                if (is_file('/etc/samba/smb.conf')) $m->sys->run(['testparm','-s','/etc/samba/smb.conf']); $m->sys->reload();
            }); break;
        default: throw new SQM\Fault('Usage: cli.php install|uninstall|start|stop|verify NAME');
    }
} catch (Throwable $e) { $m->sys->log($e->getMessage()); fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
