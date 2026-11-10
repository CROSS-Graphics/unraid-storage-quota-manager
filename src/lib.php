<?php
declare(strict_types=1);

namespace SQM;

class Fault extends \RuntimeException {}

function csrf(mixed $submitted, mixed $expected): bool {
    return is_string($submitted) && is_string($expected) && $expected!=='' && hash_equals($expected,$submitted);
}
function requestCsrf(array $post, array $server, mixed $expected): bool {
    // Unraid local_prepend.php validates and removes both native POST/header tokens.
    // Keep a separately named copy for this endpoint's independent check.
    return csrf($post['sqm_csrf_token'] ?? null,$expected);
}

/** All external commands are argument arrays, never interpreted by a shell. */
class System {
    public function run(array $argv, array $ok = [0]): string {
        $p = proc_open($argv, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, null,
            ['PATH'=>'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL'=>'C']);
        if (!is_resource($p)) throw new Fault('Cannot start command');
        fclose($pipes[0]);
        // Drain both pipes without blocking to avoid pipe-buffer deadlocks.
        stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
        $out=''; $err='';
        do {
            $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
            $s=proc_get_status($p);
            if ($s['running']) usleep(20000);
        } while ($s['running']);
        $out .= stream_get_contents($pipes[1]); $err .= stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $rc=proc_close($p);
        if ($rc === -1) $rc=$s['exitcode'];
        if (!in_array($rc,$ok,true)) throw new Fault(basename($argv[0]).': '.substr(trim($err),0,1200));
        return trim($out);
    }
    public function mounts(): array {
        return json_decode($this->run(['findmnt','--json','--list','--output','TARGET,SOURCE,FSTYPE,OPTIONS,UUID']),true,512,JSON_THROW_ON_ERROR)['filesystems'] ?? [];
    }
    public function users(): array {
        $users=[];
        foreach (file('/etc/passwd',FILE_IGNORE_NEW_LINES) as $line) {
            $p=explode(':',$line);
            if ((int)$p[2]>=1000 && (int)$p[2]<65534 && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/D',$p[0])) $users[]=$p[0];
        }
        return $users;
    }
    public function free(string $p): int { return (int)disk_free_space($p); }
    public function localBlockDevice(string $source): bool {
        $source=preg_replace('/\[.*\]$/','',$source);
        if (!preg_match('~^/dev/[A-Za-z0-9_./-]+$~D',$source) || str_contains($source,'..') || preg_match('~^/dev/(loop|ram|zram)[0-9]+~',$source)) return false;
        $device=realpath($source);
        return $device!==false && !preg_match('~^/dev/(loop|ram|zram)[0-9]+~',$device) && @filetype($device)==='block';
    }
    public function filesystemBytes(string $p): int { clearstatcache();return (int)disk_total_space($p); }
    public function allocated(string $p): int {
        return (int)$this->run(['stat','-c','%b',$p])*512;
    }
    public function shares(): array {
        $s=$this->run(['testparm','-s','/etc/samba/smb.conf']);
        preg_match_all('/^\[([^\]]+)\]/m',$s,$m); return $m[1];
    }
    /** Inspect source includes too: testparm merges duplicate section names. */
    public function externalShares(string $managed): array {
        $seen=[]; $names=[];
        $visit=function(string $path,int $depth=0) use(&$visit,&$seen,&$names,$managed) {
            if ($depth>20) throw new Fault('Samba include nesting exceeds safe limit');
            if ($path===$managed || isset($seen[$path])) return;
            $seen[$path]=true;
            if (!is_file($path)) return; // Samba permits missing optional includes.
            $text=file_get_contents($path);
            $text=preg_replace('/\\\\\r?\n/','',$text);
            foreach (preg_split('/\r?\n/',$text) as $line) {
                $line=trim($line); if ($line==='' || $line[0]==='#' || $line[0]===';') continue;
                if (preg_match('/^\[([^\]]+)\]/',$line,$m)) $names[]=trim($m[1]);
                if (preg_match('/^include\s*=\s*(.+)$/i',$line,$m)) {
                    $inc=trim($m[1]);
                    if (!str_starts_with($inc,'/') || str_contains($inc,'%') || strpbrk($inc,'*?')!==false) throw new Fault('Dynamic/registry Samba includes require manual compatibility validation');
                    $visit($inc,$depth+1);
                }
                if (preg_match('/^(registry shares|usershare max shares)\s*=\s*(yes|[1-9][0-9]*)$/i',$line)) throw new Fault('Registry/usershares are unsupported for conflict-safe registration');
            }
        };
        $visit('/etc/samba/smb.conf'); return array_values(array_unique($names));
    }
    public function reload(): void {
        // A stopped array may have no Samba daemon. Nothing can be served then.
        $running=$this->run(['pgrep','-x','smbd'],[0,1]);
        if ($running!=='') $this->run(['smbcontrol','all','reload-config']);
    }
    public function closeShare(string $name): void {
        if ($this->run(['pgrep','-x','smbd'],[0,1])!=='') $this->run(['smbcontrol','all','close-share',$name]);
    }
    public function log(string $msg): void { openlog('quota-manager',LOG_PID,LOG_USER); syslog(LOG_NOTICE,$msg); }
}

class Manager {
    public const INCLUDE_LINE='include = /run/quota-manager/shares.conf';
    public const EMPTY_SMB="# Managed by quota-manager\n[sqm_internal_guard]\npath = /run/quota-manager/nonexistent\navailable = no\nbrowseable = no\nguest ok = no\n\n";
    private $lock;
    public function __construct(public System $sys, public string $config='/boot/config/plugins/quota-manager',
        public string $runtime='/run/quota-manager', public string $extra='/boot/config/smb-extra.conf') {}
    public static function name(string $n): string {
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,39}$/D',$n) || preg_match('/^disk[0-9]+$/iD',$n) || in_array(strtolower($n),['global','homes','printers','print','ipc','flash','user','user0','sqm_internal_guard'])) throw new Fault('Invalid or reserved storage name');
        return $n;
    }
    public static function capacity(mixed $v, string $unit): int {
        if (!is_scalar($v) || !preg_match('/^[1-9][0-9]{0,5}$/D',(string)$v) || !in_array($unit,['GB','GiB'],true)) throw new Fault('Capacity must be an integer in GB or GiB');
        $bytes=(int)$v*($unit==='GB'?1000000000:1073741824);
        if ($bytes>100000000000000) throw new Fault('Maximum capacity is 100 TB');
        return $bytes;
    }
    public static function filesystem(mixed $value='ext4'): string {
        if (!is_string($value) || !in_array($value,['ext4','xfs','btrfs'],true)) throw new Fault('Choose ext4, XFS or Btrfs');
        return $value;
    }
    public static function formatCommand(string $fs,string $image): array {
        return match(self::filesystem($fs)) {
            'ext4'=>['mkfs.ext4','-q','-m','0','-E','nodiscard,lazy_itable_init=0,lazy_journal_init=0',$image],
            'xfs'=>['mkfs.xfs','-q','-K',$image],
            'btrfs'=>['mkfs.btrfs','-q','--nodiscard',$image],
        };
    }
    public function checkFilesystem(string $fs,string $image): void {
        match(self::filesystem($fs)) {
            // -n opens read-only and answers "no": never preen/repair automatically.
            'ext4'=>$this->sys->run(['e2fsck','-n',$image]),
            'xfs'=>$this->sys->run(['xfs_repair','-n',$image]),
            'btrfs'=>$this->sys->run(['btrfs','check','--readonly',$image]),
        };
    }
    public static function noLinks(string $p): void {
        $part='';
        foreach (explode('/',ltrim($p,'/')) as $s) {
            if ($s==='' || $s==='.' || $s==='..') throw new Fault('Unsafe path');
            $part.='/'.$s; if (is_link($part)) throw new Fault('Symbolic links are forbidden');
        }
    }
    public function locations(): array {
        $r=[];
        foreach ($this->sys->mounts() as $m) {
            $localRoot=preg_match('~^/mnt/[A-Za-z][A-Za-z0-9_-]*$~D',$m['target']) && !in_array(basename($m['target']),['user','user0','disks','remotes','addons']);
            $externalRoot=preg_match('~^/mnt/disks/[A-Za-z0-9][A-Za-z0-9_. -]{0,127}$~D',$m['target']);
            if (($localRoot || $externalRoot) && $this->sys->localBlockDevice($m['source']??'') &&
                in_array($m['fstype'],['xfs','btrfs','ext4']) && !str_contains(','.$m['options'].',',',ro,')) $r[]=$m['target'];
        }
        return array_values(array_unique($r));
    }
    public function location(string $p): string {
        self::noLinks($p);
        if (!preg_match('~^/mnt/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_. -]+)*$~D',$p) || !is_dir($p) || realpath($p)!==$p) throw new Fault('Use an existing canonical local disk directory');
        foreach ($this->locations() as $root) {
            if ($p===$root || str_starts_with($p,$root.'/')) {
                // Reject nested mounts: custom path must remain on the approved filesystem.
                foreach ($this->sys->mounts() as $m) if ($m['target']!==$root && str_starts_with($m['target'],$root.'/') && ($p===$m['target'] || str_starts_with($p,$m['target'].'/'))) throw new Fault('Nested mount is not permitted');
                return $p;
            }
        }
        throw new Fault('Selected disk/pool is unavailable or unsupported');
    }
    public static function atomic(string $path, string $data): void {
        if (is_link($path)) throw new Fault('Symlink at configuration path');
        $tmp=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        $h=fopen($tmp,'x'); if (!$h) throw new Fault('Cannot create configuration');
        try { if (fwrite($h,$data)!==strlen($data)) throw new Fault('Short configuration write'); fflush($h); if(function_exists('fsync')) fsync($h); } finally { fclose($h); }
        chmod($tmp,0600);
        if (!rename($tmp,$path)) { unlink($tmp); throw new Fault('Cannot commit configuration'); }
    }
    public function locked(callable $f): mixed {
        foreach ([$this->config,$this->runtime] as $p) { if (!is_dir($p) && !mkdir($p,0700,true)) throw new Fault('Cannot create private directory'); }
        $h=fopen($this->runtime.'/lock','c');
        if (!$h || !flock($h,LOCK_EX|LOCK_NB)) throw new Fault('Another operation is running; retry later');
        $this->lock=$h;
        chmod($this->runtime,0711);
        try { return $f(); } finally { flock($h,LOCK_UN); fclose($h); }
    }
    public function load(): array {
        $p=$this->config.'/storages.json';
        if (!file_exists($p)) return [];
        $d=json_decode(file_get_contents($p),true,512,JSON_THROW_ON_ERROR);
        if (!is_array($d) || ($d['schema']??null)!==1 || !is_array($d['storages'])) throw new Fault('Invalid configuration; restore backup');
        return $d['storages'];
    }
    public function save(array $all): void { self::atomic($this->config.'/storages.json',json_encode(['schema'=>1,'storages'=>$all],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)); }
    public function image(array $s): string {
        self::name($s['name']); $base=$this->location($s['location']);
        $path=$base.'/.quota_manager/'.$s['name'].'.img'; self::noLinks($path);
        if (!is_file($path) || filetype($path)!=='file') throw new Fault('Image missing or not a regular file');
        if ((stat($path)['nlink']??0)!==1) throw new Fault('Hard-linked images are forbidden');
        if (filesize($path)!==$s['bytes']) throw new Fault('Image size differs from configuration; recovery required');
        return $path;
    }
    public function target(array $s): string { return $this->runtime.'/mounts/'.self::name($s['name']); }
    public function mounted(array $s): bool {
        try {
            $image=$this->image($s); $target=$this->target($s);
            foreach ($this->sys->mounts() as $m) if ($m['target']===$target && $m['fstype']===self::filesystem($s['filesystem']??'ext4') && ($m['uuid']??'')===$s['uuid'] && !str_contains(','.$m['options'].',',',ro,')) {
                $source=$m['source']; if (!preg_match('~^/dev/loop[0-9]+$~D',$source)) return false;
                $j=json_decode($this->sys->run(['losetup','--json','--output','NAME,BACK-FILE',$source]),true,512,JSON_THROW_ON_ERROR);
                return ($j['loopdevices'][0]['back-file']??null)===$image;
            }
        } catch (\Throwable $e) { return false; }
        return false;
    }
    public function loopDevice(array $s): string {
        if (!$this->mounted($s)) throw new Fault('Mounted filesystem identity verification failed');
        $target=$this->target($s);
        foreach ($this->sys->mounts() as $m) if ($m['target']===$target && preg_match('~^/dev/loop[0-9]+$~D',$m['source'])) return $m['source'];
        throw new Fault('Loop device for mounted image not found');
    }
    public function validateUsers(array $users): array {
        if (!$users || count($users)>32) throw new Fault('Select 1–32 existing users');
        $known=$this->sys->users();
        foreach ($users as $u) if (!is_string($u) || !in_array($u,$known,true)) throw new Fault('Unknown or prohibited user');
        return array_values(array_unique($users));
    }
    public function permissions(array $users, mixed $value=null): array {
        // Records created before 1.1 retain their original read/write access.
        if ($value===null) return array_fill_keys($users,'rw');
        if (!is_array($value) || count($value)!==count($users)) throw new Fault('Specify permissions for every selected user');
        foreach ($value as $user=>$mode) if (!in_array($user,$users,true) || !in_array($mode,['read','rw'],true)) throw new Fault('Invalid user permission');
        return $value;
    }
    public function conflict(string $name, array $all): void {
        foreach ($all as $s) if (strcasecmp($name,$s['name'])===0) throw new Fault('Storage name already registered');
        foreach ($this->sys->shares() as $n) if (strcasecmp($name,$n)===0) throw new Fault('SMB name is already in use');
        foreach (glob('/boot/config/shares/*.cfg') ?: [] as $p) if (strcasecmp($name,basename($p,'.cfg'))===0) throw new Fault('Unraid share name is already in use');
    }
    public function render(array $all): string {
        $out=self::EMPTY_SMB;
        $foreign=$this->sys->externalShares($this->runtime.'/shares.conf');
        foreach ($all as $s) if (($s['share']??false) && !($s['detached']??false) && ($s['phase']??'')==='ready' && $this->mounted($s)) {
            foreach ($foreign as $name) if (strcasecmp($name,$s['name'])===0) throw new Fault('Conflicting external SMB section: '.$s['name']);
            $users=implode(' ',$this->validateUsers($s['users'])); $n=self::name($s['name']);
            $permissions=$this->permissions($s['users'],$s['permissions']??null);
            $writers=implode(' ',array_keys(array_filter($permissions,fn($mode)=>$mode==='rw')));
            $readers=implode(' ',array_keys(array_filter($permissions,fn($mode)=>$mode==='read')));
            $out.="[$n]\npath = ".$this->target($s)."\nbrowseable = yes\nmax connections = 0\nread only = yes\nguest ok = no\nvalid users = $users\nwrite list = $writers\nread list = $readers\nforce user = nobody\nforce group = users\ncreate mask = 0660\ndirectory mask = 0770\nwide links = no\nfollow symlinks = no\nroot preexec = /usr/bin/php /usr/local/emhttp/plugins/quota-manager/cli.php verify $n\nroot preexec close = yes\n\n";
        }
        return $out;
    }
    public function publish(array $all): void {
        $p=$this->runtime.'/shares.conf'; $old=is_file($p)?file_get_contents($p):self::EMPTY_SMB;
        self::atomic($p,$this->render($all)); chmod($p,0644);
        try {
            $this->assertIntegration();
            $this->sys->reload();
            foreach ($all as $s) if ($s['share'] && !$s['detached'] && $s['phase']==='ready' && $this->mounted($s)) {
                $blocked=$this->runtime.'/blocked-'.$s['name']; if (is_file($blocked)) unlink($blocked);
            }
        } catch (\Throwable $e) { self::atomic($p,$old); chmod($p,0644); throw $e; }
    }
    public function assertIntegration(): void {
        if (!in_array('sqm_internal_guard',$this->sys->shares(),true)) throw new Fault('Samba include is not active. Reinstall plugin after checking SMB Extras integration.');
    }
    public function mount(array &$s): void {
        if ($this->mounted($s)) return;
        $image=$this->image($s); $target=$this->target($s);
        self::noLinks($target);
        foreach ($this->sys->mounts() as $m) if ($m['target']===$target) throw new Fault('Mount target occupied');
        if (!is_dir(dirname($target))) mkdir(dirname($target),0711,true);
        if (!is_dir($target)) mkdir($target,0000);
        if (count(scandir($target))!==2) throw new Fault('Mount target not empty');
        chmod($target,0000);
        $uuid=$this->sys->run(['blkid','-s','UUID','-o','value',$image]);
        if ($uuid!==$s['uuid']) throw new Fault('Filesystem UUID mismatch');
        $fs=self::filesystem($s['filesystem']??'ext4');
        if ($this->sys->run(['blkid','-s','TYPE','-o','value',$image])!==$fs) throw new Fault('Filesystem type mismatch');
        // Never repair automatically, and never check a mounted image.
        $loops=$this->sys->run(['losetup','-j',$image]);
        if ($loops!=='') throw new Fault('Image already attached; inspect orphan loop before recovery');
        $this->checkFilesystem($fs,$image);
        $loop=$this->sys->run(['losetup','--find','--show','--nooverlap',$image]);
        if (!preg_match('~^/dev/loop[0-9]+$~D',$loop)) throw new Fault('Invalid loop device');
        try {
            $this->sys->run(['mount','-t',$fs,'-o','nodev,nosuid,noexec,nodiscard',$loop,$target]);
            if (!$this->mounted($s)) throw new Fault('Mounted filesystem identity verification failed');
            $this->sys->run(['chown','nobody:users',$target]); chmod($target,0770);
        } catch (\Throwable $e) {
            // Retain the loop if a mount exists: never detach an in-use device.
            $occupied=false; foreach ($this->sys->mounts() as $m) if ($m['target']===$target) $occupied=true;
            if (!$occupied) $this->sys->run(['losetup','-d',$loop]);
            throw $e;
        }
    }
    public function unmount(array $s, array $all): void {
        self::atomic($this->runtime.'/blocked-'.$s['name'],'blocked');
        $offline=$all; foreach ($offline as &$v) if ($v['name']===$s['name']) $v['share']=false; unset($v);
        $this->publish($offline);
        $this->sys->closeShare($s['name']);
        $target=$this->target($s);
        foreach ($this->sys->mounts() as $m) if ($m['target']===$target) {
            if (!$this->mounted($s)) throw new Fault('Unexpected mount identity; manual inspection required');
            $this->sys->run(['umount',$target]); // Busy mounts fail; no force/lazy fallback.
            chmod($target,0000);
            $this->sys->run(['losetup','-d',$m['source']]);
        }
    }
    public function create(array $input): array {
        $this->assertIntegration();
        $all=$this->load(); $n=self::name((string)($input['name']??'')); $this->conflict($n,$all);
        $location=$this->location((string)($input['location']??''));
        $bytes=self::capacity($input['capacity']??'',(string)($input['unit']??''));
        $users=$this->validateUsers($input['users']??[]);
        $permissions=$this->permissions($users,$input['permissions']??null);
        $fs=self::filesystem($input['filesystem']??'ext4');
        if (($input['access']??'private')!=='private') throw new Fault('Only private access is supported');
        foreach (match($fs) {'ext4'=>['mkfs.ext4','e2fsck'],'xfs'=>['mkfs.xfs','xfs_repair'],'btrfs'=>['mkfs.btrfs','btrfs']} as $tool) $this->sys->run(['which',$tool]);
        if ($this->sys->free($location)<$bytes+1073741824) throw new Fault('Insufficient free space (1 GiB safety reserve required)');
        $dir=$location.'/.quota_manager'; self::noLinks($dir);
        if (!is_dir($dir)) mkdir($dir,0700);
        elseif (PHP_OS_FAMILY!=='Windows' && (fileowner($dir)!==0 || (fileperms($dir)&0077)!==0)) throw new Fault('Image directory must be root-owned and mode 0700');
        chmod($dir,0700);
        $image=$dir.'/'.$n.'.img';
        if (file_exists($image) || is_link($image)) throw new Fault('Existing image preserved; use recovery instructions');
        $s=['name'=>$n,'location'=>$location,'bytes'=>$bytes,'filesystem'=>$fs,'users'=>$users,'permissions'=>$permissions,'auto'=>($input['auto']??false)===true,'share'=>true,'detached'=>false,'uuid'=>'','phase'=>'allocating'];
        $all[]=$s; $i=array_key_last($all); $this->save($all); // Durable intent precedes image creation.
        $new=false;
        try {
            $h=fopen($image,'x'); if (!$h) throw new Fault('Cannot exclusively create image'); fclose($h); $new=true; chmod($image,0600);
            $this->sys->run(['fallocate','-l',(string)$bytes,$image]);
            clearstatcache(true,$image);
            if (filesize($image)!==$bytes || $this->sys->allocated($image)<$bytes) throw new Fault('Full reservation not verified');
            // mkfs receives ONLY this freshly, exclusively created regular image. No -F.
            if (filetype($image)!=='file' || is_link($image)) throw new Fault('Unsafe image type');
            $this->sys->run(self::formatCommand($fs,$image));
            if ($this->sys->allocated($image)<$bytes) throw new Fault('Reservation lost during formatting');
            $s['uuid']=$this->sys->run(['blkid','-s','UUID','-o','value',$image]);
            if (!preg_match('/^[a-f0-9-]{36}$/D',$s['uuid'])) throw new Fault('Invalid new filesystem UUID');
            $s['phase']='ready'; $all[$i]=$s; $this->save($all);
            $this->mount($s); $this->publish($all);
            $this->sys->log('Created '.$n); return $s;
        } catch (\Throwable $e) {
            // Rollback conservatively. If cleanup fails, preserve durable record and image.
            try {
                $this->unmount($s,$all);
                if ($new && is_file($image) && !is_link($image)) unlink($image);
                array_pop($all); $this->save($all);
            } catch (\Throwable $cleanup) {
                $all[$i]['phase']='recovery'; $all[$i]['error']=$cleanup->getMessage(); $this->save($all);
            }
            throw $e;
        }
    }
    public function grow(array &$all,int $i,array $input): void {
        $s=$all[$i];$originalBytes=$s['bytes'];
        if ($s['phase']!=='ready' || $s['detached']) throw new Fault('Mount or recover this storage before resizing');
        $bytes=self::capacity($input['capacity']??'',(string)($input['unit']??''));
        if ($bytes<$s['bytes']) throw new Fault('Shrinking is prohibited; choose a larger capacity');
        if ($bytes===$s['bytes']) return;
        $image=$this->image($s);$fs=self::filesystem($s['filesystem']??'ext4');
        $tool=match($fs){'ext4'=>'resize2fs','xfs'=>'xfs_growfs','btrfs'=>'btrfs'};
        $this->sys->run(['which',$tool]);
        if ($this->sys->free($s['location'])<$bytes-$s['bytes']+1073741824) throw new Fault('Insufficient free space for growth (1 GiB reserve required)');
        $wasMounted=$this->mounted($s);
        if (!$wasMounted) $this->mount($s);
        $before=$this->sys->filesystemBytes($this->target($s));
        if ($before<=0) throw new Fault('Cannot determine current filesystem capacity');
        // Close SMB and detach the old loop BEFORE changing the backing file length.
        $this->unmount($s,$all);
        if ($this->sys->run(['losetup','-j',$image])!=='') throw new Fault('Image is still attached');
        if ($this->sys->run(['blkid','-s','UUID','-o','value',$image])!==$s['uuid'] || $this->sys->run(['blkid','-s','TYPE','-o','value',$image])!==$fs) throw new Fault('Filesystem identity mismatch');
        $this->checkFilesystem($fs,$image);
        $s['phase']='growing';$s['resize_from']=$s['bytes'];$s['resize_to']=$bytes;$all[$i]=$s;$this->save($all);
        try {
            $this->sys->run(['fallocate','-l',(string)$bytes,$image]);
            clearstatcache(true,$image);
            if (filesize($image)!==$bytes || $this->sys->allocated($image)<$bytes) throw new Fault('Growth reservation not verified');
            $s['bytes']=$bytes;$all[$i]=$s;$this->save($all);
            $this->mount($s);
            // Grow ext4 online through its verified loop device. Offline resize2fs can
            // refuse to run until a forced e2fsck, which would strand growth in recovery.
            if ($fs==='ext4') $this->sys->run(['resize2fs',$this->loopDevice($s)]);
            if ($fs==='xfs') $this->sys->run(['xfs_growfs','-d',$this->target($s)]);
            if ($fs==='btrfs') $this->sys->run(['btrfs','filesystem','resize','max',$this->target($s)]);
            if (!$this->mounted($s)) throw new Fault('Grown mount identity verification failed');
            // Verify reported filesystem size grew before publishing. Metadata means
            // total filesystem bytes can legitimately be less than backing image bytes.
            $total=$this->sys->filesystemBytes($this->target($s));
            if ($total<=$before || $total>$bytes) throw new Fault('Filesystem growth verification failed');
            if (!$wasMounted) $this->unmount($s,$all);
            $s['phase']='ready';unset($s['resize_from'],$s['resize_to'],$s['error']);$all[$i]=$s;$this->save($all);
            $this->publish($all);
        } catch (\Throwable $e) {
            // Never shrink/truncate as rollback: the filesystem might already have grown.
            clearstatcache(true,$image);$actual=filesize($image);
            if (is_int($actual) && $actual>=$originalBytes) $s['bytes']=$actual;
            $s['phase']='recovery';$s['error']='Growth interrupted: '.$e->getMessage();$all[$i]=$s;$this->save($all);
            try {$this->unmount($s,$all);}catch(\Throwable $cleanup){$this->sys->log('Growth cleanup: '.$cleanup->getMessage());}
            throw new Fault($s['error'].'; image retained, do not truncate or reformat');
        }
    }
    public function action(string $action, array $input): mixed {
        return $this->locked(function() use($action,$input) {
            if ($action==='create') return $this->create($input);
            $all=$this->load(); $n=self::name((string)($input['name']??'')); $i=null;
            foreach ($all as $k=>$v) if ($v['name']===$n) $i=$k;
            if ($i===null) throw new Fault('Unknown storage'); $s=$all[$i];
            switch ($action) {
                case 'mount':
                    if ($s['phase']!=='ready') throw new Fault('Recovery required');
                    $s['detached']=false; $this->mount($s); $all[$i]=$s; $this->save($all); $this->publish($all); break;
                case 'unmount': $this->unmount($s,$all); break;
                case 'remove-share':
                    $s['share']=false; $all[$i]=$s; $this->save($all); $this->publish($all);
                    $this->sys->closeShare($n); break;
                case 'detach':
                    $s['share']=false; $s['auto']=false; $s['detached']=true; $all[$i]=$s; $this->save($all); $this->unmount($s,$all); break;
                case 'edit':
                    if (isset($input['filesystem']) && self::filesystem($input['filesystem'])!==($s['filesystem']??'ext4')) throw new Fault('Existing filesystem cannot be changed or reformatted');
                    $s['users']=$this->validateUsers($input['users']??[]); $s['auto']=($input['auto']??false)===true;
                    $s['permissions']=$this->permissions($s['users'],$input['permissions']??null);
                    $s['share']=($input['share']??false)===true; $all[$i]=$s;
                    $this->save($all); $this->publish($all); $this->sys->closeShare($n); break;
                case 'resize': $this->grow($all,$i,$input); break;
                case 'delete':
                    if (($input['confirmation']??'')!=='DELETE '.$n) throw new Fault('Type DELETE '.$n.' to permanently delete data');
                    $this->unmount($s,$all); $image=$this->image($s);
                    if ($this->sys->run(['losetup','-j',$image])!=='') throw new Fault('Image is still attached');
                    if (!unlink($image)) throw new Fault('Cannot delete image');
                    unset($all[$i]); $this->save(array_values($all)); break;
                default: throw new Fault('Unknown action');
            }
            $this->sys->log($action.' '.$n); return ['ok'=>true];
        });
    }
    public function lifecycle(bool $start): void {
        $this->locked(function() use($start) {
            $all=$this->load(); $errors=[];
            if (!$all && !is_file('/etc/samba/smb.conf')) { self::atomic($this->runtime.'/shares.conf',self::EMPTY_SMB); chmod($this->runtime.'/shares.conf',0644); return; }
            // First revoke every share, then activate only verified images.
            $off=$all; foreach($off as &$s) $s['share']=false; unset($s); $this->publish($off);
            foreach ($all as &$s) {
                try {
                    if ($start) { if ($s['auto'] && !$s['detached'] && $s['phase']==='ready') {
                        // started normally follows disk availability; allow delayed local pools.
                        for ($attempt=0;$attempt<15;$attempt++) {
                            try { $this->location($s['location']); break; }
                            catch (Fault $e) { if ($attempt===14) throw $e; sleep(1); }
                        }
                        $this->mount($s);
                    } }
                    else $this->unmount($s,$off);
                } catch (\Throwable $e) { $errors[]=$s['name'].': '.$e->getMessage(); $this->sys->log(end($errors)); }
            } unset($s);
            if ($start) $this->publish($all);
            if ($errors) throw new Fault(implode('; ',$errors));
        });
    }
    public function upgradeStop(): void {
        $this->locked(function(){
            $path=$this->runtime.'/upgrade-mounts.json';
            $names=is_file($path)?json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR):[];
            foreach ($this->load() as $s) if ($this->mounted($s)) $names[]=$s['name'];
            self::atomic($path,json_encode(array_values(array_unique($names)),JSON_THROW_ON_ERROR));
        });
        $this->lifecycle(false);
    }
    public function upgradeResume(): void {
        $this->locked(function(){
            $path=$this->runtime.'/upgrade-mounts.json';if(!is_file($path))return;
            $names=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
            $all=$this->load();$errors=[];
            foreach($all as &$s) if(in_array($s['name'],$names,true)) {
                try {
                    if($s['phase']!=='ready'||$s['detached'])throw new Fault('Storage is not ready');
                    $this->mount($s);$this->sys->log('Restored mount after update: '.$s['name']);
                }catch(\Throwable $e){$errors[]=$s['name'].': '.$e->getMessage();}
            }unset($s);
            $this->publish($all);
            if($errors)throw new Fault('Update mount restore failed: '.implode('; ',$errors));
            unlink($path);
        });
    }
    public function status(): array {
        $all=$this->load();
        foreach ($all as &$s) {
            $online=$this->mounted($s); $s['status']=$online?'Online':($s['phase']==='ready'?'Offline':'Recovery required');
            $s['status_detail']=$online?($s['share']?'Image mounted; SMB enabled':'Image mounted; SMB disabled'):($s['error']??($s['detached']?'Storage detached':'Image is not mounted or its identity check failed. Use Mount and inspect logs if it fails.'));
            $s['used']=null; $s['available']=null;
            if ($online) { $p=$this->target($s); $s['available']=(int)disk_free_space($p); $s['used']=(int)disk_total_space($p)-$s['available']; }
        }
        return ['storages'=>$all,'locations'=>$this->locations(),'users'=>$this->sys->users()];
    }
}


