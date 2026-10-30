<?php
namespace PFZTest;
use DateTimeImmutable;
use DateTimeZone;
$root = __DIR__ . '/test-state-' . bin2hex(random_bytes(6));
@mkdir($root, 0700, true);
$binary = $root . '/fake-speedtest';
file_put_contents($binary, <<<'FAKE'
#!/usr/bin/env python3
import json, os, sys, time
with open(os.environ['PFZ_CALLS'], 'a') as f: f.write(json.dumps(sys.argv[1:])+'\n')
mode = os.environ.get('PFZ_MODE', 'ok')
if mode == 'timeout': time.sleep(10)
if mode == 'exit': sys.exit(2)
if mode == 'invalid': print('not json')
elif mode == 'missing': print('{}')
else: print(json.dumps({'download':100000000,'upload':50000000,'ping':8.5,'server':{'name':'test'}}))
FAKE
);
chmod($binary, 0700);
putenv('PFZ_CALLS=' . $root . '/calls');
$now = (new DateTimeImmutable('2026-10-30 03:10:00',new DateTimeZone('America/Sao_Paulo')))->getTimestamp();
$jitterAdvance = 0; $cron = []; $interfaces = []; $disks = [];
function time(){return $GLOBALS['now'];}
function sleep($seconds){$GLOBALS['now'] += $GLOBALS['jitterAdvance']; return 0;}
function install_cron_job(...$args){$GLOBALS['cron'][]=$args;}
function get_configured_interface_with_descr($all){return array_fill_keys(array_keys($GLOBALS['interfaces']), 'WAN');}
function get_interface_arr(){return array_column(array_values($GLOBALS['interfaces']), 'hwif');}
function get_interface_info($ifn){return $GLOBALS['interfaces'][$ifn];}
function get_smart_drive_list(){return array_keys($GLOBALS['disks']);}
function exec($command, &$lines=null, &$exit=null){
    foreach($GLOBALS['disks'] as $name=>$disk){
        if(strpos($command,"/dev/$name'")!==false){$lines=[$disk[0]]; $exit=$disk[1]; return $lines[0];}
    }
    throw new \RuntimeException('Unexpected exec: '.$command);
}
$source = file_get_contents(__DIR__ . '/../pfsense_zbx.php');
$source = substr($source, 5, strpos($source, '//Main Code')-5);
$source = preg_replace('/require_once\([^;]+;/', '', $source);
$source = str_replace('/usr/local/bin/speedtest', $binary, $source);
$source = str_replace('/tmp/', $root . '/', $source);
$source = str_replace("define('SPEEDTEST_TIMEOUT', 120)","define('SPEEDTEST_TIMEOUT', 1)", $source);
eval('namespace PFZTest; use DateTimeImmutable; use DateTimeZone; ' . $source);
set_error_handler(function($severity,$message,$file,$line){if(!(error_reporting()&$severity))return false;throw new \ErrorException($message,0,$severity,$file,$line);});
$checks=0;
function check($ok,$message){global $checks;if(!$ok)throw new \RuntimeException($message);$checks++;echo "PASS $message\n";}
function at($clock){$GLOBALS['now']=(new DateTimeImmutable('2026-10-30 '.$clock,new DateTimeZone('America/Sao_Paulo')))->getTimestamp();}
function calls(){global $root;return is_file($root.'/calls')?count(file($root.'/calls')):0;}
function capture($fn){ob_start();$fn();return ob_get_clean();}
foreach (['02:59:59'=>false,'03:00:00'=>true,'04:59:59'=>true,'05:00:00'=>false,'07:59:59'=>false,'08:00:00'=>false,'15:00:00'=>false,'22:00:00'=>false,'23:59:59'=>false] as $clock=>$expected){at($clock);check(pfz_speedtest_in_window()===$expected,'window '.$clock);}
pfz_speedtest_cron_install();
check($cron[0][2]==='0' && $cron[0][3]==='3' && $cron[0][4]==='*' && $cron[0][8]===true,'daily persistent cron 03:00');
check(strpos($cron[0][0],'speedtest_cron')!==false,'cron command identity preserved');
at('10:00:00');
check(pfz_speedtest_exec('em0','192.0.2.1')===false && calls()===0,'direct business-hours call blocked');
check(pfz_speedtest_cron()===false && calls()===0,'business-hours cron blocked');
at('03:10:00');
check(pfz_speedtest_exec('../bad','192.0.2.1')===false && calls()===0,'unsafe interface rejected');
check(pfz_speedtest_exec('em0','bad ip')===false && calls()===0,'invalid source rejected');
check(pfz_speedtest_exec('em0','192.0.2.1')===true && calls()===1,'first result created');
$cache=$root.'/speedtest-em0'; $original=file_get_contents($cache); touch($cache,$now);
check(pfz_speedtest_exec('em0','192.0.2.1')===true && calls()===1,'fresh result does not retest');
check(capture(function(){pfz_interface_speedtest_value('em0','server.name');})==='test','nested cache item');
check(capture(function(){pfz_interface_speedtest_value('em0','download');})==='100000000','flat cache item without warning');
check(capture(function(){pfz_interface_speedtest_value('em0','server.missing');})==='','missing nested item without warning');
foreach(['invalid','missing','exit','timeout'] as $mode){
    touch($cache,$now-21*3600); putenv('PFZ_MODE='.$mode); $started=\microtime(true);
    check(pfz_speedtest_exec('em0','192.0.2.1')===false,'reject '.$mode.' test');
    check(file_get_contents($cache)===$original,'preserve cache after '.$mode);
    if($mode==='timeout')check(\microtime(true)-$started<4,'external process actually terminated by timeout');
}
putenv('PFZ_MODE=ok'); $before=calls();
$held=\fopen($root.'/speedtest-run','c'); \flock($held,LOCK_EX|LOCK_NB);
check(pfz_speedtest_exec('em0','192.0.2.1')===false && calls()===$before,'second process blocked by held lock');
\flock($held,LOCK_UN);\fclose($held);
at('04:59:59');$jitterAdvance=2;
check(pfz_speedtest_exec('em0','192.0.2.1')===false && calls()===$before,'window checked again after jitter');
$jitterAdvance=0;at('03:10:00');
$interfaces=[
 'wan'=>['hwif'=>'em0','if'=>'em0','gateway'=>'GW0','ipaddr'=>'192.0.2.1'],
 'opt1'=>['hwif'=>'em1','if'=>'em1','gateway'=>'GW1','ipaddr'=>'192.0.2.2'],
 'opt2'=>['hwif'=>'tun_wg0','if'=>'tun_wg0','gateway'=>'VPN','ipaddr'=>'192.0.2.3'],
 'opt3'=>['hwif'=>'em2','if'=>'em2','gateway'=>'GW2','ipaddr'=>''],
];
check(pfz_speedtest_cron()===true && calls()===$before+2,'two WANs tested; VPN and missing source skipped');
$allcalls=array_map('json_decode',file($root.'/calls'));
check(in_array('192.0.2.2',end($allcalls),true),'correct source address selected for second WAN');
check(glob($root.'/speedtest-tmp-*')===[],'temporary files cleaned');
check(capture(function(){pfz_get_system_value('script_version');})==='0.24.7-o3.20261030.1','version works without system cache');
$disks=['ada0'=>['SMART overall-health self-assessment test result: FAILED!',8], 'ada1'=>['SMART overall-health self-assessment test result: PASSED',0]];
check(capture(function(){pfz_get_smart_status();})==='1','failed disk is not hidden by later healthy disk');
$disks=['ada0'=>['',0], 'ada1'=>['SMART Health Status: OK',0]];
check(capture(function(){pfz_get_smart_status();})==='2','unknown disk is not hidden by healthy disk');
$disks=['ada0'=>['SMART Health Status: OK',0]];
check(capture(function(){pfz_get_smart_status();})==='0','healthy SMART result');
$disks=[];check(capture(function(){pfz_get_smart_status();})==='2','no disks is unknown');
echo "TOTAL $checks checks passed\n";
