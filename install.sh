#!/usr/bin/env bash
# =============================================================================
# 阅心（Yuexin Reader）一键安装脚本
#
# 适用系统：Debian 12/13、Ubuntu 22.04/24.04（root 运行）
#
# 安装策略（自动探测，无需手动指定）：
#   · 已有宝塔面板安装的 Nginx / PHP / MySQL(MariaDB)  → 优先复用宝塔组件
#   · 没有宝塔或宝塔未装某组件                          → 自动用 apt 安装该组件
#   两种模式可混搭，互不冲突，脚本本身不依赖宝塔面板。
#
# 自动完成：准备运行环境 → 拉取源码 → 建库导表 →
#           生成配置 → 配置 Web 服务 →（可选）申请 HTTPS 证书
#
# 用法（任选其一）：
#   1) 远程一键：
#      curl -fsSL https://raw.githubusercontent.com/<owner>/<repo>/main/install.sh | bash
#   2) 先 clone 再本地执行：
#      git clone https://github.com/<owner>/<repo>.git && cd <repo> && bash install.sh
#
# 可选环境变量：
#   INSTALL_DIR    安装目录          （默认 /www/wwwroot/readlist）
#   DB_NAME        数据库名          （默认 readlist）
#   DB_USER        数据库用户名      （默认 readlist）
#   DOMAIN         站点域名          （默认空 = 用 IP 访问，交互询问）
#   SKIP_PACKAGES=1   跳过一切组件安装/配置（环境已全部就绪时使用）
# =============================================================================
set -euo pipefail

# ------------------------------ 基础配置 ------------------------------------
APP_NAME="阅心"
INSTALL_DIR="${INSTALL_DIR:-/www/wwwroot/readlist}"
DB_NAME="${DB_NAME:-readlist}"
DB_USER="${DB_USER:-readlist}"
DOMAIN="${DOMAIN:-}"
REPO_SLUG="${REPO_SLUG:-Vincentkeio/yuexin}"   # GitHub owner/repo，推送后由维护者填入
MIN_PHP_VER="8.1"

# ------------------------------ 输出函数 ------------------------------------
c_green()  { printf '\033[32m%s\033[0m\n' "$*"; }
c_yellow() { printf '\033[33m%s\033[0m\n' "$*"; }
c_red()    { printf '\033[31m%s\033[0m\n' "$*" >&2; }
step()     { printf '\n\033[36m==> [%s/8] %s\033[0m\n' "$1" "$2"; }
die()      { c_red "错误：$*"; exit 1; }

# ------------------------------ 0. 环境检查与探测 -----------------------------
step 0 "环境检查与组件探测"

[ "$(id -u)" -eq 0 ] || die "请用 root 运行（sudo bash install.sh）"

if command -v apt-get >/dev/null 2>&1; then
    PKG=apt
elif command -v dnf >/dev/null 2>&1; then
    PKG=dnf
else
    die "仅支持 Debian / Ubuntu（apt）或 RHEL 系（dnf）系统"
fi

if [ -e "$INSTALL_DIR" ] && [ -n "$(ls -A "$INSTALL_DIR" 2>/dev/null)" ]; then
    die "目录 $INSTALL_DIR 已存在且非空。请更换 INSTALL_DIR 或先备份清理。"
fi

if [ -t 0 ] && [ -z "$DOMAIN" ]; then
    read -rp "站点域名（已解析到本机；留空则用 IP 访问）: " DOMAIN || true
fi
PUB_IP="$(curl -fsS --max-time 8 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"
SERVER_NAME="${DOMAIN:-$PUB_IP}"

# ---- 探测宝塔组件 ------------------------------------------------------------
BT_PANEL=0; BT_PHP_VER=""; BT_PHP_BIN=""; BT_PHP_SOCK=""
BT_MYSQL_BIN=""; BT_MYSQL_INIT=""; BT_NGINX_BIN=""

