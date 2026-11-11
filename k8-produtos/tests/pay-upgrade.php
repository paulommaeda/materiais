<?php
/** Duas fases, dois processos PHP e um banco descartável: 2.7.0 -> versão atual. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD') || !in_array(getenv('K8PROD_UPGRADE_PHASE'), ['seed', 'verify'], true)) exit("Use um banco _test e K8PROD_UPGRADE_PHASE=seed|verify.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once ABSPATH . 'wp-admin/includes/plugin.php';
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\Schema;
use K8Produtos\Http;
use K8Produtos\PaymentTerms;

$checks = 0;
function upgrade_check($ok, $name) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $name); echo 'OK ' . (++$checks) . ': ' . $name . "\n"; }
function upgrade_mutate($op, $id, array $data) { $m = new ReflectionMethod(Http::class, 'mutate'); if (PHP_VERSION_ID < 80100) $m->setAccessible(true); return $m->invoke(null, $op, $id, $data); }
function upgrade_rows($table) { global $wpdb; $rows = $wpdb->get_results('SELECT * FROM ' . S::table($table) . ' ORDER BY id', ARRAY_A); if ($wpdb->last_error) throw new RuntimeException($wpdb->last_error); return $rows; }
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
$tables = ['produtos','cupons','materiais','estoque_movimentos','entregas','entregas_log','parcelamento_data'];

if (getenv('K8PROD_UPGRADE_PHASE') === 'seed') {
    upgrade_check(K8PROD_VERSION === '2.7.0', 'semente criada pelo código original 2.7.0');
    update_option('k8p_producao', '1');
    update_option('k8p_client_id', 'upgrade-cob-client');
    update_option('k8p_client_secret', 'upgrade-cob-secret');
    update_option('k8p_aes_key', '1234567890123456');
    $r = upgrade_mutate('save_product', 0, ['nome' => 'Produto antes do PAY', 'turmas' => ['5 A'], 'valor_padrao' => 2145, 'ativo' => 1, 'publicado' => 1,
        'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Cartao','Pix','Boleto'], 'cartao_parcelas' => 6]);
    $product = S::product($r['produto_id']);
    PaymentTerms::save((int) $product->id, ['titulo' => 'Compra antecipada', 'fim' => current_datetime()->modify('+1 day')->format('Y-m-d'), 'parcelas' => 12]);
    upgrade_mutate('save_coupon', (int) $product->id, ['codigo' => 'BOLSA100', 'percentual' => 100, 'uso_maximo' => 10]);
    $person = ['ra' => 'UPGRADE-PAID', 'nome_aluno' => 'Aluno original', 'nome_resp' => 'Responsável original', 'cpf_resp' => '52998224725', 'turma' => '5 A'];
    $paid = Service::checkout($product, $person, 'Cortesia', 'BOLSA100');
    Service::create_material((int) $product->id, ['nome' => 'Livro original', 'periodo_entrega' => 'ano', 'estoque_inicial' => 3, 'quantidade_por_aluno' => 1]);
    Service::delivery((int) $product->id, $paid['id'], 1, 'entregar', 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/signature.png')), 'Recebedor original', '');
    foreach (['Pix' => 'k8', 'Boleto' => 'k8', 'Cartao' => 'k8_link'] as $method => $origin) {
        $person['ra'] = 'UPGRADE-' . $method;
        S::must($wpdb->insert(S::table('pedidos'), array_merge($person, ['produto_id' => $product->id, 'valor' => 2145, 'origem' => $origin, 'metodo' => $method,
            'active_key' => hash('sha256', $product->id . ':' . $person['ra']), 'status' => 'gerado', 'status_pagamento' => 'Pendente',
            'cartao_parcelas_max' => $method === 'Cartao' ? 12 : null, 'pagamento_referencia' => $method === 'Cartao' ? 'original-reference' : null,
            'link_pagamento_id' => $method === 'Cartao' ? 'original-link' : null, 'cobranca_id' => $method !== 'Cartao' ? 'original-' . $method : null,
            'checkout_url' => $method === 'Cartao' ? 'https://pagamentos.meuk8.com.br/original-link' : null])));
    }
    $students = K8Produtos\StudentImport::compile_rows([['ra' => 'UPGRADE-ALUNO', 'nome_aluno' => 'Aluno mantido', 'turma' => '5 A', 'nome_resp1' => 'Responsável mantido', 'cpf_resp1' => '52998224725']]);
    K8Produtos\StudentImport::save_import($students, false);
    $snapshot = ['pedidos' => upgrade_rows('pedidos'), 'alunos' => K8Produtos\Students::find('52998224725'), 'tabelas' => []];
    foreach ($tables as $table) $snapshot['tabelas'][$table] = upgrade_rows($table);
    update_option('k8prod_pay_upgrade_snapshot', $snapshot, false);
    update_option('active_plugins', []); // O processo seguinte carrega somente a versão nova.
    echo "Semente 2.7.0 preservada para a próxima fase.\n";
    exit;
}

upgrade_check(version_compare(K8PROD_VERSION, '2.8.1', '>=') && get_option('k8prod_schema') === K8PROD_VERSION, 'ativação real aplicou o schema atual');
$snapshot = get_option('k8prod_pay_upgrade_snapshot');
upgrade_check(is_array($snapshot), 'semente da versão anterior disponível');
foreach ($tables as $table) upgrade_check(upgrade_rows($table) === $snapshot['tabelas'][$table], 'atualização conserva integralmente ' . $table);
$orders = upgrade_rows('pedidos');
upgrade_check(count($orders) === count($snapshot['pedidos']), 'nenhum pedido criado ou removido pela atualização');
foreach ($orders as $index => $row) {
    upgrade_check(array_intersect_key($row, $snapshot['pedidos'][$index]) === $snapshot['pedidos'][$index], 'pedido #' . $row['id'] . ' conserva todos os campos anteriores');
    upgrade_check($row['pagamento_ambiente'] === (in_array($row['origem'], ['k8','k8_link'], true) ? 'production' : null), 'ambiente COB capturado sem converter origem');
    upgrade_check($row['pay_checkout_id'] === null && $row['pay_transacao_id'] === null && $row['pay_comprador'] === null, 'nenhum identificador PAY inventado');
}
upgrade_check(K8Produtos\Students::find('52998224725') === $snapshot['alunos'], 'alunos e responsáveis mantidos');
upgrade_check(get_option('k8p_client_id') === 'upgrade-cob-client' && get_option('k8p_client_secret') === 'upgrade-cob-secret', 'credenciais COB mantidas');
upgrade_check(K8Produtos\PayPayments::gateway() === 'cob', 'atualização não ativa PAY antes da configuração');
$before = upgrade_rows('pedidos');
update_option('k8p_producao', '0');
Schema::install(); Schema::install();
upgrade_check(upgrade_rows('pedidos') === $before, 'repetir instalação não muda dados nem ambiente histórico');
upgrade_check($wpdb->last_error === '', 'atualização sem erro SQL');
echo "$checks verificações da atualização PAY aprovadas.\n";
