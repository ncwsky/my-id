基于workerman|swoole的单进程ID生成服务  
>全局唯一数字id 最大到9223372036854775807    
>id自增 重启可能存在id浪费  
>支持批量获取id   
>支持生成奇偶的id  
>支持http+tcp接入
>两种id数据存储方式json文件或mysql   
>使用主(单进程)从(多进程)服务模式时id不保持连续性 

##安装运行   
    mkdir myid
    cd myid
    
    composer require myphps/my-id
    
    cp vendor/myphps/my-id/run.example.php run.php
    cp vendor/myphps/my-id/conf.example.php conf.php
    chmod +x run.php
    
    修改 run.php conf.php配置
    运行 ./run.php 
    
    ./run.php -h 帮助
    ./run.php -t master 主服务
    ./run.php -t worker 从服务(需要配置主服务连接信息) id从这里获取

##使用   
>delta int DEFAULT 1 每次增量值  范围（1-999）  
>step int DEFAULT 100000 步长,最小1000  
>init_id int DEFAULT 0 id初始值

>http模式 

    获取id /id?name=xxx[&size=x]&key=认证key
    初始id /init?name=xxx[&&delta=1&step=1000&init_id=0]
    
>tcp模式  

    获取id 发送 name=xx[&size=x]+"\r\n" 
    初始id 发送 a=init&name=xxx[&delta=1&step=1000&init_id=0]+"\r\n" 
    
##TODO  
数据意外被删除服务未停止的情况自动重新生成数据

##备注
initId 初始id  
nextId 获取id  
updateId 更新id

单进程服务能保持id连续性   
主从服务模式id不是连续性的   
主从服务模式 在从服务的多进程里记录大量id缓存并自动预载最新id，主服务挂掉的时有足够时间来重启服务（这个过程有大量id被浪费掉）