if [ -d /www/server/panel ] && [ "${SKIP_PACKAGES:-0}" != "1" ]; then
    BT_PANEL=1

    # 宝塔 PHP：取已安装的最高版本
    for d in $(ls -d /www/server/php/[0-9]* 2>/dev/null | sort -Vr); do
        v=$(basename "$d")
        if [ -x "$d/bin/php" ]; then
            BT_PHP_VER="$v"; BT_PHP_BIN="$d/bin/php"
            for s in "$d/var/run/php-fpm.sock" "/tmp/php-cgi-${v}.sock"; do
                [ -S "$s" ] && BT_PHP_SOCK="$s"
            done
            break
        fi
    done

    # 宝塔 MySQL / MariaDB
    for p in /www/server/mysql/bin/mysql /www/server/mariadb/bin/mysql; do
        [ -x "$p" ] && BT_MYSQL_BIN="$p"
    done
    [ -n "$BT_MYSQL_BIN" ] && {
        for i in /etc/init.d/mysql /etc/init.d/mariadb; do
            [ -x "$i" ] && BT_MYSQL_INIT="$i"
        done
    }

    # 宝塔 Nginx
    [ -x /www/server/nginx/sbin/nginx ] && BT_NGINX_BIN=/www/server/nginx/sbin/nginx
fi

# ---- 判定各组件模式：bt = 复用宝塔，apt = 系统包安装 --------------------------
NGINX_MODE=apt; PHP_MODE=apt; DB_MODE=apt
[ -n "$BT_NGINX_BIN" ] && NGINX_MODE=bt
if [ -n "$BT_PHP_BIN" ]; then
    php_bt_ver="$($BT_PHP_BIN -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || echo 0.0)"
    if [ "$(printf '%s\n' "$MIN_PHP_VER" "$php_bt_ver" | sort -V | head -1)" = "$MIN_PHP_VER" ]; then
        PHP_MODE=bt
    else
        c_yellow "宝塔 PHP ${php_bt_ver} 低于 ${MIN_PHP_VER}，将改用系统源安装新版 PHP"
    fi
fi
[ -n "$BT_MYSQL_BIN" ] && DB_MODE=bt

c_green "探测结果："
[ "$BT_PANEL" = "1" ] && echo "  · 宝塔面板：已安装（/www/server/panel）"
echo "  · Nginx：$([ "$NGINX_MODE" = bt ] && echo "复用宝塔 ${BT_NGINX_BIN}" || echo 'apt 安装')"
echo "  · PHP：  $([ "$PHP_MODE"   = bt ] && echo "复用宝塔 ${BT_PHP_VER}（${BT_PHP_BIN}）" || echo 'apt 安装（优先 8.4）')"
echo "  · 数据库：$([ "$DB_MODE"   = bt ] && echo "复用宝塔 MySQL/MariaDB（${BT_MYSQL_BIN}）" || echo 'apt 安装 MariaDB')"

# ------------------------------ 1. 准备组件（安装 / 确认） ---------------------
step 1 "准备组件（Nginx / PHP / 数据库）"

export DEBIAN_FRONTEND=noninteractive
pkg_install() {
    if [ "$PKG" = "apt" ]; then
        apt-get update -qq && apt-get install -y -qq "$@"
    else
        dnf install -y -q "$@"
    fi
}

# ---- Nginx ----
if [ "$NGINX_MODE" = "apt" ] && [ "${SKIP_PACKAGES:-0}" != "1" ]; then
    pkg_install -qq nginx >/dev/null
fi
NGINX_BIN="${BT_NGINX_BIN:-$(command -v nginx || true)}"
[ -n "$NGINX_BIN" ] || die "Nginx 不可用"
if [ "$NGINX_MODE" = "apt" ]; then
    NGINX_CONF_AVAIL=/etc/nginx/sites-available
    [ -d "$NGINX_CONF_AVAIL" ] || NGINX_CONF_AVAIL=/etc/nginx/conf.d
    NGINX_CONF_ENABLED="${NGINX_CONF_AVAIL%/available}/enabled"
    [ -d "$NGINX_CONF_ENABLED" ] || NGINX_CONF_ENABLED="$NGINX_CONF_AVAIL"
else
    NGINX_CONF_AVAIL=/www/server/panel/vhost/nginx
    NGINX_CONF_ENABLED="$NGINX_CONF_AVAIL"   # 宝塔主配置已 include 整个目录
