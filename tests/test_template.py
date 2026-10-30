#!/usr/bin/env python3
"""Verify YAML references and execute the actual embedded bootstrap in a sandbox."""
import hashlib
import json
import os
from pathlib import Path
import re
import shlex
import subprocess
import tempfile
import uuid
import yaml

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('PHP_BIN', 'php')
data = yaml.safe_load((ROOT / 'zabbix/template_pfsense_script_update_zabbix6.yaml').read_text())['zabbix_export']
tpl = data['templates'][0]
macros = {m['macro']: m['value'] for m in tpl['macros']}
checks = 0


def check(value, message):
    global checks
    assert value, message
    checks += 1
    print('PASS', message)


def collect_uuids(value):
    if isinstance(value, dict):
        for name, child in value.items():
            if name == 'uuid':
                yield child
            else:
                yield from collect_uuids(child)
    elif isinstance(value, list):
        for child in value:
            yield from collect_uuids(child)


def valid_uuid4(value):
    # Zabbix 6 API requires 32 hex digits, version 4 and RFC 4122 variant.
    return isinstance(value, str) and re.fullmatch(r'[0-9a-f]{12}4[0-9a-f]{3}[89ab][0-9a-f]{15}', value) is not None


identifiers = list(collect_uuids(data))
check(len(identifiers) == 18 and all(valid_uuid4(value) and uuid.UUID(hex=value).version == 4 for value in identifiers), 'all 18 exported identifiers are UUIDv4 accepted by Zabbix 6')
check(len(identifiers) == len(set(identifiers)), 'all exported UUIDs are unique')
check(not valid_uuid4('714fe777a4ea5da0966dbefd25773772'), 'regression: previous UUIDv5 identifier is rejected')
check(data['version'] == '6.0' and 'groups' in data and 'template_groups' not in data, 'Zabbix 6 group/export format')
check(macros['{$PFSENSE.ZBX.UPDATE.ENABLED}'] == '0', 'automatic deployment disabled until pilot opt-in')
items = {i['key']: i for i in tpl['items']}
check(len(items) == len(tpl['items']), 'no duplicate item keys')
for key in ['SCRIPT', 'UPDATER']:
    url = macros['{$PFSENSE.ZBX.' + key + '.URL}']
    check(bool(re.search(r'/[a-f0-9]{40}/pfsense_zbx(?:_update)?\.php$', url)), key + ' URL uses immutable commit')
    filename = url.rsplit('/', 1)[1]
    check(hashlib.sha256((ROOT / filename).read_bytes()).hexdigest() == macros['{$PFSENSE.ZBX.' + key + '.SHA256}'], key + ' checksum matches local release')
for item in tpl['items']:
    if item['type'] == 'DEPENDENT':
        assert item['master_item']['key'] in items
check(True, 'dependent item masters resolve')
check(all('{$PFSENSE.ZBX.UPDATE.ENABLED}=1' in t['expression'] for t in data['triggers']), 'all alerts honor rollout gate')
check(items['vfs.file.contents[/var/db/pfsense-zbx-update/status.json]']['history'] == '0', 'JSON master does not retain text history')
key = tpl['items'][0]['key']
check(len(key) < 2048 and key.endswith(',nowait]'), 'dispatch key length and asynchronous mode')
body = key[len('system.run["'):-len('",nowait]')].replace('\\"', '"').replace('\\\\', '\\')
subprocess.run(['sh', '-n'], input=body, text=True, check=True)
check(True, 'decoded item shell command parses')

with tempfile.TemporaryDirectory(prefix='pfzu-bootstrap-') as directory:
    base = Path(directory)
    bindir = base / 'bin'
    bindir.mkdir()
    scripts = base / 'scripts'
    fixture = base / 'fixture.php'
    fixture.write_text('<?php file_put_contents(getenv("PFZU_BOOT_EXEC"), json_encode($argv)."\\n", FILE_APPEND);\n')
    expected = hashlib.sha256(fixture.read_bytes()).hexdigest()
    curl = bindir / 'curl'
    curl.write_text('''#!/usr/bin/env python3
import os, shutil, sys
with open(os.environ['PFZU_BOOT_DOWNLOADS'],'a') as f: f.write('download\\n')
shutil.copyfile(os.environ['PFZU_BOOT_FIXTURE'],sys.argv[sys.argv.index('--output')+1])
''')
    curl.chmod(0o700)
    checksum = bindir / 'sha256'
    checksum.write_text('''#!/usr/bin/env python3
import hashlib, pathlib, sys
print(hashlib.sha256(pathlib.Path(sys.argv[-1]).read_bytes()).hexdigest())
''')
    checksum.chmod(0o700)
    calls = base / 'exec'
    downloads = base / 'downloads'
    env = dict(os.environ, PFZU_BOOT_EXEC=str(calls), PFZU_BOOT_DOWNLOADS=str(downloads), PFZU_BOOT_FIXTURE=str(fixture))

    def run(enabled='1', root=True, updater_hash=expected):
        values = dict(macros, **{'{$PFSENSE.ZBX.UPDATE.ENABLED}': enabled, '{$PFSENSE.ZBX.UPDATER.SHA256}': updater_hash})
        command = body
        for name, value in values.items():
            command = command.replace(name, value)
        command = command.replace('/root/scripts', str(scripts))
        command = command.replace('PATH=/sbin:', 'PATH=' + str(bindir) + ':/sbin:')
        command = command.replace('/usr/local/bin/php', shlex.quote(PHP) + ' -n')
        if not root:
            command = command.replace('/usr/bin/id -u', 'printf 1001')
        return subprocess.run(['sh', '-c', command], env=env, text=True, capture_output=True, timeout=10)

    result = run(enabled='0')
    check(result.returncode == 0 and not scripts.exists(), 'disabled rollout performs no bootstrap writes')
    result = run(root=False)
    check(result.returncode == 1 and not scripts.exists(), 'non-root agent blocked before download')
    result = run()
    check(result.returncode == 0 and downloads.read_text().count('download') == 1, 'first bootstrap downloads verified updater')
    argv = json.loads(calls.read_text().splitlines()[0])
    check(argv[1:] == ['update', macros['{$PFSENSE.ZBX.SCRIPT.URL}'], macros['{$PFSENSE.ZBX.SCRIPT.SHA256}']], 'bootstrap passes approved script URL and digest')
    result = run()
    check(result.returncode == 0 and downloads.read_text().count('download') == 1, 'bootstrap does not download an unchanged updater')
    (scripts / 'pfsense_zbx_update.php').write_text('tampered updater')
    result = run()
    check(result.returncode == 0 and (scripts / 'pfsense_zbx_update.php').read_bytes() == fixture.read_bytes(), 'tampered local updater repaired before execution')
    (scripts / 'pfsense_zbx_update.php').unlink()
    before = calls.read_bytes()
    fixture.write_text('corrupt download')
    result = run()
    check(result.returncode != 0 and not (scripts / 'pfsense_zbx_update.php').exists() and calls.read_bytes() == before, 'hash mismatch never installs or executes downloaded bytes')
    fixture.write_text('<?php invalid PHP !')
    result = run(updater_hash=hashlib.sha256(fixture.read_bytes()).hexdigest())
    check(result.returncode != 0 and not (scripts / 'pfsense_zbx_update.php').exists(), 'bootstrap rejects invalid PHP even with matching digest')
    check(not list(scripts.glob('.pfzu-bootstrap.*')), 'bootstrap temporary files cleaned')

print('TOTAL', checks, 'checks passed')
