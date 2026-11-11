<?php
/** Contrato PAY em WordPress e banco descartáveis; todo HTTP financeiro é simulado. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Fixture CLI requer banco _test.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\Http;
use K8Produtos\PayClient;
use K8Produtos\PayPayments;
use K8Produtos\PaymentTerms;
use K8Produtos\Emails;
use K8Produtos\Students;
use K8Produtos\Views;
use K8Produtos\PayReturn;

$checks = 0;
function pay_check($ok, $name) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $name); echo 'OK ' . (++$checks) . ': ' . $name . "\n"; }
function pay_reject(callable $job, $name) { try { $job(); } catch (Throwable $e) { pay_check(true, $name); return $e; } pay_check(false, $name); }
function pay_mutate($op, $id, array $data) { $method = new ReflectionMethod(Http::class, 'mutate'); if (PHP_VERSION_ID < 80100) $method->setAccessible(true); return $method->invoke(null, $op, $id, $data); }
function pay_person($ra) { return ['ra' => $ra, 'nome_aluno' => 'Aluno de teste', 'nome_resp' => 'Responsável de teste', 'cpf_resp' => '52998224725', 'turma' => '5 A']; }
function pay_address() { return ['pay_endereco' => 'Rua de Teste', 'pay_numero' => '42', 'pay_bairro' => 'Centro', 'pay_cidade' => 'São Paulo', 'pay_cep' => '01234-567', 'pay_uf' => 'SP', 'pay_complemento' => 'Sala de teste']; }
function pay_start($product, $ra, $method, array $input = []) { $person = pay_person($ra); $quote = Service::quote($product, $person); return Service::checkout($product, $person, $method, '', $quote['chave_preco'], $input); }
function pay_settings(array $extra = []) {
    $data = ['gateway' => 'pay', 'producao' => 1, 'api_production' => 'https://pagamentos.meuk8.com.br/CobrancasAPI',
        'api_sandbox' => 'https://sandbox.meuk8.com.br/CobrancasAPI', 'checkout_production' => 'https://pagamentos.k8bank.com.br/cobrancasui/checkout',
        'checkout_sandbox' => 'https://pagamentos.k8bank.com.br/cobrancasui/checkout', 'query_path' => '/api/Cobranca/{id}', 'cancel_path' => '/api/Cobranca/{id}'];
    foreach (Students::defaults() as $name => $value) $data['student_' . $name] = $value;
    return array_merge($data, $extra);
}
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
update_option('timezone_string', 'America/Sao_Paulo');
update_option('k8p_client_id', 'cob-existing-id'); update_option('k8p_client_secret', 'cob-existing-secret'); update_option('k8p_aes_key', '1234567890123456');
pay_reject(fn() => pay_mutate('settings', 0, pay_settings()), 'PAY não reutiliza credenciais COB silenciosamente');
pay_check(PayPayments::gateway() === 'cob', 'rejeitar configuração incompleta preserva COB');
pay_mutate('settings', 0, pay_settings(['pay_client_id' => 'pay-production-id', 'pay_client_secret' => 'pay-production-secret', 'pay_aes_key' => 'abcdefghijklmnop',
    'pay_sandbox_client_id' => 'pay-sandbox-id', 'pay_sandbox_client_secret' => 'pay-sandbox-secret', 'pay_sandbox_aes_key' => 'ponmlkjihgfedcba']));
pay_check(PayPayments::gateway() === 'pay' && PayClient::configured('production') && PayClient::configured('sandbox'), 'credenciais PAY separadas por ambiente e provedor habilitado');
pay_check(get_option('k8p_client_id') === 'cob-existing-id' && get_option('k8p_client_secret') === 'cob-existing-secret', 'credenciais COB preservadas');
pay_reject(fn() => pay_mutate('settings', 0, pay_settings(['pay_api_production' => 'http://unsafe.invalid'])), 'endpoint PAY exige HTTPS');
pay_mutate('settings', 0, pay_settings());
pay_check(get_option('k8prod_pay_client_secret') === 'pay-production-secret', 'campos vazios preservam segredo existente');
$saved_secret = get_option('k8prod_pay_client_secret');
pay_reject(fn() => pay_mutate('settings', 0, pay_settings(['pay_client_secret' => "bad\nsecret"])), 'credencial com quebra de linha rejeitada antes de salvar');
pay_check(get_option('k8prod_pay_client_secret') === $saved_secret, 'falha de validação não altera credenciais');

$historical = (object) ['id' => 10, 'pagamento_referencia' => 'b019d641b5afbc90b9ba600cf3fa4149'];
pay_check(PayPayments::reference($historical) === 'K8PAY-10-b019d641b5afbc90b9ba600cf3fa4149' && strlen(PayPayments::reference($historical)) === 41, 'referência histórica do pedido #10 permanece exata, sem truncar nem renomear');

$GLOBALS['pay_remote'] = []; $GLOBALS['pay_posts'] = 0; $GLOBALS['pay_calls'] = []; $GLOBALS['pay_mode'] = 'normal'; $GLOBALS['pay_tamper'] = [];
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (strpos($url, '/PagamentosAPI/') === false) return $pre;
    $GLOBALS['pay_calls'][] = ['url' => $url, 'method' => $args['method'] ?? 'POST'];
    $sandbox = strpos($url, 'sandbox.meuk8.com.br') !== false;
    $aes = $sandbox ? 'ponmlkjihgfedcba' : 'abcdefghijklmnop';
    if (substr($url, -6) === '/token') {
        if (($args['body']['username'] ?? '') !== ($sandbox ? 'pay-sandbox-id' : 'pay-production-id')) throw new RuntimeException('Credencial de ambiente incorreto.');
        return ['headers' => [], 'body' => '{"access_token":"pay-fixture-token"}', 'response' => ['code' => 200, 'message' => 'OK']];
    }
    if (($args['headers']['Aeskey'] ?? '') !== $aes) throw new RuntimeException('AES de ambiente incorreto.');
    $path = parse_url($url, PHP_URL_PATH);
    $data = !empty($args['body']) ? json_decode(openssl_decrypt(base64_decode($args['body']), 'AES-128-CBC', $aes, OPENSSL_RAW_DATA, str_repeat("\0", 16)), true) : [];
    if ($path === '/PagamentosAPI/v2/CriaTransacaoCheckout') {
        $GLOBALS['pay_posts']++;
        $GLOBALS['pay_sent'] = $data;
        if (!is_string($data['Referencia'] ?? null) || strlen($data['Referencia']) > 36) $response = ['Retorno' => 'ERRO', 'DetalhesErro' => 'O tamanho máximo para a Referencia é 36 caracteres'];
        elseif ($GLOBALS['pay_mode'] === 'business_error') $response = ['Retorno' => 'ERRO', 'DetalhesErro' => 'Rejeição de teste pay-production-secret 52998224725'];
        else {
            $id = sprintf('10000000-0000-4000-8000-%012d', $GLOBALS['pay_posts']);
            $GLOBALS['pay_remote'][$data['Referencia']] = ['checkout_id' => $id, 'payload' => $data, 'state' => 'Pendente',
                'transaction_id' => $GLOBALS['pay_mode'] === 'id_only' ? $id : sprintf('20000000-0000-4000-8000-%012d', $GLOBALS['pay_posts']),
                'reference_visible' => !in_array($GLOBALS['pay_mode'], ['id_only', 'manual_only'], true)];
            if ($GLOBALS['pay_mode'] === 'timeout') return new WP_Error('fixture_timeout', 'Resposta perdida.');
            $response = ['Retorno' => 'OK', 'Identificador' => $id, 'URL' => ($GLOBALS['pay_mode'] === 'wrong_url' ? 'https://untrusted.invalid/' : ($sandbox ? 'https://sandbox.meuk8.com.br/' : 'https://pagamentos.meuk8.com.br/')) . 'checkout/' . $id];
        }
    } elseif (in_array($path, ['/PagamentosAPI/api/ConsultaTransacaoReferencia', '/PagamentosAPI/api/ConsultaTransacao'], true)) {
        if ($path === '/PagamentosAPI/api/ConsultaTransacaoReferencia') {
            $remote = $GLOBALS['pay_remote'][$data['Referencia']] ?? null;
            if ($remote && !$remote['reference_visible']) $remote = null;
        } else {
            $matches = array_values(array_filter($GLOBALS['pay_remote'], static fn($entry) => $entry['transaction_id'] === ($data['Identificador'] ?? '')));
            $remote = $matches[0] ?? null;
        }
        if (!$remote) $response = ['Retorno' => 'ERRO', 'DetalhesErro' => 'Referência ou identificador da transação não localizado.'];
        else {
            $payload = $remote['payload'];
            $method = isset($payload['Cartao']) ? 'cartao' : (isset($payload['Pix']) ? 'pix' : 'boleto');
            $response = ['Retorno' => 'OK', 'Identificador' => $remote['transaction_id'], 'Referencia' => $remote['reference_visible'] ? $payload['Referencia'] : null, 'NumeroPedido' => $payload['NumeroPedido'],
                'ClienteCPFCNPJ' => $payload['ClienteCPFCNPJ'], 'Valor' => $payload['Valor'], 'ValorPago' => in_array($remote['state'], ['Aprovada','Liquidado'], true) ? $payload['Valor'] : 0,
                'TipoPagamento' => $method, 'Tipo' => ucfirst($method), 'Estado' => $remote['state']];
            $response = array_merge($response, $GLOBALS['pay_tamper']);
        }
    } elseif ($path === '/PagamentosAPI/api/ParcelamentoConfiguracao' && $args['method'] === 'GET') $response = ['Retorno' => 'OK', 'HabilitarParcelamento' => true, 'QuantidadeMaximaParcelas' => 12];
    else throw new RuntimeException('Rota PAY não simulada: ' . $path);
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', $aes, OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 10, 3);

$calls_before_validation = count($GLOBALS['pay_calls']);
foreach ([str_repeat('a', 37), '', null] as $invalid_reference) {
    pay_reject(fn() => (new PayClient('production'))->checkout(['Referencia' => $invalid_reference]), 'referência inválida recusada antes de chamar o PAY');
}
pay_check(count($GLOBALS['pay_calls']) === $calls_before_validation, 'validação local da referência não faz autenticação nem criação remota');

$product_result = pay_mutate('save_product', 0, ['nome' => 'Material PAY de teste', 'turmas' => ['5 A'], 'valor_padrao' => '2145', 'ativo' => 1, 'publicado' => 1,
    'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Pix','Boleto','Cartao'], 'cartao_parcelas' => 6]);
$product = S::product($product_result['produto_id']);
$tomorrow = current_datetime()->modify('+1 day')->format('Y-m-d');
$rule_id = PaymentTerms::save((int) $product->id, ['titulo' => 'Compra antecipada PAY', 'fim' => $tomorrow, 'parcelas' => 12]);
$quote = Service::quote($product, pay_person('CARD-01'));
pay_check($quote['parcelas_cartao_max'] === 12 && $quote['gateway'] === 'pay' && $quote['endereco_boleto'] === true, 'cotação PAY conserva faixa por data de 12x sobre padrão 6x');
$card = pay_start($product, 'CARD-01', 'Cartao'); $card_order = Service::order((int) $product->id, $card['id']); $sent = $GLOBALS['pay_sent'];
pay_check($card_order->origem === 'pay_checkout' && $card_order->pagamento_ambiente === 'production' && $card_order->pay_checkout_id, 'pedido registra PAY, ambiente e ID do checkout');
pay_check($sent['Cartao'] === ['FormaPagamento' => ['AVista','ParceladoSemJuros'], 'QuantidadeParcelas' => 12], 'v2 envia máximo de 12x e modalidades como array de strings');
pay_check(!isset($sent['Pix'], $sent['Boleto'], $sent['ParcelaFixaQtd']) && $sent['Valor'] == 2145, 'checkout de cartão só habilita cartão com valor conferido');
pay_check($sent['NumeroPedido'] === 'K8P-' . $card_order->id && $sent['Referencia'] === PayPayments::reference($card_order), 'pedido e referência única enviados ao PAY');
pay_check(strlen($sent['Referencia']) === 36 && $sent['Referencia'] === $card_order->pagamento_referencia && wp_is_uuid($sent['Referencia'], 4), 'cartão envia a referência UUID completa com exatamente 36 caracteres');
$large_id_order = clone $card_order; $large_id_order->id = PHP_INT_MAX;
pay_check(PayPayments::reference($large_id_order) === $sent['Referencia'], 'tamanho da referência nova independe da quantidade de dígitos do pedido');
pay_check(strpos($card['redirect'], 'https://pagamentos.meuk8.com.br/checkout/') === 0 && strpos($sent['URLRetorno'], '52998224725') === false, 'checkout hospedado e retorno sem CPF');
$post_count = $GLOBALS['pay_posts'];
pay_start($product, 'CARD-01', 'Pix');
pay_check($GLOBALS['pay_posts'] === $post_count, 'pedido existente não é recriado ao trocar método');
pay_reject(fn() => pay_start($product, 'BOLETO-INVALID', 'Boleto'), 'boleto sem endereço recusado antes de chamar a K8');
pay_check(Service::active((int) $product->id, 'BOLETO-INVALID') === null && $GLOBALS['pay_posts'] === $post_count, 'endereço inválido não deixa pedido parcial nem chamada financeira');
pay_reject(fn() => pay_start($product, 'BOLETO-INVALID', 'Boleto', array_merge(pay_address(), ['pay_cep' => '111'])), 'CEP inválido recusado no servidor');
pay_reject(fn() => pay_start($product, 'BOLETO-INVALID', 'Boleto', array_merge(pay_address(), ['pay_uf' => 'XX'])), 'UF desconhecida recusada no servidor');
$boleto = pay_start($product, 'BOLETO-01', 'Boleto', pay_address()); $boleto_order = Service::order((int) $product->id, $boleto['id']); $sent = $GLOBALS['pay_sent'];
pay_check(isset($sent['Boleto']) && !isset($sent['Cartao']) && !isset($sent['Pix']) && $sent['ClienteCEP'] === '01234567' && $sent['ClienteNumero'] === '42', 'boleto v2 recebe somente boleto e endereço normalizado');
pay_check(json_decode($boleto_order->pay_comprador, true)['cidade'] === 'São Paulo' && !isset(Service::public_order($boleto_order)['pay_comprador']), 'endereço conserva histórico e não entra na resposta pública');
$pix = pay_start($product, 'PIX-01', 'Pix'); $pix_order = Service::order((int) $product->id, $pix['id']); $sent = $GLOBALS['pay_sent'];
pay_check(isset($sent['Pix']) && !isset($sent['Cartao']) && !isset($sent['Boleto']) && $sent['Pix']['Tipo'] === 'E' && $sent['Pix']['Pdv'] === false, 'Pix v2 não cria cartão ou boleto e não exige endereço');
pay_check(strlen(PayPayments::reference($pix_order)) === 36 && strlen(PayPayments::reference($boleto_order)) === 36 && count(array_unique(array_map([PayPayments::class, 'reference'], [$card_order, $pix_order, $boleto_order]))) === 3, 'cartão, Pix e boleto recebem referências distintas dentro do limite');
pay_check($sent['Pix']['SegundosExpiracao'] >= 300 && $sent['Pix']['SegundosExpiracao'] <= 2592000 && substr($sent['Pix']['DataVencimento'], 11, 8) === '23:59:59', 'Pix respeita janela documentada e vencimento no fim do dia');
pay_check(strpos(pay_mutate('pay_check', 0, [])['message'], '12 parcela') !== false, 'verificação de conexão apenas lê configuração PAY');

$reference = PayPayments::reference($card_order);
foreach (['Pendente','PendenteAntifraude','CartaoPreAutorizado','Rejeitada'] as $state) {
    $GLOBALS['pay_remote'][$reference]['state'] = $state;
    pay_check(Service::sync_order((int) $product->id, $card_order->id)->status_pagamento !== 'Paga', $state . ' não libera entrega');
}
$GLOBALS['pay_remote'][$reference]['state'] = 'Aprovada';
foreach ([['Referencia' => 'outro'], ['NumeroPedido' => 'outro'], ['ClienteCPFCNPJ' => '11144477735'], ['Valor' => 1], ['ValorPago' => 1], ['TipoPagamento' => 'pix'], ['Estado' => 'desconhecido']] as $tamper) {
    $GLOBALS['pay_tamper'] = $tamper;
    pay_reject(fn() => Service::sync_order((int) $product->id, $card_order->id), 'pagamento divergente bloqueado: ' . key($tamper));
    pay_check(Service::order((int) $product->id, $card_order->id)->status_pagamento !== 'Paga', 'divergência não grava confirmação de pagamento');
}
$GLOBALS['pay_tamper'] = [];
$paid_card = Service::sync_order((int) $product->id, $card_order->id);
pay_check($paid_card->status_pagamento === 'Paga' && $paid_card->pay_transacao_id && !Service::public_order($paid_card)['redirect'], 'pagamento integral correspondente confirmado e checkout removido');
$_GET = ['k8_product' => $product->id, 'k8_tab' => 'orders'];
$order_html = Views::dashboard_shortcode([]);
$paid_row_start = strpos($order_html, '<td>#' . $paid_card->id . '<br>');
$paid_row = substr($order_html, $paid_row_start, strpos($order_html, '</tr>', $paid_row_start) - $paid_row_start);
pay_check(strpos($paid_row, 'Consultar este pedido') !== false && strpos($paid_row, PayPayments::reference($paid_card)) !== false, 'pedido PAY já pago permite consulta manual e mostra referência para conferir estorno');
$_GET = [];
foreach ([$boleto_order, $pix_order] as $order) {
    $GLOBALS['pay_remote'][PayPayments::reference($order)]['state'] = 'Liquidado';
    pay_check(Service::sync_order((int) $product->id, $order->id)->status_pagamento === 'Paga', 'confirmação PAY funciona para ' . $order->metodo);
}
pay_reject(fn() => Service::cancel_order((int) $product->id, $card_order->id, 'Não cancelar pago'), 'botão de cancelamento de pendência não estorna pago');
$GLOBALS['pay_remote'][$reference]['state'] = 'Estornado';
$refunded = Service::sync_order((int) $product->id, $card_order->id);
pay_check($refunded->status_pagamento === 'Cancelada' && $refunded->active_key === null && $refunded->status === 'cancelado', 'pagamento já confirmado é revisto e estorno remoto bloqueia entrega');

$GLOBALS['pay_mode'] = 'timeout';
pay_reject(fn() => pay_start($product, 'TIMEOUT', 'Pix'), 'resposta perdida deixa tentativa em conferência');
$uncertain = Service::active((int) $product->id, 'TIMEOUT'); $post_count = $GLOBALS['pay_posts'];
pay_check($uncertain && !$uncertain->pay_checkout_id && $uncertain->status === 'verificacao', 'incerteza preserva trava sem inventar ID');
$GLOBALS['pay_mode'] = 'normal'; pay_start($product, 'TIMEOUT', 'Pix');
pay_check($GLOBALS['pay_posts'] === $post_count, 'timeout não repete criação de checkout');
$GLOBALS['pay_remote'][PayPayments::reference($uncertain)]['state'] = 'Aprovada';
pay_check(Service::sync_order((int) $product->id, $uncertain->id)->status_pagamento === 'Paga', 'referência recupera pagamento mesmo quando ID original não retornou');
$GLOBALS['pay_mode'] = 'wrong_url';
pay_reject(fn() => pay_start($product, 'BAD-URL', 'Cartao'), 'URL de checkout em domínio inesperado recusada');
$bad_url = Service::active((int) $product->id, 'BAD-URL');
pay_check($bad_url->pay_checkout_id && !$bad_url->checkout_url, 'ID retornado conservado antes da rejeição da URL');
$GLOBALS['pay_mode'] = 'business_error';
$error = pay_reject(fn() => pay_start($product, 'BUSINESS-ERROR', 'Pix'), 'Retorno ERRO não é aceito mesmo com HTTP 200');
$failed = Service::active((int) $product->id, 'BUSINESS-ERROR');
pay_check(strpos($error->getMessage(), 'Rejeição de teste') === false && strpos($failed->erro_mensagem, 'Rejeição de teste') !== false, 'detalhe de validação restrito à administração');
pay_check(strpos($failed->erro_mensagem, 'pay-production-secret') === false && strpos($failed->erro_mensagem, '52998224725') === false, 'diagnóstico remove credenciais e CPF');
$GLOBALS['pay_mode'] = 'normal';

$pending = pay_start($product, 'PENDING-CANCEL', 'Pix'); $pending_order = Service::order((int) $product->id, $pending['id']);
pay_reject(fn() => Service::cancel_order((int) $product->id, $pending['id'], 'Cancelar pendência'), 'cancelamento exige encerramento e conferência manual no PAY');
Service::cancel_order((int) $product->id, $pending['id'], 'Encerrado manualmente no PAY', true);
pay_check(Service::active((int) $product->id, 'PENDING-CANCEL') === null, 'confirmação administrativa libera compra após baixa remota conferida');
$unused = pay_start($product, 'UNUSED-CANCEL', 'Pix'); $unused_order = Service::order((int) $product->id, $unused['id']);
unset($GLOBALS['pay_remote'][PayPayments::reference($unused_order)]);
Service::cancel_order((int) $product->id, $unused['id'], 'Checkout encerrado no painel PAY; sem transação', true);
pay_check(Service::active((int) $product->id, 'UNUSED-CANCEL') === null && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . S::table('entregas_log') . " WHERE pedido_id=%d AND acao='pay_baixa_manual'", $unused['id'])) === 1, 'checkout sem transação exige baixa manual explícita e deixa auditoria');
$paid_cancel = pay_start($product, 'PAID-CANCEL', 'Pix'); $paid_cancel_order = Service::order((int) $product->id, $paid_cancel['id']);
$GLOBALS['pay_remote'][PayPayments::reference($paid_cancel_order)]['state'] = 'Aprovada';
pay_reject(fn() => Service::cancel_order((int) $product->id, $paid_cancel['id'], 'Teste de proteção do pago', true), 'confirmação manual não cancela pagamento encontrado no PAY');
pay_check(Service::order((int) $product->id, $paid_cancel['id'])->status_pagamento === 'Paga', 'consulta prévia ao cancelamento conserva pagamento encontrado');
pay_check(count(array_filter($GLOBALS['pay_calls'], fn($call) => strpos($call['url'], 'CancelaTransacao') !== false)) === 0, 'cancelar pendência nunca chama API de estorno');

$old_quote = Service::quote($product, pay_person('SWITCH'));
update_option('k8p_producao', '0');
pay_reject(fn() => Service::checkout($product, pay_person('SWITCH'), 'Cartao', '', $old_quote['chave_preco']), 'mudar ambiente exige reconfirmação da cotação');
$sandbox = pay_start($product, 'SANDBOX', 'Cartao'); $sandbox_order = Service::order((int) $product->id, $sandbox['id']);
pay_check($sandbox_order->pagamento_ambiente === 'sandbox' && strpos($sandbox['redirect'], 'https://sandbox.meuk8.com.br/') === 0, 'pedido sandbox usa credenciais e checkout de sandbox');
update_option('k8p_producao', '1');
$GLOBALS['pay_remote'][PayPayments::reference($sandbox_order)]['state'] = 'Aprovada';
Service::sync_order((int) $product->id, $sandbox_order->id);
pay_check(strpos(end($GLOBALS['pay_calls'])['url'], 'sandbox.meuk8.com.br') !== false, 'alterar ambiente global não redireciona histórico PAY');
update_option('k8prod_gateway', 'cob');
$GLOBALS['pay_remote'][PayPayments::reference($pix_order)]['state'] = 'Estornado';
pay_check(Service::sync_order((int) $product->id, $pix_order->id)->status_pagamento === 'Cancelada', 'selecionar COB para novas compras não muda o provedor de pedido PAY');
pay_reject(fn() => Service::checkout($product, pay_person('SWITCH-PROVIDER'), 'Pix', '', $old_quote['chave_preco']), 'mudar provedor invalida cotação anterior');
update_option('k8prod_gateway', 'pay');

PaymentTerms::delete((int) $product->id, $rule_id);
pay_check(Service::quote($product, pay_person('AFTER-PERIOD'))['parcelas_cartao_max'] === 6 && (int) Service::order((int) $product->id, $card_order->id)->cartao_parcelas_max === 12, 'encerrar faixa devolve 6x às novas compras e preserva 12x no histórico');
$stock_order = pay_start($product, 'PAY-STOCK', 'Cartao'); $stock_stored = Service::order((int) $product->id, $stock_order['id']);
$GLOBALS['pay_remote'][PayPayments::reference($stock_stored)]['state'] = 'Aprovada';
$GLOBALS['pay_tamper'] = ['Parcelas' => array_fill(0, 7, ['Numero' => 1])];
pay_reject(fn() => Service::sync_order((int) $product->id, $stock_stored->id), 'parcelamento confirmado acima do limite histórico bloqueia entrega');
$GLOBALS['pay_tamper'] = [];
Service::sync_order((int) $product->id, $stock_stored->id);
$material = Service::create_material((int) $product->id, ['nome' => 'Livro PAY', 'periodo_entrega' => 'ano', 'estoque_inicial' => 2, 'quantidade_por_aluno' => 1, 'estoque_minimo' => 0]);
$signature = 'data:image/png;base64,' . base64_encode(file_get_contents(__DIR__ . '/signature.png'));
Service::delivery((int) $product->id, $stock_stored->id, 1, 'entregar', $signature, 'Recebedor de teste', '');
pay_check((int) $wpdb->get_var($wpdb->prepare('SELECT estoque_atual FROM ' . S::table('materiais') . ' WHERE id=%d', $material)) === 1, 'pedido PAY pago permite entrega e baixa de estoque');
$GLOBALS['pay_remote'][PayPayments::reference($stock_stored)]['state'] = 'Estornado';
Service::sync_order((int) $product->id, $stock_stored->id);
pay_reject(fn() => Service::delivery((int) $product->id, $stock_stored->id, 1, 'entregar', $signature, 'Recebedor', ''), 'estorno remoto impede nova entrega');
$_GET = ['k8_product' => $product->id, 'k8_tab' => 'deliveries'];
pay_check(strpos(Views::dashboard_shortcode([]), 'PAY-STOCK') !== false, 'entrega existente continua visível após estorno financeiro');
Service::delivery((int) $product->id, $stock_stored->id, 1, 'desfazer', '', '', 'Material devolvido após estorno');
pay_check((int) $wpdb->get_var($wpdb->prepare('SELECT estoque_atual FROM ' . S::table('materiais') . ' WHERE id=%d', $material)) === 2, 'devolução física pode ser registrada depois do estorno financeiro');
$_GET = [];

// E-mails usam o checkout e a consulta PAY sem gerar novo pagamento.
$compiled = K8Produtos\StudentImport::compile_rows([['ra' => 'PAY-MAIL', 'nome_aluno' => 'Aluno de e-mail', 'turma' => '5 A', 'nome_resp1' => 'Responsável de e-mail', 'cpf_resp1' => '52998224725']]);
K8Produtos\StudentImport::save_import($compiled, false);
Emails::save((int) $product->id, ['mail_confirmacao' => 1, 'mail_carrinho' => 1, 'mail_espera' => 15]);
$GLOBALS['pay_messages'] = [];
add_filter('pre_wp_mail', static function ($result, $atts) { $GLOBALS['pay_messages'][] = $atts; return true; }, 20, 2);
$mail_order = pay_start($product, 'PAY-MAIL', 'Pix', ['email_responsavel' => 'pay-mail@example.invalid', 'lembrete_email' => 1]);
$mail_stored = Service::order((int) $product->id, $mail_order['id']);
$wpdb->update(S::table('carrinhos_email'), ['lembrar_em' => gmdate('Y-m-d H:i:s', time() - 3600)], ['id' => $mail_stored->email_carrinho_id]);
$post_count = $GLOBALS['pay_posts'];
Emails::run();
pay_check(count($GLOBALS['pay_messages']) === 1 && strpos($GLOBALS['pay_messages'][0]['message'], $mail_order['redirect']) !== false, 'lembrete de carrinho PAY inclui seu checkout hospedado');
$GLOBALS['pay_remote'][PayPayments::reference($mail_stored)]['state'] = 'Aprovada';
Service::sync_order((int) $product->id, $mail_stored->id);
Emails::run(); Emails::run();
pay_check(count($GLOBALS['pay_messages']) === 2 && $GLOBALS['pay_posts'] === $post_count, 'confirmação PAY é enviada uma vez sem criar outro checkout');

$compiled = K8Produtos\StudentImport::compile_rows([['ra' => 'PAY-MAIL-PAID', 'nome_aluno' => 'Aluno pago fora da loja', 'turma' => '5 A', 'nome_resp1' => 'Responsável de e-mail', 'cpf_resp1' => '52998224725']]);
K8Produtos\StudentImport::save_import($compiled, false);
$already_paid = pay_start($product, 'PAY-MAIL-PAID', 'Pix', ['email_responsavel' => 'pay-mail@example.invalid', 'lembrete_email' => 1]);
$already_stored = Service::order((int) $product->id, $already_paid['id']);
$wpdb->update(S::table('carrinhos_email'), ['lembrar_em' => gmdate('Y-m-d H:i:s', time() - 3600)], ['id' => $already_stored->email_carrinho_id]);
$GLOBALS['pay_remote'][PayPayments::reference($already_stored)]['state'] = 'Aprovada';
Emails::run();
pay_check(Service::order((int) $product->id, $already_paid['id'])->status_pagamento === 'Paga' && count($GLOBALS['pay_messages']) === 2, 'antes de lembrar, e-mail consulta PAY e suprime cobrança já paga');
Emails::run(); Emails::run();
pay_check(count($GLOBALS['pay_messages']) === 3, 'pagamento descoberto pelo lembrete gera somente confirmação');

// Um pedido COB anterior conserva produção mesmo durante a homologação PAY.
S::must($wpdb->insert(S::table('pedidos'), array_merge(pay_person('COB-HISTORY'), ['produto_id' => $product->id, 'origem' => 'k8', 'metodo' => 'Pix', 'status' => 'gerado', 'status_pagamento' => 'Pendente',
    'valor' => 2145, 'cliente_id' => 'legacy-customer', 'cobranca_id' => 'legacy-charge', 'pagamento_ambiente' => 'production'])));
$legacy_id = (int) $wpdb->insert_id;
$GLOBALS['cob_history_urls'] = [];
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (strpos($url, '/CobrancasAPI/') === false) return $pre;
    $GLOBALS['cob_history_urls'][] = $url;
    if (substr($url, -6) === '/token') return ['headers' => [], 'body' => '{"access_token":"cob-fixture"}', 'response' => ['code' => 200, 'message' => 'OK']];
    $response = ['Id' => 'legacy-charge', 'Estado' => 'Paga', 'Valor' => 2145, 'ClienteId' => 'legacy-customer'];
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 5, 3);
update_option('k8p_producao', '0');
pay_check(Service::sync_order((int) $product->id, $legacy_id)->status_pagamento === 'Paga' && strpos(end($GLOBALS['cob_history_urls']), 'https://pagamentos.meuk8.com.br/CobrancasAPI/api/Cobranca/legacy-charge') === 0, 'histórico COB usa rota por ID e seu ambiente original mesmo com PAY selecionado em sandbox');
update_option('k8p_producao', '1');

ob_start(); Views::admin_settings(); $settings_html = ob_get_clean();
pay_check(strpos($settings_html, 'pay_client_secret') !== false && strpos($settings_html, 'pay_sandbox_client_secret') !== false && strpos($settings_html, 'pay-production-secret') === false, 'configurações renderizam credenciais próprias sem expor segredo');
$checkout_html = Views::product_shortcode(['id' => $product->id]);
pay_check(strpos($checkout_html, 'data-k8-pay-address') !== false && strpos($checkout_html, 'pay_cep') !== false, 'formulário público inclui endereço para boleto PAY');

// Um checkout pode concluir cartão sem tornar sua referência pesquisável.
Emails::save((int) $product->id, ['mail_confirmacao' => 0, 'mail_carrinho' => 0]);
$GLOBALS['pay_mode'] = 'id_only';
$id_only = pay_start($product, 'ID-ONLY-PAID', 'Cartao');
$id_order = Service::order((int) $product->id, $id_only['id']);
$return_payload = $GLOBALS['pay_sent'];
$GLOBALS['pay_mode'] = 'normal';
$GLOBALS['pay_remote'][PayPayments::reference($id_order)]['state'] = 'Aprovada';
pay_reject(fn() => (new PayClient('production'))->consult(PayPayments::reference($id_order)), 'reproduz referência não localizada mesmo após aprovação do cartão');
$create_count = $GLOBALS['pay_posts'];
$id_confirmed = Service::sync_order((int) $product->id, $id_order->id);
pay_check($id_confirmed->status_pagamento === 'Paga' && $id_confirmed->pay_transacao_id === $id_order->pay_checkout_id && $GLOBALS['pay_posts'] === $create_count, 'consulta por ID confirma cartão sem referência e sem criar outro checkout');
pay_check(isset($return_payload['URLConfirmacao']) && strpos(rawurldecode($return_payload['URLConfirmacao']), '/pay/confirmacao/' . $id_order->id) !== false && strpos($return_payload['URLRetorno'], 'k8_pay_return=') !== false, 'novos checkouts recebem URL de confirmação e página de retorno vinculadas ao pedido');

$GLOBALS['pay_mode'] = 'manual_only';
$manual = pay_start($product, 'MANUAL-ID-PAID', 'Cartao');
$manual_order = Service::order((int) $product->id, $manual['id']);
$GLOBALS['pay_mode'] = 'normal';
$manual_remote = &$GLOBALS['pay_remote'][PayPayments::reference($manual_order)];
$manual_remote['state'] = 'Aprovada';
pay_reject(fn() => Service::sync_order((int) $product->id, $manual_order->id), 'identificador final distinto e referência ausente não são adivinhados');
foreach ([['NumeroPedido' => 'K8P-outro'], ['ClienteCPFCNPJ' => '11144477735'], ['ValorPago' => 1], ['TipoPagamento' => 'pix'], ['Identificador' => 'ffffffff-ffff-4fff-8fff-ffffffffffff']] as $wrong) {
    $GLOBALS['pay_tamper'] = $wrong;
    pay_reject(fn() => Service::reconcile((int) $product->id, $manual_order->id, $manual_remote['transaction_id']), 'vínculo manual recusa divergência de ' . key($wrong));
}
$GLOBALS['pay_tamper'] = [];
Service::reconcile((int) $product->id, $manual_order->id, $manual_remote['transaction_id']);
pay_check(Service::order((int) $product->id, $manual_order->id)->status_pagamento === 'Paga' && $GLOBALS['pay_posts'] === $create_count + 1, 'operador recupera pagamento existente por ID após validação, sem repetir compra');
pay_reject(fn() => Service::reconcile((int) $product->id, $manual_order->id, $id_confirmed->pay_transacao_id), 'transação já vinculada não pode ser trocada por outro pagamento');

// O callback e o navegador não têm autoridade para declarar pagamento.
$GLOBALS['pay_mode'] = 'id_only';
$callback_order_public = pay_start($product, 'CALLBACK-ORDER', 'Pix');
$callback_order = Service::order((int) $product->id, $callback_order_public['id']);
$GLOBALS['pay_mode'] = 'normal';
$server = rest_get_server();
function pay_callback($server, $order, $token, $method = 'POST') {
    $request = new WP_REST_Request($method, '/k8-produtos/v1/pay/confirmacao/' . $order->id);
    $request->set_query_params(['token' => $token]);
    $request->set_body_params(['Estado' => 'Aprovada', 'ValorPago' => $order->valor, 'Identificador' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa']);
    return $server->dispatch($request);
}
$call_count = count($GLOBALS['pay_calls']);
pay_check(pay_callback($server, $callback_order, str_repeat('0', 64))->get_status() === 403 && count($GLOBALS['pay_calls']) === $call_count, 'callback sem assinatura válida é recusado antes de consultar PAY');
pay_check(pay_callback($server, $callback_order, PayReturn::token($id_order))->get_status() === 403, 'assinatura de outro pedido não autoriza o callback');
pay_check(pay_callback($server, $callback_order, PayReturn::token($callback_order))->get_status() === 200 && Service::order((int) $product->id, $callback_order->id)->status_pagamento === 'Pendente', 'callback ignora aprovação e valor forjados no corpo quando API informa pendência');
$GLOBALS['pay_remote'][PayPayments::reference($callback_order)]['state'] = 'Aprovada';
$result = pay_callback($server, $callback_order, PayReturn::token($callback_order));
pay_check($result->get_status() === 200 && $result->get_data()['pagamento'] === 'Paga', 'callback válido confirma pagamento somente após consulta autenticada por ID');
pay_check(!isset($result->get_data()['cpf_resp']) && !isset($result->get_data()['nome_aluno']) && !isset($result->get_data()['checkout_url']), 'resposta do callback não revela comprador, aluno ou link de pagamento');
$posts_before_repeat = $GLOBALS['pay_posts'];
pay_callback($server, $callback_order, PayReturn::token($callback_order), 'GET');
pay_check($GLOBALS['pay_posts'] === $posts_before_repeat, 'notificação repetida nunca gera outro checkout');
$_GET = ['k8_pay_return' => $callback_order->id, 'k8_pay_token' => PayReturn::token($callback_order), 'Estado' => 'Rejeitada'];
PayReturn::prepare_return();
$return_html = PayReturn::render();
pay_check(strpos($return_html, 'Pagamento confirmado') !== false && strpos($return_html, $callback_order->cpf_resp) === false && strpos($return_html, $callback_order->nome_aluno) === false, 'página de retorno mostra confirmação verificada sem dados pessoais');
$_GET['k8_pay_token'] = str_repeat('0', 64);
$call_count = count($GLOBALS['pay_calls']);
PayReturn::prepare_return();
pay_check(strpos(PayReturn::render(), 'Retorno não identificado') !== false && count($GLOBALS['pay_calls']) === $call_count, 'retorno sem assinatura não consulta nem revela situação do pedido');
$_GET = [];
pay_check($wpdb->last_error === '', 'validação termina sem erro SQL');
echo "$checks verificações de integração PAY aprovadas.\n";