fi
c_green "Nginx 就绪：${NGINX_BIN}"

# ---- PHP ----
PHP_VER="" PHP_BIN="" PHP_SOCK=""
if [ "$PHP_MODE" = "bt" ]; then
    PHP_VER="$BT_PHP_VER"; PHP_BIN="$BT_PHP_BIN"; PHP_SOCK="$BT_PHP_SOCK"
    # 必需扩展检查（宝塔 PHP 扩展需在面板安装，脚本无法代装）
    PHP_MISSING=""
    for m in pdo_mysql curl zip simplexml mbstring fileinfo; do
        if ! "$PHP_BIN" -r "exit extension_loaded('${m}')?0:1;"; then
            PHP_MISSING="${PHP_MISSING} ${m}"
        fi
    done
    if [ -n "${PHP_MISSING// /}" ]; then
        die "宝塔 PHP ${PHP_VER} 缺少扩展：${PHP_MISSING}
请在宝塔面板 → 软件商店 → PHP ${PHP_VER} → 设置 → 安装扩展后重试。"
    fi
    [ -n "$PHP_SOCK" ] || die "未找到宝塔 PHP-FPM socket（${BT_PHP_SOCK:-/www/server/php/${BT_PHP_VER}/var/run/php-fpm.sock}），请在面板确认 PHP-FPM 已启动"
else
    # apt 安装 PHP：官方源没有 8.4 时（Debian 12 / Ubuntu）添加 sury.org 源
    resolve_php() {
        if apt-cache show "php8.4-fpm" >/dev/null 2>&1; then return 0; fi
        if [ "$PKG" = "apt" ]; then
            c_yellow "官方源无 PHP 8.4，正在添加 sury.org 仓库…"
            pkg_install -qq ca-certificates curl gnupg lsb-release >/dev/null 2>&1 || true
            install -d /usr/share/keyrings
            curl -fsSL https://packages.sury.org/php/apt.gpg \
                | gpg --dearmor -o /usr/share/keyrings/deb.sury.org-php.gpg 2>/dev/null
            echo "deb [signed-by=/usr/share/keyrings/deb.sury.org-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
                > /etc/apt/sources.list.d/sury-php.list
            apt-get update -qq
        fi
    }
    [ "${SKIP_PACKAGES:-0}" = "1" ] || resolve_php || true

    PHP_FOUND=""
    for v in 8.4 8.3 8.2 8.1; do
        if apt-cache show "php${v}-fpm" >/dev/null 2>&1; then PHP_FOUND="$v"; break; fi
    done
    [ -n "$PHP_FOUND" ] || die "找不到可用的 PHP-FPM 包，请手动安装 PHP >= ${MIN_PHP_VER} 后重试"

    [ "${SKIP_PACKAGES:-0}" = "1" ] || pkg_install -qq git \
        "php${PHP_FOUND}-fpm" "php${PHP_FOUND}-cli" "php${PHP_FOUND}-mysql" \
        "php${PHP_FOUND}-curl" "php${PHP_FOUND}-zip" "php${PHP_FOUND}-xml" \
        "php${PHP_FOUND}-mbstring" "php${PHP_FOUND}-fileinfo" >/dev/null

    PHP_VER="$PHP_FOUND"
    PHP_BIN="$(command -v php)"
    PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
    [ -S "$PHP_SOCK" ] || PHP_SOCK="/var/run/php/php${PHP_VER}-fpm.sock"
    [ -S "$PHP_SOCK" ] || PHP_SOCK=""   # 部分环境用 TCP 9000，nginx 配置处回退处理
fi
[ -n "$PHP_BIN" ] || die "PHP 不可用"
php_ver_now="$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if [ "$(printf '%s\n' "$MIN_PHP_VER" "$php_ver_now" | sort -V | head -1)" != "$MIN_PHP_VER" ]; then
    die "PHP ${php_ver_now} 低于 ${MIN_PHP_VER}"
fi
# 统一版本号：XY=8.4 / XY_STR=84（宝塔 init 与 enable 文件名用连写版本）
PHP_XY="$php_ver_now"
PHP_XY_STR="${PHP_XY//./}"
c_green "PHP 就绪：${PHP_BIN}（${PHP_XY}）"

