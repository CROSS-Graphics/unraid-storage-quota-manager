<?php
declare(strict_types=1);
require __DIR__.'/../src/lib.php';

// Integration tests exercise real Manager transactions, but ALL Linux/storage/SMB
// commands are simulated. Sparse temporary files contain no filesystem/data.
class FakeSystem extends SQM\System {
    public array $calls=[], $mounted=[], $sizes=[], $foreign=[];
    public string $fail='', $uuid='01234567-89ab-cdef-0123-456789abcdef';
    public int $available=1000000000000;
    public bool $shortAllocation=false;
    public string $filesystem='ext4';
    public int $fsBytes=90000000000;
    public function run(array $args,array $ok=[0]): string {
        $this->calls[]=$args;
        if ($this->fail===$args[0]) throw new SQM\Fault('Simulated '.$args[0].' failure');
        switch($args[0]) {
            case 'fallocate':
                $p=$args[3]; $this->sizes[$p]=(int)$args[2];
                if (PHP_OS_FAMILY==='Windows') {
                    // Mark an EMPTY TEMPORARY test file sparse before assigning virtual length.
                    parent::run(['fsutil','sparse','setflag',str_replace('/','\\',$p)]);
                }
                $h=fopen($p,'r+'); ftruncate($h,(int)$args[2]); fclose($h); return '';
            case 'blkid': return $args[2]==='TYPE'?$this->filesystem:$this->uuid;
            case 'mkfs.ext4': $this->filesystem='ext4'; return '';
            case 'mkfs.xfs': $this->filesystem='xfs'; return '';
            case 'mkfs.btrfs': $this->filesystem='btrfs'; return '';
            case 'resize2fs':
                // Online growth only: the device must be the currently mounted loop.
                if (!in_array($args[1],array_column($this->mounted,'source'),true)) throw new SQM\Fault('resize2fs: device not mounted');
                $this->fsBytes=$this->sizes[$this->sizes['loop-image']]-1000000000;return '';
            case 'xfs_growfs': $this->fsBytes=$this->sizes[$this->sizes['loop-image']]-1000000000;return '';
            case 'btrfs': if (($args[1]??'')==='filesystem') $this->fsBytes=$this->sizes[$this->sizes['loop-image']]-1000000000;return '';
            case 'losetup':
                if ($args[1]==='-j') return '';
                if ($args[1]==='--find') { $this->sizes['loop-image']=$args[4]; return '/dev/loop77'; }
                if ($args[1]==='--json') return json_encode(['loopdevices'=>[['back-file'=>$this->sizes['loop-image']]]]);
                return '';
            case 'mount': $this->mounted[]=['target'=>$args[6],'source'=>$args[5],'fstype'=>$args[2],'uuid'=>$this->uuid,'options'=>'rw,nodev,nosuid,noexec']; return '';
            case 'umount': $this->mounted=array_values(array_filter($this->mounted,fn($m)=>$m['target']!==$args[1])); return '';
            default: return '';
        }
    }
    public function mounts(): array { return $this->mounted; }
    public function users(): array { return ['pto','cross']; }
    public function shares(): array { return array_merge(['sqm_internal_guard'],$this->foreign); }
    public function externalShares(string $managed): array { return $this->foreign; }
    public function free(string $p): int { return $this->available; }
    public function localBlockDevice(string $source): bool {return preg_match('~^/dev/(sd[a-z][0-9]+|md[0-9]+|nvme[0-9]+n[0-9]+p[0-9]+)$~D',$source)===1;}
    public function filesystemBytes(string $p): int { return $this->fsBytes; }
    public function allocated(string $p): int { return $this->shortAllocation?0:$this->sizes[$p]; }
    public function log(string $s): void { $this->calls[]=['audit',$s]; }
}
class FakeManager extends SQM\Manager {
    public string $disk;
    public bool $missing=false;
    public function location(string $p): string {
        if ($this->missing || $p!==$this->disk) throw new SQM\Fault('Disk unavailable'); return $p;
    }
    public function locations(): array { return $this->missing?[]:[$this->disk]; }
}
$base=str_replace('\\','/',sys_get_temp_dir()).'/sqm-tests-'.bin2hex(random_bytes(6));mkdir($base);
$passed=0;$failed=0;
function check(bool $value,string $message='Assertion failed'): void { if(!$value)throw new RuntimeException($message); }
function rejects(callable $f,string $contains=''): void {
    try{$f();}catch(Throwable $e){check($contains===''||str_contains($e->getMessage(),$contains),'Unexpected error: '.$e->getMessage());return;}
    throw new RuntimeException('Expected operation to fail');
}
function fixture(): array {
    global $base;
    $p=$base.'/case-'.bin2hex(random_bytes(4));mkdir($p);mkdir($p.'/disk');
    $sys=new FakeSystem();$m=new FakeManager($sys,$p.'/config',$p.'/run',$p.'/extra');$m->disk=$p.'/disk';
    return [$m,$sys,['name'=>'PTO','location'=>$m->disk,'capacity'=>'100','unit'=>'GB','users'=>['pto'],'auto'=>true]];
}
function test(string $name,callable $f): void {
    global $passed,$failed;try{$f();$passed++;echo "PASS $name\n";}catch(Throwable $e){$failed++;echo "FAIL $name: ".$e->getMessage()."\n";}
}
test('GB and GiB are distinct',function(){check(SQM\Manager::capacity('100','GB')===100000000000);check(SQM\Manager::capacity(100,'GiB')===107374182400);});
test('name traversal and Samba injection rejected',function(){foreach(['../PTO','PTO;id','a\nb','[global]','disk2','IPC$','a b','-pto',''] as $n)rejects(fn()=>SQM\Manager::name($n));check(SQM\Manager::name('PTO')==='PTO');});
test('capacity shell injection/overflow/fractions rejected',function(){foreach(['1;id','0','-2','1.5','999999999999','1e3'] as $v)rejects(fn()=>SQM\Manager::capacity($v,'GB'));rejects(fn()=>SQM\Manager::capacity(1,'TB'));});
test('unsafe paths rejected by production validator',function(){ $m=new SQM\Manager(new FakeSystem());foreach(['/etc','/mnt/user','/mnt/disk1/../disk2','/mnt/disk1//foo','/dev/sda','relative'] as $p)rejects(fn()=>$m->location($p));});
test('discovery includes local USB/non-array mounts and excludes unsafe sources',function(){
    $s=new FakeSystem();$m=new SQM\Manager($s);$s->mounted=[
      ['target'=>'/mnt/disk1','source'=>'/dev/md1','fstype'=>'xfs','options'=>'rw,noquota'],
      ['target'=>'/mnt/disks/USB Backup','source'=>'/dev/sdb1','fstype'=>'ext4','options'=>'rw'],
      ['target'=>'/mnt/disks/External','source'=>'/dev/sdc1','fstype'=>'btrfs','options'=>'rw'],
      ['target'=>'/mnt/disks/ReadOnly','source'=>'/dev/sdd1','fstype'=>'xfs','options'=>'ro'],
      ['target'=>'/mnt/disks/Network','source'=>'//server/share','fstype'=>'cifs','options'=>'rw'],
      ['target'=>'/mnt/disks/NTFS','source'=>'/dev/sde1','fstype'=>'ntfs3','options'=>'rw'],
      ['target'=>'/mnt/disks/Image','source'=>'/dev/loop3','fstype'=>'ext4','options'=>'rw'],
      ['target'=>'/mnt/user','source'=>'shfs','fstype'=>'fuse.shfs','options'=>'rw']
    ];check($m->locations()===['/mnt/disk1','/mnt/disks/USB Backup','/mnt/disks/External']);
});
test('unavailable external device is removed from discovery',function(){ $s=new FakeSystem();$m=new SQM\Manager($s);$s->mounted=[['target'=>'/mnt/disks/USB','source'=>'missing','fstype'=>'xfs','options'=>'rw']];check($m->locations()===[]);});
test('existing regular file survives atomic failure',function()use($base){$p=$base.'/atomic';SQM\Manager::atomic($p,'first');SQM\Manager::atomic($p,'second');check(file_get_contents($p)==='second');});
test('malformed configuration fails closed',function(){[$m]=fixture();mkdir($m->config);file_put_contents($m->config.'/storages.json','{broken');rejects(fn()=>$m->load());});
test('create PTO 100 GB and verify mounted identity',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$all=$m->load();check(count($all)===1);check($all[0]['bytes']===100000000000);check($m->mounted($all[0]));$conf=file_get_contents($m->runtime.'/shares.conf');check(str_contains($conf,'valid users = pto'));check(str_contains($conf,'guest ok = no'));check(str_contains($conf,'root preexec close = yes'));check(!str_contains($conf,'valid users = cross'));});
test('duplicate request preserves existing image',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$p=$m->image($m->load()[0]);rejects(fn()=>$m->action('create',$in),'already registered');check(is_file($p));check(count($m->load())===1);});
test('insufficient space fails before image creation',function(){[$m,$s,$in]=fixture();$s->available=100;rejects(fn()=>$m->action('create',$in),'Insufficient');check(!is_dir($m->disk.'/.quota_manager'));});
test('missing disk fails without formatting',function(){[$m,$s,$in]=fixture();$m->missing=true;rejects(fn()=>$m->action('create',$in));check(!$s->calls);});
test('third-party SMB name conflict is case insensitive',function(){[$m,$s,$in]=fixture();$s->foreign=['pto'];rejects(fn()=>$m->action('create',$in),'SMB name');check(!$s->calls);});
test('unknown and root users rejected',function(){[$m,$s,$in]=fixture();foreach([['root'],['unknown'],[]] as $users){$in['users']=$users;rejects(fn()=>$m->action('create',$in));}check(!$s->calls);});
test('guest access refused',function(){[$m,$s,$in]=fixture();$in['access']='public';rejects(fn()=>$m->action('create',$in),'private');});
test('allocation failure rolls back and retry succeeds',function(){[$m,$s,$in]=fixture();$s->fail='fallocate';rejects(fn()=>$m->action('create',$in));check($m->load()===[]);check(!file_exists($m->disk.'/.quota_manager/PTO.img'));$s->fail='';$m->action('create',$in);check(count($m->load())===1);});
test('unreserved sparse allocation rejected before mkfs',function(){[$m,$s,$in]=fixture();$s->shortAllocation=true;rejects(fn()=>$m->action('create',$in),'reservation');foreach($s->calls as $c)check($c[0]!=='mkfs.ext4');});
test('formatting failure rolls back without existing data loss',function(){[$m,$s,$in]=fixture();file_put_contents($m->disk.'/existing.txt','precious');$s->fail='mkfs.ext4';rejects(fn()=>$m->action('create',$in));check(file_get_contents($m->disk.'/existing.txt')==='precious');check($m->load()===[]);});
test('mkfs arguments contain only newly created image and disable discard',function(){[$m,$s,$in]=fixture();$m->action('create',$in);foreach($s->calls as $c)if($c[0]==='mkfs.ext4'){check($c===['mkfs.ext4','-q','-m','0','-E','nodiscard,lazy_itable_init=0,lazy_journal_init=0',$m->disk.'/.quota_manager/PTO.img']);return;}throw new RuntimeException('No mkfs call');});
test('remove share retains data and unmount not needed',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('remove-share',['name'=>'PTO']);check(is_file($m->image($m->load()[0])));check($m->mounted($m->load()[0]));check(!str_contains(file_get_contents($m->runtime.'/shares.conf'),'[PTO]'));});
test('unmounted image never rendered as SMB share',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$s->mounted=[];check(!str_contains($m->render($m->load()),'[PTO]'));});
test('wrong loop backing file fails mount verification',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$s->sizes['loop-image']='/dev/sda';check(!$m->mounted($m->load()[0]));check(!str_contains($m->render($m->load()),'[PTO]'));});
test('corrupted UUID cannot be shared or remounted',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('unmount',['name'=>'PTO']);$s->uuid='different';rejects(fn()=>$m->action('mount',['name'=>'PTO']),'UUID');check(!str_contains(file_get_contents($m->runtime.'/shares.conf'),'[PTO]'));});
test('busy unmount preserves loop/image and blocks new SMB connects',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$s->fail='umount';rejects(fn()=>$m->action('unmount',['name'=>'PTO']));check(count($s->mounted)===1);check(is_file($m->runtime.'/blocked-PTO'));check(is_file($m->image($m->load()[0])));$after=array_slice($s->calls,-1);check($after[0][0]==='umount');});
test('detach retains image and disables automount',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('detach',['name'=>'PTO']);$r=$m->load()[0];check($r['detached']&&!$r['auto']&&!$r['share']);check(is_file($m->image($r)));check(!$m->mounted($r));});
test('delete requires exact explicit confirmation',function(){[$m,$s,$in]=fixture();$m->action('create',$in);rejects(fn()=>$m->action('delete',['name'=>'PTO','confirmation'=>'yes']));check(is_file($m->image($m->load()[0])));$m->action('delete',['name'=>'PTO','confirmation'=>'DELETE PTO']);check($m->load()===[]);check(!file_exists($m->disk.'/.quota_manager/PTO.img'));});
test('shrinking prohibited before image or mount changes',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$count=count($s->calls);rejects(fn()=>$m->action('resize',['name'=>'PTO','capacity'=>1,'unit'=>'GB']),'Shrinking');check($m->load()[0]['bytes']===100000000000);check(count($s->calls)===$count);});
test('growth retains data without invoking any formatter for all filesystems',function(){foreach(['ext4','xfs','btrfs'] as $fs){[$m,$s,$in]=fixture();$in['filesystem']=$fs;$m->action('create',$in);$image=$m->image($m->load()[0]);$h=fopen($image,'r+');fseek($h,1024);fwrite($h,'KEEP-DATA');fclose($h);$start=count($s->calls);$m->action('resize',['name'=>'PTO','capacity'=>120,'unit'=>'GB']);$r=$m->load()[0];check($r['bytes']===120000000000&&$r['phase']==='ready');check($m->mounted($r));$h=fopen($image,'r');fseek($h,1024);check(fread($h,9)==='KEEP-DATA');fclose($h);foreach(array_slice($s->calls,$start) as $c)check(!str_starts_with($c[0],'mkfs.'));}});
test('growth refuses insufficient additional space before disconnecting',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$s->available=1;rejects(fn()=>$m->action('resize',['name'=>'PTO','capacity'=>120,'unit'=>'GB']),'Insufficient');check($m->mounted($m->load()[0]));check($m->load()[0]['bytes']===100000000000);});
test('growth fails safely on busy unmount without extending image',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$s->fail='umount';rejects(fn()=>$m->action('resize',['name'=>'PTO','capacity'=>120,'unit'=>'GB']));check($m->load()[0]['bytes']===100000000000);});
test('failed filesystem growth preserves larger image for recovery',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$s->fail='resize2fs';rejects(fn()=>$m->action('resize',['name'=>'PTO','capacity'=>120,'unit'=>'GB']),'image retained');$r=$m->load()[0];check($r['bytes']===120000000000&&$r['phase']==='recovery');check(is_file($m->image($r)));check(!str_contains(file_get_contents($m->runtime.'/shares.conf'),'[PTO]'));});
test('growth preserves offline state',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('unmount',['name'=>'PTO']);$m->action('resize',['name'=>'PTO','capacity'=>120,'unit'=>'GB']);check(!$m->mounted($m->load()[0]));check($m->load()[0]['bytes']===120000000000);});
test('same-size retry is idempotent',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$n=count($s->calls);$m->action('resize',['name'=>'PTO','capacity'=>100,'unit'=>'GB']);foreach(array_slice($s->calls,$n) as $c)check(!in_array($c[0],['fallocate','resize2fs','mkfs.ext4']));});
test('array stop/start and configuration reload',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->lifecycle(false);check(!$s->mounted);$m->lifecycle(true);check($m->mounted($m->load()[0]));$m2=new FakeManager($s,$m->config,$m->runtime,$m->extra);$m2->disk=$m->disk;check($m2->load()[0]['name']==='PTO');});
test('update restores previously mounted storage even with Auto Mount off',function(){[$m,$s,$in]=fixture();$in['auto']=false;$m->action('create',$in);$m->upgradeStop();check(!$s->mounted);$m->upgradeResume();check($m->mounted($m->load()[0]));check(!$m->load()[0]['auto']);check(!is_file($m->runtime.'/upgrade-mounts.json'));});
test('update leaves previously offline Auto Mount storage offline',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('unmount',['name'=>'PTO']);$m->upgradeStop();$m->upgradeResume();check(!$s->mounted);});
test('failed update resume retains pending mounts for retry',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->upgradeStop();$s->fail='mount';rejects(fn()=>$m->upgradeResume(),'restore failed');check(is_file($m->runtime.'/upgrade-mounts.json'));$s->fail='';$m->upgradeResume();check($m->mounted($m->load()[0]));});
test('incomplete crash transaction never auto-mounted',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->lifecycle(false);$all=$m->load();$all[0]['phase']='allocating';$m->save($all);$m->lifecycle(true);check(!$s->mounted);check(!str_contains(file_get_contents($m->runtime.'/shares.conf'),'[PTO]'));});
test('ext4 check is read-only and never preens',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('unmount',['name'=>'PTO']);$start=count($s->calls);$m->action('mount',['name'=>'PTO']);$image=$m->image($m->load()[0]);check(in_array(['e2fsck','-n',$image],array_slice($s->calls,$start),true));foreach($s->calls as $c)check(!($c[0]==='e2fsck'&&array_intersect(['-p','-y','-f'],$c)));});
test('ext4 grows online on the verified loop device',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$start=count($s->calls);$m->action('resize',['name'=>'PTO','capacity'=>120,'unit'=>'GB']);$calls=array_slice($s->calls,$start);$resize=array_values(array_filter($calls,fn($c)=>$c[0]==='resize2fs'));check(count($resize)===1&&$resize[0]===['resize2fs','/dev/loop77']);$order=array_column($calls,0);check(array_search('fallocate',$order,true)<array_search('mount',$order,true));check(array_search('mount',$order,true)<array_search('resize2fs',$order,true));});
test('filesystem check error prevents mount and export',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('unmount',['name'=>'PTO']);$s->fail='e2fsck';rejects(fn()=>$m->action('mount',['name'=>'PTO']));check(!$s->mounted);});
test('per-user Read and Read/Write are persisted and rendered separately',function(){[$m,$s,$in]=fixture();$in['users']=['pto','cross'];$in['permissions']=['pto'=>'read','cross'=>'rw'];$m->action('create',$in);$c=file_get_contents($m->runtime.'/shares.conf');check(str_contains($c,"read only = yes\n"));check(str_contains($c,"write list = cross\n"));check(str_contains($c,"read list = pto\n"));check($m->load()[0]['permissions']===$in['permissions']);});
test('all read-only users leave write list empty',function(){[$m,$s,$in]=fixture();$in['permissions']=['pto'=>'read'];$m->action('create',$in);check(str_contains(file_get_contents($m->runtime.'/shares.conf'),"write list = \n"));});
test('share permits multiple concurrent connections without a plugin limit',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$c=file_get_contents($m->runtime.'/shares.conf');check(str_contains($c,"max connections = 0\n"));check(!str_contains($c,"max connections = 1\n"));});
test('XFS image uses nodiscard format and read-only checker',function(){[$m,$s,$in]=fixture();$in['filesystem']='xfs';$m->action('create',$in);$r=$m->load()[0];check($r['filesystem']==='xfs');check($m->mounted($r));check(in_array(['mkfs.xfs','-q','-K',$m->image($r)],$s->calls,true));check(in_array(['xfs_repair','-n',$m->image($r)],$s->calls,true));});
test('Btrfs image uses nodiscard and never repair mode',function(){[$m,$s,$in]=fixture();$in['filesystem']='btrfs';$m->action('create',$in);$r=$m->load()[0];check($r['filesystem']==='btrfs');check($m->mounted($r));check(in_array(['mkfs.btrfs','-q','--nodiscard',$m->image($r)],$s->calls,true));check(in_array(['btrfs','check','--readonly',$m->image($r)],$s->calls,true));});
test('unsupported and injected filesystem rejected before allocating',function(){[$m,$s,$in]=fixture();foreach(['ntfs','ext4;id','/dev/sda',[],false] as $fs){$in['filesystem']=$fs;rejects(fn()=>$m->action('create',$in));check($m->load()===[]);}});
test('legacy filesystem defaults to ext4',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$r=$m->load()[0];unset($r['filesystem']);check($m->mounted($r));});
test('filesystem conversion rejected without touching data',function(){[$m,$s,$in]=fixture();$m->action('create',$in);rejects(fn()=>$m->action('edit',['name'=>'PTO','filesystem'=>'xfs','users'=>['pto']]),'cannot be changed');check($m->load()[0]['filesystem']==='ext4');});
test('wrong on-disk filesystem blocks mount despite matching UUID',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('unmount',['name'=>'PTO']);$s->filesystem='xfs';rejects(fn()=>$m->action('mount',['name'=>'PTO']),'type mismatch');check(!$s->mounted);});
test('XFS and Btrfs checker errors keep share offline',function(){foreach(['xfs'=>'xfs_repair','btrfs'=>'btrfs'] as $fs=>$checker){[$m,$s,$in]=fixture();$in['filesystem']=$fs;$m->action('create',$in);$m->action('unmount',['name'=>'PTO']);$s->fail=$checker;rejects(fn()=>$m->action('mount',['name'=>'PTO']));check(!$s->mounted);check(!str_contains(file_get_contents($m->runtime.'/shares.conf'),'[PTO]'));}});
test('editing rights can revoke writer and exclude unchecked users',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$m->action('edit',['name'=>'PTO','users'=>['cross'],'permissions'=>['cross'=>'read'],'share'=>true]);$c=file_get_contents($m->runtime.'/shares.conf');check(str_contains($c,"valid users = cross\n"));check(str_contains($c,"read list = cross\n"));check(str_contains($c,"write list = \n"));});
test('legacy records retain read-write permissions',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$all=$m->load();unset($all[0]['permissions']);check(str_contains($m->render($all),"write list = pto\n"));});
test('unknown users, missing rights and injected rights rejected',function(){[$m,$s,$in]=fixture();foreach([['pto'=>'admin'],['pto'=>"rw\nwrite list = root"],['cross'=>'rw'],[],['pto'=>'rw','cross'=>'rw']] as $p){$in['permissions']=$p;rejects(fn()=>$m->action('create',$in));check($m->load()===[]);}});
test('configuration lock prevents concurrent mutators',function(){[$m]=fixture();$m->locked(function()use($m){$other=new FakeManager($m->sys,$m->config,$m->runtime,$m->extra);rejects(fn()=>$other->locked(fn()=>null),'Another operation');});});
test('CSRF missing, empty, array and wrong tokens refused',function(){foreach([null,'',[],['token'],'wrong'] as $v)check(!SQM\csrf($v,'correct'));check(!SQM\csrf('',''));check(SQM\csrf('correct','correct'));});
test('CSRF survives Unraid consuming BOTH native POST and header tokens',function(){
    $post=['csrf_token'=>'correct','sqm_csrf_token'=>'correct'];$server=['HTTP_X_CSRF_TOKEN'=>'correct'];
    check(SQM\csrf($post['csrf_token'],'correct'));
    unset($post['csrf_token'],$server['HTTP_X_CSRF_TOKEN']);
    check(SQM\requestCsrf($post,$server,'correct'));
    check(!SQM\requestCsrf([],[],'correct'));
    check(!SQM\requestCsrf(['sqm_csrf_token'=>'wrong'],[],'correct'));
    check(!SQM\requestCsrf(['sqm_csrf_token'=>[]],[],'correct'));
});
test('system runner passes injection characters literally',function(){ $sys=new SQM\System();$payload='PTO; echo injected & $(id)';check($sys->run([PHP_BINARY,'-r','echo $argv[1];',$payload])===$payload);rejects(fn()=>$sys->run([PHP_BINARY,'-r','fwrite(STDERR,"failure");exit(4);']),'failure');});
test('conflict added after creation blocks republishing',function(){[$m,$s,$in]=fixture();$m->action('create',$in);$s->foreign=['pto'];rejects(fn()=>$m->locked(fn()=>$m->publish($m->load())),'Conflicting');});
// Bound recursive cleanup to this explicitly-created temporary tree.
function cleanup(string $p): void {foreach(scandir($p) as $n){if($n==='.'||$n==='..')continue;$f=$p.'/'.$n;if(is_dir($f)&&!is_link($f)){chmod($f,0700);cleanup($f);}else unlink($f);}rmdir($p);}
cleanup($base);
echo "$passed passed; $failed failed\n";exit($failed?1:0);
