CREATE DATABASE IF NOT EXISTS `myid` DEFAULT CHARSET utf8; 

use `myid`;

-- id列表
-- DROP TABLE IF EXISTS `id_list`;
CREATE TABLE `id_list` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(32) NOT NULL,
  `init_id` bigint DEFAULT '0',
  `max_id` bigint DEFAULT '0',
  `step` int DEFAULT '0' COMMENT '步长',
  `delta` int DEFAULT '1' COMMENT '每次增量值',
  `last_id` bigint(20) DEFAULT '0',
  `ctime` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;