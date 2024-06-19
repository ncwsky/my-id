#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/conf.php';
require __DIR__ . '/../myphp/base.php';
require __DIR__ . '/../myphp/GetOpt.php';


//解析命令参数
GetOpt::parse('h:n:', ['host:', 'num:']);
$testCount = (int)GetOpt::val('n', 'num', 0);
$host = GetOpt::val('h', 'host', '192.168.0.219:55012');
if ($testCount <= 0) $testCount = 0;

$client = \MyId\TcpClient::instance();
$client->config($host);
$client->packageEof = "\r\n";
$count = 0;
//认证
$client->onConnect = function ($client){
    $client->send('123456');

    $name = 'test';
    $cmd = 'a=init&name='.$name.'&init_id=0&step=800&delta=2'; //自增数
    if($client->send($cmd)){
        echo $client->recv().PHP_EOL;
    }
    $name = 'abc';
    $cmd = 'a=init&name='.$name.'&init_id=1&step=600&delta=2'; //自增数
    if($client->send($cmd)){
        echo $client->recv().PHP_EOL;
    }
};


while (1) {
    try {
        $names = ['test','abc'];
        $name = $names[mt_rand(0,1)];
        $client->send('name='.$name.'&size='.mt_rand(1,100));
        $ret = $client->recv();
        echo date("Y-m-d H:i:s") . ' recv['.$name.']: ' . $ret, PHP_EOL;
        sleep(1);

        $count++;
    } catch (Exception $e) {
        echo date("Y-m-d H:i:s") . ' err: ' . $e->getMessage(), PHP_EOL;
        sleep(2);
    }
    if ($testCount && $count >= $testCount) {
        break;
    }
}

