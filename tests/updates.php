<?php
declare(strict_types=1);
require __DIR__.'/../src/update.php';
$base=sys_get_temp_dir().'/sqm-update-'.bin2hex(random_bytes(6));mkdir($base);mkdir($base.'/cache');
$installed=$base.'/installed.plg';$candidate=$base.'/cache/quota-manager.plg';
function expectUpdate(bool $expected,string $label):void{global $installed,$base;$result=SQM\cachedPluginUpdate($installed,$base.'/cache','7.3.2');if($result['available']!==$expected)throw new RuntimeException($label);echo "PASS $label\n";}
try{
file_put_contents($installed,'<PLUGIN name="quota-manager" version="2026.10.29h"/>');expectUpdate(false,'No release channel hides update icon');
file_put_contents($installed,'<PLUGIN name="quota-manager" version="2026.10.29h" pluginURL="https://example.org/quota-manager.plg"/>');expectUpdate(false,'Missing native cache hides update icon');
file_put_contents($candidate,'<PLUGIN name="quota-manager" version="2026.10.29h"/>');expectUpdate(false,'Same version hides update icon');
file_put_contents($candidate,'<PLUGIN name="quota-manager" version="2026.11.01" min="7.0"/>');expectUpdate(true,'New compatible cached version shows update icon');
file_put_contents($candidate,'<PLUGIN name="quota-manager" version="2026.11.01" min="8.0"/>');expectUpdate(false,'Incompatible version hides update icon');
file_put_contents($candidate,'<PLUGIN name="another-plugin" version="2026.11.01"/>');expectUpdate(false,'Wrong plugin name rejected');
file_put_contents($candidate,'<broken');expectUpdate(false,'Malformed cache hides update icon');
if(!(strnatcmp('1ArrayDevices','1zQuotaManagerMain')<0&&strnatcmp('1zQuotaManagerMain','2CacheDevices')<0))throw new RuntimeException('Main rank incorrect');echo "PASS Main section sorts after Array and before Pools\n";
}finally{unlink($installed);if(file_exists($candidate))unlink($candidate);rmdir($base.'/cache');rmdir($base);}
