#!/usr/bin/env python3
"""Updater integration tests with local download fixtures; no firewall/network changes."""
import base64
import fcntl
import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time

PHP = os.environ.get('PHP_BIN', 'php')
SOURCE = Path(__file__).resolve().parents[1] / 'pfsense_zbx_update.php'
ORIGIN = 'https://raw.githubusercontent.com/GBalde409/pfsense_zabbix_scripts/'
URL = ORIGIN + 'a' * 40 + '/pfsense_zbx.php'
checks = 0


def check(condition, message):
    global checks
    assert condition, message
    checks += 1
    print('PASS', message)


def php_string(value):
    return "base64_decode('" + base64.b64encode(str(value).encode()).decode() + "')"


with tempfile.TemporaryDirectory(prefix='pfzu-tests-') as directory:
    root = Path(directory)
    target = root / 'scripts/pfsense_zbx.php'
    target.parent.mkdir()
    state_dir = root / 'state'
    old = b"<?php define('SCRIPT_VERSION', 'old');\n"
    new = b'''<?php
define('SCRIPT_VERSION', '0.24.7-o3.20261030.1');
if (($argv[1] ?? '') === 'speedtest_install') {
    file_put_contents(getenv('PFZU_TEST_INSTALLS'), "install\\n", FILE_APPEND);
    exit(getenv('PFZU_TEST_FAIL_INSTALL') === '1' ? 2 : 0);
}
'''
    target.write_bytes(old)
    fixture = root / 'fixture.php'
    fixture.write_bytes(new)
    calls = root / 'downloads'
    installs = root / 'installs'
    curl = root / 'curl'
    curl.write_text('''#!/usr/bin/env python3
import os, pathlib, shutil, sys
with open(os.environ['PFZU_TEST_DOWNLOADS'], 'a') as f: f.write('download\\n')
if os.environ.get('PFZU_TEST_CURL_FAIL') == '1': sys.exit(22)
shutil.copyfile(os.environ['PFZU_TEST_FIXTURE'], sys.argv[sys.argv.index('--output')+1])
''')
    curl.chmod(0o700)
    php_wrapper = root / 'php'
    # The production updater launches the current interpreter via this fixture.
    php_wrapper.write_text('#!/bin/sh\nexec ' + "'" + PHP.replace("'", "'\\''") + "' -n \"$@\"\n")
    php_wrapper.chmod(0o700)
    runner = root / 'run.php'
    constants = {'PFZU_TARGET': target, 'PFZU_STATE_DIR': state_dir,
                 'PFZU_PHP': php_wrapper, 'PFZU_CURL': curl}
    runner.write_text('<?php\n' + '\n'.join(
        "define('%s', %s);" % (key, php_string(value)) for key, value in constants.items()
    ) + '\nrequire ' + php_string(SOURCE) + ''';
if ($argv[1] === 'timeout') {
    try { pfzu_run(array('/bin/sleep', '4'), 1); exit(0); }
    catch (Throwable $e) { exit(1); }
}
exit(pfzu_update($argv[1], $argv[2]));
''')
    env = dict(os.environ, PFZU_TEST_FIXTURE=str(fixture),
               PFZU_TEST_DOWNLOADS=str(calls), PFZU_TEST_INSTALLS=str(installs))
    digest = hashlib.sha256(new).hexdigest()

    def update(url=URL, sha=digest, extra=None):
        return subprocess.run([PHP, '-n', str(runner), url, sha], env=dict(env, **(extra or {})),
                              capture_output=True, text=True, timeout=12)

    def state():
        return json.loads((state_dir / 'status.json').read_text())

    result = update()
    check(result.returncode == 0, 'first installation succeeds: ' + result.stderr)
    check(target.read_bytes() == new, 'approved bytes replace old script')
    check(state()['code'] == 0 and state()['state'] == 'updated', 'success is recorded separately from dispatch')
    check(state()['installed_version'] == '0.24.7-o3.20261030.1', 'installed version reported')
    check(state()['installed_sha256'] == digest, 'installed SHA-256 reported')
    backups = list(target.parent.glob('.pfsense-zbx-backups/*.php'))
    check(len(backups) == 1 and backups[0].read_bytes() == old, 'prior version backed up')
    check((state_dir / 'status.json').stat().st_mode & 0o777 == 0o644, 'status readable by monitoring agent')
    result = update()
    check(result.returncode == 0 and state()['state'] == 'current', 'same version is idempotent')
    check(calls.read_text().count('download') == 1, 'no repeated download for approved installed hash')
    check(len(list(target.parent.glob('.pfsense-zbx-backups/*.php'))) == 1, 'no duplicate backups')
    check(installs.read_text().count('install') == 2, 'cron repaired on unchanged content without a speedtest')
    last_success = state()['last_success']

    target.write_bytes(old)
    fixture.write_bytes(b'wrong download')
    result = update()
    check(result.returncode == 1 and target.read_bytes() == old, 'hash mismatch preserves active script')
    check(state()['code'] == 1 and state()['last_success'] == last_success, 'failure does not refresh last successful check')
    bad = b"<?php define('SCRIPT_VERSION', 'bad'); broken syntax!"
    fixture.write_bytes(bad)
    result = update(sha=hashlib.sha256(bad).hexdigest())
    check(result.returncode == 1 and target.read_bytes() == old, 'invalid PHP rejected before replacement')
    fixture.write_bytes(new)
    result = update(extra={'PFZU_TEST_CURL_FAIL': '1'})
    check(result.returncode == 1 and target.read_bytes() == old, 'download error preserves active script')
    result = update(extra={'PFZU_TEST_FAIL_INSTALL': '1'})
    check(result.returncode == 1 and target.read_bytes() == old, 'post-install failure restores original bytes')
    check('restaurado' in state()['message'], 'rollback reported')
    before = calls.read_text()
    result = update(url=ORIGIN + 'main/pfsense_zbx.php')
    check(result.returncode == 1 and calls.read_text() == before, 'mutable branch URL rejected before network access')
    result = update(url='https://other.example/' + 'a'*40 + '/pfsense_zbx.php')
    check(result.returncode == 1 and calls.read_text() == before, 'unapproved origin rejected')
    result = update(sha='invalid')
    check(result.returncode == 1, 'invalid checksum rejected')
    with open(state_dir / 'update.lock', 'a') as held:
        fcntl.flock(held, fcntl.LOCK_EX | fcntl.LOCK_NB)
        before_state = (state_dir / 'status.json').read_bytes()
        result = update()
        check(result.returncode == 2, 'parallel updater rejected')
        check((state_dir / 'status.json').read_bytes() == before_state, 'parallel invocation does not clobber running status')
    started = time.monotonic()
    result = subprocess.run([PHP, '-n', str(runner), 'timeout'], env=env, timeout=6)
    check(result.returncode == 1 and time.monotonic()-started < 4, 'external timeout terminates child')
    check(not list(state_dir.glob('.command-*')), 'temporary process logs removed')
    check(not [p for p in target.parent.glob('.pfsense-zbx-*') if p.is_file()], 'download temporary files removed')

print('TOTAL', checks, 'checks passed')
