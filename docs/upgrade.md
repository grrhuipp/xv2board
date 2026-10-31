# 升级与 PHP-FPM

生产 Web 运行方式统一为 Nginx + PHP-FPM，站点根目录指向 `public/`。Horizon、scheduler 和已启用的入口探活继续由现有进程管理器运行。本仓库不再安装或启动 Webman/Adapterman，也不会在保存配置后按缓存 PID 杀进程。`webman.php` 只保留旧脚本 `stop/status` 调用的兼容提示；发现旧进程仍存活时拒绝操作，不启动或停止任何服务。

## 从 Webman 切换

1. 备份站点、数据库、Nginx 和进程守护配置；确认 PHP-FPM 使用与 CLI 一致的 PHP 版本和扩展。当前锁文件在 PHP 8.3 上验证，缺失扩展或不兼容版本会使 Composer 提前失败，不能忽略平台要求。
2. 将此站点 Nginx 从反代 `127.0.0.1:6600` 改为现有 PHP-FPM 的 socket/端口，根目录为本站 `public/`。通过实际站点验证动态接口、登录和订阅。
3. 在原有 Supervisor/systemd/宝塔守护中停用此站点 Webman 的自动拉起，再停止其进程。不得杀死其他站点共用的进程。确认旧 PID 不再运行，然后升级；升级器不会主动停止 Webman。

## 一次性配置

常见的同机宝塔/PHP-FPM 部署可以直接运行新版升级器：未配置的步骤会自动寻找 `mariadb-dump`/`mysqldump`（含宝塔路径），从应用配置读取数据库连接；只在找到唯一一个与当前 CLI PHP 安装匹配且有权限操作的 PHP-FPM 主进程时平滑重载，并通过应用站点 URL 指向本机 Nginx 检查动态接口。数据库密码只传入备份子进程环境，不写进命令参数。

容器、跨机器数据库、独立进程管理或不明确的环境可复制 `.deploy.env.example` 到 `.deploy.env`，限制为部署用户可读。它是本机可信 shell 配置，不受 Git 管理。显式配置优先于自动识别：

- `V2BOARD_BACKUP_COMMAND`：通过现有数据库客户端或容器环境变量生成完整、一致的 SQL 备份，写入 `$BACKUP_DIR/database.sql.gz`。不要把密码放在命令文本中；备份不成功或 gzip 校验失败会阻止切换。
- `V2BOARD_RELOAD_COMMAND`：通过现有进程管理器平滑重载本站 PHP-FPM；已启用入口探活等常驻进程也须在此命令中平滑重启。使用真实服务名称，命令失败必须返回非零。
- `V2BOARD_HEALTHCHECK_COMMAND`：通过本站 Nginx/PHP-FPM 请求动态接口并核对成功状态，不能只请求静态页面。
- 可选 `V2BOARD_COMPOSER`：Composer 的 PHP/PHAR 文件路径。某些系统的 `composer` 是调用另一 PHP 版本的 shell 包装器，必须指定真实 PHAR。升级器始终通过当前 `php` 执行 Composer。

Docker 部署可在宿主机配置命令包装器，但 `php` 必须在应用容器中执行，传入的项目/暂存目录也必须能在容器内访问。不要直接照抄不同容器的路径。本项目源站在既有容器部署环境下完成相同的备份、迁移、重载及验收步骤。

## 执行

先部署本次新版升级器；老站的第一次切换应备份后聚焦合并更新，禁止运行老版 `update.sh`，禁止 `reset --hard`。之后：

```sh
bash update.sh --check   # 只检查 Git、目标锁文件和本机部署配置；会 fetch
bash update.sh           # 快进到 origin/master 后升级
# 已经检出审核通过的目标提交时：
bash update.sh --no-pull
```

### 旧脚本已经开始运行的首次过渡

旧 Bash 脚本可能先把 Git 更新到新版本，然后继续执行内存里原来的 `composer require joanhey/adapterman`、`webman.php stop`。因此同一次输出会混合旧脚本报错和新版数据库命令提示。已经执行的旧命令无法被新文件撤回；不要根据它的提示再次安装 Adapterman。

确认当前文件已是新版（开头含 `set -Eeuo pipefail`），再从站点目录正常执行新版脚本。旧脚本改写或删除的锁文件会自动备份并恢复，无需额外参数：

