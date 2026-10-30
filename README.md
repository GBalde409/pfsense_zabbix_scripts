# Automações pfSense / Zabbix

Esta branch é uma origem independente para o script de monitoramento. `dhcp_leases.php` foi removido desta branch e permanece preservado em `main`, para atender à LLD existente. O PR #1 foi encerrado sem merge: não mescle esta branch diretamente à `main` enquanto ela excluir o DHCP.

O módulo abaixo distribui a versão aprovada de `pfsense_zbx.php` aos firewalls pelo Zabbix 6, sem novo UserParameter e sem executar speedtest durante a atualização.

## Arquivos

| Arquivo | Uso |
|---|---|
| `pfsense_zbx.php` | Interface de monitoramento corrigida: versão `0.24.7-o3.20261030.1`. |
| `pfsense_zbx_update.php` | Atualizador com verificação SHA-256, timeout, backup, rollback e status JSON. |
| `zabbix/template_pfsense_script_update_zabbix6.yaml` | Template complementar para importar no Zabbix 6.0, com 9 itens e 6 triggers. |
| `zabbix/bootstrap-approved.sh` | Comando completo para provisionamento inicial como root, com URLs e hashes desta versão. |
| `zabbix/update-command.sh` | Referência do comando embutido no item, ainda com macros Zabbix. |
| `SHA256SUMS` | Hashes do PHP principal e do atualizador. |
| `tests/` | Testes isolados; não precisam ser instalados nos firewalls. |

## Comportamento do speedtest

O cron diário é `0 3 * * *`, com intervalo mínimo de 20 horas por WAN entre resultados válidos. O script aceita testes somente entre 03h00 inclusive e 05h00 exclusive em `America/Sao_Paulo`. O cron segue o fuso local do firewall: confirme System > General Setup > Localization > Timezone e NTP nos pilotos.

Os testes são sequenciais e têm timeout real de 120 segundos por WAN, mais até dois segundos para encerramento antes de forçar a saída. A espera aleatória de 1–90 segundos foi mantida. Uma primeira madrugada pode ser pulada se o resultado antigo ainda tiver menos de 20 horas. Atualizar o arquivo não encerra uma execução antiga que já tenha sido iniciada.

A descoberta e os valores dos gateways não dependem do speedtest. A atualização chama `speedtest_install`, que instala o cron sem iniciar medição de banda. O cron de verificação de versões/pacotes foi mantido.

## Requisitos antes de habilitar

- pfSense com PHP >= 7.4 e curl. O executável de speedtest continua sendo o speedtest-cli de Python, com JSON `download/upload/ping`.
- Conectividade HTTPS e resolução DNS para `raw.githubusercontent.com`, sem token: o repositório é público.
- Zabbix agent com comandos remotos já permitidos, incluindo a chamada `system.run[...,nowait]` do template.
- **O comando precisa executar como root.** Este template não cria permissões de sudo e não usa sudo genérico. O item “UID de execução do agente” deve retornar `0`. Se retornar outro UID, provisione a permissão adequada pela automação administrativa do ambiente antes de habilitar esse host. Uma permissão restrita ao smartctl não cobre esta instalação.
- A versão do template usa itens Zabbix agent passivos. Para hosts exclusivamente ativos, adapte o tipo dos itens independentes conforme o padrão existente e valide no piloto.

## Compatibilidade com Firewall Zabbix agent

A atualização mantém o caminho `/root/scripts/pfsense_zbx.php`, os argumentos usados por `pfsense.value[*]` e o arquivo de configuração do agente. Assim, a verificação de existência do PHP e as verificações de `AllowRoot=1` e da linha do UserParameter continuam compatíveis. Elas não conferem o conteúdo ou o hash do PHP principal.

Mantenha esses itens de auditoria. O status de versão/hash do novo módulo complementa a existência do arquivo. Se uma ação ligada à trigger de ausência do script ainda baixar a versão antiga, atualize essa origem junto com o provisionamento. Ações do Zabbix não são incluídas no export de templates e precisam de revisão no servidor.

