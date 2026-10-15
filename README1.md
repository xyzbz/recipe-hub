# 🍳 家常菜谱 · Recipe Hub

<div align="center">

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?logo=php&logoColor=white)
![SQLite](https://img.shields.io/badge/SQLite-WAL-003B57?logo=sqlite&logoColor=white)
![Docker Hub](https://img.shields.io/badge/dockerhub-xyzbz%2Frecipe--hub-2496ED?logo=docker&logoColor=white)
![License](https://img.shields.io/badge/license-MIT-green)
![Docs](https://img.shields.io/badge/docs-%E7%AE%80%E4%BD%93%E4%B8%AD%E6%96%87-blue)

**自托管的中餐菜谱管理系统** —— 只做中餐，只为你自己家跑。

</div>

---

> **菜谱 → 库存 → 周计划 → 采购清单**，一家人做饭的完整链条，一个应用里闭环。
> 排好一周的饭，点一下就把整周要买的东西合并成一张清单，同一样食材的用量自动累加。
>
> 单容器，一条命令起来。数据在 SQLite 单文件里，全在你自己的服务器上，
> 不注册、不联网、不上传——**你的菜谱就是你自己的**。

**为什么「只做中餐」不是一句口号**：单位是克 / 斤 / 两 / 勺 / 瓣 / 把，
不是盎司和 cup；「少许」「适量」这类模糊量词**不参与任何计算**，
也不会被硬换算成克；生抽、酱油、豉油在食材库里是同一样东西，
采购清单里会自动合并成一行。

---

## ✨ 六个值得先看的功能

| | 功能 | 一句话 |
| :---: | --- | --- |
| 🍲 | **中餐菜谱库** | 份数缩放（2 人份改 4 人份，用量自动 ×2，模糊量不变）、中文友好搜索、私有菜谱、回收站、打印贴冰箱 |
| 🧊 | **家庭库存** | 冷藏 / 冷冻 / 常温 / 干货柜分位置记，过期提醒，还能「按家里现有的食材」倒推能做什么菜 |
| 📅 | **膳食周计划** | 拖拽排一周三餐，一键换菜、整周模板复用，指定时段直接转采购清单 |
| 🛒 | **采购清单** | 同名食材跨菜谱自动相加，按买菜动线分组，勾选已买，买回来一键入库 |
| 📲 | **微信定时推送** | 到点把「今天吃什么」推到微信 / 飞书 / 钉钉 / Telegram，带封面图、菜名链接和简介 |
| 🔐 | **私有自托管** | 三档权限（管理员 / 读写 / 只读）、登录失败锁定、改密码踢下线、可选游客浏览 |

> 完整清单见下方 [功能清单](#功能清单)，包含权限模型、单位换算规则、合并算法等细节。

---

## 🧰 技术栈

**PHP 8.2+ · SQLite（WAL）· 原生 ES module · 零 Composer 依赖 · 零前端构建链**

没有框架，没有 ORM，没有打包器。整个应用是「拷过去就能跑」的形态——
这也是它能在一台最低配的服务器上跑得很轻松的原因。
具体取舍见 [技术栈与取舍](#技术栈与取舍)。

---

## 🚀 快速开始

### 方式一：Docker（推荐）

镜像已经发布在 Docker Hub，**不用自己构建**：

```bash
docker run -d --name recipe-hub \
  -p 8080:80 \
  -v ./data:/var/www/html/data \
  -e TZ=Asia/Shanghai \
  -e RH_SITE_URL=https://eat.example.com \
  --restart unless-stopped \
  xyzbz/recipe-hub:1.1.3
```

或者用 compose（配置更好读，升级也方便）：

```bash
# 用项目自带的 docker-compose.yml（或照「Docker 配置要点」里的四行精简版自己写一份）
docker compose up -d
```

浏览器打开 `http://你的服务器:8080`，走安装向导建管理员账号，附赠 10 道示例菜谱。
更多配置项见 [Docker 配置要点](#-docker-配置要点)。

### 方式二：PHP 源码部署（没有 Docker 也行）

环境要求见下，配好之后把源码（或 Release 里的 `recipe-hub-1.1.3-deploy.zip`）
丢到站点目录即可。宝塔面板用户直接看 [宝塔面板部署](#宝塔面板部署)，七步搞定。

---

## ⚙️ 环境要求

| 项目 | 要求 | 说明 |
| --- | --- | --- |
| PHP | **8.2 及以上**（8.3 验证过） | 8.1 及以下没测，别自找麻烦 |
| 必需扩展 | `pdo_sqlite`、`sqlite3`、`mbstring`、`fileinfo`、`session`、`json` | 缺一个都起不来 |
| 图片扩展 | `gd`（需带 **WebP + JPEG + PNG + FreeType**）、`exif` | gd 负责缩放转 WebP，exif 负责手机竖拍照片的方向纠正；缺了图片功能会残废 |
| 数据库 | **无** | SQLite 单文件，PHP 自带驱动，不用另起数据库服务 |
| Web 服务 | Apache（需 `mod_rewrite`）或 Nginx | 仓库自带 `.htaccess`，Nginx 要手写伪静态（见下） |
| 目录权限 | `data/`、`data/uploads/`、`data/backups/` 需**可写** | 数据库、上传图片、备份全在这里 |
| 定时任务 | cron（可选） | 只有「膳食计划定时推送」需要，见 [膳食计划定时推送](#膳食计划定时推送) |

**Nginx 伪静态**（Apache 用户不用管，`.htaccess` 已经带好了）：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

**站点的运行目录必须指向 `public/`** —— 源码和 `data/` 在上一级，不能让它们被直接访问到。
宝塔里在建站时改「运行目录」，别把整个项目根目录当 Web 根。

---

## 🐳 Docker 配置要点

镜像：`xyzbz/recipe-hub:1.1.3`（自带 Apache + PHP 8.3 + gd/exif，500 MB 左右）。

> **想从源码自己构建**：把 compose 里的 `image:` 那行注释掉、放开 `build: .`，
> 跑 `docker compose up -d --build`，效果一样（构建时要能拉 `php:8.3-apache`）。
> 走不通外网的话用 `dist/recipe-hub-1.1.3-build-context.tar.gz`，见
> [离线部署与镜像导出](#离线部署与镜像导出)。

最小可用的 compose：

```yaml
services:
  recipe-hub:
    image: xyzbz/recipe-hub:1.1.3
    container_name: recipe-hub
    restart: unless-stopped
    ports:
      - "8080:80"                      # 左边是宿主机端口，被占用就改
    volumes:
      - ./data:/var/www/html/data      # 全部家当：数据库 + 图片 + 备份，备份就是整个拷走
    environment:
      TZ: Asia/Shanghai
      RH_SITE_URL: https://eat.example.com   # 推送要带封面图和菜名链接就填这个
```

**四个要点：**

| 项 | 值 | 为什么 |
| --- | --- | --- |
| 端口 | 容器 `80` → 宿主 `8080` | 容器内固定 80，宿主端口随便改 |
| 数据卷 | `/var/www/html/data` | **必须挂出来**，不挂容器一删数据就没了 |
| 站点地址 | `RH_SITE_URL` | 不填的话，微信/飞书推送会降级成纯文本（拼不出菜谱链接） |
| 升级 | `docker compose pull && docker compose up -d` | 数据在卷里，换镜像不影响数据 |

常用环境变量（都是可选的，不填走默认值）：

| 变量 | 默认 | 作用 |
| --- | --- | --- |
| `RH_SITE_URL` | 空 | 站点对外地址，推送富文本的前提 |
| `RH_SITE_NAME` / `RH_SITE_DESC` | 家常菜谱 | 站点名和副标题 |
| `RH_ALLOW_REGISTER` | `0` | 是否开放自助注册（私有实例建议关） |
| `RH_UPLOAD_MAX_MB` | `8` | 单张图片上限 |
| `RH_SESSION_DAYS` | `30` | 登录有效期 |
| `RH_DEBUG` | `0` | 排查问题时开 `1`，PHP 报错直接显示在页面上 |
| `PUID` / `PGID` | 不设 | 群晖/威联通上挂载卷权限打架时，填 `id -u` / `id -g` 的结果 |

完整版（含健康检查和资源说明）在仓库的 `docker-compose.yml`。
想从源码自己构建：把 `image:` 注释掉、放开 `build:` 两行，再 `docker compose up -d --build`。

---

## 目录

- [六个值得先看的功能](#-六个值得先看的功能)
- [技术栈](#-技术栈)
- [快速开始](#-快速开始)
- [环境要求](#-环境要求)
- [Docker 配置要点](#-docker-配置要点)
- [它解决什么问题](#它解决什么问题)
- [首次安装引导](#首次安装引导)
- [环境变量](#环境变量)
- [反向代理与 HTTPS](#反向代理与-https)
- [数据、备份与恢复](#数据备份与恢复)
- [升级](#升级)
- [离线部署与镜像导出](#离线部署与镜像导出)
- [宝塔面板部署](#宝塔面板部署)
- [膳食计划定时推送](#膳食计划定时推送)
- [命令行工具](#命令行工具)
- [从 Mealie 迁移](#从-mealie-迁移)
- [功能清单](#功能清单)
- [中式单位换算规则](#中式单位换算规则)
- [采购清单是怎么合并的](#采购清单是怎么合并的)
- [权限模型](#权限模型)
- [不用 Docker 跑（本地开发）](#不用-docker-跑本地开发)
- [目录结构](#目录结构)
- [常见问题](#常见问题)
- [路线图](#路线图)
- [参与](#参与)
- [许可](#许可)

---

## 它解决什么问题

家里做饭的人通常会遇到这几件事：

1. 菜谱散在微信收藏、小红书截图、手写本子里，找不到也用不上；
2. 「冰箱里还有半棵白菜、一块五花肉」和「今晚吃什么」是两件不挨着的事；
3. 想做三个菜，得自己把三份食材加起来才知道要买什么，还老忘。

这个应用把这条链条打通了：**菜谱 → 库存 → 周计划 → 采购清单**。
排好一周的饭，点一下就把整周要买的东西合并成一张清单，同一样食材的用量自动累加。

和通用菜谱软件比，中餐的地方在于：

- 单位是**克 / 斤 / 两 / 毫升 / 勺 / 碗 / 瓣 / 把**，不是盎司和 cup；
- 模糊量词（少许、适量、一把）**不参与任何计算**，也不会被强行换算成克——它们单独成行提醒你「看着放」；
- 食材库认**别名**：生抽、酱油、豉油是同一样东西，采购清单里会合并成一行；
- 菜系按鲁川粤苏闽浙湘徽 + 家常走，标签也是「快手菜 / 下饭菜 / 汤羹 / 早餐」这类。

---

## 首次安装引导

浏览器打开 `http://服务器IP:8080`，会进入**首次安装引导**：
建管理员账号 → 填站点名 → 可选灌一份示例菜谱。装完就能用了。

> 示例菜谱建议第一次装的时候要一份，里面有十来个中餐菜谱和配套食材，
> 方便你先看看界面长什么样，不想要可以在后台一键清掉。

**关于端口**：默认映射 `8080:80`。8080 被占用就改 `docker-compose.yml` 里的
`ports`，比如 `- "9090:80"`。

**关于注册**：默认关闭。家人要用，两种方式——
让管理员在后台「管理 → 用户」里加账号；或者把 `RH_ALLOW_REGISTER` 改成 `"1"`，
让他们自己注册（第一个人自动是管理员，之后注册的都是普通成员）。

---

## 环境变量

全部在 `docker-compose.yml` 的 `environment` 里改。也可以不挂卷、不挂配置，纯靠环境变量跑。

| 变量 | 默认 | 说明 |
| --- | --- | --- |
| `RH_SITE_NAME` | `家常菜谱` | 站点名，显示在标题和左上角 |
| `RH_SITE_DESC` | `自己家的中餐菜谱本` | 副标题 |
| `RH_ALLOW_REGISTER` | `0` | `1` 开放注册，`0` 只允许管理员加人 |
| `RH_UPLOAD_MAX_MB` | `8` | 单张图片上限（MB）。入口脚本会据此放开 PHP 的 `upload_max_filesize`，所以改这个就够，不用动 php.ini |
| `RH_IMAGE_MAX_WIDTH` | `1600` | 主图最长边，超出等比缩小 |
| `RH_IMAGE_QUALITY` | `82` | WebP 编码质量（40–100） |
| `RH_TIMEZONE` | `Asia/Shanghai` | 时区，影响「今天」是哪天 |
| `RH_SESSION_DAYS` | `30` | 登录有效期（天） |
| `RH_AUTO_INGREDIENT` | `1` | 菜谱里写的新食材是否自动登记进食材库 |
| `RH_DATA_DIR` | `/var/www/html/data` | 数据目录。**别改**，改了挂载卷就对不上了 |
| `RH_DEBUG` | `0` | `1` 时把 PHP 报错显示在页面上，排查问题用 |
| `PUID` / `PGID` | 空 | 可选，把容器内 `www-data` 对齐到宿主机 UID/GID，见下 |
| `TZ` | `Asia/Shanghai` | 容器系统时区 |

**关于 `RH_AUTO_INGREDIENT`**：默认开着。写菜谱时填了一个食材库里没有的名字，
系统会自动登记一条。关掉的话，这个食材不会进库，后果是它在采购清单里
**没法按分类归组、也不参与别名合并**——所以除非你有洁癖想手动维护食材库，否则别关。

**群晖 / 威联通等 NAS**：挂载的共享文件夹属主通常不是 `33`，容器写不进去。
在宿主机上 `id -u` 和 `id -g` 拿到你的用户 ID，然后：

```yaml
environment:
  PUID: "1026"
  PGID: "100"
```

入口脚本会把容器里的 `www-data` 改成这个 ID，权限问题就没了。

---

## 反向代理与 HTTPS

这个应用本身不带 TLS。对外提供 HTTPS 的标准做法是前面挡一层 Nginx（或 Caddy、
宝塔、群晖的反代功能），容器继续裸跑 HTTP。

Nginx 示例：

```nginx
server {
    listen 443 ssl http2;
    server_name caipu.example.com;

    ssl_certificate     /etc/letsencrypt/live/caipu.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/caipu.example.com/privkey.pem;

    # 图片最大 8MB，留点余量
    client_max_body_size 16m;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_http_version 1.1;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        # 这一行很关键：PHP 靠它判断原始协议，决定 Cookie 要不要加 Secure
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_read_timeout 120s;
    }
}

server {
    listen 80;
    server_name caipu.example.com;
    return 301 https://$host$request_uri;
}
```

Apache 的配置里已经加了 `SetEnvIfNoCase X-Forwarded-Proto "^https$" HTTPS=on`，
所以反代传过来的 `X-Forwarded-Proto: https` 会被识别，不用额外配置。

**如果走 Cloudflare 之类的 CDN**：CDN 回源到你的 Nginx，Nginx 再回源到容器，
`X-Forwarded-For` 会是一串 IP，应用取第一个（也就是真实客户端 IP），
登录失败锁定是按这个 IP 算的，不会因为 CDN 把所有人算成同一个。

---

## 数据、备份与恢复

**所有数据都在一个目录里**：`./data`（容器里的 `/var/www/html/data`）。

```
data/
├── recipe.sqlite          # 数据库（菜谱、用户、库存、计划、清单、日志）
├── recipe.sqlite-wal      # SQLite WAL 日志
├── uploads/               # 上传的图片，按 年/月 分目录，一律转成 WebP
│   └── 2026/10/xxxxxxxxxxxxxxxx.webp
│       └── xxxxxxxxxxxxxxxx.t.webp    # 缩略图
├── backups/               # 后台点「立即备份」或跑 cli/backup.php 生成
└── php-error.log
```

### 备份：一个 zip 装走整个站

后台点「管理 → 备份 → 立即备份」，或者命令行：

```bash
docker compose exec recipe-hub php cli/backup.php
```

产物是一份 `recipe-20261013-234500.zip`，里面就三样东西：

```
recipe-20261013-234500.zip
├── manifest.json       # 自述：程序版本、打包时间、各表有多少条
├── database.sqlite     # 数据库快照（VACUUM INTO，写库的同时跑也安全）
└── uploads/**          # 全部图片，按原目录结构原样装进来
```

**为什么是 zip**：以前只备数据库，图片得自己 `rsync` —— 换机器时最容易漏的就是这一步，
漏了的后果是菜谱全变成空图。现在一个文件装走数据库 + 图片，新服务器上把程序跑起来、
把这个包传回去点一下恢复，菜和图片一起回来。

| 产物 | 内容 | 说明 |
|---|---|---|
| `recipe-*.zip` | 数据库 + 全部图片 | 默认产物，换机器、灾难恢复用这个 |
| `recipe-*.sqlite` | 只有数据库 | `cli/backup.php --db-only` 生成，几百 KB，适合密集 cron |

备份留在 `data/backups/`，手建的默认保留最近 10 份（可在后台下载、删除）。

**定时备份**（可选）：

```cron
0 3 * * * cd /opt/recipe-hub && docker compose exec -T recipe-hub php cli/backup.php --keep=14 >> /var/log/recipe-hub-backup.log 2>&1
```

### 恢复 / 换服务器

**方式一（推荐，不用上服务器）：后台「管理 → 备份 → 从文件恢复」**

1. 把 zip 拖进页面（或点列表里那份备份的「恢复」）→ 先出**体检报告**：
   多少菜谱、多少账号、包里有几张图、有没有缺的。这一步只读，不动任何数据。
2. 确认后执行：**先把图片并回** `data/uploads/`（同名覆盖，不删你现在多出来的文件），
   再整体换库。顺序是刻意的——铺图是纯增量、不会弄坏现状，换库有回滚保护。
3. 换库前自动留一份 `before-restore-*.sqlite` 快照。恢复完发现不对，
   在列表里点它的「恢复」就是一键回滚。
4. **会话怎么处理**：
   - 备份里的账号跟当前站点一致 → 恢复后**会话保持**，你还在登录态，不踢人。
   - 账号对不上（换机器恢复、或恢复到了很早以前的备份）→ 当前会话失效，重新登录即可。
     体检报告里会明确提示。

老格式的 `recipe-*.sqlite`（只有数据库）照样能体检和恢复，只是图片得服务器上本来就有。

两个容易踩的坑已经兜住了：

- **整站包外面多套了一层文件夹**（Windows 右键「压缩」会自建同名目录，网盘转存也常这样）——
  也能认出来，体检报告里会提示一句，不影响恢复。
- **拖错文件**：把「菜谱导入包」（`recipe-hub-import.json`，Mealie 迁移用的那种）拖到这里，
  会被挡下并直接告诉你该去**菜谱页 → 导入**；混装 SQLite 的第三方包会提示直接传 `.sqlite`。
  挡下时数据一行不动。

**方式二：命令行 / 整目录搬迁**

```bash
# 1. 停服务，并给现状留个后路
docker compose down
mv data data.broken

# 2. 从整站包复原（库 + 图片一起到位）
mkdir -p data/uploads data/backups
unzip -o recipe-20261013-234500.zip -d data
mv data/database.sqlite data/recipe.sqlite
rm data/manifest.json

# 3. 起来
docker compose up -d
```

**换机器**：也可以什么都不拆，把整个 `data/` 目录拷到新机器同样的位置，`docker compose up -d`，
完事。配方里没有任何机器绑定的东西。

---

## 升级

```bash
cd recipe-hub
git pull
docker compose up -d --build
```

数据库结构变更是**幂等迁移**，容器启动时会自动跑，不用手动执行任何东西。
升级前建议先做一次备份（上面任一方式），想退版本就退镜像再把数据库恢复回去。

---

## 离线部署与镜像导出

### 两个包该怎么选（dist/）

| 包 | 给谁 | 怎么用 |
|---|---|---|
| `recipe-hub-1.1.3-deploy.zip` / `.tar.gz` | **不用 Docker**：宝塔、PHP-FPM、php -S | 上传解压 → 看包内 `DEPLOY.md` → 运行目录指到 `public/` |
| `recipe-hub-1.1.3-build-context.tar.gz` | **想自己构建镜像**的人 | 解压后 `docker compose up -d --build`，或跑 `docker/export-image.sh --all` 出自用镜像。一般不需要——直接用 Docker Hub 上的 `xyzbz/recipe-hub:1.1.3` 更省事 |

两份内容一致（源码 + `DEPLOY.md` + 空的 `data/` 骨架），都**不含任何本机数据**——
数据库、上传图片、测试脚本一个都没有。非 Docker 那份多给了 zip 格式，方便面板点解压。

### 构建上下文压缩包

`dist/recipe-hub-1.1.3-build-context.tar.gz` 是整份构建上下文：源码、前端、
种子数据、Dockerfile、compose、一键构建脚本和本文档。可以直接传到服务器：

```bash
# 本机
scp dist/recipe-hub-1.1.3-build-context.tar.gz root@服务器:/opt/

# 服务器
mkdir /opt/recipe-hub && tar xzf /opt/recipe-hub-1.1.3-build-context.tar.gz -C /opt/recipe-hub
cd /opt/recipe-hub && docker compose up -d --build
```

构建只需要拉基础镜像 `php:8.3-apache` 和 Debian 软件源。国内服务器拉不动
Docker Hub 的话，在 `/etc/docker/daemon.json` 里配 `registry-mirrors` 指到
国内镜像源，`systemctl restart docker` 后再 build。

### 一键出镜像（服务器端）

包里带了 `docker/export-image.sh`。在任何装了 Docker 的机器上解开上下文，一条命令就是真镜像：

```bash
# 服务器
mkdir /opt/recipe-hub && tar xzf /opt/recipe-hub-1.1.3-build-context.tar.gz -C /opt/recipe-hub
chmod +x /opt/recipe-hub/docker/export-image.sh

/opt/recipe-hub/docker/export-image.sh --all      # 推荐：构建 -> 真容器自检 -> 导出
/opt/recipe-hub/docker/export-image.sh --deploy   # 构建 + 直接把站点长期跑起来
```

- `--smoke` 会起一个**临时容器**，逐个核对 gd / exif / mbstring / pdo_sqlite 等扩展、打健康接口、取首页状态码，然后**自动销毁容器**不留痕。它不过，`--export` 会拒绝导出。
- `--all` = `--smoke` + `--export`：先验再用，免得导出一个缺扩展的坏镜像自己还不知道。
- 不传参数则只构建到本地镜像库。

> 这套做法是保守但诚实的：本机没有容器运行时，**保证不了「gd / exif 真的编译成了」**，
> 所以不手工拼 layer——拼出来的能 load，但可能是个缺扩展的坏镜像，比没有更糟。
> 作为补偿，本机把 Dockerfile 的 `COPY` 语义按 Docker 的口径展开、落到
> `docker-out/rootfs/`，**然后用那里面的字节起服务，跑完了全套 380 项接口测试**
> （含图片上传处理、备份与站内恢复）。所以至少「进镜像的这些文件是完整且跑得通的」
> 这件事是有证据的，不是嘴上说说。

### 导出镜像搬到别的机器

在**装了 Docker 的机器**上构建一次之后，导出成离线包，目标机 `docker load`
即用，不用再次构建：

```bash
# 有 Docker 的机器上
docker save recipe-hub:1.1.3 | gzip > recipe-hub-1.1.3-image.tar.gz

# 目标机
docker load -i recipe-hub-1.1.3-image.tar.gz
```

注意镜像里**只有程序，没有数据**：目标机还需要 `docker-compose.yml` 和
`./data/` 目录（全新部署等首次安装引导；迁移老数据就把 data 目录整个拷过去）。

> **正常情况不需要走这一步。** 官方镜像已经发布在 Docker Hub：
> `docker pull xyzbz/recipe-hub:1.1.3`。
> 上面这套「构建 → 导出 → load」是给**离线环境**（内网服务器、没外网）准备的，
> 或者你想改 Dockerfile 自己出一个定制镜像。
> 顺带说一句：镜像里的 gd / exif 是编译进来的，所以别手工拼镜像层——
> 拼得出来「load 得进去」的包，拼不出「gd 确实能用」的保证。

---

## 宝塔面板部署

不装 Docker 也行——宝塔本质就是面板化的「Nginx + PHP-FPM」，这应用没有构建步骤，
跑起来特别合拍。全程在面板上点，只在两处要敲命令。

**1. 装 PHP**：软件商店装 PHP 8.1 或更高（推荐 8.2 / 8.3）。到「PHP 设置 → 安装扩展」
确认这几个已启用：`pdo_sqlite`、`gd`（要带 webp）、`exif`、`mbstring`，缺哪个点哪个。
命令行验证：

```bash
php -m | grep -E "pdo_sqlite|gd|exif|mbstring"
```

**2. 传代码**：把部署包（`dist/recipe-hub-1.1.3-deploy.zip`，面板上传后点解压最省事）
解到站点目录：

```bash
mkdir /www/wwwroot/recipe-hub
tar xzf recipe-hub-1.1.3-deploy.tar.gz -C /www/wwwroot/recipe-hub
# 或：unzip recipe-hub-1.1.3-deploy.zip -d /www/wwwroot/recipe-hub
```

**3. 建站点**：网站 → 添加站点，数据库选「不创建」（这应用用 SQLite，不吃 MySQL）。
建好后进「站点设置 → 网站目录 → 运行目录」改成 **`public`**——这是关键一步，
改完 `src/`、`data/` 就摸不到了。

**4. 伪静态**：站点设置 → 伪静态，贴这一段：

```nginx
location / {
    try_files $uri $uri/ /index.php$is_args$args;
}
```

`/uploads/` 的图片实际存在 `data/uploads/`（docroot 之外），URL 在 public 里找不到
真实文件，会自动落回 `index.php` 由 PHP 带着权限检查流出来——**不用**为它单独配规则。

**5. 权限**：宝塔 PHP 以 `www` 用户跑，给它可写：

```bash
chown -R www:www /www/wwwroot/recipe-hub
```

**6. 改配置不需要环境变量**：宝塔里设 env 比较绕，好在这应用支持 `data/config.php`
配置文件，优先级在环境变量之下、默认值之上：

```php
<?php
// /www/wwwroot/recipe-hub/data/config.php
return array(
    'siteName'      => '我家菜谱',
    'allowRegister' => 0,    // 关公开注册
    'uploadMaxMb'   => 8,
);
```

不建这个文件也能跑，全用默认值。上传大小另受两处限制：PHP 设置里的「上传限制」
调到 ≥ 8MB；Nginx 的 `client_max_body_size` 宝塔默认 50m，够用。

**7. 备份**：计划任务 → 备份站点。`data/` 在站点目录内，数据库 + 图片会一起备走。
也可以挂 cron 跑 `php cli/backup.php`（默认打整站 zip，`--db-only` 只备数据库）。

**8. HTTPS**：站点设置 → SSL 一键 Let's Encrypt，完事。

三个坑提前说（按你会撞上的顺序排）：

- **「防跨站攻击」跟运行目录联动**：宝塔把 open_basedir 限制在**运行目录**里
  （运行目录改成 public 后就是 `…/public/:/tmp/`），而应用入口要 `require`
  上一级的 `bootstrap.php`——所以首页会直接报
  `open_basedir restriction in effect … bootstrap.php is not within the allowed path(s)`。
  二选一：
  - 同机就这一个站：站点设置 → 网站目录，**关掉「防跨站攻击(open_basedir)」**。
    入口已收敛在 public/，src/、data/ 本来就不可达，关掉不亏。
  - 同机多站、想保留站间隔离：SSH 改站点根目录下的 `.user.ini`
    （宝塔给它加了 immutable 锁，直接改会提示权限不够）：

    ```bash
    chattr -i /www/wwwroot/你的域名/.user.ini
    sed -i 's#^open_basedir=.*#open_basedir=/www/wwwroot/你的域名/:/tmp/' \
        /www/wwwroot/你的域名/.user.ini
    chattr +i /www/wwwroot/你的域名/.user.ini
    systemctl reload php-fpm-82   # 按实际 PHP 版本；或等 5 分钟 user_ini 缓存过期
    ```

    路径放宽到**项目根**（不是 public），data 在项目根内同样放行。
- 首页能开但提示「数据库初始化失败」→ `data/` 不可写：`chown -R www:www 项目根`。
- 除首页外全 404 → 伪静态没配，回看第 4 步。

---

## 膳食计划定时推送

排好了今天吃什么，到点把这一餐推到微信/手机。站点自己不依赖任何一家推送平台——
渠道是可插拔的，配什么就往哪发。

### 支持五种渠道

| 渠道 | 消息落到哪 | 备注 |
|---|---|---|
| **企业微信自建应用** | **个人微信**（关注「微信插件」后） | 给家人推最合适：不用装 App，免费，不依赖第三方中转 |
| PushPlus | 微信服务号 | 每人扫码拿 token，免费约 200 条/天 |
| Server酱 | 微信服务号 | 接入最快，免费版 5 条/天偏少 |
| WxPusher | 微信服务号 | 支持一对多主题推送 |
| 自定义 Webhook | 飞书 / 钉钉 / 企微**群机器人**、Telegram Bot、通用 JSON | 格式自动按地址识别（也可手动选）；通用 JSON 带 `X-RH-Signature` 签名头，接 n8n / 自建转发服务 |

前四种本质都是微信服务号的模板消息能力：平台认证了服务号，你关注它，它替你把消息发出去。
想让**任意注册用户**都能在微信里收到（而不只是自家小圈子），只有认证服务号模板消息
或小程序订阅消息两条路，对个人都比较折腾。

### 怎么配

进「管理后台 → 推送通知」：

1. **新增渠道**：选类型 → 填参数 → 保存 → 点「测试」看手机收不收得到
   - 自定义 Webhook 里粘贴飞书 / 钉钉 / 企微群机器人的地址会**自动识别协议**，
     消息按各家的格式组装（飞书 `msg_type=text`、钉钉/企微 `msgtype=text`、Telegram `sendMessage`）
   - 钉钉 / 飞书机器人开了「加签」就把密钥填到「签名密钥」，会按各家的算法签名；
     Telegram 另外要填 Chat ID（给 `@userinfobot` 发条消息就能查到）
   - 通用 JSON 格式保持原行为：`{"event","title","body","timestamp"}` + HMAC 签名头
2. **推送时间**：早/午/晚/加餐各有一个开关和时间点，默认午餐 11:00、晚餐 17:00
3. **挂上 cron**（站点不会自己定时）：

```bash
# 宝塔：计划任务 → Shell 脚本 → 每 1 分钟
cd /www/wwwroot/你的站点目录 && php cli/notify.php

# Docker：宿主机 crontab
* * * * * cd /opt/recipe-hub && docker compose exec -T recipe-hub php cli/notify.php
```

```bash
# 命令行手动用
php cli/notify.php                        # 按当前时间判断该发哪一餐
php cli/notify.php --dry-run              # 只看看会发什么，不真发
php cli/notify.php --slot=dinner          # 立刻推今天的晚餐
php cli/notify.php --slot=dinner --force  # 不管推没推过，强制再发一次
```

### 推送里能带图和链接吗

能。填了站点地址之后，各渠道按自己的能力来：

| 渠道 | 发成什么样 |
|---|---|
| 企业微信自建应用 / 群机器人 | **news 图文卡片**：每道菜一张，点进去是菜谱详情页 |
| 飞书 | **interactive 卡片**，正文是链接可点的 lark_md（**不带图**，卡片里的图要 `img_key`，群机器人没权限上传） |
| 钉钉 | **markdown**：封面图、菜名链接、简介全都有 |
| Telegram | **HTML 正文**（菜名是 `<a>`）+ 封面图单独发一条 |
| PushPlus / WxPusher | 切 **html** 模板 |
| Server酱 | desp 走 **Markdown** |
| 通用 webhook | JSON 里给结构化 `items`（菜名 / 简介 / 链接 / 封面图分开），另附 `html`、`markdown` 两个现成版本 |

两个前提：

1. **要填站点地址**：管理后台 → 站点设置 → 站点地址，或环境变量 `RH_SITE_URL`。
   `cli/notify.php` 跑在命令行里，没有 HTTP 请求也就没有域名概念，不填就只发纯文本。
2. **封面图得公网可达**：飞书、钉钉、企微是**它们自己的服务器来抓你的图**，
   内网部署抓不到。抓不到的后果就是图裂，所以宁可不带。

不想带就把站点设置里的「推送带封面图和链接」关掉，所有渠道退回纯文本。

一个改不了的坑：**企业微信消息里的链接需要在企微后台配「可信域名」**，
否则在微信里点了会提示非可信链接打不开。这是企微的安全策略，跟本站无关。

### 几个设计上的取舍

- **没排菜就不推**。空餐次直接跳过，不推「今天什么都没安排」这种废话。
- **去重在数据库里**：同一个「日期+餐次」在一个渠道上只许有一条成功记录，
  所以 cron 每分钟跑一次也不会把同一顿饭推 60 遍。想重发就加 `--force`。
- **密钥回前端是打码的**，编辑时没改动就保留原值（不会把星号存成真密码）。
- **富文本一律降级发送**：没站点地址、没封面图、没链接，就退回纯文本，
  绝不会推出半个坏链接（点了打不开比不发更烦人）。
- **HTML 渠道全字段转义**：菜名是用户自己填的，PushPlus / WxPusher / Telegram
  走 HTML，尖括号原样拼进去就是 XSS。
- **推送失败不会让 cron 挂掉**，错误写进 `notify_log` 和站点日志，后台能看到。

---

## 命令行工具

容器里带了四个脚本，用在「忘了密码」「定时备份」「定时推送」「从 Mealie 搬家」这几个真实场景。

```bash
# 列出所有账号（含 ID、角色、是否只读、是否停用）
docker compose exec recipe-hub php cli/reset-password.php --list

# 重置某个账号的密码（不需要登录，只能在服务器上跑）
# 会同时踢掉该账号在其它设备上的登录会话
docker compose exec recipe-hub php cli/reset-password.php 小宋 新密码至少六位

# 备份整个站（数据库 + 全部图片，产物一个 zip）
docker compose exec recipe-hub php cli/backup.php
docker compose exec recipe-hub php cli/backup.php --keep=14   # 只留最近 14 份
docker compose exec recipe-hub php cli/backup.php --db-only   # 只要数据库（快）

# 按排期推送膳食计划（挂在 cron 上，每分钟跑一次）
docker compose exec recipe-hub php cli/notify.php
docker compose exec recipe-hub php cli/notify.php --dry-run

# 从 Mealie 迁移（详见下一节）
docker compose exec recipe-hub php cli/import-mealie.php /path/to/export.zip --images --dry-run
```

---

## 从 Mealie 迁移

Mealie 后台「设置 → 导出数据」会给你一个 zip：每个菜谱一个目录，里面有 `recipe.json`
和 `assets/`（图片）。把它传到服务器上，一条命令搬完：

```bash
# 1. 先预演：看看能认出多少道菜、食材步骤各几条（不写库，随时可中断）
docker compose exec recipe-hub php cli/import-mealie.php /tmp/mealie-export.zip --dry-run

# 2. 正式导入，连图片一起
docker compose exec recipe-hub php cli/import-mealie.php /tmp/mealie-export.zip --images

# 已经解压开了、或者只有单个菜谱的 JSON，一样能用
docker compose exec recipe-hub php cli/import-mealie.php /tmp/mealie-export --images
docker compose exec recipe-hub php cli/import-mealie.php /tmp/可乐鸡翅.json
```

常用选项：`--dry-run`（只报告不写库）、`--images`（连图一起导）、`--overwrite`
（同名覆盖，默认跳过已有菜谱）、`--owner=小宋`（指定归属用户，默认第一个管理员）、
`--limit=10`（先试 10 条）、`--json`（输出机器可读的结果）。

**字段对应关系**（导入时自动翻译）：

| Mealie | 这边 | 说明 |
| --- | --- | --- |
| `name` / `title` | 菜名 | |
| `description` / `summary` | 简介 | |
| `recipeIngredient` | 食材行 | **对象数组和字符串数组都认**（v1 之后的嵌套结构、老版本的纯文本行） |
| ↳ `quantity` + `unit.name` + `food.name` | 用量 + 单位 + 名称 | |
| ↳ `note` | 备注 | 比如「中段，别用翅尖」 |
| ↳ `disableAmount: true` | 单位落到「适量」 | 跟我们处理模糊量的方式一致，不参与采购清单计算 |
| ↳ 只有 `title` 没有 `food` | **丢掉** | 那是 Mealie 的食材分组标题（"For the sauce"），导进来就是一行废话 |
| `recipeInstructions` | 步骤 | 字符串、字符串数组、`{text:...}` 数组都认 |
| `tags` + `categories` | 标签 | 两个字段合并 |
| `keywords` | 标签 | 老版本是逗号分隔的字符串 |
| `recipeYield`（"4 servings"） | 份量 | 取数字部分，原文留在备注字段 |
| `prepTime` / `cookTime` / `totalTime` | 准备 / 烹饪 / 总时长 | ISO 8601（`PT1H30M`）转分钟 |
| `orgURL` | 来源链接 | |
| `assets/` 里的图 | 成品图 | 走网页上传同一条管线：转 WebP + 出缩略图 |

**三个要提前知道的事：**

1. **单位是尽力翻译，不是保证**。Mealie 里 `cup`、`ounce`、`°F` 这类单位我们表里没有，
   会原样留着（不会瞎换算）。导入后建议翻一遍食材行，把 `cup` 改成「杯」或毫升。
   中式单位（克 / 斤 / 两 / 勺 / 瓣）是直接对得上的。
2. **菜谱图是「抓」不过来的**。Mealie 只导出它自己存的那张成品图；
   步骤配图在 Mealie 里本来也不单独存，所以步骤图不会跟过来。
3. **重名菜谱默认跳过**。想覆盖就加 `--overwrite`。同一个 zip 跑两遍是安全的——
   第二遍会全部跳过，不会给你变出两份。

导入过程中图片失败不会影响菜谱（图能后补，菜谱没了才亏），失败的条目会在最后列出来。

---

## 功能清单

### 1. 用户与权限

- 首次安装引导建管理员；可关闭公开注册，只由管理员加人
- 管理员 / 读写成员 / **只读成员**三档
- 只读成员能看所有内容、能收藏、能改自己的密码和资料，但存不了任何改动
- 登录失败达阈值自动锁定（默认 8 次锁 15 分钟，按用户名 + IP 计）
- 会话在数据库里，改密码可以把其它设备全部踢下线
- **游客浏览**（默认关）：后台一键开关，开了之后未登录也能逛公开菜谱——见「权限模型」

### 2. 菜谱管理（核心）

- 中餐字段：菜名、别名、菜系、难度、**准备/烹饪时长**（分）、份量（几份）
- 食材行：**用量 + 单位 + 名称 + 备注**，单位有下拉候选，「少许/适量」也在里面
- 步骤：文字 + 每步可配图
- 标签（多选 + 回车新建）、成品图、来源链接、小贴士
- **份数缩放**：2 人份改 4 人份，所有食材用量自动 ×2；「少许」这类模糊量保持不变
- 搜索：菜名、别名、食材、步骤、标签、菜系一起搜（中文友好，一个「番茄」能同时命中菜名和食材）
- 筛选 + 排序 + 分页；收藏夹
- **公开 / 私有**：标记私有的菜谱，除管理员外别人看不到，也搜不到、导不出、排不进计划
- 导入导出 JSON；**从 Mealie 整站迁移**（zip 直接导，含图片，见[从 Mealie 迁移](#从-mealie-迁移)）
- 复制一份改写
- **分享链接**：详情页一键复制这道菜的地址；开了「游客浏览」对方不用登录就能看
- **打印样式**：打印按钮出一张只有标题、用料、做法的纸，适合贴冰箱
- **回收站**：删除先进回收站（配料、步骤、图片全保留），随时恢复；想删干净再去回收站彻底删
- **家人留言**：每道菜下面都能留言，做过之后「下次少放点盐」写在菜谱边上，全家人看得见
- **下厨记录**：详情页「✓ 做过了」或计划页打卡，自动记「做过几次、上次啥时候」
- **今天吃什么**：🎲 随机抽一道，跟着当前筛选走（菜系/难度/时长/标签），抽不到就明说

### 3. 食材库 + 家庭库存

- 预置常见中餐食材，带别名（生抽 = 酱油 = 豉油）
- 按分类组织（蔬菜 / 肉类 / 水产 / 蛋奶 / 豆制品 / 主食 / 调味 / 干货 / 香料 / 其它）
- 合并两个食材（把别名和用量一起并过去）
- 库存：记「家里有什么」——数量、单位、存放位置（冷藏/冷冻/常温/干货柜）、保质期
- **过期提醒**：已过期 / 三天内到期，在总览和库存页都有标识
- **按库存推荐**：勾选菜谱库里有的食材，按命中率排序推荐能做的菜，并直接告诉你还缺哪几样

### 4. 膳食计划

- 周视图，早 / 午 / 晚 / 加餐四个时段
- 拖拽把菜谱挪到别的日子或时段（也支持点击选择目标格）
- **一键换菜**：某顿不想吃了，🔄 随机换成一道本周还没排过的菜
- 整周模板：存一套「我家一周的常规饭」，之后一键套用
- 复制上一周 / 清空某天
- 指定时间段一键转成采购清单
- **定时推送**：早/午/晚各设一个时间点，到点把当天这一餐推到微信（五种渠道可选，见
  [膳食计划定时推送](#膳食计划定时推送)）；当天没排菜就不推

### 5. 采购清单

- 从周计划或指定几个菜谱自动汇总
- **同名食材自动合并相加**，先按食材库别名归到正名（生抽/酱油/豉油合成一行）
- 分类分组显示，按采购动线（蔬菜 → 肉 → 水产 → 豆制品 → 调味…）
- 勾选买到了没；「清空已购」「全部取消勾选」
- 手动加临时项、改数量和单位、写备注
- 导出为文本，可以直接发微信给家人
- 买回来一键「入库」，把清单里的东西直接写进家庭库存

### 6. 系统设置

- 站点名、副标题、是否开放注册
- 个人资料：显示名、头像、**单位偏好**（决定新菜谱录入时的默认单位显示）
- 改密码
- 把「我自己的数据」整体导出成 JSON

### 7. 管理员后台

- **推送通知**：管理推送渠道（五种）、设定各餐次推送时间、测试发送、看推送记录
- 总览：用户数、菜谱数、库存项、图片占用、过期提醒
- 用户管理：新建、改角色、切只读、启用/停用、重置密码、删除
- 全局食材库维护：增删改、合并、重建索引
- 站点配置
- 操作日志（登录、增删改、备份、维护），可清空
- 数据备份：立即备份 / 列表 / 下载 / 删除
- 维护任务：重建搜索索引、`VACUUM` 压缩数据库、灌示例数据

### 8. 部署

- 单容器镜像，`docker compose up -d` 一条命令
- 不依赖 Docker：宝塔 / 裸 Nginx + PHP-FPM / `php -S` 都能跑（见「宝塔面板部署」）
- SQLite 单文件库，无外部依赖，不用另起数据库容器
- 图片本地存储、自动转 WebP 缩略
- 数据卷持久化，整卷拷走即迁移
- 反代友好（识别 `X-Forwarded-Proto` / `X-Forwarded-For`）
- **深色模式**：跟随系统 / 手动切换，记在浏览器里；侧边栏一键翻面
- 密码 `password_hash` 加盐哈希，会话 HttpOnly Cookie，写请求 CSRF 双提交校验

---

## 中式单位换算规则

这是整个应用最容易做错的地方，规则写清楚：

**基准单位只有三类：**

| 类别 | 基准 | 参与计算的单位 |
| --- | --- | --- |
| 重量 | 克 | 克、千克/公斤、斤、市斤、两、市两、钱、毫克 |
| 体积 | 毫升 | 毫升、升、大勺/汤匙/汤勺/勺、小勺/茶匙、碗、杯 |
| 计数 | 个 | 个、只、条、根、瓣、片、块、段、张、颗、粒、把、束、盒、袋、包、瓶、罐、听、扎、打（=12个） |

**市制换算（硬编码，不查表）：**

```
1 斤 = 500 克      1 两 = 50 克      1 钱 = 5 克
1 千克 = 1000 克   1 升 = 1000 毫升
1 大勺 = 15 毫升   1 小勺 = 5 毫升   1 碗 = 250 毫升   1 杯 = 240 毫升
1 打 = 12 个
```

这些数字是**写死在代码里的**，不做成可配置项——中餐厨房里「一斤就是一斤」，
没有按地域改换算的必要。要改就直接改 `src/Units.php` 那张表。

**勺子的各种写法都归到容积**：大勺 / 汤匙 / 汤勺 / 勺 = 15 毫升，小勺 / 茶匙 = 5 毫升。
注意「小勺」**不会**被当成「勺」——5 毫升和 15 毫升差三倍，这条单独写了断言盯着。

**「1 小块姜」「2 大段葱」这种写法能解析**：会归到「块」「段」这两个计数单位上。
修饰剥离只对**计数类**单位生效，所以不会把「小勺」误伤成「勺」。

**模糊量词永不参与计算**：少许、适量、少量、些许、一些、若干、一点、按需、随意、随口味。
这些**既不换算也不合并**。在采购清单里它们各自单独成行，提示你「这个自己看着买」。

**同一食材但单位量纲不同不能相加**：`2 斤土豆` 和 `3 个土豆` 是两行，
因为「1 个土豆多少克」没有标准答案。系统不去猜，宁可分成两行，也不给你一个错的合计数。

**跨界换算不做**：`1 勺盐` 是体积（15 毫升），`300 克盐` 是重量。
盐的密度应用不关心，所以这两行也不会合并。买菜场景下这不影响你「买一袋盐」。

---

## 采购清单是怎么合并的

生成清单的时候，同一个食材的用量会**累加**。顺序是这样的：

1. **按食材库别名归到正名**。`生抽 15ml`、`酱油 120ml`、`豉油 5ml`
   → 全部归成「生抽」，因为它们在食材库里是同一条（互为别名）。
2. **把用量换算到基准单位后相加**：15 + 120 + 5 = **140 毫升**。
3. **显示时换回顺眼的单位**。1350 克不会写成「1350 克」，会写成 **2.7 斤**。

几条实际效果：

| 菜谱里的写法 | 清单里的结果 |
| --- | --- |
| 2 斤 + 300 克 + 1 两 土豆 | 1350 克，显示为 **2.7 斤** |
| 生抽 15ml + 酱油 120ml + 豉油 5ml | **生抽 140 毫升**（三行合一） |
| 5 瓣 大蒜 + 2 个 大蒜 | **两行**（计数单位字面不同，不相加） |
| 少许盐 + 适量胡椒粉 | **各自单独一行**，不合并、不换算 |

单位换算和合并的算法有 120 项单元断言罩着，HTTP 端到端另有 313 项，
上面这几条都在测试里。

---

## 权限模型

| 能做什么 | 管理员 | 读写成员 | 只读成员 | 游客* |
| --- | :---: | :---: | :---: | :---: |
| 看菜谱 / 食材库 / 库存 / 计划 / 清单 | ✅ | ✅ | ✅ | 仅公开菜谱 |
| 收藏菜谱 | ✅ | ✅ | ✅ | ❌ |
| 改自己的密码、显示名、头像 | ✅ | ✅ | ✅ | — |
| 新建 / 修改 / 删除菜谱 | ✅ | ✅ | ❌ | ❌ |
| 记库存、排计划、生成清单 | ✅ | ✅ | ❌ | ❌ |
| 看**别人**的私有菜谱 | ✅ | ❌ | ❌ | ❌ |
| 用户管理、站点设置、备份、看日志 | ✅ | ❌ | ❌ | ❌ |

\* 游客模式默认**关闭**。管理员在「后台 → 站点设置 → 开放游客浏览公开菜谱」打开后，
未登录访客才能浏览公开菜谱（只读，能搜索、能看详情、能打印）。这是**分享链接**的载体：
开着它，把 `https://你的域名/#/recipe/12` 发给家人，对方不用注册就能打开。
私有菜谱对游客一律报「不存在」，不暴露它是否存在。
关掉它，未登录访问任何内容接口都是 403，和纯私有实例一样。

「私有」是菜谱上的一个开关。标记私有的菜谱，只有作者本人和管理员能看到；
别人的列表、搜索、导出、按库存推荐、膳食计划里都不会出现它。
只读成员不是「半个人」——他能收藏、能改自己密码、能登出，这些在接口层是单独一条
权限通道，不受只读限制。

---

## 不用 Docker 跑（本地开发）

容器只是为了交付方便，应用本身是纯 PHP + SQLite，没有构建步骤。

**要求**：PHP 8.1+，扩展 `pdo_sqlite`、`gd`（要 webp 支持）、`exif`、`mbstring`。

```bash
php -S 127.0.0.1:8000 -t public router.php
```

打开 `http://127.0.0.1:8000`。

前端是原生 ES module，**没有任何打包工具**——改完 JS 刷新页面就生效，
不用 npm install，不用等 webpack。`.workbuddy/` 目录是开发期跑测试用的，交付时可以不带。

要指定数据目录（比如为了不污染本地 `data/`）：

```bash
RH_DATA_DIR=/tmp/recipe-dev php -S 127.0.0.1:8000 -t public router.php
```

---

## 目录结构

```
recipe-hub/
├── Dockerfile                  # 单容器镜像（php:8.3-apache）
├── docker-compose.yml          # 一键部署
├── docker/
│   ├── apache.conf             # 站点配置：docroot=public，rewrite 兜底到 index.php
│   ├── php.ini                 # PHP 运行时（中文、opcache、会话安全）
│   └── entrypoint.sh           # 数据目录权限、PUID/PGID、上传上限
│
├── DEPLOY.md                   # 部署包解压后看这个（三步上线 + 三个坑）
├── bootstrap.php               # 入口引导：常量、自动加载、时区
├── router.php                  # 仅供 `php -S` 本地开发用
├── cli/
│   ├── reset-password.php      # 忘密码时的后门
│   ├── backup.php              # 命令行备份
│   └── notify.php              # 定时推送入口（挂每分钟 cron）
│
├── public/                     # ← 唯一的 web 根目录
│   ├── index.php               # 前端控制器：/uploads 流式输出、/api JSON、其余 SPA 外壳
│   └── assets/
│       ├── app.css
│       └── js/
│           ├── app.js          # 路由、导航、外壳
│           ├── api.js          # fetch 封装 + CSRF + 错误上报
│           ├── dom.js          # html 标签模板（自动转义）、toast、表单
│           ├── state.js        # 全局状态 + 元信息缓存
│           └── views/          # 10 个页面模块
│
├── src/                        # 后端，PSR-4 风格（RH\ 命名空间）
│   ├── Config.php  Db.php  Schema.php  Units.php
│   ├── Auth.php    Http.php Router.php Routes.php
│   ├── Images.php  Logs.php
│   ├── Repo/       # 领域逻辑：Recipe / Ingredient / Pantry / Plan / Shopping / User / Settings
│   └── Api/        # 接口层：每个资源一个文件
│
├── seed/                       # 预置数据：食材库、标签、示例菜谱
├── dist/                       # 交付产物（见下）
└── data/                       # ← 全部家当（数据库 + 图片 + 备份），挂卷在这
```

**为什么 `data/` 不在 `public/` 里**：web 根目录是 `public/`，
数据库和上传的原图都在 docroot 之外，浏览器没法直接访问。
图片是通过 `/uploads/xxx.webp` 请求 `index.php` 再流式吐出去的，
路径做白名单校验（只允许 `年/月/16位hex(.t).webp`），穿越攻击进不来；
按库里的路径找不到时，会再翻一下 `data/uploads/` 根目录的同名文件——
方便那些拿文件管理器手动塞图的人。

---

## 常见问题

**Q：打开显示「数据库初始化失败」**
`data/` 目录不可写。容器部署时多半是挂载卷属主不对，按上面 `PUID`/`PGID` 那节处理。
或者宿主机目录先 `mkdir -p data && chmod 777 data` 应急一下。

**Q：上传图片提示超出大小**
改 `RH_UPLOAD_MAX_MB`，重启容器即可，**不需要动 php.ini**——
入口脚本会按这个值重新生成 PHP 的上传上限。
如果前面有 Nginx，别忘了同时调大 `client_max_body_size`。

**Q：图片显示不出来 / 全是裂图**

三种情况，对号入座：

1. **反代或 CDN 把 `/uploads/` 拦下了** —— 让 `/uploads/` 和 `/api/` 走同一套转发规则。
2. **图放的位置和库里记的对不上** —— 库里存的是 `2026/10/xxx.webp`，文件却摊在
   `data/uploads/` 根下（用文件管理器传图时很常见）。程序会**自动翻一下根目录的同名文件**，
   所以直接摊在根下也能显示；想彻底规整就把文件挪进 `data/uploads/年/月/`。
3. **文件名不是规范名** —— 只认 `16 位十六进制.webp`（如 `5cdfe7ef7c52d664.webp`）
   和它的缩略图 `5cdfe7ef7c52d664.t.webp`。自己重命名成中文名或其它格式不会被吐出来。

> 手动往 `data/uploads/` 塞图**不会**让菜谱自动带上封面——封面是菜谱记录里的一个字段。
> 要么在编辑页「选封面」上传（程序自动命名并关联），要么导入带你这些文件名的备份/导入包。

**Q：更新了代码 / 升级了版本，页面还是老样子**

浏览器缓存了旧的 JS / CSS。静态资源默认带长缓存（容器里的 Apache 给 JS 7 天，
宝塔的 Nginx 默认也给 12 小时），所以「文件传上去了但界面没变」很常见。

按顺序试：

1. **强刷一次**：`Ctrl + F5`（macOS 是 `Cmd + Shift + R`）。这一步能解决绝大多数情况。
2. 前端资源带了 `?v=<文件修改时间>` 的指纹，**强刷后**会自动拿到新版本，
   不用手工清缓存。
3. 如果前面挂了 CDN（Cloudflare / 腾讯云 EdgeOne 等），要去控制台**刷新缓存**，
   否则 CDN 会把旧 JS 继续吐给访客。
4. 确认服务器上真的是新文件：看 `bootstrap.php` 里的 `RH_VERSION`，
   或者打开 `/healthz` 看版本号。

**Q：登录一会儿就掉**
`RH_SESSION_DAYS` 控制有效期，默认 30 天。如果走 HTTPS 反代后频繁掉线，
检查 `X-Forwarded-Proto` 有没有传对——传错会导致 Cookie 的 Secure 标记不对。

**Q：忘了管理员密码**
```bash
docker compose exec recipe-hub php cli/reset-password.php --list
docker compose exec recipe-hub php cli/reset-password.php 你的用户名 新密码
```

**Q：备份文件多大算正常**
正常。图片转 WebP 后，一张手机拍的照片约 150KB，几千张也就几百 MB。
数据库本身很小——几万条菜谱也就几十 MB。

**Q：恢复时提示「文件太大」**
整站包含图片，几百 MB 很常见。三层限制要一起放开：
- 程序侧：`RH_RESTORE_MAX_MB`（默认 512）
- PHP 侧：`upload_max_filesize` / `post_max_size`（镜像入口脚本已按上面那条自动放开）
- 反代侧：nginx 的 `client_max_body_size`（宝塔建站顺手改一下）

实在传不上去，用「恢复 / 换服务器」里的命令行方式，绕开 HTTP 上传。

**Q：能多个人同时用吗**
能。一个实例就是一个「家」，多人共用同一套菜谱、库存、计划。
权限靠角色区分。菜谱数量到几万条之前，SQLite 完全够。

**Q：想要小程序 / App**
这一版**只做 Web**（响应式，手机浏览器体验按移动优先做的，
底部有 tabbar，加了「添加到主屏幕」就是 PWA 的样子）。
路线图上小程序排在后面，先把 Web 端跑稳。

---

## 技术栈与取舍

| 选择 | 为什么 |
| --- | --- |
| PHP 8.3 + Apache | 单容器、无进程管理、`php:8.3-apache` 官方镜像开箱即用，家用服务器上没什么运维负担 |
| SQLite（WAL） | 一个文件就是全部数据，备份 = 拷贝，迁移 = 拷贝。家用规模（几个人、几千条菜谱）不需要客户端-服务端数据库 |
| 原生 ES module | 没有构建链，改一行刷新就见效。这个体量的前端，打包工具带来的是负担不是收益 |
| hash 路由 | `#/recipes/12`。部署到任何路径都不用配服务端重写，反代也不挑 |
| 图片一律转 WebP | 手机原图 4MB → 150KB，几年下来图片总量可控，且自带缩略图 |
| 不用 FTS5 做中文搜索 | SQLite 的 FTS5 中文分词要额外装扩展且效果一般。改成汇总列 + LIKE 多字段匹配，依赖零、行为可预测 |

没有前端框架，没有 ORM，没有 Composer 依赖。整个应用是「拷过去就能跑」的形态，
这也是它能在最低配的服务器上跑得很轻松的原因。

---

## 路线图

排在前面的，是想做还没做的：

- [ ] 小程序 / App（Web 端先跑稳；现在「添加到主屏幕」已经能当 PWA 用）
- [ ] 更多菜谱导入格式（已支持自家 JSON 和 Mealie 导出）
- [ ] 营养估算（热量、三大营养素，中餐食材库对不上就先不做）
- [ ] 界面多语言（目前只有简体中文）
- [ ] 把测试脚本整理进仓库（现在还躺在工作区里没入库）

有别的想法欢迎开 issue 聊。

---

## 参与

这是个给自己家做的小工具，能帮到别人更好。

- 发现问题 → 开 issue，附**复现步骤 + PHP 版本 + 部署方式（Docker / 宝塔 / 裸 PHP）**
- 想改代码 → fork 后提 PR，改动尽量小、说清楚为什么这么改
- 本地开发不用装 Docker：

```bash
php -S 127.0.0.1:8000 -t public router.php   # 数据落在 ./data，跑完就有一个可用的站
```

改完记得确认安装向导、菜谱增删改、采购清单这几条主流程还能走通。

---

## 许可

[MIT](LICENSE)。随便用、随便改、随便商用，保留版权声明即可。
