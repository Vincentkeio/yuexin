<?php
/**
 * 阅心 · 配置文件模板
 *
 * 使用方法：
 *   1. 复制本文件为 config.php（cp app/config.sample.php app/config.php）
 *   2. 把数据库用户名、密码改成你的
 *   3. 按你的部署路径修改 upload_dir / cache_dir / log_dir
 *
 * config.php 含数据库口令，已被 .gitignore 排除，不会进入版本库。
 */
return array (
  'app_name' => '阅心',
  'app_url' => 'https://example.com',   // 改成你的站点域名
  'base_url' => '',
  'timezone' => 'Asia/Shanghai',
  'db' =>
  array (
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'readlist',           // 改成你的数据库名
    'username' => 'readlist',           // 改成你的数据库用户名
    'password' => '在这里填写数据库密码',
    'charset' => 'utf8mb4',
  ),
  'upload_dir' => '/www/wwwroot/yuexin/storage/uploads',   // 改成你的部署路径
  'cache_dir' => '/www/wwwroot/yuexin/storage/cache',
  'log_dir' => '/www/wwwroot/yuexin/storage/logs',
  'douban' =>
  array (
    'timeout' => 8,
    'user_agent' => 'Mozilla/5.0 (compatible; YuexinReader/1.0)',
    'cookie' => '',
    'stop_on_block' => true,
  ),
);
