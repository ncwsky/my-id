<?php
namespace MyId;

interface IdGenerate
{
    /**
     * @return array [name=>[], ...]
     */
    public function all();

    public function has($name);

    public function save();
}