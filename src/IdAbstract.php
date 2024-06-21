<?php
namespace MyId;

abstract class IdAbstract
{
    const ALLOW_ID_NUM = 8192; //允许的id数量
    const DEF_STEP = 100000; //默认步长
    const MIN_STEP = 1000; //最小步长
    const PRE_LOAD_RATE = 0.2; //下一段id预载比率

    /**
     * id列表记录  ['name'=>[init_id,max_id,step,delta], ...]
     * @var array
     */
    protected static $idList = [];

    protected static $change = [];

    protected $isChange = false;

    /**
     * 返回自增的id
     * @param $name
     * @return string
     */
    protected function incrId($name)
    {
        static::$idList[$name]['last_id'] = static::$idList[$name]['last_id'] + static::$idList[$name]['delta'];
        if (static::$idList[$name]['last_id'] > static::$idList[$name]['pre_load_id']) { //达到预载条件
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
        static::$idList[$name]['pre_load_id'] = static::$idList[$name]['max_id'] + intval(static::PRE_LOAD_RATE * static::$idList[$name]['step']);
        static::$idList[$name]['max_id'] = static::$idList[$name]['max_id'] + static::$idList[$name]['step'];
        $this->isChange = true;
        static::$change[$name] = ['max_id' => static::$idList[$name]['max_id'], 'last_id' => static::$idList[$name]['last_id']];
    }

    /**
     * 统计信息
     * @param array $names
     * @return array
     */
    public function info($names = [])
    {
        if ($names) {
            $ret = [];
            foreach ($names as $name) {
                $ret[$name] = static::$idList[$name] ?? null;
            }
        }
        return static::$idList;
    }

    /**
     * 取下一段自增id
     * @param $data
     * @return string|null
     */
    public function nextId($data)
    {
        if (empty($data['name'])) {
            return IdLib::err('Invalid ID name');
        }
        $name = strtolower($data['name']);
        if (!isset(static::$idList[$name])) {
            return IdLib::err('ID name does not exist');
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

}