## Importar e testar em um piloto

1. Baixe `zabbix/template_pfsense_script_update_zabbix6.yaml` desta proposta.
2. No Zabbix 6, acesse Configuration > Templates > Import, selecione o YAML e importe as entidades necessárias. O nome é **Template O3 pfSense Script Update**.
3. Vincule-o primeiro a poucos hosts representativos das versões de pfSense e dos tipos de WAN usados. É complementar ao template principal; não exige substituir suas LLDs.
4. Confira em Monitoring > Latest data o item **pfSense script: UID de execucao do agente**. O retorno esperado é `0`.
5. Nas macros do **host piloto**, defina `{$PFSENSE.ZBX.UPDATE.ENABLED}` como `1`. O padrão do template é `0` e impede a distribuição até essa habilitação. Os itens de leitura continuam podendo coletar.
6. Execute “Check now / Execute now” no item **pfSense script: solicitar atualizacao**, ou aguarde o intervalo. O retorno `1` do item significa somente que a execução assíncrona foi solicitada.
7. Aguarde a conclusão e a coleta do item **pfSense script: status JSON**, que ocorre a cada cinco minutos. Pode usar “Check now” nesse item depois da atualização. Antes da primeira instalação, a ausência do arquivo de status é esperada.
8. Confirme `code=0`, versão `0.24.7-o3.20261030.1` e SHA-256 igual à macro aprovada. Quando houver acesso administrativo ao firewall, confirme também:

   ```sh
   /usr/local/bin/php /root/scripts/pfsense_zbx.php system script_version
   grep speedtest /etc/crontab
   ```

   Cron esperado: `0 3 * * * root /usr/local/bin/php /root/scripts/pfsense_zbx.php speedtest_cron`.

O bootstrap está no próprio item: ele baixa o atualizador para um temporário, verifica SHA-256 e sintaxe PHP e só então instala e executa. Não é necessário instalar manualmente um helper em cada um dos 200 firewalls quando o agente já atende aos requisitos.

## Distribuição e manutenção

Depois dos pilotos, vincule o template aos demais hosts em lotes. Para liberar a atualização, altere a macro no template (afeta os hosts que a herdam) ou sobrescreva a macro nos hosts selecionados em lotes, pela automação administrativa já usada no Zabbix. Macros herdadas podem ser sobrescritas individualmente por host.

A checagem inicial ocorre a cada hora. Após estabilizar o rollout, pode mudar `{$PFSENSE.ZBX.UPDATE.INTERVAL}` para `1d` e `{$PFSENSE.ZBX.UPDATE.MAX_AGE}` para `129600` (36 horas). Distribua as habilitações em lotes para evitar concentrar conexões. O script só é baixado quando o SHA-256 instalado difere do aprovado; o atualizador também só é baixado se necessário.

Quando o script já estiver atualizado, o atualizador apenas confirma o cron de forma idempotente e renova o status. Não cria outro backup nem altera o PHP principal. São mantidas até três cópias de recuperação, em `/root/scripts/.pfsense-zbx-backups/`.

O status fica em `/var/db/pfsense-zbx-update/status.json`, com permissão de leitura para o agente e escrita exclusiva do root. O JSON mestre tem histórico desativado, reduzindo armazenamento de texto no banco. Os campos necessários são itens dependentes com histórico de sete dias e sem trends.

| Código | Significado |
|---|---|
| 0 | Verificação bem-sucedida, com arquivo atual ou atualizado e cron confirmado. |
| 1 | Falha; veja o campo `message`. |
| 2 | Atualização em execução. |
| 3 | Ainda não executado, quando consultado pelo modo `status`. |

As seis triggers acompanham falta de root, falha, ausência de status, atraso de verificação, execução acima de dez minutos e hash diferente do aprovado. Elas respeitam a macro de habilitação. Falhas no bootstrap antes de o atualizador ser instalado aparecem pela ausência de status e/ou pelo UID, não por um falso “sucesso” do disparo.

