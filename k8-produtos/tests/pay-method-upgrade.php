<?php
/** Reproduz TipoPagamento não definido na 2.8.4 e verifica atualização para 2.8.5. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Somente WordPress local descartável.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once WP_PLUGIN_DIR . '/k8-produtos/k8-produtos.php';
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\Http;
use K8Produtos\PayPayments;
use K8Produtos\Views;
$checks = 0;
function method_upgrade_check($ok, $message) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $message); echo 'OK ' . (++$checks) . ': ' . $message . "\n"; }
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
$seed = getenv('K8PROD_UPGRADE_PHASE') === 'seed';
$mutate = new ReflectionMethod(Http::class, 'mutate'); if (PHP_VERSION_ID < 80100) $mutate->setAccessible(true);
if ($seed) {
    method_upgrade_check(K8PROD_VERSION === '2.8.4', 'reprodução usa o ZIP publicado 2.8.4');
    update_option('k8prod_gateway', 'pay'); update_option('k8p_producao', '1');
    foreach (['client_id' => 'method-fixture-id', 'client_secret' => 'method-fixture-secret', 'aes_key' => '1234567890123456'] as $name => $value) update_option('k8prod_pay_' . $name, $value);
    $saved = $mutate->invoke(null, 'save_product', 0, ['nome' => 'Material fictício de teste', 'turmas' => ['5 A'], 'valor_padrao' => '0.23', 'ativo' => 1, 'publicado' => 1,
        'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Cartao','Pix','Boleto'], 'cartao_parcelas' => 12]);
    $product = (int) $saved['produto_id'];
    foreach ([12,13] as $id) S::must($wpdb->insert(S::table('pedidos'), ['id' => $id, 'produto_id' => $product, 'ra' => 'METHOD-' . $id,
        'nome_aluno' => 'Aluno de teste', 'nome_resp' => 'Responsável de teste', 'cpf_resp' => '52998224725', 'turma' => '5 A', 'valor' => '0.23', 'origem' => 'pay_checkout',
        'metodo' => 'Cartao', 'status' => 'gerado', 'status_pagamento' => 'Pendente', 'cartao_parcelas_max' => 12, 'pagamento_ambiente' => 'production',
        'active_key' => hash('sha256', $product . ':METHOD-' . $id), 'pagamento_referencia' => sprintf('30000000-0000-4000-8000-%012d', $id),
        'pay_checkout_id' => sprintf('10000000-0000-4000-8000-%012d', $id), 'checkout_url' => 'https://pagamentos.meuk8.com.br/checkout/' . sprintf('10000000-0000-4000-8000-%012d', $id)]));
    update_option('k8prod_method_upgrade_product', $product);
} else {
    method_upgrade_check(K8PROD_VERSION === '2.8.5' && get_option('k8prod_schema') === '2.8.5', 'pacote 2.8.5 instalado e ativado');
    $product = (int) get_option('k8prod_method_upgrade_product');
    foreach ([12,13] as $id) { $old = get_option('k8prod_method_upgrade_order_' . $id); method_upgrade_check(array_intersect_key((array) Service::order($product, $id), $old) === $old, 'campos do pedido #' . $id . ' preservados antes da consulta'); }
    method_upgrade_check(get_option('k8prod_gateway') === 'pay' && get_option('k8p_producao') === '1' && get_option('k8prod_pay_client_secret') === 'method-fixture-secret', 'provedor, ambiente e credenciais preservados');
}
$payments = [];
foreach ([12,13] as $id) { $order = Service::order($product, $id); $payments[$id] = ['Retorno' => 'OK', 'Identificador' => sprintf('20000000-0000-f111-91f2-%012d', $id),
    'Referencia' => null, 'NumeroPedido' => null, 'ClienteDescricao' => 'Material fictício de teste — Pedido #' . $id, 'ClienteCPFCNPJ' => $order->cpf_resp,
    'Valor' => 0.23, 'ValorPago' => 0.23, 'Estado' => 'Aprovada', 'Tipo' => 'Cartao', 'TipoPagamento' => 'naodefinido']; }
$GLOBALS['method_upgrade_created'] = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) use ($payments) {
    if (strpos($url, '/PagamentosAPI/') === false) return $pre;
    if (substr($url, -6) === '/token') return ['headers' => [], 'body' => '{"access_token":"method-fixture-token"}', 'response' => ['code' => 200, 'message' => 'OK']];
    $path = wp_parse_url($url, PHP_URL_PATH);
    $data = json_decode(openssl_decrypt(base64_decode($args['body']), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16)), true);
    if ($path === '/PagamentosAPI/v2/CriaTransacaoCheckout') { $GLOBALS['method_upgrade_created']++; throw new RuntimeException('Não criar outra cobrança.'); }
    if ($path === '/PagamentosAPI/api/ConsultaTransacoes') $response = ['Retorno' => 'OK', 'Transacoes' => array_values($payments)];
    elseif ($path === '/PagamentosAPI/api/ConsultaTransacaoReferencia') $response = ['Retorno' => 'ERRO', 'DetalhesErro' => 'Referência não localizada'];
    elseif ($path === '/PagamentosAPI/api/ConsultaTransacao') { $found = array_values(array_filter($payments, static fn($p) => $p['Identificador'] === ($data['Identificador'] ?? ''))); $response = $found[0] ?? ['Retorno' => 'ERRO', 'DetalhesErro' => 'ID não localizado']; }
    else throw new RuntimeException('Consulta inesperada.');
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 10, 3);
if ($seed) {
    $error = null; try { Service::reconcile($product, 12, $payments[12]['Identificador']); } catch (Throwable $e) { $error = $e->getMessage(); }
    method_upgrade_check($error === 'A forma de pagamento confirmada pelo PAY não corresponde ao pedido.', '2.8.4 reproduz a recusa por método na consulta direta ao pagamento aprovado');
    $mutate->invoke(null, 'sync', $product, ['pedido_id' => 13]);
    $pending = Service::order($product, 13);
    method_upgrade_check($pending->status_pagamento === 'Pendente' && PayPayments::consultation($pending)['localizado'] === false, '2.8.4 descarta silenciosamente o cartão aprovado da busca automática');
    foreach ([12,13] as $id) update_option('k8prod_method_upgrade_order_' . $id, (array) Service::order($product, $id), false);
    update_option('active_plugins', []); exit("Dois pedidos preservados para a atualização nativa.\n");
}
$manual = $mutate->invoke(null, 'reconcile', $product, ['pedido_id' => 12, 'cobranca_id' => $payments[12]['Identificador']]);
method_upgrade_check($manual['pagamento'] === 'Paga' && Service::order($product, 12)->pay_transacao_id === $payments[12]['Identificador'], 'vínculo manual recupera o pagamento do pedido #12 sem erro de método');
$automatic = $mutate->invoke(null, 'sync', $product, ['pedido_id' => 13]);
$paid = Service::order($product, 13); $old13 = get_option('k8prod_method_upgrade_order_13');
method_upgrade_check($automatic['pagamento'] === 'Paga' && $paid->pay_transacao_id === $payments[13]['Identificador'], 'busca automática confirma Paga no pedido #13 com o ID correto');
method_upgrade_check($GLOBALS['method_upgrade_created'] === 0 && $paid->pay_checkout_id === $old13['pay_checkout_id'] && $paid->active_key === $old13['active_key'], 'recuperação preserva pedido e checkout sem emitir outra cobrança');
$summary = PayPayments::consultation($paid);
method_upgrade_check($summary['tipo_transacao'] === 'cartao' && $summary['tipo_pagamento'] === 'não definido' && strpos($paid->resposta_raw, '52998224725') === false, 'resumo explica os campos usados sem guardar CPF');
$_GET = ['k8_product' => $product, 'k8_tab' => 'orders']; $html = Views::dashboard_shortcode([]);
method_upgrade_check(substr_count($html, 'k8-badge">Paga</span>') === 2 && strpos($html, 'Tipo da transação: cartao') !== false, 'painel recarregado mostra ambos os pedidos como Paga e o instrumento confirmado');
method_upgrade_check($wpdb->last_error === '', 'instalação e recuperação terminam sem erro SQL');
echo "$checks verificações da atualização de método PAY aprovadas.\n";
