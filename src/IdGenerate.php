<?php
namespace MyId;

interface IdGenerate
{
    const MAX_UNSIGNED_INT = 4294967295;
    const MAX_INT = 2147483647;
    const MAX_UNSIGNED_BIG_INT = 18446744073709551615;
    const MAX_BIG_INT = 9223372036854775807;

    public function init();

    /**
     * @param array $names
     * @return array
     */
    public function info($names = []);

    public function save();

    public function stop();

    /**
     * @param $data
     * @return string|null
     */
    public function nextId($data);

    /**
     * @param $data
     * @return null|string
     */
    public function initId($data);

    /**
     * @param $data
     * @return null|string
     */
    public function updateId($data);
}