## Nova implantação de firewall

Retire o download antigo de `http://url.o3utm.com.br/pfsense_zbx.php` do provisionamento, para que ele não volte a instalar a versão com cron de 15 minutos. O arquivo `zabbix/bootstrap-approved.sh` contém o comando completo, com HTTPS, URLs fixadas em commit e hashes aprovados. Incorpore esse conteúdo à automação de provisionamento que já executa como root; ele instala o helper e o PHP e confirma o cron, sem speedtest.

Também é possível deixar a instalação a cargo do template, desde que o novo host o receba já habilitado pela política de provisionamento. Enquanto o template estiver desativado por macro, ele não fará a instalação.

## Liberar uma versão futura

As URLs usam o commit imutável **04d1cec36b9d4c0d4b06a2edb056d13ca6a23599**, que contém os dois arquivos PHP testados. Não usam `main` nem executam automaticamente qualquer commit novo.

Depois de revisar/testar uma nova versão, altere no template as macros `SCRIPT.URL` e `SCRIPT.SHA256`. Se mudar o atualizador, altere também `UPDATER.URL` e `UPDATER.SHA256`. Use o caminho `https://raw.githubusercontent.com/GBalde409/pfsense_zabbix_scripts/COMMIT_DE_40_CARACTERES/pfsense_zbx.php` e o hash do arquivo dessa revisão. Atualize o comando de provisionamento junto com a versão liberada.

O atualizador aceita somente o repositório acima, commit com 40 caracteres hexadecimais e nome `pfsense_zbx.php`. Não há tokens nas macros ou nos arquivos.

## Falhas e recuperação

Falha de download, hash ou sintaxe preserva o arquivo instalado. Se a confirmação do cron falhar depois da troca, o atualizador tenta restaurar os bytes anteriores e registra o resultado. Esse rollback de arquivo não é uma restauração transacional de toda a configuração do pfSense: confirme o cron no piloto e mantenha backup da configuração.

Para pausar a distribuição, defina `UPDATE.ENABLED=0`. Isso não encerra um processo de atualização já iniciado e não reverte a correção noturna. Não use `cron_cleanup` para pausar atualizações: ele remove também o agendamento de verificação de pacotes e não desativa o mecanismo de atualização.

## Validação

- PHP 8.3.6: sintaxe dos dois PHPs aprovada; shell do bootstrap aprovado por `sh -n`.
- **39 verificações** do speedtest com includes pfSense simulados: janela, cache, lock, timeout, seleção de WANs e SMART.
- **25 verificações** do atualizador com downloads simulados: idempotência, backup, rollback, hash, PHP inválido, falha de download, lock e encerramento do processo externo.
- **21 verificações** do YAML/bootstrap: referências, hashes e commits, gate de habilitação, ausência de root, comando efetivamente executado em sandbox, download corrompido e PHP inválido.
- O esquema de exportação foi conferido com a estrutura do validador `C60XmlValidator` do Zabbix. **A importação em um Zabbix real e a execução em pfSense de produção continuam pendentes do piloto.** PHP 7.4/pfSense 2.8.1 não foram executados neste ambiente.

Para repetir em ambiente de desenvolvimento, com PHP, Python 3 e PyYAML disponíveis:

```sh
php -n tests/test_speedtest.php
PHP_BIN=php python3 tests/test_update.py
PHP_BIN=php python3 tests/test_template.py
```

Os testes usam arquivos locais e simuladores, sem medir banda e sem alterar firewalls. O relatório da revisão estática do script original consta também da entrega anterior.

## Licença e origem

`pfsense_zbx.php` deriva do projeto de Riccardo Bicelli e mantém seu cabeçalho e licença Apache 2.0. O texto da licença está em `LICENSES/Apache-2.0.txt`; veja `NOTICE.md`. A inclusão desse texto não altera a licença do arquivo de DHCP existente.
