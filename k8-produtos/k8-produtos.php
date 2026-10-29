<?php
/**
 * Plugin Name: K8 Produtos, Alunos, Cobranças e Entregas
 * Description: Produtos com shortcode próprio, cobrança K8, cupons, estoque e entrega com assinatura.
 * Version: 2.3.0
 * Plugin URI: https://github.com/paulommaeda/materiais
 * Update URI: https://github.com/paulommaeda/materiais/k8-produtos
 * Author: Paulo Maeda
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: k8-produtos
 */

namespace K8Produtos;

defined('ABSPATH') || exit;

define('K8PROD_VERSION', '2.3.0');
define('K8PROD_FILE', __FILE__);
define('K8PROD_DIR', __DIR__ . '/');

foreach (['support', 'student-import', 'student-api', 'schema', 'client', 'students', 'discounts', 'service', 'http', 'views', 'storefront', 'updates'] as $module) {
    require_once K8PROD_DIR . 'includes/' . $module . '.php';
}

register_activation_hook(__FILE__, [Schema::class, 'activate']);
Updates::register();
register_deactivation_hook(__FILE__, static function () {
    wp_clear_scheduled_hook('k8prod_sync');
});
add_filter('cron_schedules', static function ($schedules) {
    $schedules['k8prod_five_minutes'] = ['interval' => 300, 'display' => 'K8: a cada cinco minutos'];
    return $schedules;
});
add_action('plugins_loaded', static function () {
    // O plugin antigo não deve voltar a administrar o mesmo banco em paralelo.
    if (function_exists('k8p_get_client') || function_exists('k8pm_criar_tabelas') || function_exists('sba_criar_tabelas_banco')) {
        add_action('admin_notices', static function () {
            echo '<div class="notice notice-error"><p>K8 Produtos: desative os plugins antigos de pagamento, dashboard, materiais e alunos para usar o plugin unificado.</p></div>';
        });
        return;
    }
    try {
        if (get_option('k8prod_schema') !== K8PROD_VERSION) {
            Schema::install();
        }
    } catch (\Throwable $e) {
        add_action('admin_notices', static function () use ($e) {
            echo '<div class="notice notice-error"><p>K8 Produtos: ' . esc_html($e->getMessage()) . '</p></div>';
        });
        return;
    }
    if (!wp_next_scheduled('k8prod_sync')) {
        wp_schedule_event(time() + 300, 'k8prod_five_minutes', 'k8prod_sync');
    }
    StudentImport::register();
    StudentApi::register();
    Storefront::register();
    Http::register();
    Views::register();
    add_action('k8prod_sync', [Service::class, 'sync_batch']);
});
