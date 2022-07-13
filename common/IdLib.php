<?php
namespace MyId;
/**
 * 公共函数类库
 * Class IdLib
 * @package MyId
 */
class IdLib
{
    use IdMsg;

    public static $authKey = '';
    public static $allowIp = '';
    /**
     * @var IdFile
     */
    public static $idObj;

    protected static $alarmInterval = 0;
    protected static $alarmFail = 0;
    /**
     * @var callable|null
     */
    protected static $onAlarm = null;

    public static $idList = [];

    public static function toJson($buffer)
    {
        return json_encode($buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 唯一id生成
     * @param $name
     * @return string len 20
     */
    public static function uniqId($name)
    {
        $m = microtime(true);
        $time = floor($m);
        $micro = $m - $time;
        $rand = mt_rand(0, 99);
        return (string)$time . ($name === '' ? (string)mt_rand(100, 999) : substr((string)crc32($name), -3)) . substr((string)$micro, 2, 5) . sprintf('%02d', $rand);
    }

    /**
     * id生成(每秒最多99999个id) 最多支持部署100个服务器 每个服务最多100个进程 10位时间戳+[5位$sequence+2位$worker_id+2位$p] 19位数字  //[5位$sequence+2位uname+2位rand]
     * @param int $worker_id 进程id 0-99
     * @param int $p 服务器区分值 0-99
     * @return int 8字节长整形
     */
    public static function bigId($worker_id = 0, $p = 0)
    {
        static $lastTime = 0, $sequence = 1, $uname;
        //if (!isset($uname)) $uname = crc32(php_uname('n')) % 10 * 1000;
        $time = time();
        if ($time == $lastTime) {
            $sequence++; // max 99999
        } else {
            $sequence = 1;
            $lastTime = $time;
        }
        //$uname + mt_rand(100, 999)
        return (int)((string)$time . '000000000') + (int)((string)$sequence . '0000') + $worker_id * 100 + $p;
        return (int)sprintf('%d%05d%02d%02d', $time, $sequence, $worker_id, $p);
        return $time * 1000000000 + $sequence * 10000 + $worker_id * 100 + $p;
    }

    /**
     * 初始配置
     */
    public static function initConf()
    {
        static::$allowIp = GetC('allow_ip');
        static::$authKey = GetC('auth_key');
        if (GetC('db.name')) {
            static::$idObj = new IdDb();
        } else {
            static::$idObj = new IdFile();
        }
        IdLib::$idObj->init();

        static::$alarmInterval = (int)GetC('alarm_interval', 60);
        static::$alarmFail = GetC('alarm_fail');
        static::$onAlarm = GetC('alarm_callback');
        if (static::$onAlarm && !is_callable(static::$onAlarm)) {
            static::$onAlarm = null;
        } elseif (static::$alarmFail <= 0) {
            static::$onAlarm = null;
        }
    }

    public static function remoteIp($con, $fd)
    {
        if (\SrvBase::$instance->isWorkerMan) return $con->getRemoteIp();

        if (is_array($fd)) { // swoole udp 客户端信息包括address/port/server_socket等多项客户端信息数据
            return $fd['address'];
        }
        return $con->getClientInfo($fd)['remote_ip'];
    }

    /**
     * tcp 认证
     * @param $con
     * @param $fd
     * @param string $recv
     * @return bool|string
     */
    public static function auth($con, $fd, $recv = null)
    {
        //优先ip
        if (static::$allowIp) {
            return $recv === false || \Helper::allowIp(static::remoteIp($con, $fd), static::$allowIp);
        }
        //认证key
        if (!static::$authKey) return true;

        if (!isset(\SrvBase::$instance->auth)) {
            \SrvBase::$instance->auth = [];
        }

        //连接断开清除
        if ($recv === false) {
            unset(\SrvBase::$instance->auth[$fd]);
            \SrvBase::$isConsole && \SrvBase::safeEcho('clear auth '.$fd);
            return true;
        }

        if ($recv) {
            if (isset(\SrvBase::$instance->auth[$fd]) && \SrvBase::$instance->auth[$fd] === true) {
                return true;
            }
            \SrvBase::$instance->server->clearTimer(\SrvBase::$instance->auth[$fd]);
            if ($recv == static::$authKey) { //通过认证
                \SrvBase::$instance->auth[$fd] = true;
            } else {
                static::err('auth fail');
                return false;
            }
            return 'ok';
        }

        //创建定时认证
        if(!isset(\SrvBase::$instance->auth[$fd])){
            \SrvBase::$isConsole && \SrvBase::safeEcho('auth timer ' . $fd . PHP_EOL);
            \SrvBase::$instance->auth[$fd] = \SrvBase::$instance->server->after(1000, function () use ($con, $fd) {
                unset(\SrvBase::$instance->auth[$fd]);
                \SrvBase::$isConsole && \SrvBase::safeEcho('auth timeout to close ' . $fd . PHP_EOL);
                if (\SrvBase::$instance->isWorkerMan) {
                    $con->close();
                } else {
                    $con->close($fd);
                }
            });
        }
        return true;
    }

    /**
     * @param \Workerman\Connection\TcpConnection|\swoole_server $con
     * @param int $fd
     * @param string $msg
     */
    public static function toClose($con, $fd=0, $msg=null){
        if (\SrvBase::$instance->isWorkerMan) {
            $con->close($msg);
        } else {
            if ($msg) $con->send($fd, $msg);
            $con->close($fd);
        }
    }
}