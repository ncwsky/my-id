<?php
namespace MyId;
use Workerman\Connection\TcpConnection;

/**
 * 公共函数类库
 * Class IdLib
 * @package MyId
 */
class IdLib
{
    public static $myMsg = '';
    public static $myCode = 0;
    //消息记录
    public static function msg($msg=null, $code=0){
        if ($msg === null) {
            return self::$myMsg;
        } else {
            self::$myMsg = $msg;
            self::$myCode = $code;
        }
    }
    //错误提示设置或读取
    public static function err($msg=null, $code=1){
        if ($msg === null) {
            return self::$myMsg;
        } else {
            self::msg('-'.$msg, $code);
        }
    }

    private static $authKey = '';
    private static $authFd = [];
    /**
     * @var IdFile
     */
    private static $idObj;

    /**
     * 信息统计
     * @var array
     */
    private static $infoStats = [];

    /**
     * 每秒实时接收数量
     * @var int
     */
    private static $realRecvNum = 0;

    public static function toJson($buffer)
    {
        return json_encode($buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
     * 进程启动时处理
     * @param $worker
     * @param $worker_id
     * @throws \Exception
     */
    public static function onWorkerStart($worker, $worker_id)
    {
        self::$authKey = GetC('auth_key');
        if (GetC('db.name')) {
            self::$idObj = new IdDb();
        } else {
            self::$idObj = new IdFile();
        }
        self::$idObj->init();

        //n ms实时数据落地
        $worker->tick(1000, function () {
            self::$realRecvNum = 0;
            self::$idObj->save();
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
        self::$idObj->stop();
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
        self::$realRecvNum++;

        if(substr($recv, 0, 3)==='GET'){
            $url = substr($recv, 4, strpos($recv, ' ', 4) - 4);
            return self::httpGetHandle($con, $url, $fd);
        }

        return self::handle($con, $recv, $fd);
    }

    public static function httpAuth($fd, $key=''){
        //\Log::write($fd, 'httpFd');
        if(isset(self::$authFd[$fd])){
            \SrvBase::$instance->server->clearTimer(self::$authFd[$fd]);
            unset(self::$authFd[$fd]);
        }
        //认证key
        if (!self::$authKey || self::$authKey === $key) return true;

        self::err('auth fail');
        return false;
    }

    /**
     * tcp 认证
     * @param TcpConnection $con
     * @param $fd
     * @param string $recv
     * @return bool|string
     */
    public static function auth($con, $fd, $recv = null)
    {
        //认证key
        if (!self::$authKey) return true;

        //连接断开清除
        if ($recv === false) {
            unset(self::$authFd[$fd]);
            //\SrvBase::$isConsole && \SrvBase::safeEcho('clear auth '.$fd);
            return true;
        }

        if ($recv!==null) {
            if (isset(self::$authFd[$fd]) && self::$authFd[$fd] === true) {
                return true;
            }
            \SrvBase::$instance->server->clearTimer(self::$authFd[$fd]);
            if ($recv == self::$authKey) { //通过认证
                self::$authFd[$fd] = true;
            } else {
                unset(self::$authFd[$fd]);
                self::err('auth fail');
                return false;
            }
            return 'ok';
        }

        //创建定时认证 $recv===null
        if(!isset(self::$authFd[$fd])){
            self::$authFd[$fd] = \SrvBase::$instance->server->after(1000, function () use ($con, $fd) {
                //\SrvBase::$isConsole && \SrvBase::safeEcho('auth timeout to close ' . $fd . '-'. self::$authFd[$fd] . PHP_EOL);
                //\Log::write('auth timeout to close ' . $fd . '-'. self::$authFd[$fd],'xx');
                unset(self::$authFd[$fd]);
                $con->close();
            });
        }
        return true;
    }

    /**
     * 统计信息 存储
     */
    private static function info(){
        self::$infoStats['date'] = date("Y-m-d H:i:s", time());
        self::$infoStats['real_recv_num'] = self::$realRecvNum;
        self::$infoStats['info'] = self::$idObj->info();
        return self::toJson(self::$infoStats);
    }

    /**
     * @param TcpConnection $con
     * @param string $recv
     * @param int $fd
     * @return array|bool|false|string
     * @throws \Exception
     */
    private static function handle($con, $recv, $fd=0){
        //认证处理
        $authRet = self::auth($con, $fd, $recv);
        if (!$authRet) {
            $con->close(self::err());
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
                $ret = self::$idObj->nextId($data);
                break;
            case 'init':
                $ret = self::$idObj->initId($data);
                break;
            case 'update':
                $ret = self::$idObj->updateId($data);
                break;
            case 'info':
                $ret = self::info();
                break;
            default:
                self::err('invalid request');
                $ret = false;
        }
        return $con->send($ret !== false ? $ret : self::err());
    }

    /**
     * @param \Workerman\Connection\TcpConnection $con
     * @param $url
     * @param int $fd
     * @return string
     * @throws \Exception
     */
    private static function httpGetHandle($con, $url, $fd=0){
        if (!$url) {
            self::err('URL read failed');
            return self::httpSend($con, false);
        }
        $parse = parse_url($url);
        $data = [];
        $path = $parse['path'];
        if(!empty($parse['query'])){
            parse_str($parse['query'], $data);
        }

        //认证处理
        if (!self::httpAuth($fd, $data['key']??'')) {
            return self::httpSend($con, false);
        }

        $ret = 'ok'; //默认返回信息
        switch ($path) {
            case '/id': //入列 用于消息重试
                $ret = self::$idObj->nextId($data);
                break;
            case '/init':
                $ret = self::$idObj->initId($data);
                break;
            case '/update':
                $ret = self::$idObj->updateId($data);
                break;
            case '/info':
                $ret = self::info();
                break;
            default:
                self::err('Invalid Request '.$path);
                $ret = false;
        }

        return self::httpSend($con, $ret);
    }

    /**
     * @param \Workerman\Connection\TcpConnection $con
     * @param false|string $ret
     * @return string
     */
    private static function httpSend($con, $ret){
        $code = 200;
        $reason = 'OK';
        if ($ret === false) {
            $code = 400;
            $ret = self::err();
            $reason = 'Bad Request';
        }

        $body_len = strlen($ret);
        $out = "HTTP/1.1 {$code} $reason\r\nServer: my-id\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: {$body_len}\r\nConnection: keep-alive\r\n\r\n{$ret}";
        $con->close($out);
        return '';
    }
}