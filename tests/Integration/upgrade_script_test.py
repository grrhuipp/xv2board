"""Exercise the real updater with disposable Git repositories and fake services.

Run on Linux: python3 tests/Integration/upgrade_script_test.py
No production services, credentials or database are used.
"""
import fcntl
import gzip
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[2]


class UpgradeScriptTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(prefix='v2board-upgrade-test-')
        self.base = Path(self.tmp.name)
        self.root = self.base / 'site'
        self.root.mkdir()
        self.bin = self.base / 'bin'
        self.bin.mkdir()
        self.events = self.base / 'events'
        self.env = dict(os.environ, PATH=str(self.bin) + ':' + os.environ['PATH'],
                        EVENTS=str(self.events), V2BOARD_HEALTH_TIMEOUT='1')
        for name in ['update.sh', 'init.sh']:
            shutil.copyfile(SOURCE / name, self.root / name)
        (self.root / '.gitignore').write_text('.env\n.deploy.env\n.upgrade*\nvendor/\nartifact/\n')
        (self.root / 'composer.json').write_text('{}')
        (self.root / 'composer.lock').write_text('{}')
        (self.root / 'artisan').write_text('fixture')
        (self.root / 'scripts').mkdir()
        (self.root / 'scripts/deploy-runtime.php').write_text('fixture runtime')
        (self.root / 'config').mkdir()
        (self.root / 'config/v2board.php').write_text('fixture configuration')
        self.git('init', '-b', 'master')
        self.git('config', 'user.name', 'Upgrade Fixture')
        self.git('config', 'user.email', 'fixture@example.test')
        self.git('add', '.')
        self.git('commit', '-qm', 'fixture base')
        self.original = self.git('rev-parse', 'HEAD').strip()
        (self.root / '.env').write_text('fixture environment')
        (self.root / 'vendor').mkdir()
        (self.root / 'vendor/old-marker').touch()
        (self.root / 'artifact').mkdir()
        (self.root / 'artifact/preserve').write_text('unrelated local artifact')
        (self.bin / 'composer').write_text('fixture php entry')
        (self.bin / 'composer').chmod(0o755)
        (self.bin / 'php').write_text('''#!/usr/bin/env bash
set -euo pipefail
if [[ "$1" == */scripts/deploy-runtime.php ]]; then
 echo "runtime $2" >>"$EVENTS"
 [[ "${FAIL_AT:-}" != auto_environment ]] || exit 27
 if [[ "$2" == backup ]]; then printf 'fixture SQL\\n' | gzip > "$3"; fi
 exit 0
fi
if [[ "$1" == */composer ]]; then
 shift
 if [[ "${1:-}" == --version ]]; then echo 'Composer version fixture'; exit 0; fi
 work="$PWD"
 for arg in "$@"; do [[ "$arg" != --working-dir=* ]] || work="${arg#*=}"; done
 echo "composer $*" >>"$EVENTS"
 if [[ " $* " == *' install '* ]]; then
  [[ "${FAIL_AT:-}" != dependency ]] || exit 23
  mkdir -p "$work/vendor"; touch "$work/vendor/new-marker"
 fi
 exit 0
fi
echo "php $*" >>"$EVENTS"
if [[ " $* " == *' v2board:update '* ]]; then
 if [[ " $* " == *' --check '* ]]; then [[ "${FAIL_AT:-}" != preflight ]] || exit 24
 else [[ "${FAIL_AT:-}" != migration ]] || exit 25; fi
fi
if [[ " $* " == *' v2board:install '* ]]; then [[ "${FAIL_AT:-}" != installation ]] || exit 26; fi
''')
        (self.bin / 'php').chmod(0o755)
        self.configure()

    def tearDown(self):
        self.tmp.cleanup()

    def configure(self, backup=None, reload=None, health=None):
        backup = backup or 'printf "fixture SQL\\n" | gzip > "$BACKUP_DIR/database.sql.gz"; echo backup >> "$EVENTS"'
        reload = reload or 'echo reload >> "$EVENTS"'
        health = health or 'echo health >> "$EVENTS"'
        # All fixture commands contain no single quotes.
        (self.root / '.deploy.env').write_text(
            f"V2BOARD_BACKUP_ROOT='{self.base}/backups'\n"
            f"V2BOARD_BACKUP_COMMAND='{backup}'\n"
            f"V2BOARD_RELOAD_COMMAND='{reload}'\n"
            f"V2BOARD_HEALTHCHECK_COMMAND='{health}'\n")

    def git(self, *args):
        return subprocess.check_output(['git', *args], cwd=self.root, stderr=subprocess.DEVNULL, text=True)

    def update(self, fail=None, args=('--no-pull',)):
        env = dict(self.env)
        if fail:
            env['FAIL_AT'] = fail
        return subprocess.run(['bash', 'update.sh', *args], cwd=self.root, env=env,
                              stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True, timeout=25)

    def assertStopped(self, result):
        self.assertNotEqual(0, result.returncode, result.stdout)
        self.assertNotIn('Upgrade complete.', result.stdout)

    def assertLiveUnchanged(self):
        self.assertEqual(self.original, self.git('rev-parse', 'HEAD').strip())
        self.assertTrue((self.root / 'vendor/old-marker').exists())
        self.assertFalse((self.root / 'vendor/new-marker').exists())

    def test_dependency_failure_leaves_live_code_vendor_and_database_untouched(self):
        self.assertStopped(self.update('dependency'))
        self.assertLiveUnchanged()
        self.assertNotIn('backup', self.events.read_text())
        self.assertNotIn('v2board:update', self.events.read_text())

    def test_dirty_checkout_is_preserved_and_rejected(self):
        (self.root / 'artisan').write_text('local modification')
        self.assertStopped(self.update())
        self.assertEqual('local modification', (self.root / 'artisan').read_text())
        self.assertLiveUnchanged()

    def test_unsupported_schema_stops_before_backup_and_activation(self):
        self.assertStopped(self.update('preflight'))
        self.assertLiveUnchanged()
        self.assertNotIn('backup', self.events.read_text())

    def test_backup_failure_stops_before_activation(self):
        self.configure(backup='exit 12')
        self.assertStopped(self.update())
        self.assertLiveUnchanged()

    def test_invalid_compressed_backup_is_rejected(self):
        self.configure(backup='echo invalid > "$BACKUP_DIR/database.sql.gz"')
        self.assertStopped(self.update())
        self.assertLiveUnchanged()

    def test_migration_failure_never_reloads_or_reports_success(self):
        self.assertStopped(self.update('migration'))
        self.assertNotIn('reload', self.events.read_text())
        self.assertTrue(list((self.base / 'backups').glob('*/database.sql.gz')))

    def test_reload_failure_is_not_success(self):
        self.configure(reload='exit 13')
        self.assertStopped(self.update())

    def test_failed_http_health_check_is_not_success(self):
        self.configure(health='exit 14')
        self.assertStopped(self.update())

    def test_success_and_repeat_preserve_config_and_unrelated_artifacts(self):
        for _ in range(2):
            result = self.update()
            self.assertEqual(0, result.returncode, result.stdout)
            self.assertIn('Upgrade complete.', result.stdout)
        self.assertEqual('fixture environment', (self.root / '.env').read_text())
        self.assertEqual('fixture configuration', (self.root / 'config/v2board.php').read_text())
        self.assertEqual('unrelated local artifact', (self.root / 'artifact/preserve').read_text())
        self.assertTrue((self.root / 'vendor/new-marker').exists())
        self.assertEqual(2, len(list((self.base / 'backups').glob('*/revision-complete'))))

    def test_concurrent_upgrade_is_rejected(self):
        with (self.root / '.upgrade.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            self.assertStopped(self.update())
        self.assertLiveUnchanged()

    def test_active_legacy_service_requires_fpm_migration_first(self):
        (self.root / 'workerman.webman.php.pid').write_text(str(os.getpid()))
        self.assertStopped(self.update())
        self.assertLiveUnchanged()

    def test_failed_automatic_detection_fails_before_changing_live_files(self):
        (self.root / '.deploy.env').unlink()
        self.assertStopped(self.update('auto_environment'))
        self.assertLiveUnchanged()

    def test_same_host_defaults_work_without_a_deploy_env(self):
        (self.root / '.deploy.env').unlink()
        result = self.update()
        self.assertEqual(0, result.returncode, result.stdout)
        self.assertIn('runtime backup', self.events.read_text())
        self.assertIn('runtime reload', self.events.read_text())
        self.assertIn('runtime health', self.events.read_text())

    def test_legacy_lock_is_backed_up_before_explicit_repair(self):
        (self.root / 'composer.lock').write_text('legacy resolver output')
        result = self.update(args=('--no-pull', '--repair-legacy-lock'))
        self.assertEqual(0, result.returncode, result.stdout)
        self.assertEqual('{}', (self.root / 'composer.lock').read_text())
        saved = list((self.base / 'backups').glob('legacy-lock-*/composer.lock.before'))
        self.assertEqual(1, len(saved))
        self.assertEqual('legacy resolver output', saved[0].read_text())

    def test_legacy_lock_repair_never_discards_other_local_changes(self):
        (self.root / 'composer.lock').write_text('legacy resolver output')
        (self.root / 'artisan').write_text('local patch')
        self.assertStopped(self.update(args=('--no-pull', '--repair-legacy-lock')))
        self.assertEqual('legacy resolver output', (self.root / 'composer.lock').read_text())
        self.assertEqual('local patch', (self.root / 'artisan').read_text())

    def test_diverged_history_is_not_reset(self):
        upstream = self.base / 'upstream.git'
        subprocess.run(['git', 'clone', '--bare', str(self.root), str(upstream)], check=True, capture_output=True)
        self.git('remote', 'add', 'origin', str(upstream))
        (self.root / 'local.txt').write_text('local commit')
        self.git('add', 'local.txt')
        self.git('commit', '-qm', 'local work')
        head = self.git('rev-parse', 'HEAD')
        self.assertStopped(self.update(args=()))
        self.assertEqual(head, self.git('rev-parse', 'HEAD'))

    def test_fast_forward_upgrade_uses_target_lock_and_retains_local_files(self):
        upstream = self.base / 'upstream.git'
        (self.root / 'release.txt').write_text('new release')
        self.git('add', 'release.txt')
        self.git('commit', '-qm', 'new release')
        target = self.git('rev-parse', 'HEAD')
        subprocess.run(['git', 'clone', '--bare', str(self.root), str(upstream)], check=True, capture_output=True)
        self.git('reset', '--hard', self.original)  # This disposable fixture only.
        self.git('remote', 'add', 'origin', str(upstream))
        result = self.update(args=())
        self.assertEqual(0, result.returncode, result.stdout)
        self.assertEqual(target, self.git('rev-parse', 'HEAD'))
        self.assertTrue((self.root / 'artifact/preserve').is_file())

    def test_install_failure_returns_nonzero(self):
        result = subprocess.run(['bash', 'init.sh'], cwd=self.root,
                                env=dict(self.env, FAIL_AT='installation'), capture_output=True, text=True)
        self.assertNotEqual(0, result.returncode)
        self.assertNotIn('Installation finished.', result.stdout)


if __name__ == '__main__':
    unittest.main(verbosity=2)
