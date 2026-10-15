# 家常菜谱 Recipe Hub · 项目速览

当前版本 **1.1.3**。自托管的中餐菜谱系统：菜谱 → 库存 → 周计划 → 采购清单，一条链闭环。
数据只落在自己的服务器上，不注册、不联网、不上传。

| | |
|---|---|
| 仓库形态 | PHP 源码，无构建步骤，拷过去就能跑 |
| 官方镜像 | `xyzbz/recipe-hub:1.1.3`（也带 `latest`） |
| 参考线上 | `https://ce.52ke.top`（宝塔 + Nginx + PHP-FPM 路线） |
| 详细文档 | 功能细节看 `README.md`，部署三步看 `DEPLOY.md` |

---

## 1. 技术线

| 层 | 选型 | 说明 |
|---|---|---|
| 后端 | **PHP 8.2+**（镜像内 8.3） | 无框架、无 Composer 依赖，手写路由 + PSR-4 风格自动加载（`src/`，命名空间 `RH\`） |
| 数据库 | **SQLite（WAL）** | 单文件 `data/recipe.sqlite`，无外部数据库服务；结构变更走幂等迁移（`PRAGMA user_version`，现 v3） |
| 前端 | **原生 ES module + hash 路由** | 无打包器、无 npm install，改完刷新即生效；路由形如 `#/recipes/12`，部署到任何路径都不用配服务端重写 |
| 接口 | 自写 JSON API | `public/index.php` 单入口分发；写请求走 CSRF 双提交（Cookie + `X-CSRF-Token`） |
| 图片 | **一律转 WebP** | gd 缩放 + 出缩略图，exif 纠正手机竖拍方向；存在 docroot 之外，经 `/uploads/…` 由 PHP 带权限流式输出 |
| 容器 | `php:8.3-apache` | 单容器，编译进 gd（freetype/jpeg/webp）与 exif，开 `rewrite/headers/expires/deflate` |

**关键取舍**：不引框架、不引 ORM、不引构建链。代价是轮子自己写（zip 读写、路由、模板转义都是手写的），
收益是「零依赖 + 一键拷贝部署」，最低配服务器也能轻松跑。

---

## 2. 功能

| 模块 | 要点 |
|---|---|
| 菜谱库 | 中餐单位（克/斤/两/勺/瓣/把）、份数缩放（模糊量「少许」不参与计算）、中文搜索、公开/私有、回收站、打印贴冰箱、家人留言、下厨记录、随机「今天吃什么」 |
| 食材与库存 | 预置食材库带别名（生抽=酱油=豉油）、冷藏/冷冻/常温/干货柜、过期提醒、按现有食材倒推能做什么菜 |
| 膳食周计划 | 拖拽排一周三餐、一键换菜、整周模板、指定时段一键转采购清单 |
| 采购清单 | 同名食材跨菜谱自动相加、按买菜动线分组、勾选已买、买回一键入库 |
| 定时推送 | 企业微信 / PushPlus / Server酱 / WxPusher / 自定义 Webhook（飞书·钉钉·Telegram），到点推「今天吃什么」 |
| 权限与安全 | 管理员 / 读写 / 只读三档、可选游客浏览、登录失败锁定、`password_hash` 加盐、会话在库里、CSRF 双提交 |
| 备份恢复 | **只有一个入口**：后台「备份」。一键产整站 zip（数据库 + 全部图片），恢复时先体检再铺图换库，换库前自动留 `before-restore-*` 快照 |
| 迁移 | 自家 JSON 导入导出；`cli/import-mealie.php` 从 Mealie 整站迁移（含图片） |
| 其它 | 深色模式、从 URL 导入未做、只做 Web（手机浏览器移动优先，可添加到主屏幕当 PWA 用） |

---

## 3. 部署环境要求（两条路线通用）

