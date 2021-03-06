<?php
namespace MyId;


class IdDb implements IdGenerate
{
    const ALLOW_ID_NUM = 256; //允许的id数量
    const DEF_STEP = 100000; //默认步长
    const MIN_STEP = 1000; //最小步长
    const PRE_LOAD_RATE = 0.2; //下一段id预载比率

    /**
     * id列表记录  ['name'=>[init_id,max_id,step,delta], ...]
     * @var array
     */
    protected static $idList = [];

    protected static $change = [];
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
        static::$idList[$name]['pro_load_id'] = static::$idList[$name]['max_id'] + intval(static::PRE_LOAD_RATE * static::$idList[$name]['step']);
        static::$idList[$name]['max_id'] = static::$idList[$name]['max_id'] + static::$idList[$name]['step'];

        static::$change[$name] = ['max_id' => static::$idList[$name]['max_id']];
    }

    public function init(){
        $lockFile = \SrvBase::$instance->runDir . '/my_id.lock';
        $is_abnormal = file_exists($lockFile);
        touch($lockFile);

        static::$idList = db()->table('id_list')->idx('name')->fields('name,init_id,max_id,step,delta,last_id')->all();
        //更新最大max_id
        foreach (static::$idList as $name => $info) {
            static::$idList[$name]['init_id'] = (int)$info['init_id'];
            static::$idList[$name]['step'] = (int)$info['step'];
            static::$idList[$name]['delta'] = (int)$info['delta'];
            static::$idList[$name]['pro_load_id'] = ($info['max_id']-$info['step']) + intval(static::PRE_LOAD_RATE * $info['step']);
            //非正常关闭的 直接使用下一段id
            if($is_abnormal){
                static::$idList[$name]['max_id'] = $info['max_id'] + $info['step'];
                static::$idList[$name]['last_id'] = $info['max_id'];
                //id下一段预载规则记录
                static::$idList[$name]['pro_load_id'] = $info['max_id'] + intval(static::PRE_LOAD_RATE * $info['step']);

                //更新数据
                db()->update(['max_id' => static::$idList[$name]['max_id'], 'last_id'=>$info['max_id']], 'id_list', ['name'=>$name]);

            }
            unset(static::$idList[$name]['name']);
        }
    }

    public function info(){
        return static::$idList;
    }

    public function save(){
        db()->beginTrans();
        foreach (static::$change as $name=>$info){
            db()->update($info, 'id_list', ['name'=>$name]);
        }
        db()->commit();
        static::$change = [];
    }

    public function stop(){
        //正常关闭更新数据最后id
        db()->beginTrans();
        foreach (static::$idList as $name=>$info){
            db()->update(['max_id'=>$info['max_id'], 'last_id'=>$info['last_id']], 'id_list', ['name'=>$name]);
        }
        db()->commit();
        $lockFile = \SrvBase::$instance->runDir . '/my_id.lock';
        file_exists($lockFile) && unlink($lockFile);
    }

    /**
     * @param $data
     * @return string|bool
     * @throws \Exception
     */
    public function nextId($data){
        if (empty($data['name'])) {
            IdLib::err('Invalid ID name');
            return false;
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            IdLib::err('ID name does not exist');
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
            IdLib::err('Invalid ID name');
            return false;
        }
        $name = strtolower($name);
        if (isset(static::$idList[$name])) {
            IdLib::err('This ID name already exists');
            return false;
        }
        if (count(static::$idList) >= static::ALLOW_ID_NUM) {
            IdLib::err('已超出可设置id数');
            return false;
        }

        $step = isset($data['step']) ? (int)$data['step'] : static::DEF_STEP;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 1;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) $step = static::DEF_STEP;
        if ($delta < 1) $delta = 1;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            IdLib::err('Invalid max_id['. $max_id .']!');
            return false;
        }

        $data = $info = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta, 'last_id' => $init_id];
        $data['name'] = $name;
        $data['ctime'] = date('Y-m-d H:i:s');
        try{
            db()->add($data, 'id_list');
        } catch (\Exception $e){
            IdLib::err($e->getMessage());
            return false;
        }

        static::$idList[$name] = $info;
        static::$idList[$name]['pro_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        return IdLib::toJson(static::$idList[$name]);
    }

    /**
     * 更新id信息
     * @param $data
     * @return bool|false|string
     */
    public function updateId($data){
        if (empty($data['name'])) {
            IdLib::err('Invalid ID name');
            return false;
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            IdLib::err('ID name does not exist');
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
            IdLib::err('Invalid init_id[' . $init_id . ']!');
            return false;
        }

        if ($init_id > 0) {
            $max_id = $init_id + ($step > 0 ? $step : static::$idList[$name]['step']);
            if ($max_id > PHP_INT_MAX) {
                IdLib::err('Invalid max_id['. $max_id .']!');
                return false;
            }
        }
        $update = [];
        if ($step > 0) {
            $update['step'] = $step;
        }
        if ($max_id > 0) $update['max_id'] = $max_id;
        if ($delta > 0) $update['delta'] = $delta;
        if ($init_id > 0) {
            $update['init_id'] = $init_id;
            $update['last_id'] = $init_id;
        }

        try{
            db()->update($update, 'id_list', ['name'=>$name]);
        } catch (\Exception $e){
            IdLib::err($e->getMessage());
            return false;
        }

        $init_id > 0 && $update['pro_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        static::$idList[$name] = array_merge(static::$idList[$name], $update);
        return IdLib::toJson(static::$idList[$name]);
    }
}