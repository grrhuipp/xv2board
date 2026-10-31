#!/usr/bin/env bash
# Site-specific commands belong in .deploy.env (trusted shell configuration).
set -Eeuo pipefail
umask 077
phase=preflight
stage_dir=''
backup_dir=''
root=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
cd "$root"
fail() { printf 'Upgrade failed (%s): %s\n' "$phase" "$1" >&2; exit 1; }
cleanup() {
    local code=$?
    if [[ -n "$stage_dir" && -d "$stage_dir" ]]; then
        if [[ ! -d "$root/vendor" && -d "$stage_dir/previous-vendor" ]]; then
            mv -- "$stage_dir/previous-vendor" "$root/vendor"
        fi
        rm -rf -- "$stage_dir"
    fi
    if (( code != 0 )); then
        printf 'Upgrade stopped during %s. Backup: %s\n' "$phase" "${backup_dir:-not created}" >&2
    fi
}
trap cleanup EXIT
trap 'exit 1' ERR
prepare_bootstrap_cache() {
    php -r '
    try {
        require getcwd() . "/vendor/autoload.php";
        require_once getcwd() . "/app/Services/DatabaseUpgradeService.php";
        App\Services\DatabaseUpgradeService::prepareBootstrapCaches(getcwd(), $argv[1] ?? "", $argv[2] ?? null);
    } catch (Throwable $e) {
        fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage() . PHP_EOL : "Cannot prepare generated bootstrap caches.\n");
        exit(1);
    }' -- "$@"
}
deployment_runtime() {
    php -r '
    try {
        require getcwd() . "/vendor/autoload.php";
        require_once getcwd() . "/app/Services/DeploymentRuntime.php";
        $app = require getcwd() . "/bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        App\Services\DeploymentRuntime::execute($argv[1] ?? "", $argv[2] ?? null);
    } catch (Throwable $e) {
        fwrite(STDERR, $e instanceof RuntimeException ? $e->getMessage() . PHP_EOL : "Deployment environment check failed; configure .deploy.env.\n");
        exit(1);
    }' -- "$@"
}
main() {
    local no_pull=0 check=0 auto_backup=0 auto_reload=0 auto_health=0 target before file
    for option in "$@"; do
        case "$option" in
            --no-pull) no_pull=1 ;;
            --check) check=1 ;;
            --help) printf 'Usage: bash update.sh [--check] [--no-pull]\nSee docs/upgrade.md and .deploy.env.example.\n'; return ;;
            *) fail 'Unknown option; use --help.' ;;
        esac
    done
    for tool in git php tar gzip flock sha256sum; do command -v "$tool" >/dev/null || fail "Missing tool: $tool"; done
    [[ -d .git || -f .git ]] || fail 'A Git checkout is required.'
    exec 9>"$root/.upgrade.lock"
    flock -n 9 || fail 'Another upgrade is running.'
    if [[ -f .deploy.env ]]; then source ./.deploy.env; fi
    # Recover an interrupted --no-dev deployment before any Laravel command.
    # Healthy caches are left intact; broken caches are backed up first.
    if (( check == 0 )); then
        prepare_bootstrap_cache --repair-stale "${V2BOARD_BACKUP_ROOT:-"$(dirname "$root")/v2board-backups"}"
    fi
    if [[ -z ${V2BOARD_BACKUP_COMMAND:-} ]]; then
        deployment_runtime check-backup
        auto_backup=1
    fi
    if [[ -z ${V2BOARD_RELOAD_COMMAND:-} ]]; then
        deployment_runtime check-reload
        auto_reload=1
    fi
    if [[ -z ${V2BOARD_HEALTHCHECK_COMMAND:-} ]]; then
        deployment_runtime check-health
        auto_health=1
    fi
    local timeout=${V2BOARD_HEALTH_TIMEOUT:-90}
    [[ "$timeout" =~ ^[1-9][0-9]*$ ]] || fail 'Health timeout must be a positive integer.'
    local -a composer
    local composer_path=${V2BOARD_COMPOSER:-}
    if [[ -z "$composer_path" ]]; then
        if [[ -f composer.phar ]]; then composer_path="$root/composer.phar"
        else composer_path=$(command -v composer || true); fi
    fi
    [[ -f "$composer_path" ]] || fail 'Set V2BOARD_COMPOSER to an installed Composer PHP/PHAR file.'
    composer=(php "$composer_path")
    local composer_version
    composer_version=$("${composer[@]}" --version)
    [[ "$composer_version" == 'Composer version '* ]] || fail 'Composer is a shell wrapper. Set V2BOARD_COMPOSER to its PHP/PHAR file so it uses the same PHP.'
    git diff --cached --quiet && git diff --quiet -- . ':!composer.lock' || fail 'Tracked local changes must be reviewed and committed before upgrading.'
    if ! git diff --quiet -- composer.lock; then
        if (( check )); then
            printf 'Local composer.lock differs; a normal upgrade will back it up and restore the reviewed lock.\n'
        else
            git cat-file -e HEAD:composer.lock || fail 'Pull the reviewed upgrade files before restoring an old lock.'
            local legacy_root=${V2BOARD_BACKUP_ROOT:-"$(dirname "$root")/v2board-backups"} legacy_backup
            mkdir -p -- "$legacy_root"
            legacy_backup=$(mktemp -d "$legacy_root/legacy-lock-$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")
            if [[ -f composer.lock ]]; then cp -p -- composer.lock "$legacy_backup/composer.lock.before"; fi
            git show HEAD:composer.lock >"$legacy_backup/composer.lock.reviewed"
            cp -- "$legacy_backup/composer.lock.reviewed" composer.lock
            printf 'Legacy lock backed up and restored from the current commit: %s\n' "$legacy_backup"
        fi
    fi
    if [[ -s workerman.webman.php.pid ]]; then
        local legacy_pid
        legacy_pid=$(cat workerman.webman.php.pid)
        if [[ "$legacy_pid" =~ ^[1-9][0-9]*$ ]] && kill -0 "$legacy_pid" 2>/dev/null; then
            fail 'Legacy Webman PID is still alive. Switch Nginx to PHP-FPM and retire its process manager first (docs/upgrade.md).'
        fi
    fi
    before=$(git rev-parse HEAD)
    if (( no_pull )); then target=$before
    else
        git fetch origin master
        target=$(git rev-parse FETCH_HEAD)
        git merge-base --is-ancestor "$before" "$target" || fail 'Upgrade is not a fast-forward; resolve local commits first.'
    fi
    git cat-file -e "$target:composer.lock" || fail 'The target revision must contain a reviewed composer.lock.'
    [[ -f .env ]] || fail 'This is an updater; install the application first.'
    if (( check )); then
        printf 'Git and deployment configuration checks passed. Target: %s\n' "$target"
        return
    fi
    phase=dependency_staging
    stage_dir=$(mktemp -d "$root/.upgrade-stage.XXXXXX")
    git archive "$target" | tar -x -C "$stage_dir"
    # Build dependencies before changing the live checkout or its vendor directory.
    "${composer[@]}" --working-dir="$stage_dir" validate --no-check-publish
    "${composer[@]}" --working-dir="$stage_dir" install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader
    "${composer[@]}" --working-dir="$stage_dir" check-platform-reqs --no-dev
    cp -p -- .env "$stage_dir/.env"
    if [[ -f config/v2board.php ]]; then cp -p -- config/v2board.php "$stage_dir/config/v2board.php"; fi
    php "$stage_dir/artisan" v2board:update --check
    phase=backup
    local backup_root=${V2BOARD_BACKUP_ROOT:-"$(dirname "$root")/v2board-backups"}
    mkdir -p -- "$backup_root"
    backup_dir=$(mktemp -d "$backup_root/upgrade-$(date -u +%Y%m%dT%H%M%SZ).XXXXXX")
    chmod 700 "$backup_dir"
    export BACKUP_DIR="$backup_dir"
    git archive "$before" | gzip >"$backup_dir/code-before.tar.gz"
    printf '%s\n' "$before" >"$backup_dir/revision-before"
    for file in .env config/v2board.php composer.lock .deploy.env; do
        if [[ -f "$file" ]]; then mkdir -p -- "$backup_dir/local/$(dirname "$file")"; cp -p -- "$file" "$backup_dir/local/$file"; fi
    done
    if [[ -d vendor ]]; then tar -czf "$backup_dir/vendor-before.tar.gz" vendor; fi
    local -a protected=(.env)
    if [[ -f config/v2board.php ]]; then protected+=(config/v2board.php); fi
    sha256sum "${protected[@]}" >"$backup_dir/protected.sha256"
    if (( auto_backup )); then deployment_runtime backup "$BACKUP_DIR/database.sql.gz"
    else bash -Eeuo pipefail -c "$V2BOARD_BACKUP_COMMAND"; fi
    [[ -s "$backup_dir/database.sql.gz" ]] || fail 'Backup command must create $BACKUP_DIR/database.sql.gz.'
    gzip -t "$backup_dir/database.sql.gz"
    phase=activation
    git merge --ff-only "$target"
    if [[ -d vendor ]]; then mv -- vendor "$stage_dir/previous-vendor"; fi
    mv -- "$stage_dir/vendor" "$root/vendor"
    # Old config/packages/services manifests can refer to removed dev providers.
    prepare_bootstrap_cache --clear "$backup_dir"
    php artisan package:discover --ansi
    php artisan v2board:update
    prepare_bootstrap_cache --restore-ownership
    sha256sum --check --status "$backup_dir/protected.sha256" || fail 'Protected local configuration changed.'
    php artisan horizon:terminate
    phase=service_reload
    if (( auto_reload )); then deployment_runtime reload
    else bash -Eeuo pipefail -c "$V2BOARD_RELOAD_COMMAND"; fi
    phase=health_check
    local deadline=$((SECONDS + timeout))
    until php artisan horizon:status >/dev/null 2>&1 && {
        if (( auto_health )); then deployment_runtime health
        else bash -Eeuo pipefail -c "$V2BOARD_HEALTHCHECK_COMMAND"; fi
    } >/dev/null 2>&1; do
        (( SECONDS < deadline )) || fail 'PHP-FPM/HTTP or Horizon did not recover before the deadline.'
        sleep 2
    done
    printf '%s\n' "$target" >"$backup_dir/revision-complete"
    phase=complete
    printf 'Upgrade complete. PHP-FPM/HTTP and Horizon checks passed. Revision: %s\nBackup: %s\n' "$target" "$backup_dir"
}
main "$@"
