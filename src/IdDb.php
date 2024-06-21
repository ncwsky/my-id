<?php
namespace MyId;

class IdDb extends IdAbstract implements IdGenerate
{
    public function init()
    {
        $lockFile = \SrvBase::$instance->runDir . '/my_id.lock';
        $is_abnormal = file_exists($lockFile);
        touch($lockFile);

        static::$idList = db()->table('id_list')->idx('name')->fields('name,init_id,max_id,step,delta,last_id')->all();
        //更新最大max_id
        foreach (static::$idList as $name => $info) {
            static::$idList[$name]['init_id'] = (int)$info['init_id'];
            static::$idList[$name]['step'] = (int)$info['step'];
            static::$idList[$name]['delta'] = (int)$info['delta'];
            static::$idList[$name]['pre_load_id'] = ($info['max_id'] - $info['step']) + intval(static::PRE_LOAD_RATE * $info['step']);
            //非正常关闭的 直接使用下一段id
            if ($is_abnormal) {
                static::$idList[$name]['max_id'] = $info['max_id'] + $info['step'];
                static::$idList[$name]['last_id'] = $info['max_id'];
                //id下一段预载规则记录
                static::$idList[$name]['pre_load_id'] = $info['max_id'] + intval(static::PRE_LOAD_RATE * $info['step']);

                //更新数据
                db()->update(['max_id' => static::$idList[$name]['max_id'], 'last_id' => $info['max_id']], 'id_list', ['name' => $name]);

            }
            unset(static::$idList[$name]['name']);
        }
    }

    public function save()
    {
        db()->beginTrans();
        foreach (static::$change as $name => $info) {
            db()->update($info, 'id_list', ['name' => $name]);
        }
        db()->commit();
        static::$change = [];
        $this->isChange = false;
    }

    public function stop()
    {
        //正常关闭更新数据最后id
        db()->beginTrans();
        foreach (static::$idList as $name => $info) {
            db()->update(['max_id' => $info['max_id'], 'last_id' => $info['last_id']], 'id_list', ['name' => $name]);
        }
        db()->commit();
        $lockFile = \SrvBase::$instance->runDir . '/my_id.lock';
        file_exists($lockFile) && unlink($lockFile);
    }

    /**
     * 初始id信息
     * @param $data
     * @return string|null
     */
    public function initId($data)
    {
        $name = isset($data['name']) ? trim($data['name']) : '';
        if (!$name) {
            return IdLib::err('Invalid ID name');
        }
        $name = strtolower($name);
        if (isset(static::$idList[$name])) {
            return IdLib::toJson(static::$idList[$name]);
            return IdLib::err('This ID name already exists');
        }
        if (count(static::$idList) >= static::ALLOW_ID_NUM) {
            return IdLib::err('已超出可设置id数');
        }

        $step = isset($data['step']) ? (int)$data['step'] : static::DEF_STEP;
        $delta = isset($data['delta']) ? (int)$data['delta'] : 1;
        $init_id = isset($data['init_id']) ? (int)$data['init_id'] : 0;
        if ($step < static::MIN_STEP) $step = static::MIN_STEP;
        if ($delta < 1) $delta = 1;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            return IdLib::err('Invalid max_id[' . $max_id . ']!');
        }

        $data = $info = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta, 'last_id' => $init_id];
        $data['name'] = $name;
        $data['ctime'] = date('Y-m-d H:i:s');
        try {
            db()->add($data, 'id_list');
        } catch (\Exception $e) {
            return IdLib::err($e->getMessage());
        }

        static::$idList[$name] = $info;
        static::$idList[$name]['pre_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        return IdLib::toJson(static::$idList[$name]);
    }

    /**
     * 更新id信息
     * @param $data
     * @return string|null
     */
    public function updateId($data)
    {
        if (empty($data['name'])) {
            return IdLib::err('Invalid ID name');
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            return IdLib::err('ID name does not exist');
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
            return IdLib::err('Invalid init_id[' . $init_id . ']!');
        }

        if ($init_id > 0) {
            $max_id = $init_id + ($step > 0 ? $step : static::$idList[$name]['step']);
            if ($max_id > PHP_INT_MAX) {
                return IdLib::err('Invalid max_id[' . $max_id . ']!');
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

        try {
            db()->update($update, 'id_list', ['name' => $name]);
        } catch (\Exception $e) {
            return IdLib::err($e->getMessage());
        }

        $init_id > 0 && $update['pre_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        static::$idList[$name] = array_merge(static::$idList[$name], $update);
        return IdLib::toJson(static::$idList[$name]);
    }
}