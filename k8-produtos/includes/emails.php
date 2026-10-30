<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

/** Mensagens transacionais por produto. O transporte é sempre o wp_mail() do site. */
final class Emails
{
    public static function register(): void
    {
        add_action('k8prod_emails', [self::class, 'run']);
        add_action('template_redirect', [self::class, 'unsubscribe_page'], 0);
        if (!wp_next_scheduled('k8prod_emails')) {
            wp_schedule_event(time() + 90, 'k8prod_five_minutes', 'k8prod_emails');
        }
    }

    public static function defaults(): array
    {
        return [
            'confirmacao' => false, 'carrinho' => false, 'espera' => 60,
            'assunto_confirmacao' => 'Pagamento confirmado: [produto] — pedido #[numero_pedido]',
            'texto_confirmacao' => "Olá, [nome_responsavel]!\n\nRecebemos a confirmação do pagamento do seu pedido #[numero_pedido].\n\nProduto: [produto]\nAluno: [nome_aluno]\nTurma: [turma]\nValor: [valor]\nForma de pagamento: [forma_pagamento]\n\n<a href=\"[link_acompanhamento]\">Acompanhar meu pedido e a entrega</a>\n\nObrigado pela compra!\n[site]",
            'assunto_carrinho' => 'Falta concluir sua compra de [produto]',
            'texto_carrinho' => "Olá, [nome_responsavel]!\n\nVocê iniciou a compra de [produto] para [nome_aluno], mas ainda não identificamos a conclusão do pagamento.\n\nValor: [valor]\n\n<a href=\"[link_pagamento]\">Retomar minha compra</a>\n\nSe ainda não gerou um pedido, os preços e as condições serão conferidos novamente ao retornar. Para acompanhar pelo site, informe o CPF do responsável e selecione o aluno.\n\n[site]",
        ];
    }

    public static function settings(object $product): array
    {
        $stored = json_decode((string) ($product->emails_config ?? ''), true);
        $config = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        $config['confirmacao'] = !empty($product->email_confirmacao);
        $config['carrinho'] = !empty($product->email_carrinho);
        return $config;
    }

    public static function markers(): array
    {
        return [
            'nome_responsavel' => 'Nome do responsável pela compra', 'email_responsavel' => 'E-mail informado na compra',
            'nome_aluno' => 'Nome do aluno', 'ra' => 'RA do aluno', 'turma' => 'Turma do aluno',
            'produto' => 'Nome do produto', 'numero_pedido' => 'Número do pedido, ou “Ainda não gerado”',
            'valor' => 'Valor do pedido ou preço atual para o carrinho', 'valor_original' => 'Preço antes dos descontos',
            'desconto' => 'Economia em reais', 'cupom' => 'Cupom aplicado, quando houver',
            'forma_pagamento' => 'Pix, boleto, cartão ou cortesia', 'parcelas' => 'Limite autorizado no cartão; não indica a quantidade escolhida na K8',
            'data_compra' => 'Data de criação do pedido ou carrinho', 'site' => 'Nome do site',
            'link_pagamento' => 'Checkout K8 do pedido pendente; sem pedido, página do produto',
            'link_produto' => 'Página pública do produto', 'link_acompanhamento' => 'Página do produto para identificar o responsável e consultar o pedido',
            'link_descadastro' => 'Link para cancelar somente os lembretes deste produto',
        ];
    }

    private static function flag($value): bool
    {
        if (!in_array($value, [0, 1, '0', '1', false, true], true)) {
            throw new \InvalidArgumentException('Escolha uma opção válida para os e-mails.');
        }
        return in_array($value, [1, '1', true], true);
    }

