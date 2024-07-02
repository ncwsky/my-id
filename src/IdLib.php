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

    /**
     * id列表记录  ['name'=>[init_id,max_id,step,delta], ...]
     * @var array
     */
    public static $idList = [];
    public static $change = [];
    public static $add = [];

    public static $allow_id_num = 8192; //允许的id数量
    public static $def_step = 100000; //默认步长
    public static $min_step = 1000; //最小步长
    public static $pre_load_rate = 0.2; //下一段id预载比率 0-1 越小越提前载入下段id

    const TYPE_MASTER = 'master'; //主服务
    const TYPE_WORKER = 'worker'; //从服务
    const TYPE_SINGLE = 'single'; //单进程服务

    const ALLOW_ID_NUM = 8192; //允许的id数量
    const DEF_STEP = 100000; //默认步长
    const MIN_STEP = 1000; //最小步长
    const PRE_LOAD_RATE = 0.2; //下一段id预载比率 []

    const MAX_UNSIGNED_INT = 4294967295;
    const MAX_INT = 2147483647;
    const MAX_UNSIGNED_BIG_INT = 18446744073709551615;
    const MAX_BIG_INT = 9223372036854775807;

    private static $autoInitId = false;
    private static $snowDataId = -1;
    public static $snowStartDiff = 0;
    private static $authKey = '';
    private static $authFd = [];
    private static $type = self::TYPE_SINGLE;
    private static $_to_init_first = true;
    private static $masterAddress = '';
    private static $masterKey = '';
    private static $masterIsHttp = false;

    private static $isWorker = false;
    private static $isMaster = false;
    private static $isSingle = false;
    /**
     * @var null|TcpClient
     */
    private static $client = null;
    private static $relayRecv = '';

    /**
     * @var IdFile|IdDb
     */
    private static $idObj;

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

    public static function isMaster()
    {
        return self::$type == self::TYPE_MASTER;
    }

    public static function isWorker()
    {
        return self::$type == self::TYPE_WORKER;
    }

    public static function checkMasterAddress(){
        $localIps = self::localIp();
        $localIps[] = '127.0.0.1';
        foreach ($localIps as $ip) {
            $address = $ip . ':' . \SrvBase::$instance->port;
            if (strpos(self::$masterAddress, $address) !== false) {
                return false;
            }
        }
        return true;
    }
    public static function forWindows()
    {
        @exec("ipconfig", $array); #ipconfig /all
        if ($array)
            return $array;
        else {
            $ipconfig = $_SERVER["WINDIR"] . "\system32\ipconfig.exe";
            if (is_file($ipconfig))
                @exec($ipconfig, $array); #$ipconfig . " /all"
            else
                @exec($_SERVER["WINDIR"] . "\system\ipconfig.exe /all", $array);
            return $array;
        }
    }
    public static function forLinux()
    {
        @exec("ifconfig", $array); #ifconfig -a
        return $array;
    }
    public static function localIp($loopback=true){
        $array = DIRECTORY_SEPARATOR === '\\' ? self::forWindows() : self::forLinux();
        $list = [];
        foreach ($array as $v){
            if(strpos($v,'inet')!==false || strpos($v,'IP')!==false){
                if (preg_match("/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/", $v, $matches)) {
                    if($matches[0]=='127.0.0.1' && !$loopback) continue;
                    $list[] = $matches[0];
                }
            }
        }
        return $list;
    }

    /**
     * id生成(每毫秒最多9999个id) 最多支持部署9个服务器 每个服务最多10个进程 13位时间戳毫秒+[4位$sequence+1位$worker_id+1位$p] 19位数字
     * @param int $worker_id 进程id 0-9
     * @param int $p 服务器区分值 0-9
     * @return string
     */
    public static function bigId($worker_id = 0, $p = 0)
    {
        static $lastTime = 0, $sequence = 1; //, $uname
        //if (!isset($uname)) $uname = crc32(php_uname('n')) % 10 * 1000;
        if ($worker_id < 0) $worker_id = mt_rand(0, 9);
        elseif ($worker_id > 9) $worker_id = (int)$worker_id % 9;
        if ($p < 0) $p = mt_rand(0, 9);
        elseif ($p > 9) $p = (int)$p % 9;

        $time = (int)(microtime(true)*1000); //time(); 使用毫秒
        if (self::$snowStartDiff > 0) {
            $time = $time - self::$snowStartDiff;
        }
        if ($time == $lastTime) {
            $sequence++; // max 9999
            if ($sequence > 9999) return '0';
        } else {
            $sequence = 1;
            $lastTime = $time;
        }
        return $time . str_pad((string)$sequence, '4', '0', STR_PAD_LEFT) . $worker_id . $p;
        //$uname + random_int(100, 999)
    }

    /**
     * 初始id信息
     * @param $data
     * @return string|null
     * @throws \Exception
     */
    protected static function initId($data)
    {
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (!$name) {
            return self::err('Invalid ID name');
        }
        $name = strtolower($name);
        if (isset(self::$idList[$name])) {
            //主服务存在的id自预载下一段
            if (self::$isMaster) {
                self::toPreLoadId($name);
            }
            return self::toJson(self::$idList[$name]);
            return self::err('This ID name already exists');
        }
        if (count(self::$idList) >= self::ALLOW_ID_NUM) {
            return self::err('已超出可设置id数');
        }

        if (self::$isWorker) {
            $data['a'] = str_replace('id', 'init', $data['a']); //可能是id->init
            $ret = self::clientSend($data, $raw);
            if (!$ret) {
                return null;
            }
            self::$idList[$name] = $ret;
            return $raw;
        }

        $step = isset($data['step']) ? (int)$data['step'] : self::DEF_STEP;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 1; // 1-999
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < self::MIN_STEP) $step = self::MIN_STEP;
        if ($delta < 1) $delta = 1;
        if ($delta > 999) $delta = 999;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            return self::err('Invalid max_id[' . $max_id . ']!');
        }

        $info = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta, 'last_id' => $init_id];
        self::$idList[$name] = $info;
        self::$idList[$name]['pre_load_id'] = $init_id + intval(self::PRE_LOAD_RATE * $step);

        $info['name'] = $name;
        $info['ctime'] = date('Y-m-d H:i:s');
        self::$add[$name] = $info;

        try {
            self::$idObj->save();
        } catch (\Exception $e) {
            return self::err($e->getMessage());
        }
        return self::toJson(self::$idList[$name]);
    }

    /**
     * 返回自增的id
     * @param $name
     * @return string
     * @throws \Exception
     */
    protected static function incrId($name)
    {
        self::$idList[$name]['last_id'] = self::$idList[$name]['last_id'] + self::$idList[$name]['delta'];

        //id超限成float类型数字
        if (!is_int(self::$idList[$name]['last_id'])) {
            return (int)self::$idList[$name]['last_id'];
        }

        if (self::$idList[$name]['last_id'] > self::$idList[$name]['pre_load_id']) { //达到预载条件
            self::toPreLoadId($name);
        } elseif (!self::$isWorker) { //仅主服务、单进程模式才需要数据落地
            if (isset(self::$change[$name])) {
                self::$change[$name]['last_id'] = self::$idList[$name]['last_id'];
            } else {
                self::$change[$name] = ['last_id' => self::$idList[$name]['last_id']];
            }
        }

        //达到本id段最大值 切换到下一已预载的id段 id值并重置为新的
        if (isset(self::$idList[$name]['next_max_id']) && self::$idList[$name]['last_id'] > self::$idList[$name]['max_id']) {
            self::$idList[$name]['max_id'] = self::$idList[$name]['next_max_id'];
            self::$idList[$name]['last_id'] = (self::$idList[$name]['max_id'] - self::$idList[$name]['step']) + self::$idList[$name]['init_id'] + self::$idList[$name]['delta'];
        }

        return (string)self::$idList[$name]['last_id'];
    }

    /**
     * 主服务初始id段数据
     * @return string|null
     * @throws \Exception
     */
    public static function toInit()
    {
        if (self::$_to_init_first) {
            self::$_to_init_first = false;
            return self::toJson(self::$idList);
        }
        foreach (self::$idList as $name => $info) {
            self::toPreLoadId($name);
        }
        self::safeEcho(self::toJson(self::$idList));
        return self::toJson(self::$idList);
    }

    /**
     * 预载下一段id
     * @param $name
     * @throws \Exception
     */
    protected static function toPreLoadId($name)
    {
        if (self::$isWorker) {
            $info = self::clientSend(['a' => 'toPreLoadId', 'name' => $name]);

            //下一段预载判断id值
            self::$idList[$name]['pre_load_id'] = $info['pre_load_id'];
            //下一段预载id最大值
            self::$idList[$name]['next_max_id'] = $info['max_id'];
            return;
        }

        self::$idList[$name]['pre_load_id'] = self::$idList[$name]['max_id'] + intval(self::PRE_LOAD_RATE * self::$idList[$name]['step']);
        //更新主服务 last_id,防止子服务拿到相同的起始id
        if (self::$isMaster) {
            self::$idList[$name]['last_id'] = self::$idList[$name]['max_id'];
        }
        //下段最大id
        self::$idList[$name]['max_id'] = self::$idList[$name]['max_id'] + self::$idList[$name]['step'];
        self::$change[$name] = ['max_id' => self::$idList[$name]['max_id'], 'last_id' => self::$idList[$name]['last_id']];
    }

    /**
     * 统计信息
     * @param array $names
     * @return string
     */
    public static function info($names = [])
    {
        if ($names) {
            $ret = [];
            foreach ($names as $name) {
                $ret[$name] = self::$idList[$name] ?? null;
            }
            return self::toJson($ret);
        }

        $infoStats = [
            'data' => date("Y-m-d H:i:s", time()),
            'real_recv_num' => self::$realRecvNum,
            'info' => self::$idList
        ];
        return self::toJson($infoStats);
    }

    /**
     * 取下一段自增id
     * @param $data
     * @return string|null
     * @throws \Exception
     */
    public static function nextId($data)
    {
        if (empty($data['name'])) {
            return self::err('Invalid ID name');
        }
        $name = strtolower($data['name']);
        if (!isset(self::$idList[$name])) {
            if (self::$autoInitId) {
                $initRet = self::initId($data); //自动初始id
                if (!$initRet) return null;
            } else {
                return self::err('ID name does not exist');
            }
        }
        $size = isset($data['size']) ? (int)$data['size'] : 1;
        if ($size < 2) return self::incrId($name);
        if ($size > self::DEF_STEP) $size = self::DEF_STEP;
        $idRet = '';
        for ($i = 0; $i < $size; $i++) {
            $id = self::incrId($name);
            if ($idRet === '') {
                $idRet = $id;
            } else {
                $idRet .= ',' . $id;
            }
        }
        return $idRet;
    }

    /**
     * 更新id信息 可用于修正id
     * @param $data
     * @return string|null
     * @throws \Exception
     */
    public static function updateId($data)
    {
        if (self::$isWorker) {
            $ret = self::clientSend($data, $raw);
            if (!$ret) return null;
            return $raw;
        }
        if (empty($data['name'])) {
            return self::err('Invalid ID name');
        }
        $name = strtolower($data['name']);
        if (!isset(self::$idList[$name])) {
            return self::err('ID name does not exist');
        }

        $max_id = 0;
        $step = isset($data['step']) ? (int)$data['step'] : 0;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 0;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < self::MIN_STEP) {
            $step = 0;
        }
        if ($delta < 1) {
            $delta = 0;
        }
        if ($init_id > 0 && $init_id < self::$idList[$name]['last_id']) {
            return self::err('Invalid init_id[' . $init_id . ']!');
        }

        if ($init_id > 0) {
            $max_id = $init_id + ($step > 0 ? $step : self::$idList[$name]['step']);
            if ($max_id > PHP_INT_MAX) {
                return self::err('Invalid max_id[' . $max_id . ']!');
            }
        }
        if ($step > 0) {
            self::$idList[$name]['step'] = $step;
        }
        if ($max_id > 0) self::$idList[$name]['max_id'] = $max_id;
        if ($delta > 0) self::$idList[$name]['delta'] = $delta;
        if ($init_id > 0) {
            self::$idList[$name]['init_id'] = $init_id;
            self::$idList[$name]['last_id'] = $init_id;
            self::$idList[$name]['pre_load_id'] = $init_id + intval(self::PRE_LOAD_RATE * $step);
        }


        self::$change[$name] = self::$idList[$name];
        unset(self::$change[$name]['pre_load_id']);

        //self::$idObj->save(); //todo save
        return self::toJson(self::$idList[$name]);
    }

    /**
     * @param array $data
     * @param null $rawRet
     * @return array|string|null
     */
    public static function clientSend($data, &$rawRet=null)
    {
        if (self::$masterIsHttp) {
            $data['key'] = self::$masterKey;
            $method = $data['a'];
            unset($data['a']);
            $rawRet = \Http::curlSend(self::$masterAddress . $method, 'GET', $data, 5);
            if (false === $rawRet) {
                return self::err(self::$masterAddress . ':请求失败');
                //throw new \Exception(self::$masterAddress . ':请求失败');
            }
        } else {
            $ok = self::$client->send(http_build_query($data, "", "&", PHP_QUERY_RFC3986));
            if (!$ok) {
                return self::err(self::$client->error);
                //throw new \Exception(self::$client->error);
            }
            $rawRet = self::$client->recv();
        }
        if ($rawRet[0] == '-') { //失败消息
            return self::err(substr($rawRet, 1));
        }
        return json_decode($rawRet, true);
    }

    /**
     * Safe Echo.
     * @param string $msg
     * @param bool $micro
     */
    public static function safeEcho($msg, $micro=false)
    {
        \SrvBase::safeEcho(($micro?date('Y-m-d H:i:s') . '.' . substr(microtime(), 2, 3).' ':'').$msg.PHP_EOL);
        !\SrvBase::$isConsole && \SrvBase::log($msg);
    }
    /**
     * 进程启动时处理 初始ID数据
     * @param \Worker2|\swoole_server $worker
     * @param $worker_id
     * @throws \Exception
     */
    public static function onWorkerStart($worker, $worker_id)
    {
        self::$type = \GetOpt::val('t', 'type', self::TYPE_SINGLE);
        //配置
        self::$authKey = GetC('auth_key', '');
        self::$autoInitId = GetC('auto_init_id', false);
        self::$snowDataId = GetC('snow_data_id', -1);
        self::$snowStartDiff = GetC('snow_start_diff', 0);
        //模式
        self::$isWorker = self::$type == self::TYPE_WORKER;
        self::$isMaster = self::$type == self::TYPE_MASTER;
        self::$isSingle = self::$type == self::TYPE_SINGLE;

        if (self::$isWorker) { //从服务模式
            $master_host = \GetOpt::val('h', 'master_host'); //优先命令输入>conf.php配置里的设置
            $key = \GetOpt::val('k', 'master_key');

            self::$masterAddress = $master_host ?: GetC('master_address');
            self::$masterKey = $key ?: GetC('master_key');

            if (!self::$masterAddress) {
                self::safeEcho('未配置主服务地址: master_address');
                \SrvBase::$instance->stop();
            }
            if (!self::checkMasterAddress()) {
                self::safeEcho('主服务地址无效，与当前服务冲突: '.self::$masterAddress);
                \SrvBase::$instance->stop();
            }

            self::safeEcho('连接主服务: ' . self::$masterAddress);
            if (substr(self::$masterAddress, 0, 4) == 'http') {
                self::$masterIsHttp = true;
            } else {
                self::$masterIsHttp = false;
                self::$client = \MyId\TcpClient::instance('', self::$masterAddress);
                self::$client->packageEof = "\r\n";
                self::$client->onConnect = function (TcpClient $client) {
                    //发送验证
                    if (self::$masterKey) {
                        $client->send(self::$masterKey);
                        $recv = self::$client->recv();
                        if ($recv[0] == '-') { //失败消息
                            self::safeEcho('连接主服务验证失败:'.substr($recv, 1));
                            \SrvBase::$instance->stop();
                        }
                    }
                    //从主服务获取id段数据
                    self::$idList = self::clientSend(['a' => 'toInit']);
                    if (self::$idList === null) {
                        self::safeEcho('从主服务获取id段数据失败:'.self::err());
                        \SrvBase::$instance->stop();
                    }
                };
                try {
                    self::$client->open();
                    self::safeEcho('连接成功');
                } catch (\Exception $e) {
                    self::safeEcho($e->getMessage());
                    \SrvBase::$instance->stop();
                }
            }
            return;
        } else {
            //$lockFile = \SrvBase::$instance->runDir . '/'.ID_NAME.'.lock';
            //$is_abnormal = file_exists($lockFile);
            //touch($lockFile);
        }

        if (GetC('db.name')) {
            self::$idObj = new IdDb();
        } else {
            self::$idObj = new IdFile();
        }

        self::$idList = self::$idObj->all();
        //更新最大max_id
        foreach (self::$idList as $name => $info) {
            $pre_step = intval(self::PRE_LOAD_RATE * $info['step']);
            self::$idList[$name]['init_id'] = (int)$info['init_id'];
            self::$idList[$name]['step'] = (int)$info['step'];
            self::$idList[$name]['delta'] = (int)$info['delta'];
            self::$idList[$name]['pre_load_id'] = ($info['max_id'] - $info['step']) + $pre_step;
            /*  //使用了预载id+定时落地数据 异常关闭重启后可不必更新为下一段id
            //非正常关闭的 直接使用下一段id
            if ($is_abnormal) {
                self::$idList[$name]['max_id'] = $info['max_id'] + $info['step'];
                self::$idList[$name]['last_id'] = $info['max_id'];
                //id下一段预载规则记录
                self::$idList[$name]['pre_load_id'] = $info['max_id'] + $pre_step;

                //变动数据
                self::$change[$name] = ['max_id' => self::$idList[$name]['max_id'], 'last_id' => $info['max_id']];
            }*/
            unset(self::$idList[$name]['name']);
        }
        //更新数据
        if (self::$change) {
            self::$idObj->save();
        }

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
        if (self::$isWorker) return; //是从服务不用处理
        self::$idObj->save(); //新增或变动的数据落地
        //$lockFile = \SrvBase::$instance->runDir . '/'.ID_NAME.'.lock';
        //file_exists($lockFile) && unlink($lockFile);
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
        if (!self::$isWorker) self::$realRecvNum++;

        $prefix = substr($recv, 0, 4);
        if ($prefix === 'GET ') {
            $url = substr($recv, 4, strpos($recv, ' ', 4) - 4);
            return self::httpGetHandle($con, $url, $fd);
        } elseif ($prefix === 'POST') {
            self::err('Only GET requests are supported');
            return self::httpSend($con, null, $fd);
        }
        //self::$relayRecv = $recv;
        return self::handle($con, $recv, $fd);
    }

    public static function httpAuth($fd, $key=''){
        //Log::write($fd, 'httpFd');
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
                //Log::write('auth timeout to close ' . $fd . '-'. self::$authFd[$fd],'xx');
                unset(self::$authFd[$fd]);
                \SrvBase::toClose($con, $fd);
            });
        }
        return true;
    }

    /**
     * @param array $data
     * @param null|string $raw
     */
    private static function _run(&$data, &$raw=null){
        switch ($data['a']) {
            case 'snow': //雪花
            case '/snow':
                if (self::$isWorker) {
                    $worker_id = isset($data['worker_id']) ? (int)$data['worker_id'] : \SrvBase::$instance->workerId();
                } else { //单进程和主服务使用随机id
                    $worker_id = random_int(0, 7);
                }
                $p = isset($data['p']) ? (int)$data['p'] : self::$snowDataId; //random_int(0,7);

                $size = isset($data['size']) ? (int)$data['size'] : 1;
                if ($size < 2) {
                    $raw = self::bigId($worker_id, $p);
                } else {
                    if ($size > self::DEF_STEP) $size = self::DEF_STEP;
                    $raw = '';
                    for ($i = 0; $i < $size; $i++) {
                        $id = self::bigId($worker_id, $p);
                        if ($raw === '') {
                            $raw = $id;
                        } else {
                            $raw .= ',' . $id;
                        }
                    }
                }
                break;
            case 'id': //获取id
            case '/id':
                if (self::$isMaster) {
                    $raw = self::err('主服务不支持此操作');
                    break;
                }
                $raw = self::nextId($data);
                break;
            case 'init': //初始id
            case '/init':
                $raw = self::initId($data);
                break;
            case 'update':
            case '/update':
                $raw = self::updateId($data);
                break;
            case 'info':
            case '/info':
                $names = isset($data['name']) ? explode(',', $data['name']) : [];
                if (self::$isWorker && !empty($data['master'])) { //获取主服务的信息
                    self::clientSend($data, $raw);
                } else {
                    $raw = self::info($names);
                }
                break;
            //主服务
            case 'toInit':
            case '/toInit':
                if (!self::$isMaster) {
                    $raw = self::err('非主服务');
                } else {
                    $raw = self::toInit();
                }
                break;
            case 'toPreLoadId':
            case '/toPreLoadId':
                if (!self::$isMaster) {
                    $raw = self::err('非主服务');
                    break;
                }
                $name = $data['name'];
                self::toPreLoadId($name);
                $raw = self::toJson([
                    'pre_load_id' => self::$idList[$name]['pre_load_id'],
                    'max_id' => self::$idList[$name]['max_id']
                ]);
                break;
            default:
                $raw = self::err('invalid request');
        }
    }

    /**
     * @param TcpConnection|\swoole_server $con
     * @param string $recv
     * @param int $fd
     * @return bool|null
     */
    private static function handle($con, $recv, $fd=0){
        //记录主服务日志
        if (self::$isMaster) {
            $remote = \SrvBase::$instance->clientInfo($fd, $con);
            self::safeEcho($remote['remote_ip'].':'.$remote['remote_port'] . ':<-' . $recv, true);
        }

        //认证处理
        $authRet = self::auth($con, $fd, $recv);
        if (false === $authRet) {
            \SrvBase::toClose($con, $fd, self::err());
            return false;
        }
        if ($authRet === null) {
            \SrvBase::toSend($con, $fd, '+OK');
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
        if (!isset($data['a'])) {
            $data['a'] = isset($data['name']) ? 'id' : 'unknown';
        }

        //处理
        self::_run($data, $raw);

        //记录主服务日志
        if (self::$isMaster) {
            self::safeEcho($remote['remote_ip'].':'.$remote['remote_port'] . ':->' . $raw, true);
        }

        return \SrvBase::toSend($con, $fd, $raw !== null ? $raw : self::err());
    }

    /**
     * @param TcpConnection|\swoole_server $con
     * @param string $url
     * @param int $fd
     * @return null
     */
    private static function httpGetHandle($con, $url, $fd=0){
        //记录主服务日志
        if (self::$isMaster) {
            $remote = \SrvBase::$instance->clientInfo($fd, $con);
            self::safeEcho($remote['remote_ip'].':'.$remote['remote_port'] . ':<-' . $url, true);
        }

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
        self::_run($data, $raw);

        //记录主服务日志
        if (self::$isMaster) {
            self::safeEcho($remote['remote_ip'].':'.$remote['remote_port'] . ':->' . $raw, true);
        }

        return self::httpSend($con, $raw, $fd);
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