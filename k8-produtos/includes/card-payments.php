<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

/** Link individual de valor fixo: a K8 aplica o limite de parcelas no checkout hospedado. */
final class CardPayments
{
    public static function is_link(object $order): bool
    {
        return $order->metodo === 'Cartao' && $order->origem === 'k8_link';
    }

    public static function reference(object $order): string
    {
        return 'K8P-' . $order->id . '-' . $order->pagamento_referencia;
    }

    public static function validate(object $order, array $link, string $expected_id): void
    {
        if (($link['Id'] ?? '') !== $expected_id || ($link['TipoValor'] ?? '') !== 'fixo' ||
            !isset($link['Valor']) || abs((float) $link['Valor'] - (float) $order->valor) > 0.009 ||
            ($link['FormasPagamento'] ?? '') !== 'Cartao' || ($link['LinkReutilizavel'] ?? null) !== false ||
            ($link['ParcelaFixa'] ?? null) !== true || (int) ($link['ParcelaFixaQtd'] ?? 0) !== (int) $order->cartao_parcelas_max ||
            ($link['Observacoes'] ?? '') !== self::reference($order)) {
            throw new \RuntimeException('Link K8 sem correspondência de referência, valor, forma de pagamento ou parcelas deste pedido. Confira na K8.');
        }
    }

    public static function create(Client $client, object $order, object $product): void
    {
        global $wpdb;
        $result = $client->create_card_link($order, $product);
        $id = $result['Id'] ?? '';
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{1,50}$/D', $id)) throw new \RuntimeException('A K8 não retornou um identificador de link válido.');
        // Conserva o ID mesmo se a leitura seguinte falhar; não repete o POST.
        Support::must($wpdb->update(Support::table('pedidos'), ['link_pagamento_id' => $id], ['id' => $order->id]));
        $order->link_pagamento_id = $id;
        $link = $client->card_link($id);
        self::validate($order, $link, $id);
        if (($link['Ativo'] ?? false) !== true) throw new \RuntimeException('O link criado não está ativo na K8.');
        $url = Client::card_checkout($link);
        Support::must($wpdb->update(Support::table('pedidos'), ['checkout_url' => $url, 'status' => 'gerado',
            'status_pagamento' => 'Pendente', 'verificado_em' => current_time('mysql')], ['id' => $order->id]));
    }

