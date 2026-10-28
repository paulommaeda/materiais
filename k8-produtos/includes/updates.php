<?php
namespace K8Produtos;
defined('ABSPATH') || exit;
require_once __DIR__ . '/update-channel.php';

final class Updates
{
    private static $channel;

    public static function register(): void
    {
        self::$channel = new \BMT_Update_Channel('k8-produtos', 'plugin', K8PROD_VERSION, K8PROD_FILE);
        add_action('admin_post_k8prod_check_updates', static function () {
            if (!current_user_can('update_plugins')) {
                wp_die('Sem permissão.', '', ['response' => 403]);
            }
            check_admin_referer('k8prod_check_updates');
            self::$channel->metadata(true);
            delete_site_transient('update_plugins');
            wp_update_plugins();
            wp_safe_redirect(admin_url('update-core.php'));
            exit;
        });
    }

    public static function status(): void
    {
        echo '<section class="k8-card"><h2>Atualizações pelo WordPress</h2><p>Versão instalada: ' . esc_html(K8PROD_VERSION) . '. Novas versões são obtidas do repositório paulommaeda/materiais.</p><p>Use Painel → Atualizações para instalar novas versões. A atualização automática pode ser ativada na lista de plugins.</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="k8prod_check_updates">';
        wp_nonce_field('k8prod_check_updates');
        echo '<button type="submit" class="k8-btn">Verificar atualizações</button></form></section>';
    }
}
