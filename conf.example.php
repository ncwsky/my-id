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
    'auth_key' => '', // tcp认证key  连接后发送认证内容:auth_key 成功:+OK,失败:-err内容
    'auto_init_id' => true, //自动按默认初始未配置的id
    'snow_data_id' => -1, //雪花生成中心id[0-9] -1随机
    'snow_start_diff' => 0, //(毫秒)雪花生成起始时间差值，建议直接使用当前时间戳作为差值, 0不作处理直接使用当前时间作为起始
    'master_address'=> '',
    'master_key'=>''
);