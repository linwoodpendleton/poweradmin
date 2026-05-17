# Poweradmin — GeoIP "Line" Routing 部署指南

本分支在标准 Poweradmin 基础上集成了 **DNSPod 风格的"线路解析"**：用户在
普通"Add record"表单里选 Line 类型（默认 / 大洲 / 国家→省→市 / 运营商 /
域名 / 连接类型），无需手写任何 Lua；后端把规则写到 `geo_routing_rules` 表
并自动生成 PowerDNS LUA dispatcher 记录。

> 配套后端：[pdns (fork)](https://github.com/linwoodpendleton/pdns) — 必须使用本扩展版才有 `%is/%dm/%ct` 占位符和 `GeoIPQueryAttribute.ISP/Domain/ConnectionType` 枚举。

---

## 1. 适用平台 & 依赖

实测平台：**Debian 12 (bookworm)，PHP 8.2，MariaDB 10.11，nginx**。

```bash
apt-get update
DEBIAN_FRONTEND=noninteractive apt-get install -y \
  nginx php8.2-fpm php8.2-mysql php8.2-mbstring php8.2-curl \
  php8.2-xml php8.2-zip php8.2-intl php8.2-gd \
  composer mariadb-server mariadb-client
update-alternatives --set php /usr/bin/php8.2  # 如系统有多版本 php
```

---

## 2. 拉代码 + composer

```bash
cd /var/www
git clone https://github.com/linwoodpendleton/poweradmin.git poweradmin
cd poweradmin
composer install --no-dev --no-interaction --no-progress
chown -R www-data:www-data /var/www/poweradmin
```

> nginx/php-fpm 默认以 `www-data` 跑；若用自定义 nginx（如 OpenResty 默认
> `user www www`），统一改 `chown` 用户名。

---

## 3. 数据库准备

### 3.1 建库 / 导入 PowerDNS + Poweradmin 标准 schema

```bash
mysql <<SQL
CREATE DATABASE IF NOT EXISTS pdns CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS pdns@localhost IDENTIFIED BY 'pdns';
GRANT ALL ON pdns.* TO pdns@localhost;
FLUSH PRIVILEGES;
SQL

# PowerDNS gmysql schema（来自 pdns 源码）
mysql pdns < /path/to/pdns-source/modules/gmysqlbackend/schema.mysql.sql

# Poweradmin schema — 由 install/ 程序化创建，见 §6
```

### 3.2 GeoIP 路由扩展表

```bash
mysql pdns < /var/www/poweradmin/sql/poweradmin-mysql-geoip-routing.sql
```

会创建：

| 表 | 行数（满数据）| 用途 |
|-----|------|------|
| `geo_continents` | 7 | 大洲下拉 |
| `geo_countries` | ~250 | 国家下拉 |
| `geo_regions` | ~3,500 | 省/州 下拉 |
| `geo_cities` | ~130,000 | 城市下拉 |
| `geo_isps` | ~75,000 | ISP type-ahead |
| `geo_domains` | ~75,000 | Domain type-ahead |
| `geo_routing_rules` | 业务表 | 用户配置的路由规则 |

### 3.3 生成 / 导入下拉数据源

需要 MaxMind 三个 CSV 包（City Locations、ISP Blocks、Domain Blocks）。
免费 GeoLite2 也可用，但 Domain/ISP 是付费版独占。

```bash
# 解压三个 CSV
cd /tmp && unzip -o /path/to/GeoIP2-City-CSV_*.zip
       && unzip -o /path/to/GeoIP2-ISP-CSV_*.zip
       && unzip -o /path/to/GeoIP2-Domain-CSV_*.zip

# 一条命令生成 INSERT SQL（生成器在源码里）
php /var/www/poweradmin/install/helpers/generate_geoip_sql.php \
    --en  /tmp/GeoIP2-City-CSV_*/GeoIP2-City-Locations-en.csv \
    --zh  /tmp/GeoIP2-City-CSV_*/GeoIP2-City-Locations-zh-CN.csv \
    --isp /tmp/GeoIP2-ISP-CSV_*/GeoIP2-ISP-Blocks-IPv4.csv \
    --domain /tmp/GeoIP2-Domain-CSV_*/GeoIP2-Domain-Blocks-IPv4.csv \
    --out /tmp/poweradmin-geoip-data.sql

# 导入（约 30s）
mysql pdns < /tmp/poweradmin-geoip-data.sql
```

> **--zh 可选**：不传则 `name_zh` 全 NULL，下拉只显示英文名。

验证：

```bash
mysql pdns -e "SELECT
  (SELECT COUNT(*) FROM geo_continents) AS continents,
  (SELECT COUNT(*) FROM geo_countries)  AS countries,
  (SELECT COUNT(*) FROM geo_regions)    AS regions,
  (SELECT COUNT(*) FROM geo_cities)     AS cities,
  (SELECT COUNT(*) FROM geo_isps)       AS isps,
  (SELECT COUNT(*) FROM geo_domains)    AS domains"
# +------------+-----------+---------+--------+-------+---------+
# | continents | countries | regions | cities | isps  | domains |
# +------------+-----------+---------+--------+-------+---------+
# |          7 |       250 |    3544 | 126460 | 76333 |   74766 |
```

---

## 4. nginx + php-fpm

`/etc/nginx/sites-available/poweradmin`（或自定义 nginx 的 vhost dir）：

```nginx
server {
    listen 80;
    server_name dns.example.com;
    root /var/www/poweradmin;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param SCRIPT_NAME $fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht { deny all; }
}
```

```bash
ln -sf /etc/nginx/sites-available/poweradmin /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
systemctl enable --now php8.2-fpm
```

> 如果用 OpenResty / 自定义 nginx 跑在 `user www`，要么把 fpm 池子
> （`/etc/php/8.2/fpm/pool.d/www.conf`）的 `listen.owner/group` 改成 `www`，
> 要么 `listen.mode = 0666`，否则报 502。

---

## 5. Poweradmin 配置

```bash
cp /var/www/poweradmin/config/settings.defaults.php /var/www/poweradmin/config/settings.php
$EDITOR /var/www/poweradmin/config/settings.php
```

关键字段：

```php
'database' => [
    'host'     => '127.0.0.1',   // 走 TCP，避免找 socket 路径
    'port'     => '',
    'user'     => 'pdns',
    'password' => 'pdns',
    'name'     => 'pdns',
    'type'     => 'mysql',
    'charset'  => 'utf8',        // utf8 (=utf8mb3) — 与 PDODatabaseConnection 的 DSN 兼容
],
'security' => [
    'session_key' => '<46 个随机字符>',
],
```

`chown www-data:www-data config/settings.php`。

---

## 6. 跑安装向导 / 创建管理员

### 方法 A：浏览器向导

访问 `http://your-host/install/`，按 7 步走完（语言 → DB 连接 → 创建表 → 写
配置 → 创建管理员 → 完成）。**步骤 4 (updateDatabase) 会自动把 GeoIP
路由 schema 一并建好**（见 `install/helpers/DatabaseHelper.php` 的
`installGeoipRoutingSchema()`）。

完成后**必须删除 install 目录**：

```bash
rm -rf /var/www/poweradmin/install
```

### 方法 B：CLI（无人值守）

```bash
cat > /tmp/install_pa.php <<'PHP'
<?php
chdir('/var/www/poweradmin');
require 'vendor/autoload.php';
require 'install/helpers/DatabaseHelper.php';
require 'install/helpers/DatabaseStructureHelper.php';
require 'install/helpers/PermissionHelper.php';

use PoweradminInstall\DatabaseHelper;
use Poweradmin\Application\Service\DatabaseService;
use Poweradmin\Infrastructure\Database\PDODatabaseConnection;

$creds = ['db_user'=>'pdns','db_pass'=>'pdns','db_host'=>'127.0.0.1',
          'db_port'=>'3306','db_name'=>'pdns','db_charset'=>'utf8mb4',
          'db_collation'=>'utf8mb4_unicode_ci','db_type'=>'mysql'];
$svc = new DatabaseService(new PDODatabaseConnection());
$db  = $svc->connect($creds);
$h   = new DatabaseHelper($db, $creds);
$h->updateDatabase();                 // 建所有表 + GeoIP routing
$h->createAdministratorUser('admin1234');  // 默认管理员 admin / admin1234
echo "Done.\n";
PHP
php /tmp/install_pa.php
rm -rf /var/www/poweradmin/install
```

---

## 7. 验证

打开 `http://your-host/login`，admin / admin1234 登录。

新增 zone → 进入 Edit zone → "Add record"，能看到 **Line** 列，6 个选项：

| Line 类型 | UI 行为 |
|----------|---------|
| 默认 / Default | 不出二级输入 — 走标准 PowerDNS A 记录 |
| 大洲 / Continent | 7 个下拉（亚洲/欧洲/北美洲...） |
| 国家 / Country → Region → City | 国家选完出省/州，省选完出城市 |
| 运营商 / ISP | 输入框 + AJAX 实时模糊匹配 ("google" → Google Cloud, Google Fiber, ...) |
| 域名 / Domain | 输入框 + AJAX 模糊匹配 ("google" → google.com, google.sg, googlebot.com, ...) |
| 连接类型 / Connection | Cable/DSL / Cellular / Corporate / Satellite |

保存后，Edit zone 列表里 Line 列显示友好标签（如 `国家: 美国 / 加州 / Acton`、
`运营商: Cloudflare`）。后台自动写 `LUA` 记录到 `records` 表，但**对用户不可见**。

---

## 8. 与 pdns 后端联动

pdns 必须**同时**：
- 启用 `gmysql` 后端读 `records` 表里的 LUA dispatcher
- 启用 `geoip` 后端响应 `geoiplookup()` Lua 调用
- 开 `edns-subnet-processing=yes` 让 geoip 用 client subnet 解析
- 加载 5 个 mmdb（详见 pdns 仓库的 `DEPLOYMENT-GEOIP-PROVINCE.md`）

`pdns.conf` 关键行：

```ini
launch=gmysql,geoip
enable-lua-records=yes
edns-subnet-processing=yes
gmysql-host=127.0.0.1
gmysql-user=pdns
gmysql-password=pdns
gmysql-dbname=pdns
geoip-database-files=/etc/geoip/GeoIP2-City.mmdb
geoip-database-isp-files=/etc/geoip/GeoIP2-ISP.mmdb
geoip-database-domain-files=/etc/geoip/GeoIP2-Domain.mmdb
geoip-database-country-files=/etc/geoip/GeoIP2-Country.mmdb
geoip-database-connection-files=/etc/geoip/GeoIP2-Connection-Type.mmdb
```

E2E 验证：

```bash
# UI 加一条：Line=ISP, value=Cloudflare, name=cloud, content=6.6.6.6
dig @your-host -p 5300 cloud.example.com A +subnet=1.1.1.1/32 +short
# → 6.6.6.6
```

---

## 9. 工作原理（一图流）

```
┌─ 用户在 UI 表单 ──────────────────────────────────────────────────────┐
│ Add record: name=cloud, type=A, Line=ISP, value=Cloudflare, → 6.6.6.6  │
└────────────────────────┬──────────────────────────────────────────────┘
                         │
                         ▼
  AddRecordController → GeoRoutingService::saveFromLine()
                         │
        ┌────────────────┼─────────────────┐
        ▼                                  ▼
  geo_routing_rules                    records 表
   line_type='isp'                     一条 LUA 记录
   line_value='Cloudflare'             content="A "(function() ... end)()""
   isp_pattern='cloudflare'                      ↑
   target='6.6.6.6'                              │
                                                  │
                  pdns 解析 cloud.example.com    │
                            │                    │
                            ▼                    │
                   gmysql 后端读 LUA record ─────┘
                            │
                            ▼
              Lua 执行 geoiplookup(ip, GeoIPQueryAttribute.ISP)
                            │
                            ▼
              geoip 后端查 GeoIP2-ISP.mmdb → "Cloudflare"
                            │
                            ▼
                    返回 6.6.6.6
```

---

## 10. 常见问题

| 现象 | 排查 |
|------|------|
| install 完登录看到大遮罩点不动 | 缓存问题；ctrl-shift-R 强刷或换浏览器无痕 |
| 登录失败 *Database error: Unable to verify ...* | users 表 schema 不一致（旧 schema）。重跑 install 用新 schema。 |
| AJAX 下拉一直空 (`[]`) | 没有跑 §3.3，`geo_*` 表是空的。 |
| ISP/Domain 搜索没结果，但表里有数据 | 没传 `--isp`/`--domain` 给生成器，数据没导入 |
| 中文显示成 `??` | settings.php 的 `charset` 是 `utf8mb4` 而不是 `utf8` — DSN 不识别。改成 `utf8`。 |
| LUA record 报 *unexpected symbol* / *cannot convert nil* | 后端没更新到包含 `GeoIPQueryAttribute` 表 + 占位符增强的 pdns fork |
| 添加记录后 dig 返回 0.0.0.0 | LUA 没匹配上任何规则，且没默认 fallback。加一条 Line=大洲 的兜底规则 |
| 性能：每次解析多查一次 MySQL | LUA 生成的脚本在 pdns LUA 解释器里调用 `geoiplookup`（内存查询，毫秒级），不会再打 MySQL；只在 zone reload 时 pdns 重新加载 records 表 |

---

## 11. 升级 / 同步上游

```bash
cd /var/www/poweradmin
git remote add upstream https://github.com/poweradmin/poweradmin.git  # 如未加
git fetch upstream
git merge upstream/master
composer install --no-dev --no-interaction --no-progress
# 数据库 schema 没变化的话不需要再跑 install
```

如果上游改动了 `EditController` / `AddRecordController` 的记录加载/保存逻辑，
本分支的 GeoIP 注入点（`EditController.php` 内的 `geoService->listLinesForZone()`
处、`AddRecordController.php` 内的 `line_type` 分发处）可能需要重新对齐。

---

## 12. 卸载

```bash
mysql pdns -e "
  DROP TABLE IF EXISTS geo_routing_rules, geo_isps, geo_domains,
                       geo_cities, geo_regions, geo_countries, geo_continents;
"
# 留下 records / domains / users 等标准 poweradmin 表
```

之后访问 Add record 表单不会再有 Line 列（EditController 用 try/catch 兜住
找不到表的情况）。
