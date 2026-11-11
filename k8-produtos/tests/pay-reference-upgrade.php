<?php
/** Atualização 2.8.0 -> 2.8.1 com referências PAY antigas, em banco descartável. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD') || !in_array(getenv('K8PROD_UPGRADE_PHASE'), ['seed', 'verify'], true)) exit("Use um banco _test e fase seed|verify.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\PayPayments;

wp_set_current_user(get_user_by('login', 'k8admin')->ID);
$checks = 0;
function ref_upgrade_check($ok, $name) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $name); echo 'OK ' . (++$checks) . ': ' . $name . "\n"; }
function ref_upgrade_rows() { global $wpdb; $rows = $wpdb->get_results('SELECT * FROM ' . S::table('pedidos') . ' ORDER BY id', ARRAY_A); if ($wpdb->last_error) throw new RuntimeException($wpdb->last_error); return $rows; }
if (getenv('K8PROD_UPGRADE_PHASE') === 'seed') {
    ref_upgrade_check(K8PROD_VERSION === '2.8.0', 'semente criada pelo pacote 2.8.0 entregue anteriormente');
    update_option('k8prod_gateway', 'pay'); update_option('k8p_producao', '1');
    foreach (['client_id' => 'fixture-pay-id', 'client_secret' => 'fixture-pay-secret', 'aes_key' => '1234567890123456'] as $name => $value) update_option('k8prod_pay_' . $name, $value);
    $method = new ReflectionMethod(K8Produtos\Http::class, 'mutate'); if (PHP_VERSION_ID < 80100) $method->setAccessible(true);
    $saved = $method->invoke(null, 'save_product', 0, ['nome' => 'Produto referência antiga', 'turmas' => ['5 A'], 'valor_padrao' => 100, 'ativo' => 1, 'publicado' => 1,
        'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Cartao','Pix','Boleto'], 'cartao_parcelas' => 12]);
    $product_id = $saved['produto_id'];
    $person = ['ra' => 'REFERENCE-FAILED', 'nome_aluno' => 'Aluno de teste', 'nome_resp' => 'Responsável de teste', 'cpf_resp' => '52998224725', 'turma' => '5 A'];
    foreach ([10 => 'verificacao', 11 => 'gerado'] as $id => $status) {
        $person['ra'] = $id === 10 ? 'REFERENCE-FAILED' : 'REFERENCE-PAID';
        S::must($wpdb->insert(S::table('pedidos'), array_merge($person, ['id' => $id, 'produto_id' => $product_id, 'origem' => 'pay_checkout', 'metodo' => 'Cartao', 'valor' => 100,
            'active_key' => hash('sha256', $product_id . ':' . $person['ra']), 'status' => $status, 'status_pagamento' => $id === 10 ? null : 'Paga',
            'cartao_parcelas_max' => 12, 'pagamento_referencia' => $id === 10 ? 'b019d641b5afbc90b9ba600cf3fa4149' : str_repeat('c', 32),
            'pay_checkout_id' => $id === 10 ? null : '10000000-0000-4000-8000-000000000011', 'pay_transacao_id' => $id === 10 ? null : '20000000-0000-4000-8000-000000000011',
            'pagamento_ambiente' => 'production', 'erro_mensagem' => $id === 10 ? 'POST /v2/CriaTransacaoCheckout — HTTP 200: O tamanho máximo para a Referencia é 36 caracteres' : null])));
    }
    update_option('k8prod_ref_upgrade_snapshot', ['produto_id' => $product_id, 'pedidos' => ref_upgrade_rows()], false);
    update_option('active_plugins', []);
    echo "Referências 2.8.0 preservadas para testar instalação do ZIP corrigido.\n";
    exit;
}
ref_upgrade_check(version_compare(K8PROD_VERSION, '2.8.1', '>=') && get_option('k8prod_schema') === K8PROD_VERSION, 'pacote corrigido instalado e ativado');
$snapshot = get_option('k8prod_ref_upgrade_snapshot');
ref_upgrade_check(ref_upgrade_rows() === $snapshot['pedidos'], 'atualização conserva todos os campos dos pedidos PAY, inclusive o #10 em conferência');
ref_upgrade_check(get_option('k8prod_gateway') === 'pay' && get_option('k8p_producao') === '1' && get_option('k8prod_pay_client_secret') === 'fixture-pay-secret', 'provedor, ambiente e credenciais PAY preservados');
$failed = Service::order($snapshot['produto_id'], 10); $paid = Service::order($snapshot['produto_id'], 11);
ref_upgrade_check(PayPayments::reference($failed) === 'K8PAY-10-b019d641b5afbc90b9ba600cf3fa4149', 'referência antiga recusada não é alterada pela instalação');
$GLOBALS['ref_upgrade_create_calls'] = 0; $GLOBALS['ref_upgrade_consult_reference'] = null; $GLOBALS['ref_upgrade_consult_id'] = null;
add_filter('pre_http_request', static function ($pre, $args, $url) use ($paid) {
    if (strpos($url, '/PagamentosAPI/') === false) return $pre;
    if (substr($url, -6) === '/token') return ['headers' => [], 'body' => '{"access_token":"fixture-token"}', 'response' => ['code' => 200, 'message' => 'OK']];
    if (strpos($url, 'CriaTransacaoCheckout') !== false) { $GLOBALS['ref_upgrade_create_calls']++; throw new RuntimeException('Atualização não deve criar checkout.'); }
    $data = json_decode(openssl_decrypt(base64_decode($args['body']), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16)), true);
    $GLOBALS['ref_upgrade_consult_reference'] = $data['Referencia'] ?? null;
    $GLOBALS['ref_upgrade_consult_id'] = $data['Identificador'] ?? null;
    $response = ['Retorno' => 'OK', 'Identificador' => $paid->pay_transacao_id, 'Referencia' => PayPayments::reference($paid), 'NumeroPedido' => 'K8P-' . $paid->id,
        'ClienteCPFCNPJ' => $paid->cpf_resp, 'Valor' => 100, 'ValorPago' => 100, 'Estado' => 'Aprovada', 'TipoPagamento' => 'cartao'];
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 10, 3);
$person = array_intersect_key((array) $failed, array_flip(['ra','nome_aluno','nome_resp','cpf_resp','turma']));
$again = Service::checkout(S::product($snapshot['produto_id']), $person, 'Cartao', '');
ref_upgrade_check($again['id'] === 10 && $again['status'] === 'verificacao' && $GLOBALS['ref_upgrade_create_calls'] === 0, 'repetir o pedido antigo não gera checkout nem muda a referência');
ref_upgrade_check(Service::sync_order($snapshot['produto_id'], 11)->status_pagamento === 'Paga' && PayPayments::reference($paid) === 'K8PAY-11-' . str_repeat('c', 32) &&
    ($GLOBALS['ref_upgrade_consult_reference'] === PayPayments::reference($paid) || $GLOBALS['ref_upgrade_consult_id'] === $paid->pay_transacao_id), 'pagamento anterior conserva referência e continua consultável');
ref_upgrade_check($GLOBALS['ref_upgrade_create_calls'] === 0 && $wpdb->last_error === '', 'atualização e consultas não criam outro pagamento nem deixam erro SQL');
echo "$checks verificações da atualização de referências PAY aprovadas.\n";
