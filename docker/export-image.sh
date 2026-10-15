#!/usr/bin/env bash
#
# Recipe Hub · 构建真镜像 + 真容器冒烟自检
#
# 在有 Docker 的机器上，解开 dist 里的构建上下文后执行本脚本。
#
#   ./export-image.sh            # 只构建出 recipe-hub:<版本> 到本地镜像库
#   ./export-image.sh --smoke    # 起临时容器跑自检：PHP 扩展逐个核 + healthz，然后自动销毁
#   ./export-image.sh --export   # 导出 recipe-hub-<版本>-image.tar.gz（目标机 docker load -i 即用）
#   ./export-image.sh --deploy   # docker compose up -d，把站点长期跑起来
#   ./export-image.sh --all      # = --smoke + --export（推荐：先验再说）
#
# 为什么本机不预先给你现成的镜像 tar：
# 这个镜像里 gd / exif 两个 PHP 扩展是编译出来的（图片缩放转 WebP、手机照片方向
# 纠正都靠它们），那一层只有真的构建环境才能保证。手工拼 tar 层能拼出「load 得
# 进去」的包，但拼不出「gd 确实编译成了」的保证 —— 与其发一个说不准的镜像，
# 不如在这里现场构建。详见 README「离线部署与镜像导出」。

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

# 版本号唯一事实来源：项目根 bootstrap.php 里的 RH_VERSION。
# 别在这里写死 —— 改了 bootstrap.php 却忘了改脚本，就会构建出 tag 与内容不符的镜像。
VERSION="$(sed -n "s/.*define('RH_VERSION',[[:space:]]*'\([^']*\)').*/\1/p" "$PROJECT_DIR/bootstrap.php" | head -1)"

if [ -z "$VERSION" ]; then
    echo "✗ 没能从 bootstrap.php 读到 RH_VERSION，拒绝构建。" >&2
    exit 1
fi

# 本地镜像名 / Docker Hub 镜像名都可用环境变量覆盖
IMAGE="${RH_IMAGE:-recipe-hub}"
HUB_IMAGE="${RH_HUB_IMAGE:-xyzbz/recipe-hub}"

DO_SMOKE=0
DO_EXPORT=0
DO_DEPLOY=0

usage() {
    cat <<USAGE
用法: export-image.sh [选项]

  (不带参数)  只构建出 ${IMAGE}:${VERSION} 到本地镜像库
  --smoke     起一个临时容器做自检：逐个核对 PHP 扩展、打 healthz、取首页状态码，
              然后自动销毁容器（不留痕）
  --export    导出 ${IMAGE}-${VERSION}-image.tar.gz，目标机 docker load -i 即用
  --deploy    docker compose up -d，把这个网站长期跑起来
  --all       --smoke + --export（推荐：先验了再导出）
  -h, --help  看这段说明

环境变量:
  RH_IMAGE       本地镜像名，默认 recipe-hub
  RH_HUB_IMAGE   Docker Hub 镜像名，默认 xyzbz/recipe-hub（构建时一并打 tag，方便直接 push）

USAGE
}

for arg in "$@"; do
    case "$arg" in
        --smoke)  DO_SMOKE=1 ;;
        --export) DO_EXPORT=1 ;;
        --deploy) DO_DEPLOY=1 ;;
        --all)    DO_SMOKE=1; DO_EXPORT=1 ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            echo "不认识的参数: $arg" >&2
            usage >&2
            exit 2
            ;;
    esac
done

if ! command -v docker >/dev/null 2>&1; then
    echo "✗ 这台机器上没找到 docker。" >&2
    echo "  本脚本要在装了 Docker 的机器上跑（你的服务器 / 虚拟机就行）。" >&2
    exit 1
fi

cd "$PROJECT_DIR"

echo "→ 构建 ${IMAGE}:${VERSION} （同时打 ${HUB_IMAGE}:${VERSION} 与 ${IMAGE}:latest）…"
docker build \
    -t "${IMAGE}:${VERSION}" \
    -t "${IMAGE}:latest" \
    -t "${HUB_IMAGE}:${VERSION}" \
    .
echo "✓ 构建完成"

# ------------------------------------------------------------------ 冒烟自检
#
# 这一环本机（没容器运行时）是永远验不了的，只能靠真容器。所以单独拎出来：
# 起容器 → 核扩展 → 打健康接口 → 取首页 → 销毁。

