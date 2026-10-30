<?php
/** WordPress/MariaDB reais; mensagens e K8 interceptados. Nunca usar em produção. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Fixture CLI requer banco _test.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\Emails as E;
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\Schema;
use K8Produtos\Http;
use K8Produtos\Students;
use K8Produtos\Views;

$checks = 0;
function mail_check($ok, $label) { global $checks; if (!$ok) throw new RuntimeException('FALHOU: ' . $label); echo 'OK ' . (++$checks) . ': ' . $label . "\n"; }
function mail_rejects(callable $job, $label) { try { $job(); } catch (Throwable $e) { mail_check(true, $label); return; } mail_check(false, $label); }
function mail_mutate($op, $product, $data) { $m = new ReflectionMethod(Http::class, 'mutate'); $m->setAccessible(true); return $m->invoke(null, $op, $product, $data); }
function mail_product($name, $price = 100) {
    $r = mail_mutate('save_product', 0, ['nome' => $name, 'turmas' => ['5º Ano A'], 'valor_padrao' => (string) $price, 'ativo' => 1, 'publicado' => 1,
        'etapas' => 1, 'vencimento_dias' => 5, 'formas_pagamento' => ['Pix', 'Boleto', 'Cartao'], 'cartao_parcelas' => 6]);
    return S::product($r['produto_id']);
}
function mail_person($ra) {
    global $wpdb;
    $p = ['ra' => $ra, 'nome_aluno' => 'Aluno ' . $ra, 'turma' => '5º Ano A', 'cpf_resp' => '52998224725', 'nome_resp' => 'Responsável de teste'];
    $wpdb->replace($wpdb->prefix . 'lista_alunos_v4', ['ra' => $ra, 'nome_aluno' => $p['nome_aluno'], 'turma' => $p['turma'], 'cpf_resp1' => $p['cpf_resp'], 'nome_resp1' => $p['nome_resp']]);
    return $p;
}
function mail_form(array $overrides = []) {
    $d = E::defaults();
    return array_merge(['mail_confirmacao' => 1, 'mail_carrinho' => 1, 'mail_espera' => 60,
        'mail_assunto_confirmacao' => $d['assunto_confirmacao'], 'mail_texto_confirmacao' => $d['texto_confirmacao'],
        'mail_assunto_carrinho' => $d['assunto_carrinho'], 'mail_texto_carrinho' => $d['texto_carrinho']], $overrides);
}
function mail_config($p, array $overrides = []) { E::save((int) $p->id, mail_form($overrides)); return S::product((int) $p->id); }
function mail_contact($p, $person, $address, $reminder = true) { return E::contact($p, $person, ['email_responsavel' => $address, 'lembrete_email' => (int) $reminder]); }
function mail_cart_due($id) {
    global $wpdb;
    $wpdb->update(S::table('carrinhos_email'), ['atualizado_em' => gmdate('Y-m-d H:i:s', time() - 7200), 'lembrar_em' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $id]);
}
function mail_job($type, $id) {
    global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . S::table('emails') . ' WHERE chave = %s', $type . ':' . $id));
}
function mail_retry_due($id) { global $wpdb; $wpdb->update(S::table('emails'), ['disponivel_em' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $id]); }
function mail_run() { $r = E::run(); mail_check(!isset($r['erro']), 'fila executa sem erro de banco'); return $r; }

Schema::install();
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
update_option('timezone_string', 'America/Sao_Paulo');
$GLOBALS['mail_messages'] = []; $GLOBALS['mail_fail'] = false;
add_filter('pre_wp_mail', static function ($return, $args) {
    $GLOBALS['mail_messages'][] = $args;
    return !$GLOBALS['mail_fail'];
}, 10, 2);
function mail_sent_count() { return count($GLOBALS['mail_messages']); }
function mail_last() { return end($GLOBALS['mail_messages']); }

$off = mail_product('E-mails desligados', 0);
$legacy = Service::checkout($off, mail_person('ANTIGO'), 'Cortesia', '');
mail_check(!E::settings($off)['confirmacao'] && !E::settings($off)['carrinho'], 'envios começam desligados por produto');
$off = mail_config($off);
mail_run();
mail_check(mail_sent_count() === 0 && !mail_job('confirmacao', $legacy['id']), 'ativar e-mails não dispara mensagens retroativas para pedidos antigos');

$a = mail_config(mail_product('Material e-mails A'));
$b = mail_product('Material e-mails B');
$c = mail_config(mail_product('Evento gratuito', 0), ['mail_carrinho' => 0]);
mail_check(E::settings($a)['confirmacao'] && !E::settings($b)['confirmacao'], 'configuração de e-mails é independente por produto');
foreach ([14, 10081, '2.5', []] as $wait) mail_rejects(fn() => E::input(mail_form(['mail_espera' => $wait])), 'prazo inválido de abandono é rejeitado');
mail_rejects(fn() => E::input(mail_form(['mail_texto_confirmacao' => '[marcador_inexistente]'])), 'marcador desconhecido é rejeitado');
mail_rejects(fn() => E::input(mail_form(['mail_assunto_confirmacao' => []])), 'assunto em formato inválido é rejeitado');
$clean = E::input(mail_form(['mail_texto_confirmacao' => '<script>alert(1)</script><p>Olá [nome_aluno]</p>', 'mail_assunto_confirmacao' => "Compra\r\nBcc: terceiro@example.invalid"]));
mail_check(strpos($clean['texto_confirmacao'], '<script') === false && strpos($clean['assunto_confirmacao'], "\n") === false, 'modelo remove scripts e quebras de cabeçalho');

$person = mail_person('DADOS');
foreach (['', 'invalido', "resp@example.invalid\r\nBcc: outro@example.invalid", []] as $email) {
    mail_rejects(fn() => Service::checkout($c, $person, 'Cortesia', '', null, ['email_responsavel' => $email]), 'confirmação exige contato válido antes de criar pedido');
}
mail_check(Service::active((int) $c->id, 'DADOS') === null, 'contato inválido não cria pedido');
$opts = Service::quote($a, $person)['email_checkout'];
mail_check($opts['ativo'] && $opts['obrigatorio'] && $opts['lembrete_disponivel'], 'cotação informa as opções de contato do produto');
mail_check(!Service::quote($b, $person)['email_checkout']['ativo'], 'produto sem envio mantém checkout anterior');
$no = mail_contact($a, mail_person('SEM-LEMBRETE'), 'sem@example.invalid', false);
mail_cart_due($no['email_carrinho_id']); mail_run();
mail_check(!mail_job('carrinho', $no['email_carrinho_id']), 'informar e-mail sem escolher lembrete não cria disparo de abandono');

$lead = mail_person('ANTES-PEDIDO');
$fields = mail_contact($a, $lead, 'lead@example.invalid', true);
$cart = E::cart((int) $a->id, $lead);
mail_check($cart && !(int) $cart->pedido_id && $cart->lembrete == 1, 'contato e preferência são registrados antes de gerar pedido');
mail_run(); mail_check(mail_sent_count() === 0, 'lembrete não é enviado antes do prazo');
mail_contact($a, $lead, 'lead@example.invalid', true);
mail_check((int) E::cart((int) $a->id, $lead)->id === (int) $cart->id, 'salvar contato repetidamente não duplica carrinho');
mail_cart_due($cart->id); mail_run();
$message = mail_last(); $job = mail_job('carrinho', $cart->id);
mail_check($job->estado === 'enviado' && $job->tentativas == 1 && $message['to'] === 'lead@example.invalid', 'carrinho sem pedido envia um lembrete ao contato informado');
mail_check(strpos($message['message'], 'Material e-mails A') !== false && strpos($message['message'], $lead['nome_aluno']) !== false, 'lembrete personaliza produto e aluno');
mail_check(strpos($message['message'], 'k8_mail_stop') !== false && strpos($message['message'], $lead['cpf_resp']) === false, 'lembrete contém descadastro sem incluir CPF no texto ou link');
mail_check($message['headers'] === ['Content-Type: text/html; charset=UTF-8'], 'envio usa wp_mail HTML sem substituir o remetente do SMTP');
$count = mail_sent_count(); mail_run();
mail_check(mail_sent_count() === $count, 'nova execução não duplica lembrete aceito');
parse_str(parse_url(E::unsubscribe_url($cart), PHP_URL_QUERY), $unsub);
mail_check(!E::unsubscribe($cart->id, str_repeat('0', 64)), 'chave inválida não cancela lembrete');
mail_check(E::unsubscribe($cart->id, $unsub['k8_mail_key']), 'destinatário pode cancelar lembretes com link assinado');
mail_check((int) E::cart((int) $a->id, $lead)->lembrete === 0, 'descadastro persiste na preferência do carrinho');
mail_contact($a, $lead, '', true);
mail_check(E::cart((int) $a->id, $lead)->email === '' && (int) E::cart((int) $a->id, $lead)->lembrete === 0, 'apagar o e-mail interrompe lembretes pendentes');

$free_person = mail_person('GRATUITO');
$free = Service::checkout($c, $free_person, 'Cortesia', '', null, ['email_responsavel' => 'gratuito@example.invalid']);
$stored = Service::order((int) $c->id, $free['id']);
mail_check($stored->email_notificar == 1 && $stored->email_responsavel === 'gratuito@example.invalid' && $stored->email_carrinho_id, 'pedido registra contato e opção transacional sem alterar a compra');
mail_run(); $message = mail_last();
mail_check(mail_job('confirmacao', $free['id'])->estado === 'enviado' && $message['to'] === 'gratuito@example.invalid', 'cortesia confirmada recebe confirmação');
mail_check(strpos($message['message'], 'R$ 0,00') !== false && strpos($message['subject'], (string) $free['id']) !== false, 'confirmação personaliza valor e número do pedido');
$count = mail_sent_count(); Service::checkout($c, $free_person, 'Cortesia', '', null, ['email_responsavel' => 'outro@example.invalid']); mail_run();
mail_check(mail_sent_count() === $count && Service::order((int) $c->id, $free['id'])->email_responsavel === 'gratuito@example.invalid', 'repetir checkout conserva o pedido e evita nova confirmação');

$escaped_person = mail_person('ESCAPE'); $escaped_person['nome_resp'] = '<img src=x onerror=alert(1)> Nome';
$escaped_order = Service::checkout($c, $escaped_person, 'Cortesia', '', null, ['email_responsavel' => 'escape@example.invalid']);
$rendered = E::render($c, 'confirmacao', Service::order((int) $c->id, $escaped_order['id']));
mail_check(strpos($rendered['html'], '&lt;img') !== false && strpos($rendered['html'], '<img src=x') === false, 'dados pessoais são escapados no HTML');
mail_check(strpos($rendered['html'], '[nome_responsavel]') === false && strpos($rendered['html'], 'href="http://') !== false, 'marcadores e links do modelo são substituídos');
mail_run();

// K8 simulada no limite HTTP: nenhuma requisição financeira sai do teste.
update_option('k8p_client_id', 'mail-fixture'); update_option('k8p_client_secret', 'mail-fixture');
update_option('k8p_aes_key', '1234567890123456'); update_option('k8p_producao', '0');
$GLOBALS['mail_charges'] = []; $GLOBALS['mail_links'] = []; $GLOBALS['mail_charge_posts'] = 0; $GLOBALS['mail_k8_calls'] = 0;
add_filter('pre_http_request', static function ($pre, $args, $url) {
    if (strpos($url, 'https://sandbox.meuk8.com.br/') !== 0) return $pre;
    $GLOBALS['mail_k8_calls']++;
    if (!empty($GLOBALS['mail_k8_fail'])) return new WP_Error('fixture', 'Falha K8 simulada.');
    if (substr($url, -6) === '/token') return ['headers' => [], 'body' => '{"access_token":"fixture"}', 'response' => ['code' => 200, 'message' => 'OK']];
    $path = parse_url($url, PHP_URL_PATH); $method = $args['method'];
    $request = !empty($args['body']) ? json_decode(openssl_decrypt(base64_decode($args['body']), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16)), true) : [];
    parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $query);
    if (substr($path, -12) === '/api/Cliente') $response = [['Id' => 'mail-customer']];
    elseif (substr($path, -13) === '/api/Cobranca' && $method === 'POST') {
        $id = 'mail-charge-' . (++$GLOBALS['mail_charge_posts']);
        $GLOBALS['mail_charges'][$id] = ['Id' => $id, 'ClienteId' => 'mail-customer', 'Valor' => $request['Valor'], 'Estado' => 'Pendente'];
        $response = ['Id' => $id];
    } elseif (substr($path, -13) === '/api/Cobranca' && $method === 'GET') $response = $GLOBALS['mail_charges'][$query['Id']];
    elseif ($method === 'POST' && substr($path, -18) === '/api/LinkPagamento') {
        $id = 'mail-link-' . (count($GLOBALS['mail_links']) + 1);
        $GLOBALS['mail_links'][$id] = array_merge($request, ['Id' => $id, 'LinkUrl' => 'https://sandbox.meuk8.com.br/link/' . $id, 'DadosPagamento' => ['Situacao' => 'pendente']]);
        $response = ['Id' => $id];
    } elseif ($method === 'GET' && preg_match('#/api/LinkPagamento/([^/]+)$#', $path, $m)) $response = $GLOBALS['mail_links'][$m[1]];
    elseif ($method === 'GET' && preg_match('#/api/Cobranca/([^/]+)$#', $path, $m)) $response = $GLOBALS['mail_charges'][$m[1]];
    else throw new RuntimeException('Rota K8 não simulada: ' . $method . ' ' . $path);
    return ['headers' => [], 'body' => base64_encode(openssl_encrypt(wp_json_encode($response), 'AES-128-CBC', '1234567890123456', OPENSSL_RAW_DATA, str_repeat("\0", 16))), 'response' => ['code' => 200, 'message' => 'OK']];
}, 10, 3);

$pending_person = mail_person('PIX-PENDENTE');
$pending = Service::checkout($a, $pending_person, 'Pix', '', null, ['email_responsavel' => 'pix@example.invalid', 'lembrete_email' => 1]);
$stored = Service::order((int) $a->id, $pending['id']); $count = mail_sent_count();
mail_run(); mail_check(mail_sent_count() === $count && !mail_job('confirmacao', $stored->id), 'pedido pendente não recebe confirmação de compra paga');
mail_cart_due($stored->email_carrinho_id);
$GLOBALS['mail_charges'][$stored->cobranca_id]['Estado'] = 'Paga';
mail_run();
mail_check(Service::order((int) $a->id, $stored->id)->status_pagamento === 'Paga' && mail_sent_count() === $count, 'antes do abandono consulta K8 e evita cobrar novamente quem já pagou');
mail_check(mail_job('carrinho', $stored->email_carrinho_id)->estado === 'cancelado', 'confirmação remota cancela o lembrete pendente');
mail_run(); mail_check(mail_sent_count() === $count + 1 && mail_last()['to'] === 'pix@example.invalid', 'pagamento identificado na conferência recebe confirmação pela fila');

$card_person = mail_person('CARTAO');
$card = Service::checkout($a, $card_person, 'Cartao', '', null, ['email_responsavel' => 'cartao@example.invalid', 'lembrete_email' => 0]);
$card_order = Service::order((int) $a->id, $card['id']); $link = $card_order->link_pagamento_id;
mail_check($card['redirect'] === $GLOBALS['mail_links'][$link]['LinkUrl'] && $card_order->cartao_parcelas_max == 6, 'e-mail preserva link K8 e limite de parcelas do cartão');
$GLOBALS['mail_charges']['card-paid'] = ['Id' => 'card-paid', 'ClienteId' => 'mail-customer', 'Valor' => 100, 'Estado' => 'Paga'];
$GLOBALS['mail_links'][$link]['DadosPagamento'] = ['Situacao' => 'pago', 'FormaPagamento' => 'Cartao', 'CobrancaId' => 'card-paid', 'NumeroCobrancasPagas' => 1];
Service::sync_order((int) $a->id, $card_order->id); $count = mail_sent_count(); mail_run();
mail_check(mail_sent_count() === $count + 1 && mail_last()['to'] === 'cartao@example.invalid', 'cartão recebe confirmação após validação do link e da cobrança');
foreach (['Pendente', 'Cancelada'] as $state) {
    $p = mail_person('ESTADO-' . $state);
    $order = Service::checkout($a, $p, 'Boleto', '', null, ['email_responsavel' => strtolower($state) . '@example.invalid', 'lembrete_email' => 1]);
    $row = Service::order((int) $a->id, $order['id']); mail_cart_due($row->email_carrinho_id);
    if ($state === 'Cancelada') $wpdb->update(S::table('pedidos'), ['status' => 'cancelado', 'status_pagamento' => 'Cancelada', 'active_key' => null], ['id' => $row->id]);
    $count = mail_sent_count(); mail_run();
    mail_check(mail_sent_count() === $count + ($state === 'Pendente' ? 1 : 0), 'lembrete respeita estado ' . $state . ' do boleto');
    if ($state === 'Pendente') mail_check(strpos(mail_last()['message'], rawurlencode($row->cobranca_id)) !== false, 'lembrete de pedido pendente contém o checkout da cobrança existente');
}

$uncertain = Service::checkout($a, mail_person('CONFERENCIA'), 'Pix', '', null, ['email_responsavel' => 'conferencia@example.invalid', 'lembrete_email' => 1]);
$uncertain_order = Service::order((int) $a->id, $uncertain['id']); mail_cart_due($uncertain_order->email_carrinho_id);
$GLOBALS['mail_k8_fail'] = true; $count = mail_sent_count(); mail_run();
mail_check(mail_sent_count() === $count && mail_job('carrinho', $uncertain_order->email_carrinho_id)->estado === 'fila', 'falha ao conferir a K8 adia o lembrete sem envio');
$GLOBALS['mail_k8_fail'] = false;
$wpdb->update(S::table('pedidos'), ['status' => 'verificacao', 'cobranca_id' => null, 'status_pagamento' => null], ['id' => $uncertain_order->id]);
mail_retry_due(mail_job('carrinho', $uncertain_order->email_carrinho_id)->id); mail_run();
mail_check(mail_sent_count() === $count && mail_job('carrinho', $uncertain_order->email_carrinho_id)->tentativas == 0, 'pedido em conferência não recebe lembrete nem consome tentativas de SMTP');
$wpdb->update(S::table('pedidos'), ['status' => 'cancelado', 'status_pagamento' => 'Cancelada', 'active_key' => null], ['id' => $uncertain_order->id]);

foreach (['inativo', 'turma', 'vinculo', 'expirado'] as $reason) {
    $p = mail_config(mail_product('Restrição ' . $reason));
    $person = mail_person('RESTRICAO-' . $reason);
    $fields = mail_contact($p, $person, $reason . '@example.invalid'); mail_cart_due($fields['email_carrinho_id']);
    if ($reason === 'inativo') $wpdb->update(S::table('produtos'), ['ativo' => 0], ['id' => $p->id]);
    if ($reason === 'turma') $wpdb->update($wpdb->prefix . 'lista_alunos_v4', ['turma' => 'Outra turma'], ['ra' => $person['ra']]);
    if ($reason === 'vinculo') $wpdb->delete($wpdb->prefix . 'lista_alunos_v4', ['ra' => $person['ra']]);
    if ($reason === 'expirado') $wpdb->update(S::table('carrinhos_email'), ['expira_em' => gmdate('Y-m-d H:i:s', time() - 60)], ['id' => $fields['email_carrinho_id']]);
    $count = mail_sent_count(); mail_run();
    mail_check(mail_sent_count() === $count, 'recuperação não envia em caso de ' . $reason);
}

$inactive_free = Service::checkout($c, mail_person('PAGO-INATIVO'), 'Cortesia', '', null, ['email_responsavel' => 'pago-inativo@example.invalid']);
$wpdb->update(S::table('produtos'), ['ativo' => 0], ['id' => $c->id]); $count = mail_sent_count(); mail_run();
mail_check(mail_sent_count() === $count + 1 && mail_last()['to'] === 'pago-inativo@example.invalid', 'desativar a vitrine preserva confirmação de compra já paga');
$wpdb->update(S::table('produtos'), ['ativo' => 1], ['id' => $c->id]);

$failed = Service::checkout($c, mail_person('FALHA-SMTP'), 'Cortesia', '', null, ['email_responsavel' => 'falha@example.invalid']);
$GLOBALS['mail_fail'] = true;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    if ($attempt > 1) mail_retry_due(mail_job('confirmacao', $failed['id'])->id);
    mail_run(); $j = mail_job('confirmacao', $failed['id']);
    mail_check((int) $j->tentativas === $attempt && $j->estado === ($attempt === 3 ? 'falha' : 'fila'), 'SMTP recusado registra e limita tentativa ' . $attempt);
}
mail_rejects(fn() => E::retry((int) $b->id, $j->id), 'nova tentativa não aceita e-mail de outro produto');
E::retry((int) $c->id, $j->id); $GLOBALS['mail_fail'] = false; mail_run();
mail_check(mail_job('confirmacao', $failed['id'])->estado === 'enviado', 'nova tentativa manual recupera confirmação após corrigir SMTP');

$edit_person = mail_person('CORRIGIR');
$edit_fields = mail_contact($a, $edit_person, 'antigo@example.invalid'); mail_cart_due($edit_fields['email_carrinho_id']);
$GLOBALS['mail_fail'] = true; mail_run(); $edit_job = mail_job('carrinho', $edit_fields['email_carrinho_id']);
$mail_count = mail_sent_count(); mail_contact($a, $edit_person, 'corrigido@example.invalid');
mail_check(mail_job('carrinho', $edit_fields['email_carrinho_id'])->email === 'corrigido@example.invalid', 'corrigir contato altera destinatário da fila ainda não enviada');
$GLOBALS['mail_fail'] = false; mail_run();
mail_check(mail_sent_count() === $mail_count, 'nova atividade adia lembrete já enfileirado');
mail_cart_due($edit_fields['email_carrinho_id']); mail_retry_due($edit_job->id); mail_run();
mail_check(mail_last()['to'] === 'corrigido@example.invalid', 'nova tentativa usa o endereço corrigido');

$stop_person = mail_person('PARAR-FILA');
$stop = mail_contact($a, $stop_person, 'parar@example.invalid'); mail_cart_due($stop['email_carrinho_id']);
$GLOBALS['mail_fail'] = true; mail_run(); $GLOBALS['mail_fail'] = false;
$stop_cart = E::cart((int) $a->id, $stop_person);
parse_str(parse_url(E::unsubscribe_url($stop_cart), PHP_URL_QUERY), $stop_args);
E::unsubscribe($stop_cart->id, $stop_args['k8_mail_key']); $count = mail_sent_count();
mail_run(); mail_check(mail_sent_count() === $count && mail_job('carrinho', $stop_cart->id)->estado === 'cancelado', 'descadastro cancela também tentativas de lembrete já na fila');

$crash = Service::checkout($c, mail_person('INTERROMPIDO'), 'Cortesia', '', null, ['email_responsavel' => 'interrompido@example.invalid']);
$GLOBALS['mail_fail'] = true; mail_run(); $GLOBALS['mail_fail'] = false; $crash_job = mail_job('confirmacao', $crash['id']);
$wpdb->update(S::table('emails'), ['estado' => 'enviando', 'iniciado_em' => gmdate('Y-m-d H:i:s', time() - 3600)], ['id' => $crash_job->id]);
$count = mail_sent_count(); mail_run();
mail_check(mail_sent_count() === $count && mail_job('confirmacao', $crash['id'])->estado === 'conferir', 'envio interrompido não é repetido automaticamente com resultado incerto');

$disabled = Service::checkout($c, mail_person('DESLIGAR-FILA'), 'Cortesia', '', null, ['email_responsavel' => 'desligado@example.invalid']);
$GLOBALS['mail_fail'] = true; mail_run(); $GLOBALS['mail_fail'] = false; $disabled_job = mail_job('confirmacao', $disabled['id']);
$c = mail_config($c, ['mail_confirmacao' => 0, 'mail_carrinho' => 0]); mail_retry_due($disabled_job->id); $count = mail_sent_count(); mail_run();
mail_check(mail_sent_count() === $count && mail_job('confirmacao', $disabled['id'])->estado === 'cancelado', 'desligar confirmação cancela envio pendente desse produto');

$_GET = ['produto_id' => $a->id]; ob_start(); Views::admin_products(); $html = ob_get_clean();
mail_check(strpos($html, 'E-mails deste produto') !== false && strpos($html, 'mail_texto_confirmacao') !== false && strpos($html, '[link_pagamento]') !== false, 'editor apresenta opções, modelos e marcadores por produto');
$shortcode = Views::product_shortcode(['id' => $a->id]);
mail_check(strpos($shortcode, 'type="email"') !== false && strpos($shortcode, 'data-k8-reminder') !== false, 'checkout contém contato e preferência de lembrete');
E::register(); E::register();
$cron_count = 0;
foreach (_get_cron_array() as $hooks) if (isset($hooks['k8prod_emails'])) $cron_count += count($hooks['k8prod_emails']);
mail_check($cron_count === 1, 'registro repetido não duplica cron da fila');

// Exercita permissões, nonce e contexto das rotas AJAX sem encerrar o processo CLI.
define('DOING_AJAX', true);
class MailAjaxExit extends RuntimeException {}
add_filter('wp_die_ajax_handler', static function () { return static function () { throw new MailAjaxExit(); }; });
function mail_ajax($public, array $data) {
    $_POST = $data; $_REQUEST = $data; ob_start();
    try { if ($public) Http::public_request(); else Http::manage_request(); } catch (MailAjaxExit $e) {}
    return json_decode(ob_get_clean(), true);
}
$rpc_person = mail_person('RPC'); $token = Students::issue((int) $a->id, $rpc_person);
$nonce = wp_create_nonce('k8prod_public'); $rpc = ['op' => 'save_cart', 'produto_id' => $a->id, 'nonce' => $nonce, 'token' => $token, 'email_responsavel' => 'rpc@example.invalid', 'lembrete_email' => '1'];
mail_check(mail_ajax(true, $rpc)['success'] === true && E::cart((int) $a->id, $rpc_person)->email === 'rpc@example.invalid', 'AJAX grava contato com nonce e vínculo válido');
mail_check(mail_ajax(true, array_merge($rpc, ['nonce' => 'invalido']))['success'] === false, 'AJAX rejeita contato sem nonce');
mail_check(mail_ajax(true, array_merge($rpc, ['produto_id' => $b->id]))['success'] === false, 'contexto de outro produto não autoriza gravação de contato');
mail_check(mail_ajax(true, array_merge($rpc, ['token' => str_repeat('0', 64)]))['success'] === false, 'AJAX rejeita contexto inexistente');
$operator = wp_create_user('mail_operator', 'fixture-only-password', 'operator@example.invalid');
get_user_by('id', $operator)->add_cap('k8_manage_products'); wp_set_current_user($operator);
$manage = array_merge(mail_form(), ['op' => 'save_email_settings', 'produto_id' => $a->id, 'nonce' => wp_create_nonce('k8prod_manage')]);
mail_check(mail_ajax(false, $manage)['success'] === false, 'operador não altera configuração de e-mails restrita ao administrador');
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
$manage['nonce'] = wp_create_nonce('k8prod_manage');
mail_check(mail_ajax(false, $manage)['success'] === true, 'administrador salva modelos do produto pela rota autenticada');
mail_check($wpdb->last_error === '', 'validação termina sem erro SQL');
echo "\n$checks verificações de e-mails aprovadas em WordPress " . get_bloginfo('version') . ", PHP " . PHP_VERSION . ".\n";
