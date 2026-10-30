<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Storefront
{
    public static function register(): void
    {
        add_shortcode('k8_catalogo', [self::class, 'shortcode']);
        add_shortcode('buscar_aluno', static function ($atts) {
            $atts = is_array($atts) ? $atts : [];
            $id = absint($atts['produto'] ?? $atts['id'] ?? get_option('k8prod_legacy_product_id', 0));
            return $id ? Views::product_shortcode(['id' => $id]) : '<h2>Escolha o produto para consultar os alunos</h2>' . self::shortcode([]);
        });
        add_filter('query_vars', static function ($vars) {
            $vars[] = 'bmt_produto';
            return $vars;
        });
        add_filter('template_include', static function ($template) {
            if (get_query_var('bmt_produto') === '') return $template;
            try {
                Support::product(absint(get_query_var('bmt_produto')), true);
                return K8PROD_DIR . 'templates/product.php';
            } catch (\InvalidArgumentException $e) {
                return get_404_template() ?: $template;
            }
        }, 100);
        add_action('template_redirect', static function () {
            if (get_query_var('bmt_produto') === '') {
                return;
            }
            if (!defined('DONOTCACHEPAGE')) {
                define('DONOTCACHEPAGE', true);
            }
            nocache_headers();
            try {
                Support::product(absint(get_query_var('bmt_produto')), true);
                status_header(200);
            } catch (\Throwable $e) {
                global $wp_query;
                $wp_query->set_404();
                status_header(404);
                return;
            }
            remove_action('wp_head', 'rel_canonical');
            add_action('wp_head', static function () {
                echo '<link rel="canonical" href="' . esc_url(self::url(absint(get_query_var('bmt_produto')))) . '">';
            });
        });
        add_filter('document_title_parts', static function ($parts) {
            if (get_query_var('bmt_produto') !== '') {
                try {
                    $parts['title'] = Support::product(absint(get_query_var('bmt_produto')), true)->nome;
                } catch (\Throwable $e) {
                    $parts['title'] = 'Página não encontrada';
                }
            }
            return $parts;
        });
        add_action('wp_enqueue_scripts', static function () {
            wp_enqueue_style('bmt-catalog', plugins_url('assets/storefront.css', K8PROD_FILE), [], K8PROD_VERSION);
        });
    }

    public static function categories(): array
    {
        return ['didatico' => 'Materiais didáticos', 'paradidatico' => 'Paradidáticos', 'evento' => 'Eventos escolares'];
    }

    public static function url(int $id): string
    {
        return add_query_arg('bmt_produto', $id, home_url('/'));
    }

    public static function catalog(string $category = ''): array
    {
        global $wpdb;
        $products = Support::table('produtos');
        $where = 'p.ativo = 1 AND p.publicado = 1';
        if (isset(self::categories()[$category])) {
            $where .= $wpdb->prepare(' AND p.categoria = %s', $category);
        }
        $items = $wpdb->get_results("SELECT p.*
            FROM $products p WHERE $where AND p.valor_padrao IS NOT NULL
            ORDER BY p.ordem ASC,p.nome ASC");
        $quotes = Discounts::catalog_prices($items);
        $terms = PaymentTerms::for_products($items);
        $result = [];
        foreach ($items as $product) {
            if (isset($quotes[$product->id])) {
                $product->catalog_price = $quotes[$product->id];
                $product->catalog_payment = $terms[$product->id];
                $result[] = $product;
            }
        }
        return $result;
    }

    public static function price_html(object $product): string
    {
        return Discounts::render_price($product->catalog_price) . PaymentTerms::render($product->catalog_payment);
    }

    public static function icon(string $category): string
    {
        $paths = [
            'didatico' => '<path d="M5 5h7a4 4 0 0 1 4 4v18a5 5 0 0 0-4-2H5zM27 5h-7a4 4 0 0 0-4 4v18a5 5 0 0 1 4-2h7z"/><path d="M9 10h3m-3 5h3m8-5h3m-3 5h3"/>',
            'paradidatico' => '<path d="M6 7h17v21H6zM10 3h17v21M10 12h9M10 17h7"/><path d="m15 21 1 2 2 .3-1.5 1.5.4 2.2-1.9-1-2 1 .4-2.2-1.5-1.5 2.2-.3z"/>',
            'evento' => '<rect x="5" y="6" width="22" height="22" rx="3"/><path d="M10 3v6M22 3v6M5 13h22m-16 7 3 3 7-6"/>',
        ];
        return '<svg viewBox="0 0 32 32" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$category] ?? $paths['didatico']) . '</svg>';
    }

    public static function shortcode($atts): string
    {
        $atts = shortcode_atts(['categoria' => '', 'filtros' => '1'], $atts, 'k8_catalogo');
        $category = sanitize_key($atts['categoria'] ?: ($_GET['bmt_categoria'] ?? ''));
        if (!isset(self::categories()[$category])) {
            $category = '';
        }
        $items = self::catalog($category);
        wp_enqueue_script('bmt-catalog', plugins_url('assets/storefront.js', K8PROD_FILE), [], K8PROD_VERSION, true);
        ob_start();
        echo '<div class="bmt-catalog" data-bmt-catalog data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '">';
        if ($atts['filtros'] === '1') {
            echo '<nav class="bmt-categories" aria-label="Categorias de produtos">';
            foreach (['' => 'Todos'] + self::categories() as $key => $label) {
                $url = $key ? add_query_arg('bmt_categoria', $key, home_url('/')) : home_url('/');
                echo '<a href="' . esc_url($url . '#produtos') . '" ' . ($category === $key ? 'aria-current="true"' : '') . '>' . esc_html($label) . '</a>';
            }
            echo '</nav>';
        }
        if (!$items) {
            echo '<div class="bmt-empty"><h3>Novidades a caminho</h3><p>Os materiais e eventos disponíveis serão publicados aqui. Consulte a escola para saber sobre as próximas ofertas.</p></div>';
        } else {
            echo '<div class="bmt-products">';
            foreach ($items as $p) {
                $cat = isset(self::categories()[$p->categoria]) ? $p->categoria : 'didatico';
                echo '<article class="bmt-product-card" data-bmt-product="' . (int) $p->id . '"><a class="bmt-product-art art-' . esc_attr($cat) . '" href="' . esc_url(self::url((int) $p->id)) . '" aria-label="' . esc_attr('Ver ' . $p->nome) . '">';
                if ($p->imagem_id && wp_attachment_is_image((int) $p->imagem_id)) {
                    echo wp_get_attachment_image((int) $p->imagem_id, 'medium_large', false, ['loading' => 'lazy']);
                } else {
                    echo '<span class="bmt-art-icon">' . self::icon($cat) . '</span><span class="bmt-art-caption">' . esc_html(self::categories()[$cat]) . '</span>';
                }
                echo '</a><div class="bmt-product-info"><span class="bmt-product-category">' . esc_html(self::categories()[$cat]) . '</span><h3><a href="' . esc_url(self::url((int) $p->id)) . '">' . esc_html($p->nome) . '</a></h3><p>' . esc_html(wp_trim_words($p->descricao ?: 'Selecione o aluno e consulte as condições para a sua turma.', 20)) . '</p><div class="bmt-product-bottom"><div data-bmt-price>' . self::price_html($p) . '</div><a class="bmt-buy-link" href="' . esc_url(self::url((int) $p->id)) . '">Ver produto <span aria-hidden="true">↗</span></a></div></div></article>';
            }
            echo '</div>';
        }
        echo '</div>';
        return ob_get_clean();
    }
}