# ---- MySQL / MariaDB ----
MYSQL_BIN="$BT_MYSQL_BIN"
if [ "$DB_MODE" = "apt" ]; then
    [ "${SKIP_PACKAGES:-0}" = "1" ] || pkg_install -qq mariadb-server >/dev/null
    MYSQL_BIN="$(command -v mysql || true)"
fi
[ -n "$MYSQL_BIN" ] || die "mysql 客户端不可用"

# 服务启动函数（宝塔 init / systemd 兼容；不依赖宝塔本身）
svc_start() {
    local name="$1" init="${2:-}"
    if [ -n "$init" ] && [ -x "$init" ]; then
        "$init" start >/dev/null 2>&1 || true
    fi
    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now "$name" >/dev/null 2>&1 || true
    fi
    return 0
}

# 宝塔 MySQL root 通常有密码（宝塔安装时随机生成，可在面板查看）
MYSQL_ROOT_PWD="${MYSQL_ROOT_PWD:-}"
mysql_exec() {  # SQL 从 stdin 读，参数透传给 mysql
    if [ -n "$MYSQL_ROOT_PWD" ]; then
        "$MYSQL_BIN" -uroot -p"$MYSQL_ROOT_PWD" "$@"
    elif [ -f /root/.my.cnf ] && grep -q '\[client\]' /root/.my.cnf 2>/dev/null; then
        "$MYSQL_BIN" "$@"
    else
        "$MYSQL_BIN" -uroot "$@"
    fi
}

if [ "${SKIP_PACKAGES:-0}" != "1" ]; then
    svc_start nginx ""
    if [ "$PHP_MODE" = "bt" ]; then
        svc_start "php-fpm-${PHP_XY_STR}" "/etc/init.d/php-fpm-${PHP_XY_STR}"
        pgrep -f "php-fpm.*${PHP_XY_STR}" >/dev/null 2>&1 \
            || svc_start "php-fpm-${PHP_XY}" "/etc/init.d/php-fpm-${PHP_XY}"
    else
        svc_start "php${PHP_VER}-fpm" ""
    fi
    if [ "$DB_MODE" = "bt" ]; then
        svc_start mysql "$BT_MYSQL_INIT"
    else
        svc_start mariadb ""
    fi
fi

# 数据库连接测试（宝塔密码失败时引导输入）
DB_OK=0
for _try in 1 2 3; do
    if echo "SELECT 1" | mysql_exec >/dev/null 2>&1; then DB_OK=1; break; fi
    [ -t 0 ] || break
    c_yellow "无法以当前凭据连接数据库。"
    [ -n "$BT_MYSQL_BIN" ] && c_yellow "root 密码可在 宝塔面板 → 数据库 查看，或查看 /www/server/panel/default.pl"
    read -rsp "请输入数据库 root 密码（直接回车重试免密）: " input_pwd; echo
    MYSQL_ROOT_PWD="$input_pwd"
done
[ "$DB_OK" = "1" ] || die "数据库连接失败，请检查 root 密码后重试"
c_green "数据库连接正常"

# ------------------------------ 2. 获取源码 ----------------------------------
step 2 "获取源码"

SRC_OK=0
if command -v git >/dev/null 2>&1; then
    if git clone --depth 1 "https://github.com/${REPO_SLUG}.git" "$INSTALL_DIR" 2>/dev/null; then
        SRC_OK=1
    fi
fi
if [ "$SRC_OK" -eq 0 ]; then
    c_yellow "git clone 失败，改为下载源码包…"
    TARBALL_URL="https://github.com/${REPO_SLUG}/archive/refs/heads/main.tar.gz"
    mkdir -p "$INSTALL_DIR"
    curl -fsSL "$TARBALL_URL" | tar xz -C "$INSTALL_DIR" --strip-components=1 \
        || die "下载源码失败：$TARBALL_URL"
fi
cd "$INSTALL_DIR"

# ------------------------------ 3. 创建数据库 --------------------------------
step 3 "创建数据库"

DB_PASS="$(openssl rand -hex 16)"

