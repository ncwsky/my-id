基于workerman的单进程ID生成服务  
>全局唯一数字id 最大到9223372036854775807    
>id自增 重启可能存在id浪费  
>支持批量获取id   
>支持生成奇偶的id  
>支付http+tcp接入
>两种id数据存储方式json文件或mysql     

###安装   
    mkdir myid
    cd myid
    
    composer require myphps/my-id
    
    cp vendor/myphps/my-id/run.example.php run.php
    cp vendor/myphps/my-id/conf.example.php conf.php
    chmod +x run.php
    
    修改 run.php conf.php配置
    运行 ./run.php 

###使用   
>http模式 

    获取id /id?name=xxx[&size=x]
    初始id /init?name=xxx[&delta=1&step=1000&init_id=0]
>tcp模式  

    获取id 发送 name=xx[&size=x]+"\r\n" 
    初始id 发送 a=init&name=xxx[&delta=1&step=1000&init_id=0]+"\r\n" 