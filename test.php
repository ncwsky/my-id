#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/conf.php';
require __DIR__ . '/../myphp/base.php';
require __DIR__ . '/../myphp/GetOpt.php';


//解析命令参数
GetOpt::parse('h:n:', ['host:', 'num:']);
$testCount = (int)GetOpt::val('n', 'num', 0);
$host = GetOpt::val('h', 'host', '192.168.0.245:55012');
if ($testCount <= 0) $testCount = 0;

$client = TcpClient::instance();
$client->config($host);
$client->packageEof = "\r\n";
$run_times = 0;
//认证
$client->onConnect = function ($client){
    $client->send('123456');
    $client->recv();
};
$name = 'id_incr_1';
$cmd = 'a=init&name='.$name.'&init_id=0&delta=1'; //自增数
if($cmd && $client->send($cmd)){
    echo $client->recv().PHP_EOL;
}
while (1) {
    $run_times++;
    try {
        $client->send('name='.$name.'&size='.mt_rand(0, 10));
        $ret = $client->recv();
        //echo date("Y-m-d H:i:s") . ' recv['.$name.']: ' . $ret, PHP_EOL;
        //sleep(1);
        if(strpos($ret, ',')){
            $idList = explode(',', $ret);
        }else{
            $idList = [$ret];
        }

        $real_count = count($idList);
        $data = [];
        foreach($idList as $id){
            echo $id. PHP_EOL;
            $data[] = ['id'=>$id];
        }
        db()->beginTrans();
        $ok = db()->execute(db()->add_sql($data, 'test_incr'));
        db()->commit();

        echo $ok.'-> last_id:'.$id.', real_count:'. $real_count.PHP_EOL;
        
        $line = $real_count.','.$ok.PHP_EOL;
        file_put_contents(__DIR__.'/'.$name.'.csv', $line, FILE_APPEND|LOCK_EX);
        usleep(mt_rand(500000, 1000000));
    } catch (Exception $e) {
        echo date("Y-m-d H:i:s") . ' err: ' . $e->getMessage(), PHP_EOL;
        break;
    }
    if ($testCount && $run_times >= $testCount) {
        break;
    }
}

