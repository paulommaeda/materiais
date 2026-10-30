<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Http
{
    public static function register(): void
    {
        add_action('wp_ajax_k8prod_public', [self::class, 'public_request']);
        add_action('wp_ajax_nopriv_k8prod_public', [self::class, 'public_request']);
        add_action('wp_ajax_k8prod_manage', [self::class, 'manage_request']);
    }

    private static function rate(string $key, int $limit, int $seconds): void
    {
        $name = 'k8prod_rate_' . hash_hmac('sha256', $key, wp_salt('nonce'));
        $state = get_transient($name);
        if (!$state) {
            $state = ['count' => 0, 'until' => time() + $seconds];
        }
        if ($state['count'] >= $limit) {
            throw new \RuntimeException('Muitas consultas. Aguarde alguns minutos antes de tentar novamente.');
        }
        $state['count']++;
        set_transient($name, $state, max(1, $state['until'] - time()));
    }

    public static function public_request(): void
    {
        nocache_headers();
        $data = wp_unslash($_POST);
        $op = Support::text($data, 'op');
        if ($op === 'bootstrap') {
            wp_send_json_success(['nonce' => wp_create_nonce('k8prod_public')]);
        }
        if (!check_ajax_referer('k8prod_public', 'nonce', false)) {
            wp_send_json_error(['message' => 'Atualize a página para continuar.'], 403);
        }
        try {
            if ($op === 'catalog_prices') {
                $requested = isset($data['ids']) && is_array($data['ids']) ? array_slice(array_map('absint', $data['ids']), 0, 100) : [];
                $items = array_values(array_filter(Storefront::catalog(), static function ($p) use ($requested) {
                    return in_array((int) $p->id, $requested, true);
                }));
                $html = [];
                foreach ($items as $item) {
                    $html[$item->id] = Storefront::price_html($item);
                }
                $ids = array_map('intval', array_column($items, 'id'));
                wp_send_json_success(['prices' => (object) $html, 'refresh_after' => min(Discounts::refresh_after($ids), PaymentTerms::refresh_after($ids))]);
            }
            $id = absint($data['produto_id'] ?? 0);
            try {
                $product = Support::product($id, true);
            } catch (\InvalidArgumentException $e) {
                if ($op === 'availability') wp_send_json_success(['available' => false]);
                wp_send_json_error(['message' => 'Produto indisponível.', 'code' => 'product_unavailable'], 404);
            }
            if ($op === 'availability') wp_send_json_success(['available' => true]);
            if ($op === 'lookup') {
                $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
                self::rate('lookup-ip:' . $ip, 30, 15 * MINUTE_IN_SECONDS);
                $cpf = Support::cpf(Support::text($data, 'cpf'));
                if (!Support::valid_cpf($cpf)) {
                    throw new \InvalidArgumentException('Informe um CPF válido do responsável.');
                }
                self::rate('lookup-cpf:' . $cpf, 12, 15 * MINUTE_IN_SECONDS);
                $students = [];
                foreach (Students::find($cpf) as $person) {
                    $existing = Service::active($id, $person['ra']);
                    $price = Service::price($product, $person['turma']);
                    if ($price === null && !$existing) {
                        continue;
                    }
                    $students[] = ['ra' => $person['ra'], 'nome_aluno' => $person['nome_aluno'], 'turma' => $person['turma'],
                        'responsavel' => $person['nome_resp'], 'valor' => $existing ? (float) $existing->valor : $price,
                        'token' => Students::issue($id, $person)];
                }
                if (!$students) {
                    throw new \InvalidArgumentException('Nenhum aluno com produto disponível foi encontrado para este CPF. Contate a secretaria.');
                }
                wp_send_json_success(['students' => $students]);
            }
            $person = Students::context($id, Support::text($data, 'token'));
            if ($op === 'save_cart') {
                self::rate('cart:' . $id . ':' . $person['ra'], 40, 10 * MINUTE_IN_SECONDS);
                Emails::contact($product, $person, $data, Support::text($data, 'cupom'));
                wp_send_json_success(['saved' => true]);
            }
            if ($op === 'quote') {
                wp_send_json_success(Service::quote($product, $person, Support::text($data, 'cupom')));
            }
            if ($op === 'checkout') {
                self::rate('checkout:' . $id . ':' . $person['ra'], 8, 10 * MINUTE_IN_SECONDS);
                wp_send_json_success(Service::checkout($product, $person, Support::text($data, 'metodo'), Support::text($data, 'cupom'), Support::text($data, 'chave_preco'), $data));
            }
            if ($op === 'status') {
                $order = Service::active($id, $person['ra']);
                if ($order && ($order->cobranca_id || $order->link_pagamento_id) && (!$order->verificado_em || strtotime($order->verificado_em) < current_time('timestamp') - 60)) {
                    try {
                        $order = Service::sync_order($id, (int) $order->id);
                    } catch (\Throwable $e) {
                        // Mantém o último estado conhecido sem revelar respostas do provedor.
                    }
                }
                wp_send_json_success(['order' => $order ? Service::public_order($order) : null]);
            }
            throw new \InvalidArgumentException('Operação inválida.');
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    public static function manage_request(): void
    {
        nocache_headers();
        if (!check_ajax_referer('k8prod_manage', 'nonce', false)) {
            wp_send_json_error(['message' => 'Atualize a página para continuar.'], 403);
        }
        try {
            $data = wp_unslash($_POST);
            $op = Support::text($data, 'op');
            $id = absint($data['produto_id'] ?? 0);
            if (in_array($op, ['save_product', 'set_product_status', 'save_email_settings', 'settings'], true)) {
                if (!current_user_can('manage_options')) {
                    throw new \RuntimeException('Somente administradores podem alterar esta configuração.');
                }
            } elseif (!$id || !Support::can_manage($id)) {
                throw new \RuntimeException('Sem permissão para administrar este produto.');
            }
            if ($id) {
                Support::product($id);
            }
            $result = self::mutate($op, $id, $data);
            wp_send_json_success(array_merge(['message' => 'Alteração salva.'], $result));
        } catch (\Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()], 400);
        }
    }

    private static function mutate(string $op, int $id, array $data): array
    {
        global $wpdb;
        if ($op === 'set_product_status') {
            $product = Support::product($id);
            $active = $data['ativo'] ?? null;
            if (!in_array($active, [0, 1, '0', '1'], true)) throw new \InvalidArgumentException('Escolha uma situação válida para o produto.');
            if ((int) $active === 1 && $product->valor_padrao === null) throw new \InvalidArgumentException('Informe o preço normal do produto antes de reativá-lo.');
            Support::must($wpdb->update(Support::table('produtos'), ['ativo' => (int) $active], ['id' => $id]));
            do_action('k8prod_product_visibility_changed', $id, (bool) $active);
            return ['message' => (int) $active ? 'Produto reativado.' : 'Produto desativado e ocultado do site.'];
        }
        if ($op === 'save_email_settings') {
            Emails::save($id, $data);
            return ['message' => 'E-mails deste produto configurados.', 'redirect' => admin_url('admin.php?page=k8prod-products&produto_id=' . $id . '#emails-produto')];
        }
        if ($op === 'retry_email') {
            Emails::retry($id, absint($data['id'] ?? 0));
            return ['message' => 'E-mail colocado na fila para nova tentativa.', 'redirect' => admin_url('admin.php?page=k8prod-products&produto_id=' . $id . '#emails-produto')];
        }
        if ($op === 'save_product') {
            $name = Support::text($data, 'nome');
            $stages = (int) ($data['etapas'] ?? 1);
            $days = (int) ($data['vencimento_dias'] ?? 5);
            if (!$name || strlen($name) > 255 || !in_array($stages, [1, 2], true) || $days < 1 || $days > 365) {
                throw new \InvalidArgumentException('Confira nome, etapas e vencimento do produto.');
            }
            if ($id && $stages === 1 && (int) Support::product($id)->etapas === 2) {
                $materials = Support::table('materiais');
                $deliveries = Support::table('entregas');
                if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $deliveries WHERE produto_id = %d AND semestre = 2 LIMIT 1", $id)) ||
                    $wpdb->get_var($wpdb->prepare("SELECT id FROM $materials WHERE produto_id = %d AND periodo_entrega IN ('semestre_2','bimestre_3','bimestre_4') LIMIT 1", $id))) {
                    throw new \InvalidArgumentException('O produto já possui materiais ou entregas da segunda etapa. Mantenha duas etapas.');
                }
            }
            $classes = ProductClasses::input($data);
            $payment_terms = PaymentTerms::input($data);
            if (!empty($data['ativo']) && Support::text($data, 'valor_padrao') === '') {
                throw new \InvalidArgumentException('Informe o preço normal deste produto para mantê-lo ativo.');
            }
            $record = ['nome' => $name, 'descricao' => sanitize_textarea_field($data['descricao'] ?? ''),
                'turmas_disponiveis' => wp_json_encode($classes),
                'valor_padrao' => Support::text($data, 'valor_padrao') === '' ? null : Support::money($data['valor_padrao']),
                'etapas' => $stages, 'permitir_negativo' => empty($data['permitir_negativo']) ? 0 : 1,
                'vencimento_dias' => $days, 'ativo' => empty($data['ativo']) ? 0 : 1] + $payment_terms;
            $category = Support::text($data, 'categoria') ?: 'didatico';
            if (!isset(Storefront::categories()[$category])) {
                throw new \InvalidArgumentException('Escolha uma categoria válida.');
            }
            $image = absint($data['imagem_id'] ?? 0);
            if ($image && !wp_attachment_is_image($image)) {
                throw new \InvalidArgumentException('Escolha uma imagem da biblioteca de mídia.');
            }
            $record += ['categoria' => $category, 'imagem_id' => $image,
                'publicado' => empty($data['publicado']) ? 0 : 1, 'ordem' => (int) ($data['ordem'] ?? 0)];
            $table = Support::table('produtos');
            if ($id) {
                Support::must($wpdb->update($table, $record, ['id' => $id]));
            } else {
                $record['slug'] = substr(sanitize_title($name), 0, 160) . '-' . substr(bin2hex(random_bytes(5)), 0, 10);
                Support::must($wpdb->insert($table, $record));
                $id = (int) $wpdb->insert_id;
            }
            do_action('k8prod_product_visibility_changed', $id, (bool) $record['ativo']);
            return ['redirect' => admin_url('admin.php?page=k8prod-products&produto_id=' . $id), 'produto_id' => $id];
        }
        if ($op === 'save_discount') {
            Discounts::save($id, $data);
            return ['redirect' => admin_url('admin.php?page=k8prod-products&produto_id=' . $id . '#descontos-data')];
        } elseif ($op === 'save_payment_period') {
            PaymentTerms::save($id, $data);
            return ['redirect' => admin_url('admin.php?page=k8prod-products&produto_id=' . $id . '#parcelamento-data')];
        } elseif ($op === 'toggle_payment_period') {
            PaymentTerms::toggle($id, absint($data['id'] ?? 0));
        } elseif ($op === 'toggle_discount') {
            Discounts::toggle($id, absint($data['id'] ?? 0));
        } elseif ($op === 'delete_discount') {
            Discounts::delete($id, absint($data['id'] ?? 0));
            return ['message' => 'Faixa de preço excluída.', 'redirect' => admin_url('admin.php?page=k8prod-products&produto_id=' . $id . '#descontos-data')];
        } elseif ($op === 'delete_payment_period') {
            PaymentTerms::delete($id, absint($data['id'] ?? 0));
            return ['message' => 'Faixa de parcelamento excluída.', 'redirect' => admin_url('admin.php?page=k8prod-products&produto_id=' . $id . '#parcelamento-data')];
        } elseif ($op === 'save_coupon') {
            $code = strtoupper(Support::text($data, 'codigo'));
            $percent = (float) str_replace(',', '.', Support::text($data, 'percentual'));
            $date = Support::text($data, 'validade');
            $max = Support::text($data, 'uso_maximo');
            if (!preg_match('/^[A-Z0-9_-]{1,50}$/D', $code) || $percent <= 0 || $percent > 100 ||
                ($max !== '' && (!ctype_digit($max) || (int) $max < 1)) ||
                ($date && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))))) {
                throw new \InvalidArgumentException('Confira código, percentual, validade e limite do cupom.');
            }
            $table = Support::table('cupons');
            $record = ['produto_id' => $id, 'codigo' => $code, 'percentual' => $percent,
                'turma_restrita' => Support::text($data, 'turma_restrita') ?: null, 'validade' => $date ?: null,
                'uso_maximo' => $max === '' ? null : (int) $max, 'ativo' => 1];
            Support::lock('coupon:' . $id . ':' . $code, static function () use ($wpdb, $table, $record, $id, $code) {
                $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE produto_id = %d AND codigo = %s", $id, $code));
                if ($existing) {
                    Support::must($wpdb->update($table, $record, ['id' => $existing]));
                } else {
                    Support::must($wpdb->insert($table, $record));
                }
            });
        } elseif ($op === 'toggle_coupon' || $op === 'toggle_material') {
            $table = Support::table($op === 'toggle_coupon' ? 'cupons' : 'materiais');
            Support::must($wpdb->query($wpdb->prepare("UPDATE $table SET ativo = 1 - ativo WHERE id = %d AND produto_id = %d", absint($data['id'] ?? 0), $id)));
        } elseif ($op === 'save_material') {
            Service::create_material($id, $data);
        } elseif ($op === 'stock') {
            Service::move_stock($id, absint($data['material_id'] ?? 0), Support::text($data, 'tipo'), (int) ($data['quantidade'] ?? 0), Support::text($data, 'observacao'));
        } elseif ($op === 'delivery') {
            Service::delivery($id, absint($data['pedido_id'] ?? 0), (int) ($data['semestre'] ?? 1), Support::text($data, 'acao'),
                Support::text($data, 'assinatura'), Support::text($data, 'recebido_por'), Support::text($data, 'motivo'));
        } elseif ($op === 'sync') {
            if (!empty($data['pedido_id'])) {
                Service::sync_order($id, absint($data['pedido_id']));
            } else {
                $result = Service::sync_batch($id);
                return ['message' => $result['atualizados'] . ' pedido(s) consultado(s); ' . $result['falhas'] . ' falha(s).'];
            }
        } elseif ($op === 'cancel') {
            Service::cancel_order($id, absint($data['pedido_id'] ?? 0), Support::text($data, 'motivo'), !empty($data['confirmar_sem_cobranca']));
        } elseif ($op === 'reconcile') {
            Service::reconcile($id, absint($data['pedido_id'] ?? 0), Support::text($data, 'cobranca_id'));
        } elseif ($op === 'settings') {
            self::settings($data);
        } else {
            throw new \InvalidArgumentException('Operação inválida.');
        }
        return [];
    }

    private static function settings(array $data): void
    {
        $map = [];
        foreach (Students::defaults() as $key => $default) {
            $value = Support::text($data, 'student_' . $key);
            if (($value === '' && in_array($key, ['cpf2', 'parent2'], true)) || preg_match('/^[A-Za-z0-9_]+$/D', $value)) {
                $map[$key] = $value;
            } else {
                throw new \InvalidArgumentException('Use apenas letras, números e sublinhado no mapeamento de alunos.');
            }
        }
        $urls = [];
        foreach (['api_production', 'api_sandbox', 'checkout_production', 'checkout_sandbox'] as $key) {
            $url = Support::text($data, $key);
            $parts = wp_parse_url($url);
            if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                throw new \InvalidArgumentException('Informe URLs HTTPS válidas para API e checkout.');
            }
            $urls[$key] = esc_url_raw($url);
        }
        $paths = [];
        foreach (['query_path', 'cancel_path'] as $key) {
            $value = Support::text($data, $key);
            if (strpos($value, '/api/') !== 0 || strpos($value, '{id}') === false || strpos($value, '..') !== false || preg_match('/[\r\n]/', $value)) {
                throw new \InvalidArgumentException('Use um caminho iniciado por /api/ que contenha {id}.');
            }
            $paths[$key] = $value;
        }
        // Valida tudo antes de alterar as opções.
        update_option('k8prod_students', $map, false);
        foreach (array_merge($urls, $paths) as $key => $value) {
            update_option('k8prod_' . $key, $value, false);
        }
        foreach (['client_id', 'client_secret', 'aes_key'] as $key) {
            $value = Support::text($data, $key);
            if ($value !== '') {
                update_option('k8p_' . $key, $value, false);
            }
        }
        update_option('k8p_producao', empty($data['producao']) ? '0' : '1', false);
    }
}
