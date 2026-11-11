<?php
/** Reproduz vínculo pendente na 2.8.3 e confere a atualização nativa para 2.8.4. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Somente WordPress local descartável.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once WP_PLUGIN_DIR . '/k8-produtos/k8-produtos.php';
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\PayPayments;
use K8Produtos\Http;
use K8Produtos\Views;
$checks = 0;
function status_upgrade_check($ok, $message) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $message); echo 'OK ' . (++$checks) . ': ' . $message . "\n"; }
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
$old_id = '20000000-0000-f111-91f2-000000000012';
$paid_id = '30000000-0000-f111-91f2-000000000012';
$seed = getenv('K8PROD_UPGRADE_PHASE') === 'seed';
$mutate = new ReflectionMethod(Http::class, 'mutate'); if (PHP_VERSION_ID < 80100) $mutate->setAccessible(true);
if ($seed) {
    status_upgrade_check(K8PROD_VERSION === '2.8.3', 'reprodução usa o pacote publicado 2.8.3');
    update_option('k8prod_gateway', 'pay'); update_option('k8p_producao', '1');
    foreach (['client_id' => 'status-fixture-id', 'client_secret' => 'status-fixture-secret', 'aes_key' => '1234567890123456'] as $name => $value) update_option('k8prod_pay_' . $name, $value);
    $saved = $mutate->invoke(null, 'save_product', 0, ['nome' => 'Material fictício de teste', 'turmas' => ['5 A'], 'valor_padrao' => '0.23', 'ativo' => 1, 'publicado' => 1,
        'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Cartao','Pix','Boleto'], 'cartao_parcelas' => 12]);
    S::must($wpdb->insert(S::table('pedidos'), ['id' => 12, 'produto_id' => $saved['produto_id'], 'ra' => 'STATUS-PAID', 'nome_aluno' => 'Aluno de teste', 'nome_resp' => 'Responsável de teste', 'cpf_resp' => '52998224725', 'turma' => '5 A',
        'valor' => '0.23', 'origem' => 'pay_checkout', 'metodo' => 'Cartao', 'status' => 'gerado', 'status_pagamento' => 'Pendente', 'cartao_parcelas_max' => 12,
        'active_key' => hash('sha256', $saved['produto_id'] . ':STATUS-PAID'), 'pagamento_referencia' => '40000000-0000-4000-8000-000000000012', 'pagamento_ambiente' => 'production',
        'pay_checkout_id' => '10000000-0000-4000-8000-000000000012', 'pay_transacao_id' => $old_id, 'checkout_url' => 'https://pagamentos.meuk8.com.br/checkout/10000000-0000-4000-8000-000000000012']));
    $order = Service::order($saved['produto_id'], 12);
} else {
    status_upgrade_check(K8PROD_VERSION === '2.8.4' && get_option('k8prod_schema') === '2.8.4', 'pacote 2.8.4 instalado e ativado');
    $old = get_option('k8prod_status_upgrade_order'); $order = Service::order((int) $old['produto_id'], 12);
    status_upgrade_check(array_intersect_key((array) $order, $old) === $old, 'atualização preserva todos os campos do pedido anterior à nova consulta');
    status_upgrade_check(get_option('k8prod_gateway') === 'pay' && get_option('k8p_producao') === '1' && get_option('k8prod_pay_client_secret') === 'status-fixture-secret', 'provedor, ambiente e credenciais preservados');
}
$GLOBALS['status_upgrade_created'] = 0; $GLOBALS['status_upgrade_listed'] = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) use ($order, $old_id, $paid_id) {
    if (strpos($url, '/PagamentosAPI/') === false) return $pre;
    if (substr($url, -6) === '/token') return ['headers' => [], 'body' => '{"access_token":"status-fixture-token"}', 'response' => ['code' => 200, 'message' => 'OK']];
    $path = wp_parse_url($url, PHP_URL_PATH);
    $data = json_decode(openssl_decrypt(base64_decode($args['body']), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16)), true);
    $paid = ['Retorno' => 'OK', 'Identificador' => $paid_id, 'Referencia' => null, 'NumeroPedido' => null, 'ClienteDescricao' => 'Material fictício de teste — Pedido #12',
        'ClienteCPFCNPJ' => $order->cpf_resp, 'Valor' => 0.23, 'ValorPago' => 0.23, 'TipoPagamento' => 'cartao', 'Estado' => 'Aprovada'];
    $pending = $paid; $pending['Identificador'] = $old_id; $pending['Estado'] = 'Pendente'; $pending['ValorPago'] = 0;
    if ($path === '/PagamentosAPI/v2/CriaTransacaoCheckout') { $GLOBALS['status_upgrade_created']++; throw new RuntimeException('Não criar outro pagamento.'); }
    if ($path === '/PagamentosAPI/api/ConsultaTransacoes') { $GLOBALS['status_upgrade_listed']++; $response = ['Retorno' => 'OK', 'Transacoes' => [$pending, $paid]]; }
    elseif ($path === '/PagamentosAPI/api/ConsultaTransacao' && ($data['Identificador'] ?? '') === $paid_id) $response = $paid;
    elseif ($path === '/PagamentosAPI/api/ConsultaTransacao' && ($data['Identificador'] ?? '') === $old_id) $response = $pending;
    elseif ($path === '/PagamentosAPI/api/ConsultaTransacaoReferencia') $response = ['Retorno' => 'ERRO', 'DetalhesErro' => 'Referência não localizada'];
    else throw new RuntimeException('Consulta inesperada.');
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 10, 3);
$result = $mutate->invoke(null, 'sync', (int) $order->produto_id, ['pedido_id' => 12]);
$stored = Service::order((int) $order->produto_id, 12);
if ($seed) {
    status_upgrade_check($stored->status_pagamento === 'Pendente' && $stored->pay_transacao_id === $old_id && $GLOBALS['status_upgrade_listed'] === 0, '2.8.3 reproduz consulta sem erro, presa à tentativa antiga enquanto outra está aprovada');
    status_upgrade_check($result === [], '2.8.3 não informa a situação no resultado da consulta administrativa');
    update_option('k8prod_status_upgrade_order', (array) $stored, false); update_option('active_plugins', []);
    exit("Pedido #12 preservado para a instalação nativa da atualização.\n");
}
status_upgrade_check($result['pagamento'] === 'Paga' && $stored->status_pagamento === 'Paga' && $stored->pay_transacao_id === $paid_id, 'consulta registra Paga e o ID aprovado no mesmo pedido #12');
status_upgrade_check($GLOBALS['status_upgrade_listed'] > 0 && $GLOBALS['status_upgrade_created'] === 0 && $stored->pay_checkout_id === $order->pay_checkout_id && $stored->active_key === $order->active_key, 'recuperação mantém pedido e checkout sem criar cobrança');
$summary = PayPayments::consultation($stored);
status_upgrade_check($summary['estado'] === 'Aprovada' && $summary['situacao_pedido'] === 'Paga' && $summary['transacao_id'] === $paid_id && strpos($stored->resposta_raw, $order->cpf_resp) === false, 'última consulta registra aprovação e persistência sem guardar CPF');
$_GET = ['k8_product' => $order->produto_id, 'k8_tab' => 'orders']; $html = Views::dashboard_shortcode([]);
status_upgrade_check(strpos($html, 'k8-badge">Paga</span>') !== false && strpos($html, 'Última consulta PAY') !== false, 'painel exibe Paga após recarregar e mostra o resumo da consulta');
status_upgrade_check($wpdb->last_error === '', 'atualização e recuperação terminam sem erro SQL');
echo "$checks verificações da atualização de status PAY aprovadas.\n";
