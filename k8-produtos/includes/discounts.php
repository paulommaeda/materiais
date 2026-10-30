<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Discounts
{
    public static function today(?\DateTimeImmutable $at = null): string
    {
        return ($at ?: current_datetime())->setTimezone(wp_timezone())->format('Y-m-d');
    }

    public static function rules(int $product, ?\DateTimeImmutable $at = null): array
    {
        global $wpdb;
        $table = Support::table('descontos_data');
        $today = self::today($at);
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d AND ativo = 1
            AND (inicio IS NULL OR inicio <= %s) AND fim >= %s ORDER BY fim ASC,id ASC", $product, $today, $today));
    }

    public static function apply(float $base, string $class, array $rules): array
    {
        $original = (int) round($base * 100);
        $best = $original;
        $chosen = null;
        foreach ($rules as $rule) {
            if ($rule->turma_restrita && $rule->turma_restrita !== $class) {
                continue;
            }
            $amount = (int) round((float) $rule->valor * 100);
            // Percentual em centésimos de ponto percentual, valores monetários em centavos.
            $candidate = $rule->tipo === 'percentual'
                ? (int) round($original * (10000 - $amount) / 10000) : $amount;
            if ($candidate >= 0 && $candidate < $best) {
                $best = $candidate;
                $chosen = $rule;
            }
        }
        return ['original' => $original / 100, 'valor' => $best / 100, 'promocao' => $chosen];
    }

    public static function price(object $product, string $class, ?\DateTimeImmutable $at = null): ?array
    {
        $base = Service::price($product, $class);
        return $base === null ? null : self::apply($base, $class, self::rules((int) $product->id, $at));
    }

    public static function save(int $product, array $data): int
    {
        global $wpdb;
        $record_product = Support::product($product);
        $name = Support::text($data, 'titulo');
        $type = Support::text($data, 'tipo');
        $start = Support::text($data, 'inicio');
        $end = Support::text($data, 'fim');
        $class = Support::text($data, 'turma_restrita');
        if ($class !== '' && !ProductClasses::allows($record_product, $class)) {
            throw new \InvalidArgumentException('O preço por data só pode ser configurado para uma turma selecionada neste produto.');
        }
        $value = Support::money(Support::text($data, 'valor'));
        foreach ([$start, $end] as $date) {
            if ($date !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ||
                !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4)))) {
                throw new \InvalidArgumentException('Informe datas válidas para o desconto.');
            }
        }
        if (!$name || strlen($name) > 160 || strlen($class) > 100 || !$end || ($start && $start > $end) ||
            !in_array($type, ['percentual', 'preco'], true) || ($type === 'percentual' && ((float) $value <= 0 || (float) $value > 100))) {
            throw new \InvalidArgumentException('Confira nome, tipo, valor e período do desconto.');
        }
        $table = Support::table('descontos_data');
        $id = absint($data['id'] ?? 0);
        $record = ['produto_id' => $product, 'titulo' => $name, 'tipo' => $type, 'valor' => $value,
            'turma_restrita' => $class ?: null, 'inicio' => $start ?: null, 'fim' => $end,
            'acumular_cupom' => empty($data['acumular_cupom']) ? 0 : 1, 'ativo' => 1];
        if ($id) {
            if (!$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE id = %d AND produto_id = %d", $id, $product))) {
                throw new \InvalidArgumentException('Desconto não encontrado neste produto.');
            }
            Support::must($wpdb->update($table, $record, ['id' => $id, 'produto_id' => $product]));
        } else {
            Support::must($wpdb->insert($table, $record));
            $id = (int) $wpdb->insert_id;
        }
        do_action('k8prod_discount_changed', $product);
        return $id;
    }

    public static function toggle(int $product, int $id): void
    {
        global $wpdb;
        $table = Support::table('descontos_data');
        if (Support::must($wpdb->query($wpdb->prepare("UPDATE $table SET ativo = 1 - ativo WHERE id = %d AND produto_id = %d", $id, $product))) !== 1) {
            throw new \InvalidArgumentException('Desconto não encontrado neste produto.');
        }
        do_action('k8prod_discount_changed', $product);
    }

    public static function date_label(string $date): string
    {
        return substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4);
    }

    public static function delete(int $product, int $id): void
    {
        global $wpdb;
        if (Support::must($wpdb->delete(Support::table('descontos_data'), ['id' => $id, 'produto_id' => $product])) !== 1) {
            throw new \InvalidArgumentException('Faixa de preço não encontrada neste produto.');
        }
        do_action('k8prod_discount_changed', $product);
    }

    /** Cada preço promocional é pareado ao preço normal da mesma turma. */
    public static function catalog_prices(array $products): array
    {
        global $wpdb;
        if (!$products) {
            return [];
        }
        $ids = array_map('intval', array_column($products, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rules_table = Support::table('descontos_data');
        $prices = ProductClasses::price_choices($products);
        $today = self::today();
        $rules = [];
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $rules_table WHERE produto_id IN ($placeholders)
            AND ativo = 1 AND (inicio IS NULL OR inicio <= %s) AND fim >= %s ORDER BY fim ASC,id ASC", array_merge($ids, [$today, $today]))) as $rule) {
            $rules[$rule->produto_id][] = $rule;
        }
        $result = [];
        foreach ($products as $p) {
            $choices = $prices[$p->id] ?? [];
            $active = $rules[$p->id] ?? [];
            $best = null;
            foreach ($choices as $class => $base) {
                $quote = self::apply($base, (string) $class, $active);
                if ($best === null || $quote['valor'] < $best['valor'] ||
                    ($quote['valor'] === $best['valor'] && $quote['promocao'] && !$best['promocao'])) {
                    $best = $quote;
                }
            }
            if ($best !== null) {
                $result[$p->id] = $best;
            }
        }
        return $result;
    }

    public static function render_price(array $price): string
    {
        $money = static function ($value) { return 'R$ ' . number_format((float) $value, 2, ',', '.'); };
        $html = '<small>A partir de</small>';
        if ($price['promocao']) {
            $html .= '<del class="bmt-original-price">' . esc_html($money($price['original'])) . '</del>';
        }
        $html .= '<strong>' . esc_html($money($price['valor'])) . '</strong>';
        if ($price['promocao']) {
            $html .= '<span class="bmt-promo-until">Até ' . esc_html(self::date_label($price['promocao']->fim)) . '</span>';
        }
        return $html;
    }

    public static function refresh_after(array $products): int
    {
        global $wpdb;
        if (!$products) {
            return 300;
        }
        $table = Support::table('descontos_data');
        $now = current_datetime();
        $today = self::today($now);
        $seconds = 300;
        $ids = array_map('intval', $products);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        foreach ($wpdb->get_results($wpdb->prepare("SELECT inicio,fim FROM $table WHERE ativo = 1 AND fim >= %s AND produto_id IN ($placeholders)", array_merge([$today], $ids))) as $rule) {
            foreach ([$rule->inicio, $rule->fim ? (new \DateTimeImmutable($rule->fim, wp_timezone()))->modify('+1 day')->format('Y-m-d') : null] as $date) {
                if ($date) {
                    $delta = (new \DateTimeImmutable($date . ' 00:00:00', wp_timezone()))->getTimestamp() - $now->getTimestamp();
                    if ($delta >= 0) {
                        $seconds = min($seconds, max(1, $delta + 1));
                    }
                }
            }
        }
        return $seconds;
    }
}