| 项目 | 要求 |
|---|---|
| PHP | **8.2 及以上**（8.3 已验证；8.1 未测） |
| 必需扩展 | `pdo_sqlite`、`sqlite3`、`mbstring`、`fileinfo`、`session`、`json` |
| 图片扩展 | `gd`（需 WebP + JPEG + PNG + FreeType）、`exif` —— 缺了图片功能残废 |
| 数据库 | 无（SQLite 自带驱动） |
| Web 服务 | Apache（需 `mod_rewrite`）或 Nginx |
| 目录权限 | `data/`、`data/uploads/`、`data/backups/` 必须**可写** |
| 定时任务 | 可选，只有「膳食计划定时推送」需要（每分钟跑一次） |
| 资源占用 | 极低；镜像约 500 MB，运行内存几十 MB 起步 |

**Web 根目录必须指向 `public/`** —— `src/` 和 `data/` 在上一级，绝不能被直接访问。

健康检查：访问 `/healthz`，正常返回 `{"ok":true,"version":"1.1.3","time":"…"}`。
版本以 `bootstrap.php` 里的 `RH_VERSION` 为准，各处版本号统一从它读取。

---

## 4. Docker 部署

### 最小 compose

```yaml
services:
  recipe-hub:
    image: xyzbz/recipe-hub:1.1.3
    container_name: recipe-hub
    restart: unless-stopped
    ports:
      - "8080:80"                       # 左宿主 / 右容器（容器内固定 80）
    volumes:
      - ./data:/var/www/html/data       # 全部家当：数据库 + 图片 + 备份
    environment:
      TZ: Asia/Shanghai
      RH_SITE_URL: https://eat.example.com
```

```bash
docker compose up -d          # 起来
docker compose pull && docker compose up -d   # 升级（数据在卷里，不受影响）
docker compose logs -f        # 看日志
```

浏览器打开 `http://服务器IP:8080`，走安装向导建管理员账号。

### 四个要点

| 项 | 值 | 为什么 |
|---|---|---|
| 端口 | 容器 `80` → 宿主 `8080` | 宿主端口随意改 |
| 数据卷 | `/var/www/html/data` | **必须挂出来**，不挂容器一删数据就没 |
| 站点地址 | `RH_SITE_URL` | 不填推送会降级成纯文本（拼不出菜谱链接） |
| 反代 | 传 `X-Forwarded-Proto` | PHP 靠它判断原始协议，决定 Cookie 是否加 Secure |

### 常用环境变量（全可选）

| 变量 | 默认 | 作用 |
|---|---|---|
| `RH_SITE_URL` | 空 | 站点对外地址，推送富文本的前提 |
| `RH_SITE_NAME` / `RH_SITE_DESC` | 家常菜谱 | 站点名 / 副标题 |
| `RH_ALLOW_REGISTER` | `0` | 开放自助注册（私有实例建议关） |
| `RH_UPLOAD_MAX_MB` | `8` | 单图上限（入口脚本自动放开 PHP 上传限制） |
| `RH_RESTORE_MAX_MB` | `512` | 恢复包上限，整站含图可能几百 MB |
| `RH_SESSION_DAYS` | `30` | 登录有效期 |
| `RH_DEBUG` | `0` | 排查时开 `1`，报错直接显示 |
| `PUID` / `PGID` | 不设 | NAS 挂载卷权限打架时填 `id -u` / `id -g` |

### 想自己构建镜像

```bash
tar xzf dist/recipe-hub-1.1.3-build-context.tar.gz -C /opt/recipe-hub
cd /opt/recipe-hub && chmod +x docker/export-image.sh
./docker/export-image.sh --all      # 构建 → 真容器自检 → 导出 tar.gz
```

`--all` 会起临时容器逐个核对 gd / exif / mbstring / pdo_sqlite 等扩展、打 `/healthz`、取首页状态码，
过了才导出——避免导出一个缺扩展的坏镜像。离线环境把产物 `docker load -i` 即可。

---

## 5. Web 环境配置（宝塔 / PHP-FPM / 裸 Nginx）

