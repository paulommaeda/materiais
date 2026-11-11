<?php
/** Recupera, sem recriar, um cartão pago que a versão 2.8.1 não localizava pela referência. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD') || !in_array(getenv('K8PROD_UPGRADE_PHASE'), ['seed','verify'], true)) exit("Somente banco _test e fase seed|verify.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\PayPayments;
$checks = 0;
function return_upgrade_check($ok, $name) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $name); echo 'OK ' . (++$checks) . ': ' . $name . "\n"; }
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
if (getenv('K8PROD_UPGRADE_PHASE') === 'seed') {
    return_upgrade_check(K8PROD_VERSION === '2.8.1', 'reprodução executa o pacote 2.8.1 entregue anteriormente');
    update_option('k8prod_gateway', 'pay'); update_option('k8p_producao', '1');
    foreach (['client_id' => 'return-fixture-id', 'client_secret' => 'return-fixture-secret', 'aes_key' => '1234567890123456'] as $name => $value) update_option('k8prod_pay_' . $name, $value);
    $method = new ReflectionMethod(K8Produtos\Http::class, 'mutate'); if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    $saved = $method->invoke(null, 'save_product', 0, ['nome' => 'Produto retorno PAY', 'turmas' => ['5 A'], 'valor_padrao' => 2145, 'ativo' => 1, 'publicado' => 1,
        'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Cartao','Pix','Boleto'], 'cartao_parcelas' => 12]);
    S::must($wpdb->insert(S::table('pedidos'), ['produto_id' => $saved['produto_id'], 'ra' => 'RETURN-PAID', 'nome_aluno' => 'Aluno retorno', 'nome_resp' => 'Responsável retorno', 'cpf_resp' => '52998224725', 'turma' => '5 A',
        'valor' => 2145, 'origem' => 'pay_checkout', 'metodo' => 'Cartao', 'status' => 'gerado', 'status_pagamento' => 'Pendente', 'cartao_parcelas_max' => 12,
        'active_key' => hash('sha256', $saved['produto_id'] . ':RETURN-PAID'), 'pagamento_referencia' => 'bea00508-e99d-4727-9e2a-35ac6424dd66',
        'pay_checkout_id' => '12345678-1234-4234-9234-123456789012', 'pagamento_ambiente' => 'production', 'checkout_url' => 'https://pagamentos.meuk8.com.br/checkout/12345678-1234-4234-9234-123456789012']));
    $order = Service::order($saved['produto_id'], (int) $wpdb->insert_id);
    update_option('k8prod_return_upgrade_order', (array) $order, false);
} else {
    return_upgrade_check(K8PROD_VERSION === '2.8.2' && get_option('k8prod_schema') === '2.8.2', 'pacote corrigido instalado e ativado');
    $old = get_option('k8prod_return_upgrade_order');
    $order = Service::order((int) $old['produto_id'], (int) $old['id']);
    return_upgrade_check((array) $order === $old, 'instalação preserva o pedido pendente, checkout, referência, parcelas e trava de nova compra');
    return_upgrade_check(get_option('k8prod_gateway') === 'pay' && get_option('k8p_producao') === '1' && get_option('k8prod_pay_client_secret') === 'return-fixture-secret', 'atualização preserva provedor, ambiente e credenciais');
}
$GLOBALS['return_upgrade_creations'] = 0; $GLOBALS['return_upgrade_query'] = '';
add_filter('pre_http_request', static function ($pre, $args, $url) use ($order) {
    if (strpos($url, '/PagamentosAPI/') === false) return $pre;
    if (substr($url, -6) === '/token') return ['headers' => [], 'body' => '{"access_token":"return-fixture-token"}', 'response' => ['code' => 200, 'message' => 'OK']];
    $GLOBALS['return_upgrade_query'] = $url;
    if (strpos($url, 'CriaTransacaoCheckout') !== false) { $GLOBALS['return_upgrade_creations']++; throw new RuntimeException('Não recriar o pagamento já realizado.'); }
    $data = json_decode(openssl_decrypt(base64_decode($args['body']), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16)), true);
    if (wp_parse_url($url, PHP_URL_PATH) === '/PagamentosAPI/api/ConsultaTransacaoReferencia') $response = ['Retorno' => 'ERRO', 'DetalhesErro' => 'Referencia da Transação ' . PayPayments::reference($order) . ' não localizada'];
    else {
        if (($data['Identificador'] ?? '') !== $order->pay_checkout_id) throw new RuntimeException('Consulta por identificador inesperado.');
        $response = ['Retorno' => 'OK', 'Identificador' => $order->pay_checkout_id, 'Referencia' => null, 'NumeroPedido' => 'K8P-' . $order->id,
            'ClienteCPFCNPJ' => $order->cpf_resp, 'Valor' => 2145, 'ValorPago' => 2145, 'TipoPagamento' => 'cartao', 'Estado' => 'Aprovada'];
    }
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 10, 3);
if (getenv('K8PROD_UPGRADE_PHASE') === 'seed') {
    $error = null;
    try { Service::sync_order((int) $order->produto_id, (int) $order->id); } catch (Throwable $e) { $error = S::diagnostic($e); }
    return_upgrade_check($error !== null && strpos($error, 'não localizada') !== false, 'versão anterior reproduz referência não localizada mesmo com cartão aprovado');
    update_option('active_plugins', []);
    echo "Pedido pago preservado para a atualização.\n";
    exit;
}
$result = Service::sync_order((int) $order->produto_id, (int) $order->id);
return_upgrade_check($result->status_pagamento === 'Paga' && $result->pay_transacao_id === $order->pay_checkout_id && $result->checkout_url === null, 'consulta por ID recupera aprovação do mesmo pagamento existente');
return_upgrade_check(substr($GLOBALS['return_upgrade_query'], -strlen('/api/ConsultaTransacao')) === '/api/ConsultaTransacao', 'confirmação usa o contrato documentado de consulta por identificador');
return_upgrade_check($result->pagamento_referencia === $order->pagamento_referencia && $result->pay_checkout_id === $order->pay_checkout_id && (int) $result->cartao_parcelas_max === 12, 'confirmação preserva referência, checkout e limite de 12x');
return_upgrade_check($GLOBALS['return_upgrade_creations'] === 0 && $wpdb->last_error === '', 'recuperação não cria novo checkout e termina sem erro SQL');
echo "$checks verificações da atualização de retorno PAY aprovadas.\n";
