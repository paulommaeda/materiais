<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class PaymentTerms
{
    public static function labels(): array
    {
        return ['Pix' => 'Pix', 'Boleto' => 'Boleto', 'Cartao' => 'Cartão de crédito'];
    }

    public static function methods(object $product): array
    {
        // Registros anteriores à migração continuam com as três formas já disponíveis.
        if (!isset($product->formas_pagamento)) {
            return array_keys(self::labels());
        }
        $methods = json_decode($product->formas_pagamento, true);
        return is_array($methods) ? array_values(array_intersect(array_keys(self::labels()), $methods)) : [];
    }

    public static function count($value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/^(?:[1-9]|1[0-2])$/D', (string) $value)) {
            throw new \InvalidArgumentException('Escolha de 1 a 12 parcelas no cartão.');
        }
        return (int) $value;
    }

    public static function input(array $data): array
    {
        $methods = $data['formas_pagamento'] ?? [];
        if (!is_array($methods) || !$methods || array_filter($methods, static function ($value) {
            return !is_string($value) || !isset(self::labels()[$value]);
        })) {
            throw new \InvalidArgumentException('Selecione pelo menos uma forma de pagamento válida para este produto.');
        }
        return ['formas_pagamento' => wp_json_encode(array_values(array_intersect(array_keys(self::labels()), $methods))),
            'cartao_parcelas' => self::count($data['cartao_parcelas'] ?? 1)];
    }

    public static function for_products(array $products, ?\DateTimeImmutable $at = null): array
    {
        global $wpdb;
        if (!$products) return [];
        $ids = array_map('intval', array_column($products, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $today = Discounts::today($at);
        $rules = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Support::table('parcelamento_data') . " WHERE produto_id IN ($placeholders)
            AND ativo = 1 AND (inicio IS NULL OR inicio <= %s) AND fim >= %s ORDER BY inicio DESC,id DESC", array_merge($ids, [$today, $today]))) as $rule) {
            if (!isset($rules[$rule->produto_id])) $rules[$rule->produto_id] = $rule;
        }
        $result = [];
        foreach ($products as $product) {
            $rule = $rules[$product->id] ?? null;
            $count = $rule ? (int) $rule->parcelas : (int) ($product->cartao_parcelas ?? 1);
            $methods = self::methods($product);
            $card = in_array('Cartao', $methods, true);
            $result[$product->id] = ['formas_pagamento' => $methods, 'parcelas_cartao_max' => $card ? max(1, min(12, $count)) : 0,
                'parcelamento_regra_id' => $card && $rule ? (int) $rule->id : null,
                'parcelamento_titulo' => $card && $rule ? $rule->titulo : null,
                'parcelamento_fim' => $card && $rule ? $rule->fim : null];
        }
        return $result;
    }

    public static function current(object $product, ?\DateTimeImmutable $at = null): array
    {
        return self::for_products([$product], $at)[$product->id];
    }

    private static function dates(array $data): array
    {
        $start = Support::text($data, 'inicio');
        $end = Support::text($data, 'fim');
        foreach ([$start, $end] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
                !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                throw new \InvalidArgumentException('Informe datas válidas para o parcelamento.');
            }
        }
        if (!$end || ($start && $start > $end)) throw new \InvalidArgumentException('Informe a data final e confira o período do parcelamento.');
        return [$start, $end];
    }

    private static function check_overlap(int $product, string $start, string $end, int $exclude): void
    {
        global $wpdb;
        $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Support::table('parcelamento_data') .
            ' WHERE produto_id = %d AND ativo = 1 AND id <> %d AND (inicio IS NULL OR inicio <= %s) AND fim >= %s LIMIT 1',
            $product, $exclude, $end, $start ?: '1000-01-01'));
        if ($exists) throw new \InvalidArgumentException('Este período se sobrepõe a outro parcelamento ativo do produto. Ajuste as datas ou desative a outra faixa.');
    }

    public static function save(int $product, array $data): int
    {
        Support::product($product);
        $title = Support::text($data, 'titulo');
        if (!$title || strlen($title) > 160) throw new \InvalidArgumentException('Informe um nome de até 160 caracteres para o parcelamento.');
        [$start, $end] = self::dates($data);
        $count = self::count($data['parcelas'] ?? '');
        $id = absint($data['id'] ?? 0);
        return Support::lock('payment-terms:' . $product, static function () use ($product, $title, $start, $end, $count, $id) {
            global $wpdb;
            $table = Support::table('parcelamento_data');
            if ($id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE produto_id = %d AND id = %d", $product, $id))) {
                throw new \InvalidArgumentException('Parcelamento não encontrado neste produto.');
            }
            self::check_overlap($product, $start, $end, $id);
            $record = ['produto_id' => $product, 'titulo' => $title, 'inicio' => $start ?: null, 'fim' => $end, 'parcelas' => $count, 'ativo' => 1];
            if ($id) Support::must($wpdb->update($table, $record, ['id' => $id, 'produto_id' => $product]));
            else { Support::must($wpdb->insert($table, $record)); $id = (int) $wpdb->insert_id; }
            return $id;
        });
    }

    public static function toggle(int $product, int $id): void
    {
        Support::lock('payment-terms:' . $product, static function () use ($product, $id) {
            global $wpdb;
            $table = Support::table('parcelamento_data');
            $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d AND id = %d", $product, $id));
            if (!$rule) throw new \InvalidArgumentException('Parcelamento não encontrado neste produto.');
            if (!(int) $rule->ativo) self::check_overlap($product, (string) $rule->inicio, $rule->fim, $id);
            Support::must($wpdb->update($table, ['ativo' => 1 - (int) $rule->ativo], ['id' => $id, 'produto_id' => $product]));
        });
    }

    public static function refresh_after(array $products): int
    {
        global $wpdb;
        if (!$products) return 300;
        $ids = array_map('intval', $products);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $now = current_datetime();
        $seconds = 300;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT inicio,fim FROM ' . Support::table('parcelamento_data') .
            " WHERE produto_id IN ($placeholders) AND ativo = 1 AND fim >= %s", array_merge($ids, [$now->format('Y-m-d')])));
        foreach ($rows as $rule) {
            foreach ([$rule->inicio, (new \DateTimeImmutable($rule->fim, wp_timezone()))->modify('+1 day')->format('Y-m-d')] as $date) {
                if ($date) {
                    $delta = (new \DateTimeImmutable($date . ' 00:00:00', wp_timezone()))->getTimestamp() - $now->getTimestamp();
                    if ($delta >= 0) $seconds = min($seconds, max(1, $delta + 1));
                }
            }
        }
        return $seconds;
    }

    public static function delete(int $product, int $id): void
    {
        Support::lock('payment-terms:' . $product, static function () use ($product, $id) {
            global $wpdb;
            if (Support::must($wpdb->delete(Support::table('parcelamento_data'), ['id' => $id, 'produto_id' => $product])) !== 1) {
                throw new \InvalidArgumentException('Faixa de parcelamento não encontrada neste produto.');
            }
        });
    }

    public static function render(array $terms): string
    {
        if (in_array('Cartao', $terms['formas_pagamento'], true)) {
            $label = $terms['parcelas_cartao_max'] > 1 ? 'Cartão em até ' . $terms['parcelas_cartao_max'] . 'x' : 'Cartão à vista';
            if ($terms['parcelamento_fim']) $label .= ' até ' . Discounts::date_label($terms['parcelamento_fim']);
        } else {
            $label = implode(' · ', array_map(static function ($key) { return self::labels()[$key]; }, $terms['formas_pagamento']));
        }
        return '<span class="bmt-payment-terms">' . esc_html($label) . '</span>';
    }

    public static function migrate(): void
    {
        global $wpdb;
        Support::must($wpdb->query($wpdb->prepare('UPDATE ' . Support::table('produtos') . ' SET formas_pagamento = %s WHERE formas_pagamento IS NULL', wp_json_encode(array_keys(self::labels())))));
    }
}