```sh
head -n 8 update.sh
git status --short
bash update.sh
```

升级器先保存当前锁文件（若文件已删除则记录恢复后的版本），再恢复当前提交内已审核的锁文件。若除 `composer.lock` 之外还有本地代码或 `composer.json` 改动，或存在暂存区改动，将停止并保留全部修改。`--check` 只报告待处理的锁文件差异，不修改文件、不创建备份。自动处理不清空数据库或覆盖站点配置；其余步骤照常暂存依赖、完整备份、迁移并验证服务。

`Module "mbstring" is already loaded` 表示当前 PHP 的 ini/扩展扫描重复加载了同一扩展，并不表示缺少 Webman。用 `php --ini` 定位实际配置；移除重复项应按该服务器的 PHP 配置处理，升级器不会擅自重写全局 PHP 配置。

### 切换 `--no-dev` 后提示 IgnitionServiceProvider 不存在

旧的 `bootstrap/cache/config.php`、`packages.php` 或 `services.php` 可能仍保存已移除包的 Provider。新版升级器在启动任何 Laravel 命令前检查所有 Provider 的失效引用，并识别损坏的生成缓存，先把相关文件保存到私有备份目录，再清理；切换 vendor 后总会在包发现前备份并清理这三个缓存，重建后恢复配置缓存属主和安全权限。处理不依赖具体包名，也不启动 Laravel 内核，因此可以修复 Artisan 已经无法启动的站点。

遇到 `Class "Facade\Ignition\IgnitionServiceProvider" not found`，拉取此修复后重新运行 `bash update.sh` 即可恢复升级，不需要安装开发依赖。`.env`、`config/v2board.php`、Redis 登录会话及其他缓存文件均不在此清理范围。原升级备份继续保留。

有未提交的受跟踪文件或本地分叉提交时会拒绝更新；原样保留修改，先自行审查合并。旧站忽略的本地锁文件会在切换前备份。更新先在独立目录安装锁定依赖、检查平台和目标代码的数据库兼容性，失败时不改动线上代码/vendor。随后备份代码、原 vendor、本地配置及数据库，再快进合并、切换 vendor、执行迁移、刷新配置、通知 Horizon 退出、重载 PHP-FPM。只有动态 HTTP 检查和 Horizon 恢复运行后才报告成功。不会清空 Redis 或统一注销用户。

备份及失败阶段会显示在输出中。切换后失败不会自动回滚数据库：DDL 和迁移可能已经提交。保持备份，修复具体失败步骤后重试；需要回滚时由部署人员对照 `revision-before`、代码/vendor/配置备份及数据库备份恢复。不要只回退代码而遗留不兼容数据库。不要从 `.upgrade-stage.*` 建立到线上 vendor 的软链接。

## 数据库兼容性

`php artisan v2board:update --check` 只读检查现代 V2Board 基础表及迁移文件。`php artisan v2board:update` 只运行带版本记录的 App/SmartRoute、订阅分析、入口、v2node 和 APP 凭据迁移。它不会单独重载服务，也不会声称完整部署已成功。迁移或配置刷新失败返回非零；重复执行跳过已登记的迁移。

早期缺少现代用户、订单、协议或日志基础表/字段的版本不支持直接跨级升级，会在改表前给出缺失项。需先在备份克隆的测试库按原版本转换路径升级到支持的基础结构，再执行本流程。历史 `database/update.sql` 包含破坏性转换，已退出自动升级入口，不能手工整份重放。

APP 凭据迁移也覆盖之前升级失败而遗漏字段的站点：已有账户兼容值为 0，新账户默认 1；已存在的 0/1 值和用户订阅 token 均保留。不会因重复升级而重新启用被撤销的旧 APP 登录。

## 验证

```sh
python3 tests/Integration/upgrade_script_test.py
vendor/bin/phpunit tests/Unit
```

脚本集成测试只操作临时 Git 仓库和模拟服务，覆盖依赖/备份/迁移/重载/健康检查失败、脏工作树、分叉、并发、首次及重复升级。数据库升级还应在独立 MySQL/MariaDB 测试库执行两次，核对用户、订阅凭据、已有兼容标记和迁移记录。
