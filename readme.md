基于workerman|swoole的单进程ID生成服务  
>全局唯一数字id 最大到9223372036854775807    
>id自增 重启可能存在id浪费  
>支持批量获取id   
>支持生成奇偶的id  
>支持http+tcp接入
>两种id数据存储方式json文件或mysql     

##安装   
    mkdir myid
    cd myid
    
    composer require myphps/my-id
    
    cp vendor/myphps/my-id/run.example.php run.php
    cp vendor/myphps/my-id/conf.example.php conf.php
    chmod +x run.php
    
    修改 run.php conf.php配置
    运行 ./run.php 

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

单进程记录所有信息->多进程读取数据（id获取不会连续，单进程id能保持连续）
一对多方式 在多进程里记录大量id缓存并定时去更新最新id，中心进程（一）挂掉的时有足够时间来重启服务（这个过程有大量id被浪费掉）
