<?php
// O3 pfSense updater. PHP >= 7.4. State can be read without loading pfSense APIs.
foreach (array(
    'PFZU_TARGET' => '/root/scripts/pfsense_zbx.php',
    'PFZU_STATE_DIR' => '/var/db/pfsense-zbx-update',
    'PFZU_PHP' => '/usr/local/bin/php',
    'PFZU_CURL' => '/usr/local/bin/curl',
    'PFZU_ORIGIN' => 'https://raw.githubusercontent.com/GBalde409/pfsense_zabbix_scripts/'
) as $name => $value) {
    if (!defined($name)) define($name, $value);
}

function pfzu_version($file) {
    $text = is_file($file) ? (string) file_get_contents($file) : '';
    if (preg_match('/define\s*\(\s*[\'\"]SCRIPT_VERSION[\'\"]\s*,\s*[\'\"]([^\'\"]+)/', $text, $m)) return $m[1];
    return '';
}

function pfzu_state() {
    $file = PFZU_STATE_DIR . '/status.json';
    $state = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    return is_array($state) ? $state : array(
        'code' => 3, 'state' => 'never', 'checked_at' => 0,
        'last_success' => 0, 'last_change' => 0, 'installed_version' => '',
        'installed_sha256' => '', 'expected_sha256' => '', 'message' => 'Ainda nao executado'
    );
}

function pfzu_write_state($state) {
    $state['installed_sha256'] = is_file(PFZU_TARGET) ? hash_file('sha256', PFZU_TARGET) : '';
    $state['installed_version'] = pfzu_version(PFZU_TARGET);
    $temp = tempnam(PFZU_STATE_DIR, '.status-');
    if ($temp === false) throw new RuntimeException('Nao foi possivel criar o status temporario');
    try {
        if (file_put_contents($temp, json_encode($state, JSON_UNESCAPED_SLASHES)) === false
            || !chmod($temp, 0644) || !rename($temp, PFZU_STATE_DIR . '/status.json')) {
            throw new RuntimeException('Nao foi possivel gravar o status');
        }
    } finally {
        if (is_file($temp)) unlink($temp);
    }
}

function pfzu_root() {
    if (function_exists('posix_geteuid')) return posix_geteuid() === 0;
    $lines = array();
    exec('/usr/bin/id -u', $lines, $exit);
    return $exit === 0 && trim(implode('', $lines)) === '0';
}

// Run without a shell, with an actual wall-clock bound on the child process.
function pfzu_run($command, $seconds) {
    $log = tempnam(PFZU_STATE_DIR, '.command-');
    if ($log === false) throw new RuntimeException('Nao foi possivel criar o log temporario');
    $process = null;
    try {
        $pipes = array();
        $process = proc_open($command, array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('file', $log, 'a'),
            2 => array('file', $log, 'a')
        ), $pipes);
        if (!is_resource($process)) throw new RuntimeException('Nao foi possivel iniciar o comando');
        $deadline = microtime(true) + $seconds;
        while (true) {
            $info = proc_get_status($process);
            if (!$info['running']) {
                if ($info['exitcode'] !== 0) {
                    $detail = preg_replace('/\s+/', ' ', (string) file_get_contents($log));
                    throw new RuntimeException('Comando falhou (' . $info['exitcode'] . '): ' . substr($detail, 0, 160));
                }
                return;
            }
            if (microtime(true) >= $deadline) throw new RuntimeException('Tempo limite do comando excedido');
            usleep(100000);
        }
    } finally {
        if (is_resource($process)) {
            $info = proc_get_status($process);
            if ($info['running']) {
                proc_terminate($process);
                $grace = microtime(true) + 2;
                do {
                    usleep(100000);
                    $info = proc_get_status($process);
                } while ($info['running'] && microtime(true) < $grace);
                if ($info['running']) proc_terminate($process, 9);
            }
            proc_close($process);
        }
        if (is_file($log)) unlink($log);
    }
}

