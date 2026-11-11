<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

/** O retorno só identifica o pedido; a situação sempre vem de consulta autenticada ao PAY. */
final class PayReturn
{
    private static ?array $view = null;

    public static function register(): void
    {
        add_action('rest_api_init', static function () {
            register_rest_route('k8-produtos/v1', '/pay/confirmacao/(?P<id>\d+)', [
                'methods' => ['GET', 'POST'], 'callback' => [self::class, 'confirmation'],
                'permission_callback' => [self::class, 'permission'],
            ]);
        });
        add_action('template_redirect', [self::class, 'prepare_return'], 1000);
    }

    public static function token(object $order): string
    {
        return hash_hmac('sha256', 'k8-pay-return|' . $order->id . '|' . PayPayments::reference($order), wp_salt('auth'));
    }

    public static function urls(object $order): array
    {
        $token = self::token($order);
        return ['URLRetorno' => add_query_arg(['k8_pay_return' => (int) $order->id, 'k8_pay_token' => $token], Storefront::url((int) $order->produto_id)),
            'URLConfirmacao' => add_query_arg('token', $token, rest_url('k8-produtos/v1/pay/confirmacao/' . (int) $order->id))];
    }

    private static function authorized(int $id, $token): ?object
    {
        global $wpdb;
        if ($id < 1 || !is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $order = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Support::table('pedidos') . ' WHERE id=%d', $id));
        return $order && PayPayments::is_pay($order) && hash_equals(self::token($order), $token) ? $order : null;
    }

    public static function permission(\WP_REST_Request $request)
    {
        $path = $request->get_url_params();
        return self::authorized((int) ($path['id'] ?? 0), $request->get_param('token')) ? true : new \WP_Error('k8_pay_return_invalid', 'Link de confirmação inválido.', ['status' => 403]);
    }

    private static function sync(object $order): object
    {
        try {
            return Service::sync_order((int) $order->produto_id, (int) $order->id);
        } catch (\Throwable $e) {
            global $wpdb;
            $wpdb->update(Support::table('pedidos'), ['erro_mensagem' => Support::diagnostic($e)], ['id' => $order->id]);
            throw $e;
        }
    }

    public static function confirmation(\WP_REST_Request $request)
    {
        nocache_headers();
        $path = $request->get_url_params();
        $order = self::authorized((int) ($path['id'] ?? 0), $request->get_param('token'));
        if (!$order) return new \WP_Error('k8_pay_return_invalid', 'Link de confirmação inválido.', ['status' => 403]);
        try {
            // Estado, valor e comprador enviados no callback nunca confirmam pagamento.
            $order = self::sync($order);
            return rest_ensure_response(['pedido' => (int) $order->id, 'pagamento' => $order->status_pagamento ?: 'Pendente']);
        } catch (\Throwable $e) {
            return new \WP_Error('k8_pay_lookup_pending', 'A confirmação aguarda consulta ao PAY. Preserve o pedido e tente consultar novamente.', ['status' => 503]);
        }
    }

    public static function prepare_return(): void
    {
        if (!isset($_GET['k8_pay_return'])) return;
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        nocache_headers();
        if (!headers_sent()) {
            header('Referrer-Policy: no-referrer');
            header('X-Robots-Tag: noindex, nofollow');
        }
        $id = is_scalar($_GET['k8_pay_return']) ? absint($_GET['k8_pay_return']) : 0;
        $token = isset($_GET['k8_pay_token']) && is_string($_GET['k8_pay_token']) ? wp_unslash($_GET['k8_pay_token']) : '';
        $order = self::authorized($id, $token);
        self::$view = ['valid' => false, 'paid' => false, 'cancelled' => false, 'retry' => null, 'store' => home_url('/')];
        if ($order) {
            try { $order = self::sync($order); } catch (\Throwable $e) { /* Mensagem pública genérica, diagnóstico no painel. */ }
            self::$view = ['valid' => true, 'paid' => $order->status_pagamento === 'Paga', 'cancelled' => $order->status_pagamento === 'Cancelada',
                'retry' => self::urls($order)['URLRetorno'], 'store' => Storefront::url((int) $order->produto_id)];
        }
        status_header($order ? 200 : 403);
        add_filter('template_include', static function () { return K8PROD_DIR . 'templates/pay-return.php'; }, 999);
        wp_enqueue_style('k8prod', plugins_url('assets/app.css', K8PROD_FILE), [], K8PROD_VERSION);
    }

    public static function render(): string
    {
        $view = self::$view;
        if (!$view || !$view['valid']) return '<section class="k8prod k8-card"><h1>Retorno não identificado</h1><p>Volte à janela da loja para consultar a situação do pedido. Se você já pagou, preserve o pedido e fale com a secretaria.</p></section>';
        $title = $view['paid'] ? 'Pagamento confirmado' : ($view['cancelled'] ? 'Pagamento cancelado no PAY' : 'Pagamento em conferência');
        $message = $view['paid'] ? 'A confirmação do pagamento foi registrada. Volte à janela da loja para acompanhar seu pedido e a entrega.'
            : ($view['cancelled'] ? 'A consulta ao PAY informou cancelamento. Confira com a secretaria antes de qualquer nova compra.' : 'A loja ainda aguarda a confirmação pela consulta ao PAY. Se você já pagou, não repita o pagamento; atualize a confirmação ou fale com a secretaria.');
        $html = '<section class="k8prod k8-card"><h1>' . esc_html($title) . '</h1><p>' . esc_html($message) . '</p>';
        if (!$view['paid'] && !$view['cancelled']) $html .= '<p><a class="k8-btn" href="' . esc_url($view['retry']) . '" rel="noreferrer">Atualizar confirmação</a></p>';
        return $html . '<p><a class="k8-btn secondary" href="' . esc_url($view['store']) . '">Voltar à loja</a></p></section>';
    }
}
