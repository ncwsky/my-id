<?php
namespace MyId;

class IdDb implements IdGenerate
{
    public function all(): array
    {
        return db()->table('id_list')->idx('name')->fields('name,init_id,max_id,step,delta,last_id')->all();
    }

    public function has($name): bool
    {
        $one = db()->table('id_list')->fields('name')->where(['name' => $name])->one();
        return $one ? true : false;
    }

    public function save()
    {
        db()->beginTrans();
        if (IdLib::$add) {
            db()->add(array_values(IdLib::$add), 'id_list'); //批量处理
        }
        foreach (IdLib::$change as $name => $info) {
            db()->update($info, 'id_list', ['name' => $name]);
        }
        db()->commit();
        IdLib::$add = [];
        IdLib::$change = [];
    }
}