function pfzu_update($url, $expected) {
    if (!pfzu_root()) throw new RuntimeException('A atualizacao exige root; status pode ser lido pelo agente');
    if (!is_dir(PFZU_STATE_DIR) && !mkdir(PFZU_STATE_DIR, 0755, true))
        throw new RuntimeException('Nao foi possivel criar o diretorio de status');
    if (!chmod(PFZU_STATE_DIR, 0755)) throw new RuntimeException('Nao foi possivel liberar leitura do status');
    if (!is_dir(dirname(PFZU_TARGET)) && !mkdir(dirname(PFZU_TARGET), 0755, true))
        throw new RuntimeException('Nao foi possivel criar o diretorio de scripts');
    $lock = fopen(PFZU_STATE_DIR . '/update.lock', 'c');
    if ($lock === false) throw new RuntimeException('Nao foi possivel abrir o lock');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return 2; }
    $candidate = false;
    $backup = null;
    $replaced = false;
    $state = pfzu_state();
    $state['code'] = 2;
    $state['state'] = 'running';
    $state['checked_at'] = time();
    $state['expected_sha256'] = $expected;
    $state['message'] = 'Verificando versao aprovada';
    try {
        pfzu_write_state($state);
        // Pin both origin and immutable commit; no token or branch name in URLs.
        if (strpos($url, PFZU_ORIGIN) !== 0
            || !preg_match('/^[a-f0-9]{40}\/pfsense_zbx\.php$/D', substr($url, strlen(PFZU_ORIGIN)))
            || !preg_match('/^[a-f0-9]{64}$/D', $expected)) {
            throw new RuntimeException('URL deve usar commit de 40 caracteres do repositorio aprovado; SHA-256 invalido');
        }
        $current = is_file(PFZU_TARGET) ? hash_file('sha256', PFZU_TARGET) : '';
        if ($current !== $expected) {
            $candidate = tempnam(dirname(PFZU_TARGET), '.pfsense-zbx-');
            if ($candidate === false) throw new RuntimeException('Nao foi possivel criar o arquivo temporario');
            pfzu_run(array(PFZU_CURL, '--fail', '--silent', '--show-error', '--location',
                '--proto', '=https', '--proto-redir', '=https', '--connect-timeout', '10',
                '--max-time', '45', '--max-filesize', '1048576', '--output', $candidate, $url), 50);
            clearstatcache(true, $candidate);
            if (filesize($candidate) > 1048576 || hash_file('sha256', $candidate) !== $expected)
                throw new RuntimeException('SHA-256 divergente ou tamanho invalido; script ativo preservado');
            pfzu_run(array(PFZU_PHP, '-l', $candidate), 15);
            if (pfzu_version($candidate) === '') throw new RuntimeException('Arquivo nao identifica SCRIPT_VERSION');
            $backupDir = dirname(PFZU_TARGET) . '/.pfsense-zbx-backups';
            if (!is_dir($backupDir) && !mkdir($backupDir, 0700)) throw new RuntimeException('Nao foi possivel criar backups');
            if (is_file(PFZU_TARGET)) {
                $backup = $backupDir . '/pfsense_zbx-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.php';
                if (!copy(PFZU_TARGET, $backup) || !chmod($backup, 0600))
                    throw new RuntimeException('Nao foi possivel guardar backup');
            }
            if (!chmod($candidate, 0644) || !rename($candidate, PFZU_TARGET))
                throw new RuntimeException('Nao foi possivel substituir o script');
            $replaced = true;
        }
        // Idempotent: repair the cron even if the script was copied by another tool.
        // This command installs a schedule and does not run a speedtest.
        pfzu_run(array(PFZU_PHP, PFZU_TARGET, 'speedtest_install'), 30);
        $state['code'] = 0;
        $state['state'] = $replaced ? 'updated' : 'current';
        $state['checked_at'] = $state['last_success'] = time();
        if ($replaced) $state['last_change'] = time();
        $state['message'] = $replaced ? 'Script atualizado e cron confirmado' : 'Script atual; cron confirmado';
        pfzu_write_state($state);
        // Keep three recovery copies; never touch unrelated files.
        $backups = glob(dirname(PFZU_TARGET) . '/.pfsense-zbx-backups/pfsense_zbx-*.php') ?: array();
        usort($backups, function($a, $b) { return filemtime($b) <=> filemtime($a); });
        foreach (array_slice($backups, 3) as $old) unlink($old);
        return 0;
    } catch (Throwable $error) {
        $message = $error->getMessage();
        if ($replaced) {
            // Restore the prior bytes when post-installation fails. Never execute the old cron command.
            if ($backup !== null) {
                $restore = tempnam(dirname(PFZU_TARGET), '.restore-');
                if ($restore !== false && copy($backup, $restore) && chmod($restore, 0644) && rename($restore, PFZU_TARGET)) {
                    $message .= '; arquivo anterior restaurado';
                } else {
                    if ($restore !== false && is_file($restore)) unlink($restore);
                    $message .= '; FALHA ao restaurar o arquivo anterior';
                }
            } elseif (is_file(PFZU_TARGET)) {
                unlink(PFZU_TARGET);
            }
        }
        $state['code'] = 1;
        $state['state'] = 'error';
        $state['checked_at'] = time();
        $state['message'] = substr($message, 0, 250);
        pfzu_write_state($state);
        fwrite(STDERR, $message . "\n");
        return 1;
    } finally {
        if ($candidate !== false && is_file($candidate)) unlink($candidate);
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (($argv[1] ?? '') === 'status') {
            echo json_encode(pfzu_state(), JSON_UNESCAPED_SLASHES) . "\n";
            exit(0);
        }
        if (($argv[1] ?? '') !== 'update' || count($argv) !== 4)
            throw new RuntimeException('Uso: php pfsense_zbx_update.php update URL SHA256 | status');
        exit(pfzu_update($argv[2], $argv[3]));
    } catch (Throwable $error) {
        fwrite(STDERR, $error->getMessage() . "\n");
        exit(1);
    }
}
