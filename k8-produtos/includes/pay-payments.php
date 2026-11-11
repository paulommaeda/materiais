<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

/** Checkout hospedado PAY; não recebe número de cartão ou código de segurança. */
final class PayPayments
{
    public static function gateway(): string
    {
        return get_option('k8prod_gateway', 'cob') === 'pay' ? 'pay' : 'cob';
    }

    public static function is_pay(object $order): bool { return $order->origem === 'pay_checkout'; }

    public static function reference(object $order): string
    {
        // Novos pedidos guardam a referência completa; os antigos mantêm o formato enviado.
        if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/iD', (string) $order->pagamento_referencia)) return $order->pagamento_referencia;
        return 'K8PAY-' . $order->id . '-' . $order->pagamento_referencia;
    }

    public static function buyer(string $method, array $input): array
    {
        if ($method !== 'Boleto') return [];
        $result = [];
        foreach (['endereco' => 255, 'numero' => 20, 'bairro' => 100, 'cidade' => 100, 'cep' => 9, 'uf' => 2, 'complemento' => 255] as $key => $length) {
            $value = Support::text($input, 'pay_' . $key);
            if (strlen($value) > $length || ($key !== 'complemento' && $value === '')) throw new \InvalidArgumentException('Preencha o endereço completo para pagamento por boleto.');
            $result[$key] = $value;
        }
        $result['cep'] = preg_replace('/\D/', '', $result['cep']);
        $result['uf'] = strtoupper($result['uf']);
        $states = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
        if (!preg_match('/^\d{8}$/D', $result['cep']) || !preg_match('/^\d{1,20}$/D', $result['numero']) || !in_array($result['uf'], $states, true)) throw new \InvalidArgumentException('Confira o CEP, o número do endereço e a UF do boleto.');
        return $result;
    }

    public static function payload(object $order, object $product): array
    {
        $due = (new \DateTimeImmutable('now', wp_timezone()))->modify('+' . (int) $product->vencimento_dias . ' days')->setTime(23, 59, 59);
        $data = ['Valor' => (float) $order->valor, 'ClienteCPFCNPJ' => $order->cpf_resp, 'ClienteNome' => $order->nome_resp,
            'ClienteDescricao' => $product->nome . ' — Pedido #' . $order->id,
            'NumeroPedido' => 'K8P-' . $order->id, 'Referencia' => self::reference($order)] + PayReturn::urls($order);
        if (!empty($order->email_responsavel) && is_email($order->email_responsavel)) $data['ClienteEmail'] = $order->email_responsavel;
        if ($order->metodo === 'Cartao') {
            $count = PaymentTerms::count((int) $order->cartao_parcelas_max);
            $data['Cartao'] = ['FormaPagamento' => $count > 1 ? ['AVista', 'ParceladoSemJuros'] : ['AVista'], 'QuantidadeParcelas' => $count];
        } elseif ($order->metodo === 'Pix') {
            $data['Pix'] = ['Tipo' => 'E', 'Pdv' => false, 'SegundosExpiracao' => min(2592000, max(300, $due->getTimestamp() - time())),
                'DataVencimento' => $due->format('Y-m-d\TH:i:sP'), 'Desconto' => 0];
        } elseif ($order->metodo === 'Boleto') {
            $buyer = json_decode((string) $order->pay_comprador, true);
            if (!is_array($buyer)) throw new \RuntimeException('O pedido não possui endereço de boleto válido.');
            $input = [];
            foreach ($buyer as $key => $value) $input['pay_' . $key] = $value;
            $buyer = self::buyer('Boleto', $input);
            foreach (['endereco' => 'ClienteEndereco', 'numero' => 'ClienteNumero', 'bairro' => 'ClienteBairro', 'cidade' => 'ClienteCidade',
                'cep' => 'ClienteCEP', 'uf' => 'ClienteUF', 'complemento' => 'ClienteComplemento'] as $key => $field) $data[$field] = $buyer[$key];
            $data['Boleto'] = ['DataVencimento' => $due->format('Y-m-d\TH:i:sP'), 'Desconto' => 0];
        } else throw new \InvalidArgumentException('Forma de pagamento PAY inválida.');
        return $data;
    }

    public static function checkout_url(string $url, string $environment): string
    {
        $parts = wp_parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        $allowed = ['pagamentos.meuk8.com.br', 'sandbox.meuk8.com.br', 'pagamentos.k8bank.com.br'];
        $base = (string) get_option('k8prod_pay_api_' . $environment, '');
        $configured_host = wp_parse_url($base, PHP_URL_HOST);
        if ($configured_host) $allowed[] = strtolower($configured_host);
        if (!$parts || strlen($url) > 4096 || ($parts['scheme'] ?? '') !== 'https' || !in_array($host, $allowed, true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && (int) $parts['port'] !== 443)) throw new \RuntimeException('O PAY retornou uma URL de checkout não reconhecida. Confira o pedido no painel PAY.');
        return esc_url_raw($url);
    }

