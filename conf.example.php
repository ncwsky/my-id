<?php
$cfg = array(
    '__db' => [
        'dbms' => 'mysql', //数据库
        'server' => '127.0.0.1',//数据库主机
        'name' => 'myid',    //数据库名称
        'user' => 'root',    //数据库用户
        'pwd' => '123456',    //数据库密码
        'port' => 3306,     // 端口
    ],
    'log_dir' => __DIR__ . '/log/', //日志记录主目录名称
    'log_size' => 4194304,// 日志文件大小限制
    'log_level' => 2,// 日志记录等级
    'auth_key' => '', // tcp认证key  连接后发送认证内容:auth_key
    'auto_init_id' => false, //自动按默认初始未配置的id
    'master_address'=> '',
    'master_key'=>''
);