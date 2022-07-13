<?php
namespace MyId;


class IdFile extends IdGenerate
{
    use IdMsg;

    protected static $isChange = false;
    /**
     * id列表记录  ['name'=>[init_id,max_id,step,delta], ...]
     * @var array
     */
    protected static $idList = [];

    protected static function jsonFileName(){
        return \SrvBase::$instance->runDir . '/.' . \SrvBase::$instance->serverName().'.json';
    }

    /**
     * 返回自增的id
     * @param $name
     * @return string
     */
    protected function incrId($name){
        static::$idList[$name]['last_id'] = static::$idList[$name]['last_id'] + static::$idList[$name]['delta'];
        if (static::$idList[$name]['last_id'] > static::$idList[$name]['pro_load_id']) { //达到预载条件
            $this->toPreLoadId($name);
        }
        return (string)static::$idList[$name]['last_id'];
    }

    /**
     * 预载下一段id
     * @param $name
     */
    protected function toPreLoadId($name)
    {
        static::$idList[$name]['pro_load_id'] = static::$idList[$name]['max_id'] + static::$idList[$name]['pre_step'];
        static::$idList[$name]['max_id'] = static::$idList[$name]['max_id'] + static::$idList[$name]['step'];
        static::$isChange = true;
    }

    public function init(){
        $lockFile = \SrvBase::$instance->runDir . '/' . \SrvBase::$instance->serverName() . '.lock';
        $is_abnormal = file_exists($lockFile);
        touch($lockFile);

        if(is_file(static::jsonFileName())){
            static::$idList = (array)json_decode(file_get_contents(static::jsonFileName()), true);
            //更新最大max_id
            foreach (static::$idList as $name => $info) {
                static::$idList[$name]['pre_step'] = intval(static::PRE_LOAD_RATE * $info['step']);
                //非正常关闭的 直接使用下一段id
                if($is_abnormal){
                    static::$idList[$name]['max_id'] = $info['max_id'] + $info['step'];
                    static::$idList[$name]['last_id'] = $info['max_id'];
                    //id下一段预载规则记录
                    static::$idList[$name]['pro_load_id'] = $info['max_id'] + $info['pre_step'];
                }
            }
            static::$isChange = true;
        }
    }

    public function info() {
        return static::$idList;
    }

    public function save(){
        if (!static::$isChange) return;
        static::$isChange = false;
        file_put_contents(static::jsonFileName(), json_encode(static::$idList), LOCK_EX | LOCK_NB);
    }

    public function stop(){
        static::$isChange = true;
        static::save();
        $lockFile = \SrvBase::$instance->runDir . '/' . \SrvBase::$instance->serverName() . '.lock';
        file_exists($lockFile) && unlink($lockFile);
    }

    /**
     * @param $data
     * @return string|bool
     * @throws \Exception
     */
    public function nextId($data){
        if (empty($data['name'])) {
            self::err('Invalid ID name');
            return false;
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            self::err('ID name does not exist');
            return false;
        }
        $size = isset($data['size']) ? (int)$data['size'] : 1;
        if ($size < 2) return $this->incrId($name);
        if ($size > static::DEF_STEP) $size = static::DEF_STEP;
        $idRet = '';
        for ($i = 0; $i < $size; $i++) {
            $id = $this->incrId($name);
            if ($idRet === '') {
                $idRet = $id;
            } else {
                $idRet .= ',' . $id;
            }
        }
        return $idRet;
    }

    /**
     * 初始id信息
     * @param $data
     * @return false|array
     */
    public function initId($data){
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (!$name) {
            self::err('Invalid ID name');
            return false;
        }
        $name = strtolower($name);
        if (isset(static::$idList[$name])) {
            self::err('This ID name already exists');
            return false;
        }
        if (count(static::$idList) >= static::ALLOW_ID_NUM) {
            self::err('已超出可设置id数');
            return false;
        }

        $step = isset($data['step']) ? (int)$data['step'] : static::DEF_STEP;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 1;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) $step = static::DEF_STEP;
        if ($delta < 1) $delta = 1;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            self::err('Invalid max_id['. $max_id .']!');
            return false;
        }

        static::$idList[$name] = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta, 'last_id' => $init_id, 'pre_step'=>intval(static::PRE_LOAD_RATE * $step)];
        static::$idList[$name]['pro_load_id'] = $init_id + static::$idList[$name]['pre_step'];
        static::$isChange = true;
        static::save();
        return IdLib::toJson(static::$idList[$name]);
    }

    /**
     * 更新id信息
     * @param $data
     * @return bool|false|string
     */
    public function updateId($data){
        if (empty($data['name'])) {
            self::err('Invalid ID name');
            return false;
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            self::err('ID name does not exist');
            return false;
        }

        $max_id = 0;
        $step = isset($data['step']) ? (int)$data['step'] : 0;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 0;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) {
            $step = 0;
        }
        if ($delta < 1) {
            $delta = 0;
        }
        if ($init_id > 0 && $init_id < static::$idList[$name]['last_id']) {
            self::err('Invalid init_id[' . $init_id . ']!');
            return false;
            $init_id = 0;
        }

        if ($init_id > 0) {
            $max_id = $init_id + ($step > 0 ? $step : static::$idList[$name]['step']);
            if ($max_id > PHP_INT_MAX) {
                self::err('Invalid max_id['. $max_id .']!');
                return false;
            }
        }
        if ($step > 0) {
            static::$idList[$name]['step'] = $step;
            static::$idList[$name]['pre_step'] = intval(static::PRE_LOAD_RATE * $step);
        }
        if ($max_id > 0) static::$idList[$name]['max_id'] = $max_id;
        if ($delta > 0) static::$idList[$name]['delta'] = $delta;
        if ($init_id > 0) {
            static::$idList[$name]['init_id'] = $init_id;
            static::$idList[$name]['last_id'] = $init_id;
            static::$idList[$name]['pro_load_id'] = $init_id + static::$idList[$name]['pre_step'];
        }

        static::$isChange = true;
        $this->save();
        return IdLib::toJson(static::$idList[$name]);
    }
}