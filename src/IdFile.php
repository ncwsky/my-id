<?php
namespace MyId;

class IdFile extends IdAbstract implements IdGenerate
{
    const ALLOW_ID_NUM = 2048; //允许的id数量

    protected static function jsonFileName()
    {
        return \SrvBase::$instance->runDir . '/.my_id.json';
    }

    public function init()
    {
        $lockFile = \SrvBase::$instance->runDir . '/my_id.lock';
        $is_abnormal = file_exists($lockFile);
        touch($lockFile);

        if (is_file(static::jsonFileName())) {
            static::$idList = (array)\json_decode(file_get_contents(static::jsonFileName()), true);
            //更新最大max_id
            foreach (static::$idList as $name => $info) {
                $pre_step = intval(static::PRE_LOAD_RATE * $info['step']);
                static::$idList[$name]['pre_load_id'] = ($info['max_id'] - $info['step']) + $pre_step;
                //非正常关闭的 直接使用下一段id
                if ($is_abnormal) {
                    static::$idList[$name]['max_id'] = $info['max_id'] + $info['step'];
                    static::$idList[$name]['last_id'] = $info['max_id'];
                    //id下一段预载规则记录
                    static::$idList[$name]['pre_load_id'] = $info['max_id'] + $pre_step;
                }
            }
            $this->isChange = true;
        }
    }

    public function save()
    {
        if (!$this->isChange) return;
        $this->isChange = false;
        file_put_contents(static::jsonFileName(), \json_encode(static::$idList), LOCK_EX | LOCK_NB);
        static::$change = [];
    }

    public function stop()
    {
        file_put_contents(static::jsonFileName(), \json_encode(static::$idList), LOCK_EX);
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
        if ($step < static::MIN_STEP) $step = static::DEF_STEP;
        if ($delta < 1) $delta = 1;

        $max_id = $init_id + $step;
        if ($max_id > PHP_INT_MAX) {
            return IdLib::err('Invalid max_id[' . $max_id . ']!');
        }

        static::$idList[$name] = ['init_id' => $init_id, 'max_id' => $max_id, 'step' => $step, 'delta' => $delta, 'last_id' => $init_id];
        static::$idList[$name]['pre_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        $this->isChange = true;
        $this->save();
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
        if ($step > 0) {
            static::$idList[$name]['step'] = $step;
        }
        if ($max_id > 0) static::$idList[$name]['max_id'] = $max_id;
        if ($delta > 0) static::$idList[$name]['delta'] = $delta;
        if ($init_id > 0) {
            static::$idList[$name]['init_id'] = $init_id;
            static::$idList[$name]['last_id'] = $init_id;
            static::$idList[$name]['pre_load_id'] = $init_id + intval(static::PRE_LOAD_RATE * $step);
        }

        $this->isChange = true;
        $this->save();
        return IdLib::toJson(static::$idList[$name]);
    }
}