    public static function create(object $order, object $product): void
    {
        global $wpdb;
        $client = new PayClient($order->pagamento_ambiente);
        $result = $client->checkout(self::payload($order, $product));
        $id = $result['Identificador'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9-]{1,50}$/D', $id)) throw new \RuntimeException('O PAY não retornou um identificador de checkout válido.');
        // Salva o ID antes de validar a URL: uma falha posterior não repete a criação.
        Support::must($wpdb->update(Support::table('pedidos'), ['pay_checkout_id' => $id], ['id' => $order->id]));
        $url = self::checkout_url(is_string($result['URL'] ?? null) ? $result['URL'] : '', $order->pagamento_ambiente);
        Support::must($wpdb->update(Support::table('pedidos'), ['checkout_url' => $url, 'status' => 'gerado',
            'status_pagamento' => 'Pendente', 'verificado_em' => current_time('mysql'), 'erro_mensagem' => null], ['id' => $order->id]));
    }

    public static function synchronized(object $order, ?string $transaction_id = null): array
    {
        global $wpdb;
        $client = new PayClient($order->pagamento_ambiente);
        $expected_id = $transaction_id ?? ($order->pay_transacao_id ?: null);
        if ($expected_id !== null) {
            if ($order->pay_transacao_id && strcasecmp($order->pay_transacao_id, $expected_id) !== 0) throw new \InvalidArgumentException('O pedido já está vinculado a outra transação PAY. Confira o histórico antes de alterar o vínculo.');
            $result = $client->transaction($expected_id);
        } elseif ($order->pay_checkout_id) {
            try {
                $result = $client->transaction($order->pay_checkout_id);
                $expected_id = $order->pay_checkout_id;
            } catch (PayException $e) {
                // Algumas contas só expõem a transação final depois do pagamento.
                $result = $client->consult(self::reference($order));
            }
        } else {
            $result = $client->consult(self::reference($order));
        }
        $id = $result['Identificador'] ?? '';
        $reference = $result['Referencia'] ?? null;
        $reference_matches = $reference === self::reference($order) || ($expected_id !== null && ($reference === null || $reference === ''));
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9-]{1,50}$/D', $id) || ($expected_id !== null && strcasecmp($id, $expected_id) !== 0) || !$reference_matches ||
            (string) ($result['NumeroPedido'] ?? '') !== 'K8P-' . $order->id || Support::cpf((string) ($result['ClienteCPFCNPJ'] ?? '')) !== Support::cpf($order->cpf_resp) ||
            !isset($result['Valor']) || !is_numeric($result['Valor']) || abs((float) $result['Valor'] - (float) $order->valor) > 0.009) throw new \RuntimeException('Pagamento PAY sem correspondência de referência, pedido, comprador ou valor. A entrega continua bloqueada até conferência.');
        $state = $result['Estado'] ?? '';
        $paid = in_array($state, ['Aprovada', 'Liquidado'], true);
        $cancelled = in_array($state, ['Cancelada', 'Estornado'], true);
        $pending = ['Pendente','Rejeitada','ACancelar','pendenteCobranca','acancelarCNAB','PendenteAntifraude','AnaliseAntifraude',
            'ProcessarInstrucao','EmProcessamento','PagoeAberto','EstornarPIX','ErroEstorno','FalhaCancelamento','CartaoPreAutorizado','CartaoCancelametoFuturo'];
        if (!$paid && !$cancelled && !in_array($state, $pending, true)) throw new \RuntimeException('Estado PAY não reconhecido. Confira a transação antes de liberar entrega.');
        $method = ['Pix' => 'pix', 'Cartao' => 'cartao', 'Boleto' => 'boleto'][$order->metodo];
        if (($paid || $cancelled) && strtolower((string) ($result['TipoPagamento'] ?? '')) !== $method) throw new \RuntimeException('A forma de pagamento confirmada pelo PAY não corresponde ao pedido.');
        if ($paid && (!isset($result['ValorPago']) || !is_numeric($result['ValorPago']) || abs((float) $result['ValorPago'] - (float) $order->valor) > 0.009)) throw new \RuntimeException('O PAY não confirmou o pagamento integral do pedido.');
        if ($paid && $order->metodo === 'Cartao' && is_array($result['Parcelas'] ?? null) && count($result['Parcelas']) > (int) $order->cartao_parcelas_max) throw new \RuntimeException('O parcelamento confirmado pelo PAY excede o limite registrado no pedido. Confira antes de liberar a entrega.');
        if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Support::table('pedidos') . ' WHERE pay_transacao_id = %s AND id <> %d LIMIT 1', $id, $order->id))) throw new \RuntimeException('Esta transação PAY já está vinculada a outro pedido.');
        $update = ['pay_transacao_id' => $id, 'verificado_em' => current_time('mysql'), 'erro_mensagem' => null];
        if ($paid) return $update + ['status' => 'gerado', 'status_pagamento' => 'Paga', 'checkout_url' => null];
        if ($cancelled) return $update + ['status_pagamento' => 'Cancelada', 'status' => 'cancelado', 'checkout_url' => null, 'active_key' => null, 'cupom_reservado' => 0];
        if (in_array($state, ['ACancelar','EstornarPIX','ErroEstorno','FalhaCancelamento','CartaoCancelametoFuturo'], true) || $order->status_pagamento === 'Paga') {
            return $update + ['status' => 'gerado', 'status_pagamento' => 'Em conferência', 'checkout_url' => null];
        }
        return $update + ['status' => 'gerado', 'status_pagamento' => 'Pendente'];
    }
}
