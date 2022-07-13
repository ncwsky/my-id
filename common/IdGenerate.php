<?php
namespace MyId;

abstract class IdGenerate
{
    use IdMsg;

    const MAX_UNSIGNED_INT = 4294967295;
    const MAX_INT = 2147483647;
    const MAX_UNSIGNED_BIG_INT = 18446744073709551615;
    const MAX_BIG_INT = 9223372036854775807;

    const ALLOW_ID_NUM = 256; //允许的id数量
    const DEF_STEP = 100000; //默认步长
    const MIN_STEP = 1000; //最小步长
    const PRE_LOAD_RATE = 0.2; //下一段id预载比率

    abstract public function init();
    abstract public function info();
    abstract public function save();
    abstract public function stop();
    abstract public function nextId($data);
    abstract public function initId($data);
    abstract public function updateId($data);
}