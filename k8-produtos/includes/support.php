<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Support
{
    public static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . 'k8p_' . $suffix;
    }

    public static function must($result)
    {
        if ($result === false) {
            throw new \RuntimeException('Não foi possível gravar os dados. Tente novamente ou consulte o administrador.');
        }
        return $result;
    }

    public static function text(array $data, string $key): string
    {
        return isset($data[$key]) && is_scalar($data[$key]) ? sanitize_text_field((string) $data[$key]) : '';
    }

    public static function money($value): string
    {
        $value = trim((string) $value);
        if (strpos($value, ',') !== false) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        }
        if (!preg_match('/^\d+(?:\.\d{1,2})?$/D', $value) || (float) $value > 99999999.99) {
            throw new \InvalidArgumentException('Informe um valor válido, como 1.250,00.');
        }
        return number_format((float) $value, 2, '.', '');
    }

    public static function cpf(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }

    public static function valid_cpf(string $cpf): bool
    {
        $cpf = self::cpf($cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/D', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            if (((10 * $sum) % 11) % 10 !== (int) $cpf[$t]) {
                return false;
            }
        }
        return true;
    }

    public static function can_manage(int $product = 0): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        if (!current_user_can('k8_manage_products')) {
            return false;
        }
        $allowed = get_user_meta(get_current_user_id(), 'k8prod_product_ids', true);
        if (is_array($allowed) && (!$product || !in_array($product, array_map('intval', $allowed), true))) {
            return false;
        }
        return (bool) apply_filters('k8prod_can_manage_product', true, $product, get_current_user_id());
    }

    public static function product(int $id, bool $public = false): object
    {
        global $wpdb;
        $table = self::table('produtos');
        $product = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $id));
        if (!$product || ($public && !(int) $product->ativo)) {
            throw new \InvalidArgumentException('Produto indisponível.');
        }
        return $product;
    }

    public static function products(): array
    {
        global $wpdb;
        $table = self::table('produtos');
        return array_values(array_filter($wpdb->get_results("SELECT * FROM $table ORDER BY ativo DESC, nome ASC"), static function ($p) {
            return self::can_manage((int) $p->id);
        }));
    }

    public static function lock(string $resource, callable $callback)
    {
        global $wpdb;
        $name = 'k8prod_' . substr(hash('sha256', $wpdb->prefix . $resource), 0, 48);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 5)', $name)) !== 1) {
            throw new \RuntimeException('Este registro está sendo processado. Aguarde alguns segundos.');
        }
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public static function transaction(callable $callback)
    {
        global $wpdb;
        self::must($wpdb->query('START TRANSACTION'));
        try {
            $result = $callback();
            self::must($wpdb->query('COMMIT'));
            return $result;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }
    }

    public static function log(int $product, int $order, string $action, array $data = []): void
    {
        global $wpdb;
        $details = $data;
        unset($details['assinatura']);
        self::must($wpdb->insert(self::table('entregas_log'), [
            'produto_id' => $product,
            'pedido_id' => $order,
            'entrega_id' => (int) ($data['entrega_id'] ?? 0),
            'acao' => $action,
            'usuario' => is_user_logged_in() ? wp_get_current_user()->display_name : 'Sistema',
            'usuario_id' => get_current_user_id(),
            'motivo' => $data['motivo'] ?? null,
            'assinatura' => $data['assinatura'] ?? null,
            'dados' => wp_json_encode($details),
            'criado_em' => current_time('mysql'),
        ]));
    }

    public static function semester(string $period): int
    {
        return in_array($period, ['semestre_2', 'bimestre_3', 'bimestre_4'], true) ? 2 : 1;
    }

    public static function periods(): array
    {
        return ['ano' => 'Entrega única / anual', 'semestre_1' => '1º semestre', 'semestre_2' => '2º semestre',
            'bimestre_1' => '1º bimestre', 'bimestre_2' => '2º bimestre', 'bimestre_3' => '3º bimestre', 'bimestre_4' => '4º bimestre'];
    }
}
