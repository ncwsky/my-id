<?php
namespace MyId;

interface IdGenerate
{
    const MAX_UNSIGNED_INT = 4294967295;
    const MAX_INT = 2147483647;
    const MAX_UNSIGNED_BIG_INT = 18446744073709551615;
    const MAX_BIG_INT = 9223372036854775807;

    public function init();
    public function info();
    public function save();
    public function stop();
    public function nextId($data);
    public function initId($data);
    public function updateId($data);
}