    public static function synchronized(Client $client, object $order, ?array $link = null): array
    {
        global $wpdb;
        $link = $link ?? $client->card_link($order->link_pagamento_id);
        self::validate($order, $link, $order->link_pagamento_id);
        $payment = is_array($link['DadosPagamento'] ?? null) ? $link['DadosPagamento'] : [];
        $update = ['verificado_em' => current_time('mysql'), 'erro_mensagem' => null];
        $paid = ($payment['Situacao'] ?? '') === 'pago' || (int) ($payment['NumeroCobrancasPagas'] ?? 0) > 0;
        if ($paid) {
            $charge_id = $payment['CobrancaId'] ?? '';
            if (!is_string($charge_id) || !preg_match('/^[A-Za-z0-9-]{1,50}$/D', $charge_id) ||
                !empty($payment['ReembolsadoAoCliente']) || ($payment['FormaPagamento'] ?? '') !== 'Cartao' ||
                (isset($payment['NumeroCobrancasPagas']) && (int) $payment['NumeroCobrancasPagas'] !== 1)) {
                throw new \RuntimeException('O pagamento do link precisa de conferência na K8 antes de liberar a entrega.');
            }
            $charge = $client->card_charge($charge_id);
            if (($charge['Id'] ?? '') !== $charge_id || ($charge['Estado'] ?? '') !== 'Paga' ||
                !isset($charge['Valor']) || abs((float) $charge['Valor'] - (float) $order->valor) > 0.009) {
                throw new \RuntimeException('Cobrança do link sem confirmação de pagamento ou valor correspondente.');
            }
            if ($wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Support::table('pedidos') . ' WHERE cobranca_id = %s AND id <> %d LIMIT 1', $charge_id, $order->id))) {
                throw new \RuntimeException('Esta cobrança já está vinculada a outro pedido.');
            }
            return $update + ['cobranca_id' => $charge_id, 'cliente_id' => isset($charge['ClienteId']) ? (string) $charge['ClienteId'] : null,
                'status' => 'gerado', 'status_pagamento' => 'Paga', 'checkout_url' => null];
        }
        if (($link['Ativo'] ?? false) !== true) throw new \RuntimeException('Link de cartão desativado ou expirado. A secretaria precisa conferir o pedido na K8.');
        return $update + ['status' => 'gerado', 'status_pagamento' => 'Pendente', 'checkout_url' => Client::card_checkout($link)];
    }

    public static function cancel(Client $client, object $order): void
    {
        global $wpdb;
        $link = $client->card_link($order->link_pagamento_id);
        self::validate($order, $link, $order->link_pagamento_id);
        $payment = is_array($link['DadosPagamento'] ?? null) ? $link['DadosPagamento'] : [];
        if (($payment['Situacao'] ?? '') === 'pago' || (int) ($payment['NumeroCobrancasPagas'] ?? 0) > 0) {
            Support::must($wpdb->update(Support::table('pedidos'), self::synchronized($client, $order, $link), ['id' => $order->id]));
            throw new \InvalidArgumentException('O cartão já foi pago na K8. O pedido foi atualizado; trate o reembolso diretamente na K8.');
        }
        if (($link['Ativo'] ?? false) === true) $client->disable_card_link($link);
        $after = $client->card_link($order->link_pagamento_id);
        self::validate($order, $after, $order->link_pagamento_id);
        $payment = is_array($after['DadosPagamento'] ?? null) ? $after['DadosPagamento'] : [];
        $paid = ($payment['Situacao'] ?? '') === 'pago' || (int) ($payment['NumeroCobrancasPagas'] ?? 0) > 0;
        if ($paid) {
            Support::must($wpdb->update(Support::table('pedidos'), self::synchronized($client, $order, $after), ['id' => $order->id]));
            throw new \InvalidArgumentException('O pagamento foi confirmado durante a conferência. Pedido atualizado para pago.');
        }
        Support::must($wpdb->update(Support::table('pedidos'), ['checkout_url' => null], ['id' => $order->id]));
        if (($after['Ativo'] ?? true) !== false || (int) ($payment['NumeroCobrancasPendentes'] ?? 0) > 0 ||
            (int) ($payment['NumeroCobrancasInadimplentes'] ?? 0) > 0 ||
            (int) ($payment['NumeroCobrancas'] ?? 0) > (int) ($payment['NumeroCobrancasCanceladas'] ?? 0)) {
            throw new \InvalidArgumentException('O link foi desativado, mas existem transações a conferir na K8. Resolva os pagamentos pendentes antes de cancelar o pedido.');
        }
    }

    public static function reconcile(object $order, string $id): void
    {
        global $wpdb;
        if ($order->link_pagamento_id && $order->link_pagamento_id !== $id) throw new \InvalidArgumentException('Este pedido já possui outro link. Consulte o link registrado antes de conciliar.');
        $client = new Client();
        $link = $client->card_link($id);
        self::validate($order, $link, $id);
        $order->link_pagamento_id = $id;
        $update = self::synchronized($client, $order, $link) + ['link_pagamento_id' => $id];
        Support::transaction(static function () use ($wpdb, $order, $update, $id) {
            Support::must($wpdb->update(Support::table('pedidos'), $update, ['id' => $order->id]));
            Support::log((int) $order->produto_id, (int) $order->id, 'conciliado', ['motivo' => 'Link ' . $id . ' vinculado após conferência.']);
        });
    }
}
