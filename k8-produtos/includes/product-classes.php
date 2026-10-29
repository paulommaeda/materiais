<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class ProductClasses
{
    private static function normalize(array $classes): array
    {
        if (count($classes) > 1000) {
            throw new \InvalidArgumentException('Selecione até 1.000 turmas por produto.');
        }
        $result = [];
        foreach ($classes as $class) {
            if (!is_string($class)) {
                throw new \InvalidArgumentException('Informe nomes válidos para as turmas deste produto.');
            }
            $class = sanitize_text_field(trim($class));
            if (strlen($class) > 100) {
                throw new \InvalidArgumentException('O nome de cada turma deve ter até 100 caracteres.');
            }
            if ($class !== '') {
                $result[] = $class;
            }
        }
        $result = array_values(array_unique($result, SORT_STRING));
        sort($result, SORT_STRING);
        return $result;
    }

    public static function input(array $data): array
    {
        $classes = $data['turmas'] ?? [];
        $extra = $data['turmas_adicionais'] ?? '';
        if (!is_array($classes) || !is_string($extra)) {
            throw new \InvalidArgumentException('Selecione as turmas deste produto.');
        }
        $classes = self::normalize(array_merge($classes, preg_split('/\r\n|\r|\n/', $extra)));
        if (!$classes) {
            throw new \InvalidArgumentException('Selecione pelo menos uma turma para este produto.');
        }
        return $classes;
    }

    public static function selected(object $product): array
    {
        $classes = json_decode((string) ($product->turmas_disponiveis ?? ''), true);
        if (!is_array($classes)) {
            return [];
        }
        return array_values(array_filter($classes, static function ($class) {
            return is_string($class) && $class !== '';
        }));
    }

    public static function allows(object $product, string $class): bool
    {
        return in_array($class, self::selected($product), true);
    }

    public static function options(object $product): array
    {
        global $wpdb;
        try {
            $classes = Students::classes();
        } catch (\Throwable $e) {
            $classes = [];
        }
        $prices = !empty($product->id) ? $wpdb->get_col($wpdb->prepare('SELECT turma FROM ' . Support::table('valores_turma') . ' WHERE produto_id = %d', $product->id)) : [];
        return self::normalize(array_merge($classes, self::selected($product), $prices));
    }

    /** Preços apenas das turmas explicitamente liberadas em cada produto. */
    public static function price_choices(array $products): array
    {
        global $wpdb;
        if (!$products) {
            return [];
        }
        $ids = array_map('intval', array_column($products, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $overrides = [];
        foreach ($wpdb->get_results($wpdb->prepare('SELECT produto_id,turma,valor FROM ' . Support::table('valores_turma') . " WHERE produto_id IN ($placeholders)", $ids)) as $price) {
            $overrides[$price->produto_id][$price->turma] = (float) $price->valor;
        }
        $result = [];
        foreach ($products as $product) {
            $choices = [];
            foreach (self::selected($product) as $class) {
                $price = $overrides[$product->id][$class] ?? ($product->valor_padrao !== null ? (float) $product->valor_padrao : null);
                if ($price !== null) {
                    $choices[$class] = $price;
                }
            }
            $result[$product->id] = $choices;
        }
        return $result;
    }

    /** Converte o alcance anterior em uma lista explícita, somente na atualização. */
    public static function migrate(): void
    {
        global $wpdb;
        $table = Support::table('produtos');
        $pending = $wpdb->get_results("SELECT * FROM $table WHERE turmas_disponiveis IS NULL");
        if (!$pending) {
            return;
        }
        try {
            $known = Students::classes();
        } catch (\Throwable $e) {
            $known = $wpdb->get_col('SELECT DISTINCT turma FROM ' . $wpdb->prefix . "lista_alunos_v4 WHERE turma <> ''");
        }
        foreach ($pending as $product) {
            $priced = $wpdb->get_col($wpdb->prepare('SELECT turma FROM ' . Support::table('valores_turma') . ' WHERE produto_id = %d', $product->id));
            $classes = self::normalize(array_merge($priced, $product->valor_padrao !== null ? $known : []));
            Support::must($wpdb->update($table, ['turmas_disponiveis' => wp_json_encode($classes)], ['id' => $product->id]));
        }
    }
}
