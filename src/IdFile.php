<?php
namespace MyId;

class IdFile implements IdGenerate
{
    protected static function jsonFileName()
    {
        return \SrvBase::$instance->runDir . '/.'.ID_NAME.'.json';
    }

    public function all(): array
    {
        $all = \json_decode(file_get_contents(static::jsonFileName()), true);
        return $all && is_array($all) ? $all : [];
    }

    public function has($name): bool
    {
        return isset(IdLib::$idList[$name]);
    }

    public function save()
    {
        if (!IdLib::$change && !IdLib::$add) return;
        file_put_contents(static::jsonFileName(), \json_encode(IdLib::$idList), LOCK_EX);
        IdLib::$add = [];
        IdLib::$change = [];
    }
}