    public static function input(array $data): array
    {
        $config = self::defaults();
        $config['confirmacao'] = self::flag($data['mail_confirmacao'] ?? 0);
        $config['carrinho'] = self::flag($data['mail_carrinho'] ?? 0);
        $wait = $data['mail_espera'] ?? 60;
        if (!is_scalar($wait) || !preg_match('/^\d{1,5}$/D', (string) $wait) || (int) $wait < 15 || (int) $wait > 10080) {
            throw new \InvalidArgumentException('O prazo de carrinho abandonado deve ficar entre 15 e 10.080 minutos.');
        }
        $config['espera'] = (int) $wait;
        foreach (['confirmacao', 'carrinho'] as $type) {
            foreach (['assunto', 'texto'] as $field) {
                $key = $field . '_' . $type;
                $raw = $data['mail_' . $key] ?? $config[$key];
                if (!is_string($raw) || strlen($raw) > ($field === 'assunto' ? 800 : 30000)) {
                    throw new \InvalidArgumentException('Confira o tamanho dos assuntos e textos dos e-mails.');
                }
                $clean = $field === 'assunto' ? sanitize_text_field($raw) : wp_kses_post($raw);
                if (!trim(wp_strip_all_tags($clean))) throw new \InvalidArgumentException('Preencha os assuntos e os textos dos e-mails.');
                preg_match_all('/\[([A-Za-z_]+)\]/', $clean, $matches);
                if (array_diff($matches[1], array_keys(self::markers()))) {
                    throw new \InvalidArgumentException('Há um marcador desconhecido no e-mail. Use os marcadores disponíveis nesta tela.');
                }
                $config[$key] = $clean;
            }
        }
        return $config;
    }

    public static function save(int $product, array $data): void
    {
        global $wpdb;
        Support::product($product);
        $config = self::input($data);
        Support::must($wpdb->update(Support::table('produtos'), [
            'email_confirmacao' => (int) $config['confirmacao'], 'email_carrinho' => (int) $config['carrinho'],
            'emails_config' => wp_json_encode($config),
        ], ['id' => $product]));
        $carts = Support::table('carrinhos_email');
        Support::must($wpdb->query($wpdb->prepare("UPDATE $carts SET lembrar_em = DATE_ADD(atualizado_em, INTERVAL %d MINUTE) WHERE produto_id = %d",
            $config['espera'], $product)));
    }

    private static function cart_key(int $product, array $person): string
    {
        return hash('sha256', wp_json_encode([$product, $person['ra'], $person['cpf_resp']]));
    }

