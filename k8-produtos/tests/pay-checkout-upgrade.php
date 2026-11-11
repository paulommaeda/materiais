<?php
/** 2.8.2 -> 2.8.3: checkout e transação distintos; identificação preservada apenas na descrição. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD') || !in_array(getenv('K8PROD_UPGRADE_PHASE'), ['seed','verify'], true)) exit("Somente banco _test e fase seed|verify.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
use K8Produtos\Support as S;
use K8Produtos\Service;
$checks = 0;
function checkout_upgrade_check($ok, $name) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $name); echo 'OK ' . (++$checks) . ': ' . $name . "\n"; }
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
$transaction_id = '20000000-0000-f111-91f2-000000000012';
if (getenv('K8PROD_UPGRADE_PHASE') === 'seed') {
    checkout_upgrade_check(K8PROD_VERSION === '2.8.2', 'reprodução executa o pacote publicado 2.8.2');
    update_option('k8prod_gateway', 'pay'); update_option('k8p_producao', '1');
    foreach (['client_id' => 'checkout-fixture-id', 'client_secret' => 'checkout-fixture-secret', 'aes_key' => '1234567890123456'] as $name => $value) update_option('k8prod_pay_' . $name, $value);
    $method = new ReflectionMethod(K8Produtos\Http::class, 'mutate'); if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    $saved = $method->invoke(null, 'save_product', 0, ['nome' => 'Material fictício de teste', 'turmas' => ['5 A'], 'valor_padrao' => '0.23', 'ativo' => 1, 'publicado' => 1,
        'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Cartao','Pix','Boleto'], 'cartao_parcelas' => 12]);
    $inserted = $wpdb->insert(S::table('pedidos'), ['id' => 12, 'produto_id' => $saved['produto_id'], 'ra' => 'CHECKOUT-PAID', 'nome_aluno' => 'Aluno de teste', 'nome_resp' => 'Responsável de teste', 'cpf_resp' => '52998224725', 'turma' => '5 A',
        'valor' => '0.23', 'origem' => 'pay_checkout', 'metodo' => 'Cartao', 'status' => 'gerado', 'status_pagamento' => 'Pendente', 'cartao_parcelas_max' => 12,
        'active_key' => hash('sha256', $saved['produto_id'] . ':CHECKOUT-PAID'), 'pagamento_referencia' => '30000000-0000-4000-8000-000000000012',
        'pay_checkout_id' => '10000000-0000-4000-8000-000000000012', 'pagamento_ambiente' => 'production', 'checkout_url' => 'https://pagamentos.meuk8.com.br/checkout/10000000-0000-4000-8000-000000000012']);
    S::must($inserted);
    $order = Service::order($saved['produto_id'], 12);
    update_option('k8prod_checkout_upgrade_order', (array) $order, false);
} else {
    checkout_upgrade_check(K8PROD_VERSION === '2.8.3' && get_option('k8prod_schema') === '2.8.3', 'pacote 2.8.3 instalado e ativado');
    $old = get_option('k8prod_checkout_upgrade_order');
    $order = Service::order((int) $old['produto_id'], 12);
    checkout_upgrade_check(array_intersect_key((array) $order, $old) === $old && $order->pay_descricao === null, 'instalação preserva integralmente os campos anteriores do pedido #12');
    checkout_upgrade_check(get_option('k8prod_gateway') === 'pay' && get_option('k8p_producao') === '1' && get_option('k8prod_pay_client_secret') === 'checkout-fixture-secret', 'provedor, ambiente e credenciais preservados');
}
$GLOBALS['checkout_upgrade_created'] = 0; $GLOBALS['checkout_upgrade_listed'] = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) use ($order, $transaction_id) {
    if (strpos($url, '/PagamentosAPI/') === false) return $pre;
    if (substr($url, -6) === '/token') return ['headers' => [], 'body' => '{"access_token":"checkout-fixture-token"}', 'response' => ['code' => 200, 'message' => 'OK']];
    $path = wp_parse_url($url, PHP_URL_PATH);
    $data = json_decode(openssl_decrypt(base64_decode($args['body']), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16)), true);
    $payment = ['Retorno' => 'OK', 'Identificador' => $transaction_id, 'Referencia' => null, 'NumeroPedido' => null,
        'ClienteDescricao' => 'Material fictício de teste — Pedido #12', 'ClienteCPFCNPJ' => $order->cpf_resp,
        'Valor' => 0.23, 'ValorPago' => 0.23, 'TipoPagamento' => 'cartao', 'Estado' => 'Aprovada'];
    if ($path === '/PagamentosAPI/v2/CriaTransacaoCheckout') { $GLOBALS['checkout_upgrade_created']++; throw new RuntimeException('Não recriar um pagamento já realizado.'); }
    if ($path === '/PagamentosAPI/api/ConsultaTransacaoReferencia' || ($path === '/PagamentosAPI/api/ConsultaTransacao' && $data['Identificador'] === $order->pay_checkout_id)) $response = ['Retorno' => 'ERRO', 'DetalhesErro' => 'Referência ou transação não localizada'];
    elseif ($path === '/PagamentosAPI/api/ConsultaTransacoes') { $GLOBALS['checkout_upgrade_listed']++; $response = ['Retorno' => 'OK', 'Transacoes' => [$payment]]; }
    elseif ($path === '/PagamentosAPI/api/ConsultaTransacao' && $data['Identificador'] === $transaction_id) $response = $payment;
    else throw new RuntimeException('Rota de consulta inesperada.');
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 10, 3);
if (getenv('K8PROD_UPGRADE_PHASE') === 'seed') {
    $error = null;
    try { Service::reconcile((int) $order->produto_id, 12, $transaction_id); } catch (Throwable $e) { $error = $e->getMessage(); }
    checkout_upgrade_check($error !== null && strpos($error, 'sem correspondência') !== false, '2.8.2 reproduz o bloqueio no vínculo manual com CPF/valor corretos e descrição do pedido');
    update_option('active_plugins', []);
    echo "Pedido pago de R$0,23 preservado para a atualização.\n";
    exit;
}
$result = Service::sync_order((int) $order->produto_id, 12);
checkout_upgrade_check($result->status_pagamento === 'Paga' && $result->pay_transacao_id === $transaction_id && $result->pay_checkout_id !== $transaction_id, 'consulta automática descobre o ID real e confirma o mesmo pagamento de R$0,23');
checkout_upgrade_check($GLOBALS['checkout_upgrade_listed'] > 0 && $GLOBALS['checkout_upgrade_created'] === 0, 'recuperação usa busca e consulta sem criar outra cobrança');
checkout_upgrade_check($result->pay_descricao === 'Material fictício de teste — Pedido #12' && $result->pagamento_referencia === $order->pagamento_referencia && (int) $result->cartao_parcelas_max === 12, 'descrição validada, referência antiga e limite de 12x preservados');
checkout_upgrade_check($wpdb->last_error === '', 'atualização e recuperação terminam sem erro SQL');
echo "$checks verificações da atualização de vínculo PAY aprovadas.\n";
