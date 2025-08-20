<?php
return [
	'db_host' => '127.0.0.1',
	'db_port' => 3306,
	'db_name' => 'shenma',
	'db_user' => 'root',
	'db_pass' => 'xLa2DVQxSxdIJobx',
	'db_charset' => 'utf8mb4',
	// Redis 连接配置（优先使用 ext-redis，若无则使用 predis/predis）
	'redis' => [
		'host' => '127.0.0.1',
		'port' => 6379,
		'password' => '', // 无密码留空
		'db' => 0,
		'timeout' => 2.0,
	],
]; 