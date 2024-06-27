#!/usr/bin/env php
<?php
error_reporting(E_ALL);
ini_set('display_errors', 'On');// 有些环境关闭了错误显示

if (realpath(dirname($_SERVER['SCRIPT_FILENAME'])) != __DIR__ && !defined('RUN_DIR')) {
    define('RUN_DIR', realpath(dirname($_SERVER['SCRIPT_FILENAME'])));
}

defined('RUN_DIR') || define('RUN_DIR', __DIR__);
if (!defined('VENDOR_DIR')) {
    if (is_dir(__DIR__ . '/vendor')) {
        define('VENDOR_DIR', __DIR__ . '/vendor');
    } elseif (is_dir(__DIR__ . '/../vendor')) {
        define('VENDOR_DIR', __DIR__ . '/../vendor');
    } elseif (is_dir(__DIR__ . '/../../../vendor')) {
        define('VENDOR_DIR', __DIR__ . '/../../../vendor');
    }
}

defined('MY_PHP_DIR') || define('MY_PHP_DIR', VENDOR_DIR . '/myphps/myphp');
//defined('MY_PHP_SRV_DIR') || define('MY_PHP_SRV_DIR', VENDOR_DIR . '/myphps/my-php-srv');

defined('ID_LISTEN') || define('ID_LISTEN', '0.0.0.0');
defined('ID_PORT') || define('ID_PORT', 55012);
defined('MAX_INPUT_SIZE') || define('MAX_INPUT_SIZE', 65536); //接收包限制大小64k
defined('IS_SWOOLE') || define('IS_SWOOLE', 0);

require_once VENDOR_DIR . '/autoload.php';
require_once MY_PHP_DIR . '/GetOpt.php';
defined('MY_PHP_SRV_DIR') && require_once MY_PHP_SRV_DIR . '/Load.php';

//解析命令参数
GetOpt::parse('shp:l:w:t:m:k:', ['help', 'swoole', 'port:', 'listen:', 'worker_num', 'type', 'master_host:', 'master_key:']);
//处理命令参数
$isSwoole = GetOpt::has('s', 'swoole') || IS_SWOOLE;
$port = (int)GetOpt::val('p', 'port', ID_PORT);
$listen = GetOpt::val('l', 'listen', ID_LISTEN);
$worker_num = (int)GetOpt::val('w', 'worker_num', 4); //进程数 有此配置为从服务模式
$type = GetOpt::val('t', 'type', 'single'); //服务类型 master主服务|worker从服务|其他为单进程

//进程数
$count = 1;
$name = 'my_id_'.$type.'_'.$port;
define('ID_NAME', $name); //进程名
if ($type == 'worker') $count = max(1, $worker_num);

//worker_num|master都不指定时为单进程id服务
//自动检测
if (!$isSwoole && !SrvBase::workermanCheck() && defined('SWOOLE_VERSION')) {
    $isSwoole = true;
}

if (GetOpt::has('h', 'help')) {
    echo 'Usage: php my_id.php OPTION [restart|reload|stop][--console]
   or: my_id.php OPTION [restart|reload|stop][--console]

   --help
   -l --listen=?      监听地址 默认 0.0.0.0
   -p --port=?        TCP端口 默认 55012
   -t --type=?        服务类型: master 主服务, worker 从服务, single 单进程服务(默认)
   -w --worker_num=?  从服务进程数
   -m --master_host=? 主服务地址 支持HTTP|TCP 示例:127.0.0.1:xxx|http://127.0.0.1:xxx
   -k --master_key=?  主服务验证key
   -s --swoole      swolle运行', PHP_EOL, PHP_EOL;
    exit(0);
}
if(!is_file(RUN_DIR . '/conf.php')){
    echo RUN_DIR . '/conf.php does not exist';
    exit(0);
}

$conf = [
    'name' => $name, //服务名
    'ip' => $listen,
    'port' => $port,
    'type' => 'tcp',
    'setting' => [
        'count' => $count,
        'protocol' => '\MyId\IdPackEof',
        //'stdoutFile' => RUN_DIR . '/'.$name.'.log', //终端输出
        'pidFile' => RUN_DIR . '/.'.$name.'.pid',  //pid_file
        'logFile' => RUN_DIR . '/'.$name.'.log', //日志文件 log_file
        'log_level' => 4, //swoole日志等级
        'open_eof_check' => true,
        'open_eof_split' => true,
        'package_max_length' => MAX_INPUT_SIZE,
        'package_eof' => "\r\n"
    ],
    'event' => [
        'onWorkerStart' => function ($worker, $worker_id) {
            \MyId\IdLib::onWorkerStart($worker, $worker_id);
        },
        'onWorkerStop' => function ($worker, $worker_id) {
            \MyId\IdLib::onWorkerStop($worker, $worker_id);
        },
        'onConnect' => function ($con, $fd = 0) use ($isSwoole) {
            if (!$isSwoole) {
                $fd = $con->id;
            }

            if(!\MyId\IdLib::auth($con, $fd)){
                \SrvBase::toClose($con, $fd);
            }
        },
        'onClose' => function ($con, $fd = 0) use($isSwoole) {
            if (!$isSwoole) {
                $fd = $con->id;
            }
            \MyId\IdLib::auth($con, $fd, null);
        },
        'onReceive' => function (\swoole_server $server, int $fd, int $reactor_id, string $data) { //swoole tcp
            $data = \MyId\IdPackEof::decode($data);
            \MyId\IdLib::onReceive($server, $data, $fd);
        },
        'onMessage' => function (\Workerman\Connection\TcpConnection $con, $data) {
            \MyId\IdLib::onReceive($con, $data, $con->id);
        },
    ],
    // 进程内加载的文件
    'worker_load' => [
        RUN_DIR . '/conf.php',
        MY_PHP_DIR . '/base.php'
    ],
];

if ($isSwoole) {
    $srv = new SwooleSrv($conf);
} else {
    // 设置每个连接接收的数据包最大为64K
    \Workerman\Connection\TcpConnection::$defaultMaxPackageSize = MAX_INPUT_SIZE;
    $srv = new WorkerManSrv($conf);
    if (!\extension_loaded('event') && !\extension_loaded('libevent') && defined('SWOOLE_VERSION')) {
        \Worker2::$eventLoopClass = "\Workerman\Events\Swoole";
    }
}
$srv->run($argv);