用 `dist/recipe-hub-1.1.3-deploy.zip`（或 `.tar.gz`）。包里**只有代码**，不含任何数据，
`data/` 只是三个空目录，覆盖解压不会动现有的库和图片。

### 七步

1. **装 PHP 8.2 / 8.3**，确认扩展：`pdo_sqlite`、`gd`（带 webp）、`exif`、`mbstring`
   ```bash
   php -m | grep -E "pdo_sqlite|gd|exif|mbstring"
   ```
2. **传代码**：面板上传 zip 后点解压，解到站点目录（如 `/www/wwwroot/recipe-hub`）
3. **建站点**：数据库选「不创建」（用 SQLite，不吃 MySQL）
4. **改运行目录**：站点设置 → 网站目录 → 运行目录 → **`public`**（关键一步）
5. **伪静态**：贴这段
   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$args;
   }
   ```
   `/uploads/` 不用单独配：URL 在 public 里找不到真实文件，会自动落回 `index.php` 由 PHP 流式吐出
6. **权限**：`chown -R www:www /www/wwwroot/recipe-hub`
7. **定时任务**（可选，只推送需要）：计划任务 → Shell 脚本 → 每 1 分钟
   ```bash
   cd /www/wwwroot/recipe-hub && php cli/notify.php
   ```

### 配置文件（不用环境变量）

`data/config.php`，优先级：环境变量 > 这个文件 > 默认值：

```php
<?php
return array(
    'siteName'      => '我家菜谱',
    'allowRegister' => 0,
    'uploadMaxMb'   => 8,
);
```

### 三个必踩的坑

1. **open_basedir**：宝塔「防跨站攻击」把 PHP 锁在**运行目录**（改成 public 后就是 `public/`），
   而入口要 require 上一级的 `bootstrap.php` → 报
   `open_basedir restriction … bootstrap.php is not within the allowed path(s)`。
   解法：站点设置 → 网站目录 → **关掉「防跨站攻击」**（入口已收敛在 public/，src/、data/ 本来就不可达）。
2. **首页能开但「数据库初始化失败」** → `data/` 不可写，回到第 6 步。
3. **除首页外全 404** → 伪静态没配，回到第 5 步。

### 上传大小三处限制要一起放开

程序侧 `uploadMaxMb` / PHP 侧 `upload_max_filesize` + `post_max_size` / 反代侧 `client_max_body_size`。
走 HTTPS 就在站点设置里一键 Let's Encrypt。

---

## 6. 数据与备份

**所有数据在一个目录 `data/`**：`recipe.sqlite`（库）、`uploads/`（图片，按 年/月 分目录）、`backups/`（备份）。

- 备份：**后台 → 备份 → 一键备份**，产物 `recipe-20261015-120000.zip`
  = `manifest.json` + `database.sqlite` + `uploads/**`，一个文件装走整个站
- 命令行：`php cli/backup.php`（Docker：`docker compose exec -T recipe-hub php cli/backup.php`）
- 恢复：**后台 → 备份 → 从文件恢复**，拖入 zip → 先出体检报告（只读） → 确认后先铺图（只增不减）再换库
- **备份文件一定要下载到本机或网盘**：留在同一台服务器等于没备份
- 整站包不含 `data/config.php`，手改过配置的话另外留一份

---

## 7. 常用命令

```bash
# 重置密码（只能在服务器上跑，会踢掉该账号其它设备）
php cli/reset-password.php --list
php cli/reset-password.php 用户名 新密码

# 推送（不真发，只看看会发什么）
php cli/notify.php --dry-run
```

## 8. 升级

- **Docker**：`docker compose pull && docker compose up -d`（数据在卷里）
- **Web**：先备份 → 新部署包解压覆盖 → `chown -R www:www` → 浏览器 **Ctrl+F5** 强刷
  （挂了 CDN 还要去控制台刷缓存；`Ctrl+F5` 后前端资源的 `?v=<修改时间>` 指纹会自动拿新版）
- 数据库结构变更是幂等迁移，启动时自动跑，不用手动执行