    public static function cart(int $product, array $person): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Support::table('carrinhos_email') . ' WHERE chave = %s',
            self::cart_key($product, $person))) ?: null;
    }

    public static function checkout_options(object $product, array $person): array
    {
        $config = self::settings($product);
        $active = $config['confirmacao'] || $config['carrinho'];
        $cart = $active ? self::cart((int) $product->id, $person) : null;
        return ['ativo' => $active, 'obrigatorio' => $config['confirmacao'], 'lembrete_disponivel' => $config['carrinho'],
            'email' => $cart ? $cart->email : '', 'lembrete' => $cart && (bool) $cart->lembrete];
    }

    /** Valida e salva contato somente após vínculo de CPF/aluno e produto serem conferidos. */
    public static function contact(object $product, array $person, array $data, string $coupon = '', bool $checkout = false): array
    {
        $product = Support::product((int) $product->id, true);
        $config = self::settings($product);
        $empty = ['email_responsavel' => null, 'email_notificar' => 0, 'email_carrinho_id' => null];
        if (!$config['confirmacao'] && !$config['carrinho']) return $empty;
        $raw = $data['email_responsavel'] ?? '';
        if (!is_string($raw)) throw new \InvalidArgumentException('Informe um e-mail válido.');
        $email = trim($raw);
        $reminder = $config['carrinho'] && self::flag($data['lembrete_email'] ?? 0);
        if ($email === '' && !$checkout) $reminder = false;
        if (($checkout && $config['confirmacao']) || $reminder || $email !== '') {
            if (strlen($email) > 254 || preg_match('/[\r\n]/', $email) || !is_email($email)) {
                throw new \InvalidArgumentException('Informe um e-mail válido para receber as mensagens da compra.');
            }
        }
        try { $quote = Service::quote($product, $person, $coupon); }
        catch (\InvalidArgumentException $e) { $quote = Service::quote($product, $person); }
        return Support::lock('mail-cart:' . self::cart_key((int) $product->id, $person), static function () use ($product, $person, $email, $reminder, $quote, $config, $empty) {
            global $wpdb;
            $table = Support::table('carrinhos_email');
            $cart = self::cart((int) $product->id, $person);
            if (!$cart && $email === '') return $empty;
            $now = gmdate('Y-m-d H:i:s');
            $record = ['produto_id' => $product->id, 'chave' => self::cart_key((int) $product->id, $person),
                'ra' => $person['ra'], 'cpf_resp' => $person['cpf_resp'], 'nome_aluno' => $person['nome_aluno'],
                'turma' => $person['turma'], 'nome_resp' => $person['nome_resp'], 'email' => $email,
                'lembrete' => (int) $reminder, 'cupom' => $quote['cupom'], 'atualizado_em' => $now,
                'lembrar_em' => gmdate('Y-m-d H:i:s', time() + $config['espera'] * MINUTE_IN_SECONDS),
                'expira_em' => gmdate('Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS)];
            if ($cart) {
                Support::must($wpdb->update($table, $record, ['id' => $cart->id]));
                $id = (int) $cart->id;
            } else {
                $record['criado_em'] = $now;
                Support::must($wpdb->insert($table, $record));
                $id = (int) $wpdb->insert_id;
            }
            // Uma correção de e-mail ou nova atividade atualiza a fila ainda não enviada.
            $messages = Support::table('emails');
            Support::must($wpdb->query($wpdb->prepare("UPDATE $messages SET email = %s, disponivel_em = %s
                WHERE carrinho_id = %d AND tipo = 'carrinho' AND estado = 'fila'", $email, $record['lembrar_em'], $id)));
            if ($reminder) {
                Support::must($wpdb->query($wpdb->prepare("UPDATE $messages SET estado = 'fila', email = %s, disponivel_em = %s, erro = NULL
                    WHERE carrinho_id = %d AND tipo = 'carrinho' AND estado = 'cancelado' AND tentativas = 0", $email, $record['lembrar_em'], $id)));
            }
            return ['email_responsavel' => $email ?: null, 'email_notificar' => (int) ($config['confirmacao'] && $email !== ''), 'email_carrinho_id' => $id];
        });
    }

    public static function attach(int $cart, int $order): void
    {
        global $wpdb;
        if ($cart) Support::must($wpdb->update(Support::table('carrinhos_email'), ['pedido_id' => $order], ['id' => $cart]));
    }

    private static function enqueue(string $type, int $product, string $email, string $key, int $order = 0, int $cart = 0): void
    {
        global $wpdb;
        if (!is_email($email)) return;
        $table = Support::table('emails');
        $now = gmdate('Y-m-d H:i:s');
        Support::must($wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table
            (produto_id,tipo,email,chave,pedido_id,carrinho_id,estado,tentativas,disponivel_em,criado_em)
            VALUES (%d,%s,%s,%s,%d,%d,'fila',0,%s,%s)", $product, $type, $email, $key, $order, $cart, $now, $now)));
    }

    private static function queue_due(): void
    {
        global $wpdb;
        $orders = Support::table('pedidos'); $products = Support::table('produtos');
        $messages = Support::table('emails'); $carts = Support::table('carrinhos_email');
        $paid = $wpdb->get_results("SELECT o.* FROM $orders o INNER JOIN $products p ON p.id = o.produto_id
            LEFT JOIN $messages m ON m.chave = CONCAT('confirmacao:',o.id)
            WHERE p.email_confirmacao = 1 AND o.email_notificar = 1 AND o.status_pagamento = 'Paga' AND m.id IS NULL
            ORDER BY o.id LIMIT 30");
        foreach ($paid as $order) {
            self::enqueue('confirmacao', (int) $order->produto_id, (string) $order->email_responsavel, 'confirmacao:' . $order->id,
                (int) $order->id, (int) $order->email_carrinho_id);
        }
        $due = $wpdb->get_results($wpdb->prepare("SELECT c.* FROM $carts c INNER JOIN $products p ON p.id = c.produto_id
            LEFT JOIN $messages m ON m.chave = CONCAT('carrinho:',c.id)
            WHERE p.ativo = 1 AND p.email_carrinho = 1 AND c.lembrete = 1 AND c.email <> ''
            AND c.lembrar_em <= %s AND c.expira_em > %s AND m.id IS NULL ORDER BY c.lembrar_em,c.id LIMIT 30",
            gmdate('Y-m-d H:i:s'), gmdate('Y-m-d H:i:s')));
        foreach ($due as $cart) {
            self::enqueue('carrinho', (int) $cart->produto_id, $cart->email, 'carrinho:' . $cart->id,
                (int) $cart->pedido_id, (int) $cart->id);
        }
    }

    private static function unsubscribe_key(object $cart): string
    {
        return hash_hmac('sha256', $cart->id . ':' . $cart->produto_id . ':' . $cart->email . ':' . $cart->criado_em, wp_salt('auth'));
    }

    public static function unsubscribe_url(object $cart): string
    {
        return add_query_arg(['k8_mail_stop' => $cart->id, 'k8_mail_key' => self::unsubscribe_key($cart)], home_url('/'));
    }

    public static function unsubscribe(int $id, string $key): bool
    {
        global $wpdb;
        $cart = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Support::table('carrinhos_email') . ' WHERE id = %d', $id));
        if (!$cart || !preg_match('/^[a-f0-9]{64}$/D', $key) || !hash_equals(self::unsubscribe_key($cart), $key)) return false;
        Support::must($wpdb->update(Support::table('carrinhos_email'), ['lembrete' => 0], ['id' => $id]));
        Support::must($wpdb->query($wpdb->prepare("UPDATE " . Support::table('emails') . " SET estado = 'cancelado', erro = 'Lembretes cancelados pelo destinatário.'
            WHERE carrinho_id = %d AND tipo = 'carrinho' AND estado = 'fila'", $id)));
        return true;
    }

    public static function unsubscribe_page(): void
    {
        if (!isset($_GET['k8_mail_stop'])) return;
        nocache_headers();
        header('Referrer-Policy: no-referrer');
        $id = absint($_GET['k8_mail_stop']);
        $raw = wp_unslash($_GET['k8_mail_key'] ?? '');
        $key = is_string($raw) ? $raw : '';
        global $wpdb;
        $cart = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Support::table('carrinhos_email') . ' WHERE id = %d', $id));
        if (!$cart || !preg_match('/^[a-f0-9]{64}$/D', $key) || !hash_equals(self::unsubscribe_key($cart), $key)) {
            wp_die('Link de cancelamento inválido.', 'Lembretes', ['response' => 400]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            self::unsubscribe($id, $key);
            wp_die('Os lembretes deste produto foram cancelados. A confirmação de compras continua disponível.', 'Lembretes cancelados', ['response' => 200]);
        }
        wp_die('<p>Deseja parar de receber lembretes deste produto?</p><form method="post"><button type="submit">Cancelar lembretes</button></form>',
            'Cancelar lembretes', ['response' => 200]);
    }

    private static function money(float $value): string
    {
        return 'R$ ' . number_format($value, 2, ',', '.');
    }

    public static function render(object $product, string $type, object $source, ?object $cart = null): array
    {
        if (!in_array($type, ['confirmacao', 'carrinho'], true)) throw new \InvalidArgumentException('Tipo de e-mail inválido.');
        $config = self::settings($product);
        $is_order = isset($source->status_pagamento);
        $url = Storefront::url((int) $product->id);
        $payment = $is_order ? (Service::public_order($source)['redirect'] ?: $url) : $url;
        if (!$is_order) {
            $person = ['ra' => $source->ra, 'cpf_resp' => $source->cpf_resp, 'nome_aluno' => $source->nome_aluno,
                'nome_resp' => $source->nome_resp, 'turma' => $source->turma];
            try { $quote = Service::quote($product, $person, (string) $source->cupom); }
            catch (\InvalidArgumentException $e) { $quote = Service::quote($product, $person); }
        }
        $value = $is_order ? (float) $source->valor : $quote['valor'];
        $original = $is_order ? (float) ($source->valor_original ?? $source->valor) : $quote['original'];
        $count = $is_order ? (int) $source->cartao_parcelas_max : (int) $quote['parcelas_cartao_max'];
        $method = $is_order ? $source->metodo : '';
        $values = [
            'nome_responsavel' => $source->nome_resp, 'email_responsavel' => $is_order ? $source->email_responsavel : $source->email,
            'nome_aluno' => $source->nome_aluno, 'ra' => $source->ra, 'turma' => $source->turma, 'produto' => $product->nome,
            'numero_pedido' => $is_order ? (string) $source->id : 'Ainda não gerado',
            'valor' => self::money($value), 'valor_original' => self::money($original), 'desconto' => self::money(max(0, $original - $value)),
            'cupom' => $is_order ? (string) $source->cupom_codigo : $quote['cupom'],
            'forma_pagamento' => $is_order ? (PaymentTerms::labels()[$method] ?? 'Cortesia') : 'A escolher',
            'parcelas' => $method === 'Cartao' ? ($count > 1 ? 'Até ' . $count . 'x' : 'À vista') : ($is_order ? 'À vista' : 'Conforme a forma de pagamento'),
            'data_compra' => $is_order ? mysql2date('d/m/Y H:i', $source->criado_em) : get_date_from_gmt($source->criado_em, 'd/m/Y H:i'),
            'site' => wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES),
            'link_pagamento' => $payment, 'link_produto' => $url, 'link_acompanhamento' => $url,
            'link_descadastro' => $cart ? self::unsubscribe_url($cart) : '',
        ];
        if ($type === 'carrinho' && $cart) {
            $values['nome_responsavel'] = $cart->nome_resp;
            $values['email_responsavel'] = $cart->email;
        }
        $plain = []; $html = [];
        foreach ($values as $key => $value) {
            $plain['[' . $key . ']'] = (string) $value;
            $html['[' . $key . ']'] = strpos($key, 'link_') === 0 ? esc_url((string) $value) : esc_html((string) $value);
        }
        $subject = sanitize_text_field(strtr($config['assunto_' . $type], $plain));
        $body = wpautop(strtr(wp_kses_post($config['texto_' . $type]), $html));
        if ($type === 'carrinho' && $cart) {
            $body .= '<p style="font-size:12px"><a href="' . esc_url(self::unsubscribe_url($cart)) . '">Cancelar lembretes deste produto</a></p>';
        }
        return ['assunto' => $subject, 'html' => '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"></head><body><div style="font-family:Arial,sans-serif;line-height:1.6;max-width:640px;margin:auto;padding:24px">' . $body . '</div></body></html>'];
    }

    private static function send(string $email, array $message): array
    {
        $error = 'O WordPress/SMTP não aceitou o envio.';
        $capture = static function ($failure) use (&$error) {
            if (is_wp_error($failure)) $error = substr(sanitize_text_field($failure->get_error_message()), 0, 500);
        };
        add_action('wp_mail_failed', $capture);
        try {
            $ok = wp_mail($email, $message['assunto'], $message['html'], ['Content-Type: text/html; charset=UTF-8']);
        } catch (\Throwable $e) {
            $ok = false; $error = substr(sanitize_text_field($e->getMessage()), 0, 500);
        } finally {
            remove_action('wp_mail_failed', $capture);
        }
        return ['ok' => (bool) $ok, 'erro' => $ok ? null : $error];
    }

    private static function process(object $job): string
    {
        global $wpdb;
        $table = Support::table('emails');
        $started = gmdate('Y-m-d H:i:s');
        $claimed = Support::must($wpdb->query($wpdb->prepare("UPDATE $table SET estado = 'enviando', iniciado_em = %s
            WHERE id = %d AND estado = 'fila' AND disponivel_em <= %s", $started, $job->id, $started)));
        if ($claimed !== 1) return 'ignorados';
        $attempted = false;
        try {
            $product = Support::product((int) $job->produto_id);
            $config = self::settings($product);
            $cart = $job->carrinho_id ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Support::table('carrinhos_email') . ' WHERE id = %d AND produto_id = %d',
                $job->carrinho_id, $product->id)) : null;
            $source = null;
            if ($job->tipo === 'confirmacao') {
                $source = Service::order((int) $product->id, (int) $job->pedido_id);
                $eligible = $config['confirmacao'] && (int) $source->email_notificar === 1 && $source->status_pagamento === 'Paga'
                    && $source->email_responsavel === $job->email;
            } else {
                $eligible = $config['carrinho'] && (int) $product->ativo === 1 && $cart && (int) $cart->lembrete === 1
                    && $cart->email === $job->email && $cart->expira_em > $started;
                if ($eligible) {
                    $people = Students::find($cart->cpf_resp, $cart->ra);
                    $eligible = $people && ProductClasses::allows($product, $people[0]['turma']);
                    if ($eligible) {
                        $cart = clone $cart;
                        foreach (['nome_resp', 'nome_aluno', 'turma'] as $field) $cart->$field = $people[0][$field];
                    }
                }
                if ($eligible && $cart->lembrar_em > $started) {
                    Support::must($wpdb->update($table, ['estado' => 'fila', 'disponivel_em' => $cart->lembrar_em], ['id' => $job->id]));
                    return 'adiados';
                }
                if ($eligible) {
                    $active = Service::active((int) $product->id, $cart->ra);
                    $source = $active ?: ($cart->pedido_id ? Service::order((int) $product->id, (int) $cart->pedido_id) : $cart);
                    if (isset($source->status_pagamento)) {
                        if ($source->cobranca_id || $source->link_pagamento_id) $source = Service::sync_order((int) $product->id, (int) $source->id);
                        if (in_array($source->status, ['iniciado', 'verificacao'], true)) {
                            throw new \RuntimeException('O pedido aguarda conferência do pagamento na K8.');
                        }
                        $eligible = $source->status === 'gerado' && in_array($source->status_pagamento, ['Pendente', 'Inadimplente'], true)
                            && (bool) Service::public_order($source)['redirect'];
                    }
                }
            }
            if (!$eligible || !$source || !is_email($job->email)) {
                Support::must($wpdb->update($table, ['estado' => 'cancelado', 'erro' => 'Envio não aplicável: confira situação, produto, contato e preferência de lembrete.'], ['id' => $job->id]));
                return 'cancelados';
            }
            $message = self::render($product, $job->tipo, $source, $cart);
            $attempted = true;
            $result = self::send($job->email, $message);
            $attempts = (int) $job->tentativas + 1;
            $update = ['tentativas' => $attempts, 'erro' => $result['erro']];
            if ($result['ok']) {
                $update += ['estado' => 'enviado', 'enviado_em' => gmdate('Y-m-d H:i:s')];
            } else {
                $update += ['estado' => $attempts >= 3 ? 'falha' : 'fila',
                    'disponivel_em' => gmdate('Y-m-d H:i:s', time() + ($attempts === 1 ? 5 : 15) * MINUTE_IN_SECONDS)];
            }
            Support::must($wpdb->update($table, $update, ['id' => $job->id]));
            return $result['ok'] ? 'enviados' : 'falhas';
        } catch (\Throwable $e) {
            $wpdb->update($table, ['estado' => $attempted ? 'conferir' : 'fila',
                'disponivel_em' => gmdate('Y-m-d H:i:s', time() + 15 * MINUTE_IN_SECONDS),
                'erro' => $attempted ? 'Resultado do envio incerto. Confira o log do SMTP antes de repetir.' : 'Envio adiado: ' . substr(sanitize_text_field($e->getMessage()), 0, 400)],
                ['id' => $job->id]);
            return 'adiados';
        }
    }

    public static function run(): array
    {
        try {
            $result = Support::lock('mail-worker', static function () {
                global $wpdb;
                $table = Support::table('emails');
                Support::must($wpdb->query($wpdb->prepare("UPDATE $table SET estado = 'conferir', erro = 'Envio interrompido. Confira o SMTP antes de repetir.'
                    WHERE estado = 'enviando' AND iniciado_em < %s", gmdate('Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS))));
                self::queue_due();
                $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE estado = 'fila' AND disponivel_em <= %s
                    ORDER BY (tipo = 'confirmacao') DESC,id LIMIT 10", gmdate('Y-m-d H:i:s')));
                $totals = ['enviados' => 0, 'falhas' => 0, 'cancelados' => 0, 'adiados' => 0, 'ignorados' => 0];
                $start = microtime(true);
                foreach ($jobs as $job) {
                    if (microtime(true) - $start > 15) break;
                    $totals[self::process($job)]++;
                }
                return $totals;
            });
        } catch (\Throwable $e) {
            $result = ['erro' => substr(sanitize_text_field($e->getMessage()), 0, 400)];
        }
        update_option('k8prod_mail_worker_state', ['data' => gmdate('Y-m-d H:i:s')] + $result, false);
        return $result;
    }

    public static function retry(int $product, int $id): void
    {
        global $wpdb;
        $table = Support::table('emails');
        $affected = Support::must($wpdb->query($wpdb->prepare("UPDATE $table SET estado = 'fila', tentativas = 0,
            disponivel_em = %s, erro = NULL WHERE id = %d AND produto_id = %d AND estado IN ('falha','conferir')",
            gmdate('Y-m-d H:i:s'), $id, $product)));
        if ($affected !== 1) throw new \InvalidArgumentException('Este e-mail não está disponível para nova tentativa neste produto.');
    }
}
