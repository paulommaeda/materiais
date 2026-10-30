<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Service
{
    public static function price(object $product, string $class): ?float
    {
        if (!ProductClasses::allows($product, $class)) {
            return null;
        }
        return $product->valor_padrao !== null ? (float) $product->valor_padrao : null;
    }

    public static function active(int $product, string $ra): ?object
    {
        global $wpdb;
        $table = Support::table('pedidos');
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d AND ra = %s
            AND status IN ('gerado','iniciado','verificacao') AND (status_pagamento IS NULL OR status_pagamento <> 'Cancelada')
            ORDER BY (status_pagamento = 'Paga') DESC,id DESC LIMIT 1", $product, $ra)) ?: null;
    }

    public static function order(int $product, int $id): object
    {
        global $wpdb;
        $table = Support::table('pedidos');
        $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND produto_id = %d", $id, $product));
        if (!$order) {
            throw new \InvalidArgumentException('Pedido não encontrado neste produto.');
        }
        return $order;
    }

    public static function coupon(int $product, string $code, string $class): ?object
    {
        global $wpdb;
        if ($code === '') {
            return null;
        }
        $table = Support::table('cupons');
        $coupon = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d AND codigo = %s AND ativo = 1", $product, strtoupper(trim($code))));
        if (!$coupon || ($coupon->validade && $coupon->validade < current_time('Y-m-d')) ||
            ($coupon->turma_restrita && $coupon->turma_restrita !== $class) ||
            ($coupon->uso_maximo !== null && (int) $coupon->usado_count >= (int) $coupon->uso_maximo)) {
            throw new \InvalidArgumentException('Cupom inválido, expirado, esgotado ou não aplicável a este produto/turma.');
        }
        return $coupon;
    }

    public static function quote(object $product, array $person, string $code = ''): array
    {
        $product = Support::product((int) $product->id, true);
        $dated = Discounts::price($product, $person['turma']);
        if ($dated === null) {
            throw new \InvalidArgumentException('Produto indisponível ou sem preço para esta turma. Contate a secretaria.');
        }
        $base = $dated['original'];
        $value = $dated['valor'];
        $promotion = $dated['promocao'];
        $coupon = self::coupon((int) $product->id, $code, $person['turma']);
        $coupon_applied = false;
        $warning = '';
        if ($coupon) {
            $stack = $promotion && (bool) $promotion->acumular_cupom;
            $coupon_base = (int) round(($stack ? $value : $base) * 100);
            $percent = (int) round((float) $coupon->percentual * 100);
            $with_coupon = max(0, (int) round($coupon_base * (10000 - $percent) / 10000)) / 100;
            if ($with_coupon < $value) {
                $value = $with_coupon;
                $coupon_applied = true;
                if (!$stack) {
                    $promotion = null;
                }
            } else {
                $warning = 'O desconto por data já oferece o melhor preço. O cupom não foi utilizado.';
            }
        }
        $terms = PaymentTerms::current($product);
        $quote = $terms + ['original' => $base, 'valor' => $value,
            'valor_apos_data' => $promotion ? $dated['valor'] : $base,
            'promocao_id' => $promotion ? (int) $promotion->id : null,
            'promocao_titulo' => $promotion ? $promotion->titulo : null,
            'promocao_fim' => $promotion ? $promotion->fim : null,
            'promocao_ate' => $promotion ? Discounts::date_label($promotion->fim) : null,
            'cupom' => $coupon_applied ? $coupon->codigo : '', 'percentual' => $coupon_applied ? (float) $coupon->percentual : 0,
            'aviso_cupom' => $warning, 'parcelamento_ate' => $terms['parcelamento_fim'] ? Discounts::date_label($terms['parcelamento_fim']) : null,
            'email_checkout' => Emails::checkout_options($product, $person),
            'refresh_after' => min(Discounts::refresh_after([(int) $product->id]), PaymentTerms::refresh_after([(int) $product->id]))];
        $quote['chave_preco'] = hash('sha256', wp_json_encode([$product->id, $person['turma'], $base, $value,
            $quote['valor_apos_data'], $quote['promocao_id'], $quote['promocao_fim'], $quote['cupom'], $quote['percentual'],
            $terms['formas_pagamento'], $terms['parcelas_cartao_max'], $terms['parcelamento_regra_id'], $terms['parcelamento_fim']]));
        return $quote;
    }

    private static function release_coupon(object $order): void
    {
        global $wpdb;
        if ((int) $order->cupom_reservado && $order->cupom_codigo) {
            $coupons = Support::table('cupons');
            Support::must($wpdb->query($wpdb->prepare("UPDATE $coupons SET usado_count = GREATEST(0, usado_count - 1) WHERE produto_id = %d AND codigo = %s",
                $order->produto_id, $order->cupom_codigo)));
        }
    }

    public static function checkout(object $product, array $person, string $method, string $code, ?string $price_key = null, array $contact = []): array
    {
        return Support::lock('checkout:' . $product->id . ':' . $person['ra'], static function () use ($product, $person, $method, $code, $price_key, $contact) {
            $product = Support::product((int) $product->id, true);
            global $wpdb;
            $orders = Support::table('pedidos');
            $existing = self::active((int) $product->id, $person['ra']);
            if ($existing) {
                return self::public_order($existing);
            }
            if (!in_array($method, ['Pix', 'Boleto', 'Cartao', 'Cortesia'], true)) {
                throw new \InvalidArgumentException('Escolha uma forma de pagamento válida.');
            }
            $quote = self::quote($product, $person, $code);
            if ($price_key !== null && !hash_equals($quote['chave_preco'], $price_key)) {
                throw new \InvalidArgumentException('O preço ou as condições de pagamento mudaram. Confira os dados atualizados e confirme novamente.');
            }
            if ($quote['valor'] > 0 && !in_array($method, $quote['formas_pagamento'], true)) {
                throw new \InvalidArgumentException('Esta forma de pagamento não está disponível para este produto.');
            }
            if (($quote['valor'] <= 0) !== ($method === 'Cortesia')) {
                throw new \InvalidArgumentException('Confira o valor e escolha a opção de pagamento correspondente.');
            }
            $email_fields = Emails::contact($product, $person, $contact, $code, true);
            $client = $quote['valor'] > 0 ? new Client() : null;
            $id = Support::transaction(static function () use ($wpdb, $product, $person, $quote, $method, $orders, $email_fields) {
                if ($quote['cupom']) {
                    $coupons = Support::table('cupons');
                    $affected = Support::must($wpdb->query($wpdb->prepare("UPDATE $coupons SET usado_count = usado_count + 1
                        WHERE produto_id = %d AND codigo = %s AND ativo = 1
                        AND (uso_maximo IS NULL OR usado_count < uso_maximo) AND (validade IS NULL OR validade >= %s)
                        AND (turma_restrita IS NULL OR turma_restrita = '' OR turma_restrita = %s)
                        AND percentual = %f", $product->id, $quote['cupom'], current_time('Y-m-d'), $person['turma'], $quote['percentual'])));
                    if ($affected !== 1) {
                        throw new \InvalidArgumentException('O cupom não está mais disponível. Refaça a consulta.');
                    }
                }
                Support::must($wpdb->insert($orders, array_merge($person, $email_fields, [
                    'produto_id' => $product->id, 'active_key' => hash('sha256', $product->id . ':' . $person['ra']),
                    'valor' => $quote['valor'], 'valor_original' => $quote['original'], 'cupom_codigo' => $quote['cupom'] ?: null,
                    'promocao_id' => $quote['promocao_id'], 'promocao_titulo' => $quote['promocao_titulo'],
                    'promocao_fim' => $quote['promocao_fim'], 'valor_apos_data' => $quote['valor_apos_data'],
                    'cupom_reservado' => $quote['cupom'] ? 1 : 0,
                    'origem' => $quote['valor'] > 0 ? ($method === 'Cartao' ? 'k8_link' : 'k8') : ($quote['cupom'] ? 'cupom_gratuito' : ($quote['promocao_id'] ? 'promocao_data' : 'gratuito')),
                    'cartao_parcelas_max' => $method === 'Cartao' ? $quote['parcelas_cartao_max'] : null,
                    'parcelamento_regra_id' => $method === 'Cartao' ? $quote['parcelamento_regra_id'] : null,
                    'parcelamento_regra_titulo' => $method === 'Cartao' ? $quote['parcelamento_titulo'] : null,
                    'parcelamento_regra_fim' => $method === 'Cartao' ? $quote['parcelamento_fim'] : null,
                    'pagamento_referencia' => $method === 'Cartao' ? bin2hex(random_bytes(16)) : null,
                    'metodo' => $method, 'status' => $quote['valor'] <= 0 ? 'gerado' : 'iniciado',
                    'status_pagamento' => $quote['valor'] <= 0 ? 'Paga' : null, 'criado_em' => current_time('mysql'),
                ])));
                $order_id = (int) $wpdb->insert_id;
                Emails::attach((int) $email_fields['email_carrinho_id'], $order_id);
                return $order_id;
            });
            if ($quote['valor'] <= 0) {
                return self::public_order(self::order((int) $product->id, $id));
            }
            $request_started = false;
            try {
                if ($method === 'Cartao') {
                    Support::must($wpdb->update($orders, ['status' => 'verificacao'], ['id' => $id]));
                    $request_started = true;
                    CardPayments::create($client, self::order((int) $product->id, $id), $product);
                    return self::public_order(self::order((int) $product->id, $id));
                }
                $customer = $client->customer($person);
                if (empty($customer['Id']) || !is_scalar($customer['Id'])) {
                    throw new \RuntimeException('A K8 não retornou um cliente válido.');
                }
                Support::must($wpdb->update($orders, ['cliente_id' => (string) $customer['Id'], 'status' => 'verificacao'], ['id' => $id]));
                // A partir daqui falhas de rede NÃO liberam uma nova tentativa de cobrança.
                $request_started = true;
                $result = $client->charge(self::order((int) $product->id, $id), $product);
                $charge_id = $result['Id'] ?? null;
                if (!$charge_id || !is_scalar($charge_id) || strlen((string) $charge_id) > 50) {
                    throw new \RuntimeException('A K8 não retornou um identificador de cobrança válido.');
                }
                Support::must($wpdb->update($orders, ['cobranca_id' => (string) $charge_id, 'status' => 'gerado',
                    'status_pagamento' => 'Pendente', 'resposta_raw' => wp_json_encode($result)], ['id' => $id]));
            } catch (\Throwable $e) {
                if (!$request_started) {
                    Support::transaction(static function () use ($wpdb, $orders, $product, $id, $e) {
                        $order = self::order((int) $product->id, $id);
                        self::release_coupon($order);
                        Support::must($wpdb->update($orders, ['status' => 'erro', 'active_key' => null,
                            'cupom_reservado' => 0, 'erro_mensagem' => $e->getMessage()], ['id' => $id]));
                    });
                } else {
                    Support::must($wpdb->update($orders, ['status' => 'verificacao', 'erro_mensagem' => $e->getMessage()], ['id' => $id]));
                }
                throw new \RuntimeException($request_started ? 'Pedido #' . $id . ' em conferência. Contate a secretaria antes de tentar novamente.' : $e->getMessage());
            }
            return self::public_order(self::order((int) $product->id, $id));
        });
    }

    public static function public_order(object $order): array
    {
        global $wpdb;
        $deliveries = Support::table('entregas');
        return ['id' => (int) $order->id, 'valor' => (float) $order->valor, 'metodo' => $order->metodo,
            'parcelas_cartao_max' => $order->cartao_parcelas_max !== null ? (int) $order->cartao_parcelas_max : null,
            'parcelamento_titulo' => $order->parcelamento_regra_titulo ?? null,
            'valor_original' => (float) ($order->valor_original ?? $order->valor),
            'promocao_titulo' => $order->promocao_titulo ?? null,
            'status' => $order->status, 'pagamento' => $order->status_pagamento ?: 'Pendente',
            'redirect' => !in_array($order->status_pagamento, ['Paga', 'Cancelada'], true)
                ? (CardPayments::is_link($order) ? ($order->checkout_url ?: null) : ($order->cobranca_id ? Client::checkout($order->cobranca_id) : null)) : null,
            'entregas' => $wpdb->get_results($wpdb->prepare("SELECT semestre,status,entregue_em FROM $deliveries WHERE pedido_id = %d AND produto_id = %d ORDER BY semestre", $order->id, $order->produto_id))];
    }

    public static function sync_order(int $product, int $id): object
    {
        return Support::lock('order:' . $id, static function () use ($product, $id) {
            global $wpdb;
            $order = self::order($product, $id);
            if (in_array($order->status_pagamento, ['Paga', 'Cancelada'], true)) {
                return $order;
            }
            if (CardPayments::is_link($order) && $order->link_pagamento_id) {
                $update = CardPayments::synchronized(new Client(), $order);
                Support::must($wpdb->update(Support::table('pedidos'), $update, ['id' => $id]));
                return self::order($product, $id);
            }
            if (!$order->cobranca_id) return $order;
            $data = (new Client())->consult($order->cobranca_id);
            $status = $data['Estado'] ?? '';
            if (!in_array($status, ['Paga', 'Pendente', 'Inadimplente', 'Cancelada'], true)) {
                throw new \RuntimeException('Estado de cobrança não reconhecido. Confira o endpoint de consulta.');
            }
            Support::transaction(static function () use ($wpdb, $order, $status) {
                $update = ['status_pagamento' => $status, 'verificado_em' => current_time('mysql'), 'erro_mensagem' => null];
                if ($status === 'Cancelada') {
                    self::release_coupon($order);
                    $update += ['active_key' => null, 'cupom_reservado' => 0, 'status' => 'cancelado'];
                }
                Support::must($wpdb->update(Support::table('pedidos'), $update, ['id' => $order->id]));
            });
            return self::order($product, $id);
        });
    }

    public static function sync_batch(int $product = 0): array
    {
        global $wpdb;
        $orders = Support::table('pedidos');
        $where = $product ? $wpdb->prepare(' AND produto_id = %d', $product) : '';
        $rows = $wpdb->get_results("SELECT id,produto_id FROM $orders WHERE (status = 'gerado' OR (status = 'verificacao' AND link_pagamento_id IS NOT NULL))
            AND (cobranca_id IS NOT NULL OR link_pagamento_id IS NOT NULL)
            AND (status_pagamento IS NULL OR status_pagamento NOT IN ('Paga','Cancelada')) $where
            ORDER BY COALESCE(verificado_em,'1000-01-01') ASC,id ASC LIMIT 30");
        $result = ['atualizados' => 0, 'falhas' => 0];
        $started = microtime(true);
        foreach ($rows as $row) {
            // Deixa o restante para a próxima execução em hospedagens com limite curto.
            if (microtime(true) - $started > 20) {
                break;
            }
            try {
                self::sync_order((int) $row->produto_id, (int) $row->id);
                $result['atualizados']++;
            } catch (\Throwable $e) {
                $result['falhas']++;
                $wpdb->update($orders, ['verificado_em' => current_time('mysql'), 'erro_mensagem' => $e->getMessage()], ['id' => $row->id]);
            }
        }
        return $result;
    }

    public static function materials(int $product, ?string $class = null, int $stage = 0): array
    {
        global $wpdb;
        $table = Support::table('materiais');
        $where = $wpdb->prepare('produto_id = %d AND ativo = 1', $product);
        if ($class !== null) {
            $where .= $wpdb->prepare(" AND (turma_restrita IS NULL OR turma_restrita = '' OR turma_restrita = %s)", $class);
        }
        $rows = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY id ASC");
        return $stage ? array_values(array_filter($rows, static function ($m) use ($stage) {
            return Support::semester($m->periodo_entrega) === $stage;
        })) : $rows;
    }

    // Deve ser chamado dentro de transação; o UPDATE condicional também protege concorrência.
    private static function stock_delta(int $product, int $material, int $delta, string $note, int $delivery = 0, bool $negative = true): void
    {
        global $wpdb;
        $table = Support::table('materiais');
        $guard = $negative || $delta >= 0 ? '' : $wpdb->prepare(' AND estoque_atual >= %d', abs($delta));
        $changed = Support::must($wpdb->query($wpdb->prepare("UPDATE $table SET estoque_atual = estoque_atual + %d WHERE id = %d AND produto_id = %d $guard", $delta, $material, $product)));
        if ($changed !== 1) {
            throw new \InvalidArgumentException('Material inválido ou estoque insuficiente. Nenhum item foi movimentado.');
        }
        Support::must($wpdb->insert(Support::table('estoque_movimentos'), ['produto_id' => $product, 'material_id' => $material,
            'entrega_id' => $delivery ?: null, 'tipo' => $delta < 0 ? 'saida' : 'entrada', 'quantidade' => abs($delta),
            'observacao' => $note, 'usuario' => wp_get_current_user()->display_name, 'criado_em' => current_time('mysql')]));
    }

    public static function move_stock(int $product, int $material, string $type, int $quantity, string $note): void
    {
        $p = Support::product($product);
        if ($quantity < 1 || $quantity > 1000000 || !in_array($type, ['entrada', 'saida'], true)) {
            throw new \InvalidArgumentException('Informe tipo e quantidade válidos.');
        }
        Support::transaction(static function () use ($p, $material, $type, $quantity, $note) {
            self::stock_delta((int) $p->id, $material, $type === 'saida' ? -$quantity : $quantity, $note, 0, (bool) $p->permitir_negativo);
        });
    }

    public static function create_material(int $product, array $data): int
    {
        global $wpdb;
        $p = Support::product($product);
        $period = Support::text($data, 'periodo_entrega');
        $initial = filter_var($data['estoque_inicial'] ?? 0, FILTER_VALIDATE_INT);
        $quantity = filter_var($data['quantidade_por_aluno'] ?? 1, FILTER_VALIDATE_INT);
        $minimum = filter_var($data['estoque_minimo'] ?? 0, FILTER_VALIDATE_INT);
        $name = Support::text($data, 'nome');
        if (!$name || !isset(Support::periods()[$period]) || $initial === false || $initial < 0 || $initial > 1000000 ||
            $quantity === false || $quantity < 1 || $quantity > 10000 || $minimum === false || $minimum < 0 || $minimum > 1000000 ||
            ((int) $p->etapas === 1 && Support::semester($period) === 2)) {
            throw new \InvalidArgumentException('Confira nome, período, quantidades e estoque do material.');
        }
        return Support::transaction(static function () use ($wpdb, $product, $data, $period, $initial, $quantity, $minimum, $name) {
            Support::must($wpdb->insert(Support::table('materiais'), ['produto_id' => $product, 'nome' => $name,
                'turma_restrita' => Support::text($data, 'turma_restrita') ?: null, 'periodo_entrega' => $period,
                'quantidade_por_aluno' => $quantity, 'estoque_minimo' => $minimum, 'estoque_atual' => 0]));
            $id = (int) $wpdb->insert_id;
            if ($initial > 0) {
                self::stock_delta($product, $id, $initial, 'Estoque inicial');
            }
            return $id;
        });
    }

    private static function valid_signature(string $signature): bool
    {
        if (strlen($signature) > 700000 || strpos($signature, 'data:image/png;base64,') !== 0) {
            return false;
        }
        $bytes = base64_decode(substr($signature, 22), true);
        $info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
        return $info && $info[2] === IMAGETYPE_PNG && $info[0] >= 10 && $info[1] >= 10 && $info[0] <= 2400 && $info[1] <= 1200;
    }

    public static function delivery(int $product, int $order_id, int $stage, string $action, string $signature, string $recipient, string $reason): void
    {
        $p = Support::product($product);
        if ($stage < 1 || $stage > (int) $p->etapas || !in_array($action, ['entregar', 'desfazer'], true)) {
            throw new \InvalidArgumentException('Etapa ou ação inválida.');
        }
        if ($action === 'entregar' && (!self::valid_signature($signature) || !$recipient)) {
            throw new \InvalidArgumentException('Informe o nome de quem recebeu e uma assinatura válida.');
        }
        if ($action === 'desfazer' && strlen($reason) < 5) {
            throw new \InvalidArgumentException('Informe uma justificativa com pelo menos cinco caracteres.');
        }
        Support::lock('order:' . $order_id, static function () use ($product, $order_id, $stage, $action, $signature, $recipient, $reason, $p) {
            Support::transaction(static function () use ($product, $order_id, $stage, $action, $signature, $recipient, $reason, $p) {
                global $wpdb;
                $orders = Support::table('pedidos');
                $order = $wpdb->get_row($wpdb->prepare("SELECT * FROM $orders WHERE id = %d AND produto_id = %d FOR UPDATE", $order_id, $product));
                if (!$order || $order->status_pagamento !== 'Paga') {
                    throw new \InvalidArgumentException('Somente pedidos pagos deste produto podem ter entrega registrada.');
                }
                $table = Support::table('entregas');
                $delivery = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE pedido_id = %d AND semestre = %d FOR UPDATE", $order_id, $stage));
                if (!$delivery) {
                    Support::must($wpdb->insert($table, ['produto_id' => $product, 'pedido_id' => $order_id, 'semestre' => $stage]));
                    $delivery = (object) ['id' => $wpdb->insert_id, 'status' => 'pendente', 'materiais_entregues' => '[]'];
                }
                if (($action === 'entregar' && $delivery->status === 'entregue') || ($action === 'desfazer' && $delivery->status !== 'entregue')) {
                    return; // Repetir a mesma ação não repete baixa/estorno.
                }
                $now = current_time('mysql');
                $user = wp_get_current_user()->display_name;
                if ($action === 'entregar') {
                    $snapshot = [];
                    foreach (self::materials($product, $order->turma, $stage) as $m) {
                        $quantity = (int) $m->quantidade_por_aluno;
                        self::stock_delta($product, (int) $m->id, -$quantity, 'Entrega #' . $delivery->id, (int) $delivery->id, (bool) $p->permitir_negativo);
                        $snapshot[] = ['id' => (int) $m->id, 'nome' => $m->nome, 'quantidade' => $quantity];
                    }
                    Support::must($wpdb->update($table, ['status' => 'entregue', 'materiais_entregues' => wp_json_encode($snapshot),
                        'assinatura' => $signature, 'recebido_por' => $recipient, 'entregue_em' => $now, 'entregue_por' => $user,
                        'motivo_desfazer' => null, 'desfeito_por' => null, 'desfeito_em' => null], ['id' => $delivery->id]));
                } else {
                    $snapshot = json_decode((string) $delivery->materiais_entregues, true) ?: [];
                    foreach ($snapshot as $m) {
                        self::stock_delta($product, (int) $m['id'], (int) ($m['quantidade'] ?? 1), 'Estorno da entrega #' . $delivery->id, (int) $delivery->id);
                    }
                    Support::must($wpdb->update($table, ['status' => 'pendente', 'motivo_desfazer' => $reason, 'desfeito_por' => $user, 'desfeito_em' => $now], ['id' => $delivery->id]));
                }
                Support::log($product, $order_id, $action === 'entregar' ? 'entregue' : 'desfeita',
                    ['entrega_id' => $delivery->id, 'motivo' => $reason, 'assinatura' => $action === 'entregar' ? $signature : null,
                        'semestre' => $stage, 'materiais' => $snapshot, 'recebido_por' => $recipient]);
            });
        });
    }

    public static function cancel_order(int $product, int $id, string $reason, bool $release_uncertain = false): void
    {
        if (strlen($reason) < 5) {
            throw new \InvalidArgumentException('Informe o motivo com pelo menos cinco caracteres.');
        }
        Support::lock('order:' . $id, static function () use ($product, $id, $reason, $release_uncertain) {
            global $wpdb;
            $order = self::order($product, $id);
            if ($order->status_pagamento === 'Paga') {
                throw new \InvalidArgumentException('Pedido pago: trate o reembolso diretamente na K8. O histórico é preservado.');
            }
            if ($order->status_pagamento === 'Cancelada') {
                return;
            }
            if ($order->status === 'verificacao' && !$order->cobranca_id && !$order->link_pagamento_id && !$release_uncertain) {
                throw new \InvalidArgumentException('Confira na K8 se existe cobrança e confirme a liberação manual da tentativa.');
            }
            if (CardPayments::is_link($order) && $order->link_pagamento_id) {
                CardPayments::cancel(new Client(), $order);
            } elseif ($order->cobranca_id) {
                $client = new Client();
                $data = $client->consult($order->cobranca_id);
                if (($data['Estado'] ?? '') === 'Paga') {
                    Support::must($wpdb->update(Support::table('pedidos'), ['status_pagamento' => 'Paga'], ['id' => $id]));
                    throw new \InvalidArgumentException('O pagamento já foi confirmado na K8. O pedido foi atualizado para pago.');
                }
                if (!in_array($data['Estado'] ?? '', ['Pendente', 'Inadimplente', 'Cancelada'], true)) {
                    throw new \InvalidArgumentException('Não foi possível confirmar o estado da cobrança.');
                }
                if ($data['Estado'] !== 'Cancelada') {
                    $client->cancel($order->cobranca_id);
                }
            }
            Support::transaction(static function () use ($wpdb, $order, $reason) {
                self::release_coupon($order);
                Support::must($wpdb->update(Support::table('pedidos'), ['status' => 'cancelado', 'status_pagamento' => 'Cancelada',
                    'active_key' => null, 'cupom_reservado' => 0, 'verificado_em' => current_time('mysql')], ['id' => $order->id]));
                Support::log((int) $order->produto_id, (int) $order->id, 'cancelado', ['motivo' => $reason]);
            });
        });
    }

    public static function reconcile(int $product, int $id, string $charge): void
    {
        if (!preg_match('/^[A-Za-z0-9-]{1,50}$/D', $charge)) {
            throw new \InvalidArgumentException('Identificador de cobrança inválido.');
        }
        Support::lock('order:' . $id, static function () use ($product, $id, $charge) {
            global $wpdb;
            $order = self::order($product, $id);
            if ($order->status !== 'verificacao' || $order->cobranca_id) {
                throw new \InvalidArgumentException('Este pedido não está aguardando conciliação.');
            }
            if (CardPayments::is_link($order)) {
                CardPayments::reconcile($order, $charge);
                return;
            }
            $data = (new Client())->consult($charge);
            if ((string) ($data['Id'] ?? '') !== $charge || (string) ($data['ClienteId'] ?? '') !== (string) $order->cliente_id ||
                !isset($data['Valor']) || abs((float) $data['Valor'] - (float) $order->valor) > 0.009 ||
                !in_array($data['Estado'] ?? '', ['Paga', 'Pendente', 'Inadimplente'], true)) {
                throw new \InvalidArgumentException('Cobrança sem correspondência de cliente, valor, ID ou estado. Confira a resposta da API K8.');
            }
            $table = Support::table('pedidos');
            if ($wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE cobranca_id = %s AND id <> %d LIMIT 1", $charge, $id))) {
                throw new \InvalidArgumentException('Cobrança já vinculada a outro pedido.');
            }
            Support::transaction(static function () use ($wpdb, $table, $id, $charge, $data, $product) {
                Support::must($wpdb->update($table, ['cobranca_id' => $charge, 'status' => 'gerado', 'status_pagamento' => $data['Estado'],
                    'erro_mensagem' => null, 'verificado_em' => current_time('mysql')], ['id' => $id]));
                Support::log($product, $id, 'conciliado', ['motivo' => 'Cobrança ' . $charge . ' vinculada após conferência.']);
            });
        });
    }
}
