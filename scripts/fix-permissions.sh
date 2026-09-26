#!/bin/bash
# ============================================================
# 修正运行期目录属主（config / storage / bootstrap/cache）
#
# 用途：
#   git 拉取的新文件属主是执行者（通常 root），而 PHP-FPM 多以别的用户
#   运行。后台保存配置会直接改写 config/v2board.php，属主不对就会报
#   file_put_contents ... Permission denied，且配置存不进去。
#
# 用法：
#   bash scripts/fix-permissions.sh                 # 自动探测
#   bash scripts/fix-permissions.sh nobody          # 指定用户名
#   bash scripts/fix-permissions.sh 65534           # 指定 UID
#   PHP_CONTAINER=php bash scripts/fix-permissions.sh   # 指定容器名
#
# PHP 跑在容器里时本脚本在宿主机执行，会自动进容器读取 fpm 用户。
# ============================================================

# 若被以 CRLF 传到服务器（FTP / 记事本编辑），先自愈再重跑，
# 否则 bash 会报 $'\r': command not found
if grep -q $'\r' "$0" 2>/dev/null; then
    sed -i 's/\r$//' "$0" && exec bash "$0" "$@"
fi

set -u

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WANT="${1:-}"

info() { echo "[info] $*"; }
warn() { echo "[warn] $*"; }

# ---------- 探测 PHP 运行用户 ----------
detect_php_user() {
  local u="" d c
  # 1) 宿主机上的 php-fpm 池配置
  for d in /usr/local/etc/php-fpm.d /etc/php-fpm.d /etc/php/*/fpm/pool.d; do
    [ -d "$d" ] || continue
    u=$(grep -rhE '^[[:space:]]*user[[:space:]]*=' "$d" 2>/dev/null | head -n1 \
        | sed 's/.*=[[:space:]]*//' | tr -d '[:space:]')
    [ -n "$u" ] && { echo "$u"; return; }
  done
  # 2) 正在运行的 worker 进程属主（排除 master 的 root）
  u=$(ps -eo user,args 2>/dev/null | grep -E 'php-fpm: pool|apache2|httpd' | grep -v grep \
      | awk '{print $1}' | grep -v '^root$' | sort | uniq -c | sort -rn | head -n1 | awk '{print $2}')
  [ -n "$u" ] && { echo "$u"; return; }
  # 3) PHP 在容器里：进容器读池配置
  if command -v docker > /dev/null 2>&1; then
    for c in ${PHP_CONTAINER:-} php php-fpm; do
      [ -n "$c" ] || continue
      docker inspect "$c" > /dev/null 2>&1 || continue
      u=$(docker exec "$c" sh -c 'grep -rhE "^[[:space:]]*user[[:space:]]*=" /usr/local/etc/php-fpm.d /etc/php-fpm.d 2>/dev/null | head -n1' 2>/dev/null \
          | sed 's/.*=[[:space:]]*//' | tr -d '[:space:]')
      if [ -n "$u" ]; then
        info "从容器 $c 读到 fpm 用户: $u"
        echo "$u"
        return
      fi
      # 池配置读不到就看容器内 worker 进程
      u=$(docker exec "$c" sh -c 'ps -eo user,args 2>/dev/null || ps aux' 2>/dev/null \
          | grep -E 'php-fpm: pool' | grep -v grep \
          | awk '{print $1}' | grep -v '^root$' | sort | uniq -c | sort -rn | head -n1 | awk '{print $2}')
      if [ -n "$u" ]; then
        info "从容器 $c 进程读到 fpm 用户: $u"
        echo "$u"
        return
      fi
    done
  fi
  echo ""
}

# ---------- 把用户名解析成数字 uid:gid ----------
# 挂载卷不做 UID 映射，所以数字 ID 在宿主机与容器间通用；
# 容器内的用户（如 alpine 的 nobody）在宿主机可能没有同名条目。
resolve_ids() {
  local who="$1" uid="" gid="" c
  if echo "$who" | grep -qE '^[0-9]+$'; then
    echo "$who:$who"
    return
  fi
  uid=$(id -u "$who" 2>/dev/null)
  gid=$(id -g "$who" 2>/dev/null)
  if [ -z "$uid" ] && command -v docker > /dev/null 2>&1; then
    for c in ${PHP_CONTAINER:-} php php-fpm; do
      [ -n "$c" ] || continue
      docker inspect "$c" > /dev/null 2>&1 || continue
      uid=$(docker exec "$c" id -u "$who" 2>/dev/null | tr -d '[:space:]')
      gid=$(docker exec "$c" id -g "$who" 2>/dev/null | tr -d '[:space:]')
      [ -n "$uid" ] && break
    done
  fi
  [ -n "$uid" ] || { echo ""; return; }
  [ -n "$gid" ] || gid="$uid"
  echo "$uid:$gid"
}

# ---------- 主流程 ----------
if [ -n "$WANT" ]; then
  USER_SPEC="$WANT"
  info "使用指定用户: $USER_SPEC"
else
  USER_SPEC=$(detect_php_user)
  if [ -z "$USER_SPEC" ]; then
    warn "未能探测到 PHP 运行用户。"
    warn "请查看 fpm 配置后手动指定，例如："
    warn "  docker exec php sh -c 'grep -rE \"^user\" /usr/local/etc/php-fpm.d/'"
    warn "  bash scripts/fix-permissions.sh nobody"
    exit 1
  fi
  info "探测到 PHP 运行用户: $USER_SPEC"
fi

IDS=$(resolve_ids "$USER_SPEC")
if [ -z "$IDS" ]; then
  warn "无法把 $USER_SPEC 解析为 uid:gid。可直接传数字 UID，例如："
  warn "  bash scripts/fix-permissions.sh 65534"
  exit 1
fi
UID_N="${IDS%%:*}"
GID_N="${IDS##*:}"

info "目标站点: $ROOT"
info "属主将设为 uid=$UID_N gid=$GID_N"

CHANGED=0
for p in config storage bootstrap/cache; do
  if [ ! -e "$ROOT/$p" ]; then
    warn "跳过（不存在）: $p"
    continue
  fi
  if chown -R "$UID_N:$GID_N" "$ROOT/$p" 2>/dev/null; then
    info "  ok: $p"
    CHANGED=$((CHANGED + 1))
  else
    warn "  失败: $p（需要 root 权限？）"
  fi
done

# storage 与 cache 需要目录可写，补一次权限位
for p in storage bootstrap/cache; do
  [ -d "$ROOT/$p" ] && chmod -R u+rwX,g+rwX "$ROOT/$p" 2>/dev/null
done
# config/v2board.php 由后台保存配置时改写，确保属主可写
[ -f "$ROOT/config/v2board.php" ] && chmod u+rw "$ROOT/config/v2board.php" 2>/dev/null

echo
if [ "$CHANGED" -gt 0 ]; then
  info "完成，已处理 $CHANGED 个目录。"
  info "验证（以 PHP 用户身份试写）："
  info "  docker exec -u $UID_N -w /var/www/$(basename "$ROOT") \${PHP_CONTAINER:-php} sh -c 'touch config/.t && rm config/.t && echo OK'"
else
  warn "没有任何目录被处理，请检查路径与权限。"
  exit 1
fi
