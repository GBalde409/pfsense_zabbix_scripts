<?php

// Script corrigido para contagem de Leases DHCP no pfSense
// Implementado Buffer de Saída para evitar quebras de linha (\n\n) vindas do pfSense

$action = $argv[1] ?? 'discovery';
$pool_target = $argv[2] ?? '';

// Inicia o buffer de saída. Tudo o que os arquivos carregados abaixo
// tentarem cuspir na tela ficará guardado na memória em vez de ir para o Zabbix.
ob_start();

// Carrega as configurações de DHCP do pfSense
require_once("config.inc");
require_once("util.inc");
require_once("interfaces.inc");

// Limpa absolutamente tudo o que os arquivos acima geraram na tela (incluindo \n\n)
ob_clean();

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
            if (isset($dhcpif['enable']) && isset($dhcpif['range'])) {
                
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

// Lógica para pegar os dados de um Pool específico
if ($action == 'pool_stats' && !empty($pool_target)) {
    $dhcpif = $config['dhcpd'][$pool_target] ?? null;
    if (!$dhcpif) { 
        echo "0"; 
        exit; 
    }

    // Calcula o tamanho total do Pool
    $from = ip2long($dhcpif['range']['from']);
    $to = ip2long($dhcpif['range']['to']);
    $total_capacity = ($to - $from) + 1;

    // Lê o arquivo de leases para contar os ativos
    $leases_content = file_get_contents($leases_file);
    preg_match_all('/lease\s+([0-9\.]+)\s+\{.*?binding state active;.*?\}/s', $leases_content, $matches);
    
    $active_leases = 0;
    if (isset($matches[1])) {
        foreach ($matches[1] as $ip) {
            $ip_long = ip2long($ip);
            if ($ip_long >= $from && $ip_long <= $to) {
                $active_leases++;
            }
        }
    }

    $metric = $argv[3] ?? 'total';
    
    // O trim() garante que nenhuma quebra de linha residual seja enviada
    if ($metric == 'total') {
        echo trim((string)$total_capacity);
    } elseif ($metric == 'used') {
        echo trim((string)$active_leases);
    }
    exit;
}