if echo "SHOW DATABASES LIKE '${DB_NAME}'" | mysql_exec -N | grep -q "${DB_NAME}"; then
    die "数据库 ${DB_NAME} 已存在。请设置 DB_NAME=其他名字 后重试。"
fi

mysql_exec <<SQL
CREATE DATABASE \`${DB_NAME}\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
CREATE USER IF NOT EXISTS '${DB_USER}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
c_green "数据库 ${DB_NAME} 已创建"

# 导入表结构：优先无库名依赖的 install.readlist.sql，回退替换库名的 install.sql
if [ -f "sql/install.readlist.sql" ]; then
    echo "SOURCE $(pwd)/sql/install.readlist.sql;" | mysql_exec "$DB_NAME"
elif [ -f "sql/install.sql" ]; then
    sed "s/\`yuexin\`/\`${DB_NAME}\`/g; s/^USE .*;/USE \`${DB_NAME}\`;/" sql/install.sql \
        | mysql_exec "$DB_NAME"
else
    die "找不到 SQL 文件（sql/install.sql）"
fi
TABLE_N="$("$MYSQL_BIN" -uroot ${MYSQL_ROOT_PWD:+-p$MYSQL_ROOT_PWD} -N -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}';" 2>/dev/null || echo '?')"
c_green "表结构已导入（${TABLE_N} 张表）"

# ------------------------------ 4. 生成配置文件 ------------------------------
step 4 "生成配置文件"

[ -f app/config.sample.php ] || die "缺少 app/config.sample.php"
cp app/config.sample.php app/config.php

sed -i \
    -e "s/'database' => '[^']*'/'database' => '${DB_NAME}'/" \
    -e "s/'username' => '[^']*'/'username' => '${DB_USER}'/" \
    -e "s/'password' => '[^']*'/'password' => '${DB_PASS}'/" \
    -e "s#/www/wwwroot/yuexin/storage#${INSTALL_DIR}/storage#g" \
    app/config.php

# 校验配置可解析且能连上数据库
"$PHP_BIN" -r '
$c = require "app/config.php";
if (empty($c["db"]["password"])) { fwrite(STDERR, "config.php 缺少数据库密码\n"); exit(1); }
$dsn = sprintf("mysql:host=%s;port=%d;dbname=%s;charset=%s",
    $c["db"]["host"], $c["db"]["port"], $c["db"]["database"], $c["db"]["charset"]);
new PDO($dsn, $c["db"]["username"], $c["db"]["password"]);
echo "";
' || die "配置文件生成失败或数据库连接失败"
c_green "config.php 已生成，数据库连接测试通过"

chmod 600 app/config.php
# Web 用户：Debian 系（apt 与宝塔）均为 www-data；老宝塔可能是 www
chown -R www-data:www-data "$INSTALL_DIR" 2>/dev/null \
    || chown -R www:www "$INSTALL_DIR" 2>/dev/null || true

# ------------------------------ 5. 配置 Nginx --------------------------------
step 5 "配置 Nginx"

VHOST_FILE="${NGINX_CONF_AVAIL}/readlist.conf"
cat > "$VHOST_FILE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${SERVER_NAME};

    root ${INSTALL_DIR}/public;
    index index.php index.html;

    client_max_body_size 32m;

    # 拒绝访问隐藏文件、版本控制、配置与安装脚本
    location ~ ^/(\.user\.ini|\.htaccess|\.git|\.env|\.svn|\.project|\.gitignore|LICENSE|README\.md|sql|install\.sh|app|\.gitattributes) {
        return 404;
    }

    # 证书申请验证目录
    location ^~ /.well-known/ {
        allow all;
    }

    # 存储目录（日志/缓存）不对外
    location ^~ /storage/logs/  { return 404; }
    location ^~ /storage/cache/ { return 404; }

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }
NGINX

if [ "$PHP_MODE" = "bt" ]; then
    # 宝塔：include 面板托管的 PHP-FPM 配置
    cat >> "$VHOST_FILE" <<NGINX

    include /www/server/nginx/conf/enable-php-${PHP_XY_STR}.conf;
}
NGINX
    [ -f "/www/server/nginx/conf/enable-php-${PHP_XY_STR}.conf" ] \
        || die "缺少宝塔 PHP 配置：/www/server/nginx/conf/enable-php-${PHP_XY_STR}.conf（请先在面板安装该版本 PHP）"
else
    cat >> "$VHOST_FILE" <<NGINX

    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
NGINX
    if [ -n "$PHP_SOCK" ]; then
        printf '        fastcgi_pass unix:%s;\n    }\n}\n' "$PHP_SOCK" >> "$VHOST_FILE"
    else
        printf '        fastcgi_pass 127.0.0.1:9000;\n    }\n}\n' >> "$VHOST_FILE"
    fi
fi

if [ "$NGINX_MODE" = "bt" ]; then
    "$NGINX_BIN" -t || die "Nginx 配置校验失败，请检查上方输出"
    svc_start nginx ""; "$NGINX_BIN" -s reload
else
    ln -sf "$VHOST_FILE" "${NGINX_CONF_ENABLED}/readlist.conf" 2>/dev/null || true
    rm -f "${NGINX_CONF_ENABLED}/default" 2>/dev/null || true
    nginx -t || die "Nginx 配置校验失败，请检查上方输出"
    svc_start nginx ""; nginx -s reload 2>/dev/null || true
fi
c_green "Nginx 已配置（$VHOST_FILE）"

# ------------------------------ 6. HTTPS 证书（可选）--------------------------
step 6 "HTTPS 证书（可选）"

SITE_URL="http://${SERVER_NAME}"
if [ -n "$DOMAIN" ] && [ -t 0 ]; then
    read -rp "是否为 ${DOMAIN} 申请 Let's Encrypt HTTPS 证书？（需域名已解析本机）[y/N] " APPLY_SSL || true
    if [[ "${APPLY_SSL:-}" =~ ^[Yy]$ ]]; then
        read -rp "用于证书到期提醒的邮箱: " CERT_EMAIL
        pkg_install -qq certbot python3-certbot-nginx >/dev/null 2>&1 || true
        if command -v certbot >/dev/null 2>&1 && \
           certbot --nginx -d "$DOMAIN" -m "$CERT_EMAIL" --agree-tos -n; then
            SITE_URL="https://${DOMAIN}"
            c_green "证书申请成功：${SITE_URL}"
        else
            c_yellow "证书申请失败（DNS 未生效或 80 端口不通），站点仍可 http 访问"
        fi
    fi
else
    c_yellow "未设置域名，跳过 HTTPS。后续可手动执行：certbot --nginx -d 你的域名"
fi

# ------------------------------ 7. 安装完成 ----------------------------------
step 7 "安装完成"

cat <<DONE

$(c_green "${APP_NAME} 安装完成！")

  访问地址    ${SITE_URL}/
  安装目录    ${INSTALL_DIR}
  站点根目录  ${INSTALL_DIR}/public

  运行环境    Nginx: $([ "$NGINX_MODE" = bt ] && echo '宝塔' || echo 'apt')
              PHP:   ${PHP_XY}（$([ "$PHP_MODE" = bt ] && echo '宝塔' || echo 'apt')）
              DB:    $([ "$DB_MODE" = bt ] && echo '宝塔 MySQL/MariaDB' || echo 'apt MariaDB'）

  数据库      库名: ${DB_NAME}
              用户: ${DB_USER}@localhost / @127.0.0.1
              密码: ${DB_PASS}
              （已写入 ${INSTALL_DIR}/app/config.php，权限 600）

  下一步      打开网站 → 注册第一个账号（第一个注册用户即为站主）

  可选增强
    · 豆瓣异步队列 worker（每分钟执行，按需开启）：
        * * * * * ${PHP_BIN} ${INSTALL_DIR}/app/cli/douban_worker.php >> ${INSTALL_DIR}/storage/logs/worker_cron.log 2>&1

DONE

step 8 "安全提醒"
c_yellow "· app/config.php 含数据库口令，权限已设为 600，请勿提交到任何仓库"
c_yellow "· 防火墙放行 Web 端口：ufw allow OpenSSH && ufw allow 'Nginx Full' && ufw enable"
c_yellow "· 定期备份：mysqldump ${DB_NAME} | gzip > ~/readlist-\$(date +%F).sql.gz"
