<?php
namespace MyId;

use Workerman\Connection\TcpConnection;

/**
 * id自增 - 用于本机服务 自增最大值32|64位有符号
 * Class IdServerIncr
 * @package MyId
 */
class IdServerIncr
{
    /**
     * 信息统计
     * @var array
     */
    protected static $infoStats = [];

    /**
     * 每秒实时接收数量
     * @var int
     */
    protected static $realRecvNum = 0;

    /**
     * 统计信息 存储
     */
    public static function info(){
        static::$infoStats['date'] = date("Y-m-d H:i:s", time());
        static::$infoStats['real_recv_num'] = static::$realRecvNum;
        static::$infoStats['info'] = IdLib::$idObj->info();
        return IdLib::toJson(static::$infoStats);
    }

    /**
     * 进程启动时处理
     * @param $worker
     * @param $worker_id
     * @throws \Exception
     */
    public static function onWorkerStart($worker, $worker_id)
    {
        IdLib::initConf();

        //n ms实时数据落地
        $worker->tick(1000, function () {
            static::$realRecvNum = 0;
            IdLib::$idObj->save();
        });
    }

    /**
     * 终端数据进程结束时的处理
     * @param $worker
     * @param $worker_id
     * @throws \Exception
     */
    public static function onWorkerStop($worker, $worker_id)
    {
        IdLib::$idObj->stop();
    }

    /**
     * 处理数据
     * @param TcpConnection $con
     * @param string $recv
     * @param int $fd
     * @return bool|array
     * @throws \Exception
     */
    public static function onReceive($con, $recv, $fd=0)
    {
        static::$realRecvNum++;

        if(substr($recv, 0, 3)==='GET'){
            $url = substr($recv, 4, strpos($recv, ' ', 4) - 4);
            return static::httpGetHandle($con, $url, $fd);
        }

        //\SrvBase::$isConsole && \SrvBase::safeEcho($recv . PHP_EOL);
        //\Log::trace($recv);

        return static::handle($con, $recv, $fd);
    }

    /**
     * @param TcpConnection $con
     * @param string $recv
     * @param int $fd
     * @return array|bool|false|string
     * @throws \Exception
     */
    protected static function handle($con, $recv, $fd=0){
        //认证处理
        $authRet = IdLib::auth($con, $fd, $recv);
        if (!$authRet) {
            IdLib::toClose($con, $fd, IdLib::err());
            return false;
        }
        if($authRet==='ok'){
            return $con->send('ok');
        }

        if ($recv[0] == '{') { // substr($recv, 0, 1) == '{' && substr($recv, -1) == '}'
            $data = json_decode($recv, true);
        } else { // querystring
            parse_str($recv, $data);
        }

        if (empty($data)) {
            return $con->send('empty data: '.$recv);
        }
        if (!isset($data['a'])) $data['a'] = 'id';

        $ret = 'ok'; //默认返回信息
        switch ($data['a']) {
            case 'id': //入列 用于消息重试
                $ret = IdLib::$idObj->nextId($data);
                break;
            case 'init':
                $ret = IdLib::$idObj->initId($data);
                break;
            case 'update':
                $ret = IdLib::$idObj->updateId($data);
                break;
            case 'info':
                $ret = static::info();
                break;
            default:
                IdLib::err('invalid request');
                $ret = false;
        }
        return $con->send($ret !== false ? $ret : IdLib::err());
    }

    /**
     * @param \Workerman\Connection\TcpConnection $con
     * @param $url
     * @param int $fd
     * @return string
     * @throws \Exception
     */
    protected static function httpGetHandle($con, $url, $fd=0){
        if (!$url) {
            IdLib::err('URL read failed');
            return static::httpSend($con, $fd, false);
        }
        $parse = parse_url($url);
        $data = [];
        $path = $parse['path'];
        if(!empty($parse['query'])){
            parse_str($parse['query'], $data);
        }

        //认证处理
        if (!IdLib::auth($con, $fd, $data['key']??'nil')) {
            return static::httpSend($con, $fd, false);
        }

        $ret = 'ok'; //默认返回信息
        switch ($path) {
            case '/id': //入列 用于消息重试
                $ret = IdLib::$idObj->nextId($data);
                break;
            case '/init':
                $ret = IdLib::$idObj->initId($data);
                break;
            case '/update':
                $ret = IdLib::$idObj->updateId($data);
                break;
            case '/info':
                $ret = static::info();
                break;
            default:
                IdLib::err('Invalid Request '.$path);
                $ret = false;
        }

        return static::httpSend($con, $fd, $ret);
    }

    /**
     * @param \Workerman\Connection\TcpConnection $con
     * @param int $fd
     * @param false|string $ret
     * @return string
     */
    protected static function httpSend($con, $fd, $ret){
        $code = 200;
        $reason = 'OK';
        if ($ret === false) {
            $code = 400;
            $ret = IdLib::err();
            $reason = 'Bad Request';
        }

        $body_len = strlen($ret);
        $out = "HTTP/1.1 {$code} $reason\r\nServer: my-id\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: {$body_len}\r\nConnection: keep-alive\r\n\r\n{$ret}";
        $con->close($out);
        return '';
    }
}