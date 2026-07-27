<?php

// Script corrigido para contagem de Leases DHCP no pfSense (Múltiplas Pools suportadas)

$action = $argv[1] ?? 'discovery';
$pool_target = $argv[2] ?? '';

// Carrega as configurações de DHCP do pfSense
require_once("config.inc");
require_once("util.inc");
require_once("interfaces.inc");

$leases_file = "/var/dhcpd/var/db/dhcpd.leases";
if (!file_exists($leases_file)) {
    echo json_encode([]);
    exit;
}

// Lógica de Descoberta (Discovery)
if ($action == 'discovery') {
    $discovery = ['data' => []];
    if (is_array($config['dhcpd'])) {
        foreach ($config['dhcpd'] as $iface => $dhcpif) {
            // Verifica se a interface está ativa e possui range principal OU pools adicionais
            if (isset($dhcpif['enable']) && (isset($dhcpif['range']) || isset($dhcpif['pool']))) {
                
                // Função nativa do pfSense para pegar o nome amigável da interface
                $friendly_name = convert_friendly_interface_to_friendly_descr($iface);
                
                // Se falhar ou vier vazio, usa o ID interno em maiúsculo por garantia
                if (empty($friendly_name)) {
                    $friendly_name = strtoupper($iface);
                }

                $discovery['data'][] = [
                    '{#POOL}' => $iface,
                    '{#POOLNAME}' => $friendly_name
                ];
            }
        }
    }
    echo json_encode($discovery);
    exit;
}

// Lógica para pegar os dados de um Pool específico (agregando interface inteira)
if ($action == 'pool_stats' && !empty($pool_target)) {
    $dhcpif = $config['dhcpd'][$pool_target] ?? null;
    if (!$dhcpif) { echo "0"; exit; }

    $ranges = [];
    $total_capacity = 0;

    // 1. Coleta o range principal (se existir)
    if (isset($dhcpif['range']['from']) && isset($dhcpif['range']['to'])) {
        $ranges[] = [
            'from' => ip2long($dhcpif['range']['from']),
            'to'   => ip2long($dhcpif['range']['to'])
        ];
    }

    // 2. Coleta os ranges das pools adicionais (se existirem)
    if (isset($dhcpif['pool']) && is_array($dhcpif['pool'])) {
        foreach ($dhcpif['pool'] as $pool) {
            if (isset($pool['range']['from']) && isset($pool['range']['to'])) {
                $ranges[] = [
                    'from' => ip2long($pool['range']['from']),
                    'to'   => ip2long($pool['range']['to'])
                ];
            }
        }
    }

    // 3. Calcula a capacidade total somando todos os ranges mapeados
    foreach ($ranges as $r) {
        $total_capacity += ($r['to'] - $r['from']) + 1;
    }

    // 4. Lê o arquivo de leases para contar os ativos
    $leases_content = file_get_contents($leases_file);
    preg_match_all('/lease\s+([0-9\.]+)\s+\{.*?binding state active;.*?\}/s', $leases_content, $matches);
    
    $active_leases = 0;
    if (isset($matches[1])) {
        foreach ($matches[1] as $ip) {
            $ip_long = ip2long($ip);
            // Verifica se o IP lido pertence a ALGUM dos intervalos (ranges) da interface
            foreach ($ranges as $r) {
                if ($ip_long >= $r['from'] && $ip_long <= $r['to']) {
                    $active_leases++;
                    break; // Validação confirmada, pula para o próximo IP
                }
            }
        }
    }

    $metric = $argv[3] ?? 'total';
    if ($metric == 'total') {
        echo (string)$total_capacity;
    } elseif ($metric == 'used') {
        echo (string)$active_leases;
    }
    exit;
}
