<?php
namespace MyId;
use Workerman\Connection\TcpConnection;

/**
 * ID生成类库
 * Class IdLib
 * @package MyId
 */
class IdLib
{
    use \MyMsg;

    //错误提示设置或读取
    public static function err($msg=null, $code=1){
        if ($msg === null) {
            return self::$myMsg;
        } else {
            self::msg('-'.$msg, $code); //参照redis的错误信息
            return null;
        }
    }

    private static $authKey = '';
    private static $authFd = [];
    /**
     * @var IdFile|IdDb
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

    /**
     * @param $buffer
     * @return string|null
     */
    public static function toJson($buffer):?string
    {
        $json = \json_encode($buffer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return false === $json ? null : $json;
    }

    /**
     * id生成(每秒最多99999个id) 最多支持部署100个服务器 每个服务最多100个进程 10位时间戳+[5位$sequence+2位$worker_id+2位$p] 19位数字  //[5位$sequence+2位uname+2位rand]
     * @param int $worker_id 进程id 0-99
     * @param int $p 服务器区分值 0-99
     * @return int 8字节长整形
     */
    public static function bigId($worker_id = 0, $p = 0)
    {
        static $lastTime = 0, $sequence = 1; //, $uname
        //if (!isset($uname)) $uname = crc32(php_uname('n')) % 10 * 1000;
        if ($worker_id < 0) $worker_id = 0;
        elseif ($worker_id > 99) $worker_id = 99;
        if ($p < 0) $p = 0;
        elseif ($p > 99) $p = 99;

        $time = time();
        if ($time == $lastTime) {
            $sequence++; // max 99999
        } else {
            $sequence = 1;
            $lastTime = $time;
        }
        //$uname + random_int(100, 999)
        return (int)((string)$time . '000000000') + (int)((string)$sequence . '0000') + $worker_id * 100 + $p;
        return (int)sprintf('%d%05d%02d%02d', $time, $sequence, $worker_id, $p);
        return $time * 1000000000 + $sequence * 10000 + $worker_id * 100 + $p;
    }

    /**
     * 进程启动时处理
     * @param \Worker2|\swoole_server $worker
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
     * @param TcpConnection|\swoole_server $con
     * @param string $recv
     * @param int $fd
     * @return bool|null
     */
    public static function onReceive($con, $recv, $fd=0)
    {
        self::$realRecvNum++;

        $prefix = substr($recv, 0, 4);
        if ($prefix === 'GET ') {
            $url = substr($recv, 4, strpos($recv, ' ', 4) - 4);
            return self::httpGetHandle($con, $url, $fd);
        } elseif ($prefix === 'POST') {
            self::err('Only GET requests are supported');
            return self::httpSend($con, null, $fd);
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
     * tcp认证
     * @param TcpConnection|\swoole_server $con
     * @param int $fd
     * @param string $recv
     * @return bool|null
     */
    public static function auth($con, $fd, $recv = '')
    {
        //认证key
        if (!self::$authKey) return true;

        //连接断开清除
        if ($recv === null) {
            unset(self::$authFd[$fd]);
            //\SrvBase::$isConsole && \SrvBase::safeEcho('clear auth '.$fd);
            return true;
        }

        if ($recv!=='') {
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
            return null;
        }

        //创建定时认证 $recv===''
        if(!isset(self::$authFd[$fd])){
            self::$authFd[$fd] = \SrvBase::$instance->server->after(1000, function () use ($con, $fd) {
                //\SrvBase::$isConsole && \SrvBase::safeEcho('auth timeout to close ' . $fd . '-'. self::$authFd[$fd] . PHP_EOL);
                //\Log::write('auth timeout to close ' . $fd . '-'. self::$authFd[$fd],'xx');
                unset(self::$authFd[$fd]);
                \SrvBase::toClose($con, $fd);
            });
        }
        return true;
    }

    /**
     * 统计信息
     * @param array $names
     * @return string|null
     */
    private static function info($names=[]){
        self::$infoStats['date'] = date("Y-m-d H:i:s", time());
        self::$infoStats['real_recv_num'] = self::$realRecvNum;
        self::$infoStats['info'] = self::$idObj->info();
        return self::toJson(self::$infoStats);
    }

    private static function _run(&$data, &$ret=false){
        switch ($data['a']) {
            case 'snow': //雪花
            case '/snow':
                $worker_id = isset($data['worker_id']) ? (int)$data['worker_id'] : random_int(0,99);
                $p = isset($data['p']) ? (int)$data['p'] : random_int(0,99);
                $ret = self::bigId($worker_id, $p);
                break;
            case 'id': //获取id
            case '/id':
                $ret = self::$idObj->nextId($data);
                break;
            case 'init': //初始id
            case '/init':
                $ret = self::$idObj->initId($data);
                break;
            case 'update':
            case '/update':
                $ret = self::$idObj->updateId($data);
                break;
            case 'info':
            case '/info':
                $ret = self::info();
                break;
            default:
                $ret = self::err('invalid request');
        }
    }

    /**
     * @param TcpConnection|\swoole_server $con
     * @param string $recv
     * @param int $fd
     * @return bool|null
     */
    private static function handle($con, $recv, $fd=0){
        //认证处理
        $authRet = self::auth($con, $fd, $recv);
        if (false === $authRet) {
            \SrvBase::toClose($con, $fd, self::err());
            return false;
        }
        if ($authRet === null) {
            return null;
        }

        if ($recv[0] == '{') { // substr($recv, 0, 1) == '{' && substr($recv, -1) == '}'
            $data = \json_decode($recv, true);
        } else { // querystring
            parse_str($recv, $data);
        }

        if (empty($data)) {
            return \SrvBase::toSend($con, $fd, '-empty data: '.$recv);
        }
        if (!isset($data['a'])) $data['a'] = 'id';

        //处理
        self::_run($data, $ret);

        return \SrvBase::toSend($con, $fd, $ret !== null ? $ret : self::err());
    }

    /**
     * @param TcpConnection|\swoole_server $con
     * @param string $url
     * @param int $fd
     * @return null
     */
    private static function httpGetHandle($con, $url, $fd=0){
        if (!$url) {
            self::err('URL read failed');
            return self::httpSend($con, null, $fd);
        }
        $parse = parse_url($url);
        $data = [];
        if(!empty($parse['query'])){
            parse_str($parse['query'], $data);
        }
        $data['a'] = $parse['path'];

        //认证处理
        if (!self::httpAuth($fd, $data['key']??'')) {
            return self::httpSend($con, null, $fd);
        }

        //处理
        self::_run($data, $ret);

        return self::httpSend($con, $ret, $fd);
    }

    /**
     * @param TcpConnection|\swoole_server $con
     * @param string|null $ret
     * @param int $fd
     * @return null
     */
    private static function httpSend($con, $ret, $fd=0){
        $code = 200;
        $reason = 'OK';
        if ($ret === null) {
            #$code = 400;
            $ret = self::err();
            #$reason = 'Bad Request';
        }

        $body_len = strlen($ret);
        $out = "HTTP/1.1 {$code} $reason\r\nServer: my-id\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: {$body_len}\r\nConnection: keep-alive\r\n\r\n{$ret}";
        \SrvBase::toClose($con, $fd, $out);
        return null;
    }
}