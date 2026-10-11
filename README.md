# 阅心

阅心是一个以阅读计划执行为核心的个人阅读管理系统。适配宝塔 + PHP 8.4 + MariaDB 11。

## 功能

- 注册、登录、退出
- 我的书库：书名、作者、作者国籍、出版社、ISBN、阅读形式、平台、开始阅读日期、读完日期、评分等
- 阅读计划：周度、月度、季度、年度
- 计划任务：计划开始读、计划读完、实际开始读、实际读完、进度、状态、是否延续
- 豆瓣 URL 自动识别：在计划详情页粘贴豆瓣图书 URL，识别极简信息，确认后加入计划
- 阅读明细：多条件筛选，展示每次计划执行记录
- 统计中心：完成数、目标数、延期数、延续数，并可点击查看明细
- 标准格式导入导出：CSV / XLSX 导入，CSV 导出；导入可形成历史阅读记录

## 一键安装（推荐）

适用 Debian 12/13、Ubuntu 22.04/24.04 的全新 VPS（root 执行）。脚本会自动完成
安装 Nginx + PHP + MariaDB、拉取源码、建库导表、生成配置、配置 Nginx，可选申请 HTTPS 证书：

```bash
curl -fsSL https://raw.githubusercontent.com/<owner>/<repo>/main/install.sh | bash
```

安装过程中会询问站点域名（留空则用 IP 访问）。安装结束后会输出访问地址和数据库信息，
打开网站注册第一个账号即可使用。

> 自定义安装目录、数据库名等可用环境变量，例如 `INSTALL_DIR=/data/readlist DB_NAME=readlist bash install.sh`。
> 已自行装好 Nginx/PHP/MariaDB 的环境可用 `SKIP_PACKAGES=1 bash install.sh`。

## 宝塔部署

也可以手动部署在宝塔面板：

1. 上传整个 `yuexin` 目录到：

```text
/www/wwwroot/yuexin
```

2. 宝塔新建站点，运行目录设置为：

```text
/www/wwwroot/yuexin/public
```

3. 创建 MariaDB 数据库，例如：

```text
数据库名：yuexin
用户名：yuexin
密码：强密码
字符集：utf8mb4
```

4. 导入：

```text
sql/install.sql
```

5. 复制配置模板并修改：

```text
cp app/config.sample.php app/config.php
```

把 `app/config.php` 里的数据库用户名、密码改成你的。
`config.php` 含数据库口令，已被 `.gitignore` 排除，请勿提交。

6. 访问网站，注册第一个用户。

## 标准导入格式

进入“导入导出”页面下载标准模板 CSV。支持字段包括：

```text
书名, 作者, 作者国籍, 出版社, 出版日期, ISBN, 书籍类型, 标签, 阅读形式, 阅读平台, 阅读状态, 阅读进度, 开始阅读日期, 读完日期, 我的评分, 我的等级, 短评, 笔记, 豆瓣ID, 计划名称, 计划类型, 计划开始日期, 计划结束日期, 计划开始读, 计划读完, 实际开始读, 实际读完, 计划内状态, 是否延续, 本期备注
```

阅读形式可填：纸质书、电子书、有声书。

阅读状态可填：未读、在读、已读、未完成、弃读。

计划类型可填：周度、月度、季度、年度。

计划内状态可填：未读、在读、完成、未完成、弃读。

## 注意

豆瓣页面结构可能变化，如果识别失败，只需要修改 `app/services/DoubanService.php`。

## PHP 扩展要求

宝塔 PHP 8.4 建议开启：

```text
pdo_mysql
curl
zip
simplexml
mbstring
fileinfo
```

其中 curl 用于豆瓣 URL 识别，zip/simplexml 用于 XLSX 标准表导入。
