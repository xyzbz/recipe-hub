# 解压之后看这个（1 分钟版）

家常菜谱 Recipe Hub v1.1.3 · 无构建步骤，PHP 8.1+ 直接跑。
完整文档见同目录 `README.md`（环境变量、备份恢复、推送配置、命令行工具都在那里）。

## 目录里都是什么

```
bootstrap.php  router.php   应用引导 / 本地开发路由（只有 php -S 需要它）
public/        ← 网站根目录（站点「运行目录」要指到这里）
src/           后端代码，docroot 之外，浏览器够不到
seed/          食材库 300 条、标签、10 道示例菜谱
cli/           命令行：备份 / 改密码 / 定时推送
data/          运行时数据：数据库、上传图片、备份（解压后才有，唯一需要可写的目录）
docker/        Docker 路线才用得到，宝塔部署可以无视
```

## 路线一：宝塔面板（推荐，全程点鼠标）

1. **装 PHP 8.2 或 8.3**，确认扩展里勾上 `pdo_sqlite`、`gd`（要带 webp）、`exif`、`mbstring`
2. **传代码**：把本包解压到站点目录（面板上传 zip 后点「解压」即可）
3. **建站点**：数据库选「不创建」（用 SQLite，不吃 MySQL）
4. **改运行目录**：站点设置 → 网站目录 → 运行目录 → **`public`**（关键一步）
5. **伪静态**：贴这段

   ```nginx
   location / {
       try_files $uri $uri/ /index.php$is_args$args;
   }
   ```

6. **给权限**：`chown -R www:www /www/wwwroot/你的站点目录`
7. 浏览器打开站点 → 走安装向导 → 建管理员账号 → 完事

**最容易撞的三个坑**（按概率排序）：

- 首页报 `open_basedir restriction … bootstrap.php is not within the allowed path(s)`
  → 宝塔的「防跨站攻击」把 PHP 锁在了 `public/` 里，而入口要 require 上一级文件。
  站点设置 → 网站目录 → **关掉「防跨站攻击」**（入口已收敛在 public/，src/、data/ 本来就不可达）。
- 首页能开但「数据库初始化失败」→ `data/` 不可写，回到第 6 步。
- 除首页外全 404 → 伪静态没配（第 5 步）。

## 路线二：纯 PHP（没面板也行）

```bash
cd /opt/recipe-hub
php -S 0.0.0.0:8080 -t public router.php      # 临时跑 / 内网用
```

生产环境配 PHP-FPM + Nginx：docroot 指向 `public/`，伪静态同上，PHP-FPM 用户
要对项目根的 `data/` 有写权限。

## 路线三：Docker

```bash
docker compose up -d --build     # 8080 端口
```

## 别忘了这两件事

**备份**（计划任务 → Shell 脚本 → 每天）：

```bash
cd /www/wwwroot/你的站点目录 && php cli/backup.php --keep=14
```

打出来的是一份含数据库 + 全部图片的 zip，落在 `data/backups/`。
换服务器时把它拿到后台「备份 → 从文件恢复」传上去点一下就行。

**定时推送**（计划任务 → 每 1 分钟；不配也能用，只是膳食计划不会自动推）：

```bash
cd /www/wwwroot/你的站点目录 && php cli/notify.php
```

（Docker 部署的话：`* * * * * cd /opt/recipe-hub && docker compose exec -T recipe-hub php cli/notify.php`）

## 升级

**只覆盖代码，别动 `data/`**——数据库和上传的图片全在里面。
保险起见先跑一次 `php cli/backup.php`，再解压新版本覆盖，最后浏览器硬刷新（Ctrl+F5）。

## 忘了密码

```bash
php cli/reset-password.php --list                    # 列出账号
php cli/reset-password.php 用户名 新密码              # 重置（会踢掉该账号其它设备）
```

## 两个可选的配置文件

- `data/config.php`（返回 PHP 数组）改站名、是否开放注册、上传上限，优先级高于默认值
- 环境变量（Docker 用）：`RH_SITE_NAME`、`RH_ALLOW_REGISTER`、`RH_UPLOAD_MAX_MB` 等，见 README