fail=0

if [ "$DO_SMOKE" -eq 1 ]; then
    echo ""
    echo "=== 冒烟自检（真容器）==="

    if ! command -v curl >/dev/null 2>&1; then
        echo "✗ 没找到 curl，健康检查跑不了。" >&2
        fail=$((fail + 1))
    else
        name="rh-smoke-$$"
        echo "→ 起临时容器 ${name} …"
        cid="$(docker run -d --rm --name "$name" -p 127.0.0.1::80 "${IMAGE}:${VERSION}")"
        # 兜底清理：脚本中途挂了也不留容器
        trap 'docker rm -f "$name" >/dev/null 2>&1 || true' EXIT

        port="$(docker port "$name" 80/tcp | head -1 | awk -F: '{print $NF}' | tr -d '[:space:]')"
        echo "  容器 ${cid:0:12} 已起，宿主机端口 ${port}"

        # 1) 扩展：这是「编译那层真的成了」的唯一证据
        echo "→ 核对 PHP 扩展 …"
        for ext in gd exif mbstring pdo_sqlite sqlite3 fileinfo session; do
            if docker exec "$name" php -m 2>/dev/null | tr -d '\r' | grep -qx "$ext"; then
                echo "    ✓ $ext"
            else
                echo "    ✗ $ext 缺失 —— 图片处理会炸，别拿这个镜像上线"
                fail=$((fail + 1))
            fi
        done

        # 2) 健康检查：等 Apache + PHP 真起来
        echo "→ 等 healthz …"
        healthy=""
        for _ in $(seq 1 30); do
            if curl -fsS "http://127.0.0.1:${port}/healthz" >/dev/null 2>&1; then
                healthy=1
                break
            fi
            sleep 1
        done

        if [ -n "$healthy" ]; then
            echo "    ✓ 健康检查通过：$(curl -fsS "http://127.0.0.1:${port}/healthz")"
        else
            echo "    ✗ 30 秒内 healthz 没通 —— 下面是容器日志最后 20 行："
            docker logs "$name" 2>&1 | tail -20 || true
            fail=$((fail + 1))
        fi

        # 3) 首页能出 SPA 外壳
        code="$(curl -s -o /dev/null -w '%{http_code}' "http://127.0.0.1:${port}/" || echo 000)"
        if [ "$code" = "200" ]; then
            echo "    ✓ 首页返回 200"
        else
            echo "    ✗ 首页状态码 ${code}（期望 200）"
            fail=$((fail + 1))
        fi

        echo "→ 清理临时容器 …"
        docker rm -f "$name" >/dev/null 2>&1 || true
        trap - EXIT

        if [ "$fail" -eq 0 ]; then
            echo "✓ 冒烟自检全过 —— 这个镜像是真能跑的"
        else
            echo "✗ 冒烟自检有 ${fail} 项没过，先别导出"
        fi
    fi
fi

# ------------------------------------------------------------------ 导出 / 部署

if [ "$DO_EXPORT" -eq 1 ]; then
    if [ "$fail" -ne 0 ]; then
        echo "✗ 自检没通过，拒绝导出坏镜像。" >&2
        exit 1
    fi

    out="${IMAGE}-${VERSION}-image.tar.gz"
    echo "→ 导出 ${out} …"
    # 两个 tag 一起存：load 到目标机后即带 Docker Hub 名字，不用再手动 docker tag
    docker save "${IMAGE}:${VERSION}" "${HUB_IMAGE}:${VERSION}" | gzip > "$out"
    ls -lh "$out"
    echo "✓ 拿到真镜像了。拷到目标机后：docker load -i ${out}"
    echo "  然后直接：docker push ${HUB_IMAGE}:${VERSION}"
fi

if [ "$DO_DEPLOY" -eq 1 ]; then
    echo "→ 启动容器 …"
    docker compose up -d
    docker compose ps
fi

if [ "$DO_EXPORT" -eq 0 ] && [ "$DO_DEPLOY" -eq 0 ] && [ "$DO_SMOKE" -eq 0 ]; then
    echo "✓ 镜像在本地了，可以跑："
    echo "    docker run -d -p 8080:80 -v ./data:/var/www/html/data ${IMAGE}:${VERSION}"
    echo "  推到 Docker Hub："
    echo "    docker push ${HUB_IMAGE}:${VERSION}"
fi

exit "$fail"
