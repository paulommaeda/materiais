<?php
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Somente CI descartável.\n");
$db = getenv('K8PROD_CI_DB') ?: 'k8prod_emails_test';
if (!preg_match('/^k8prod_[a-z0-9_]+_test$/D', $db)) exit("Banco de teste inválido.\n");
$connection = new mysqli('127.0.0.1', 'root', 'k8-test-only', '', 3306);
$connection->query("CREATE DATABASE IF NOT EXISTS \x60$db\x60 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$connection->close();
define('WP_INSTALLING', true);
require getenv('K8PROD_WP_LOAD');
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (!is_blog_installed()) wp_install('BMT — teste de integração', 'k8admin', 'admin@example.invalid', false, '', 'fixture-only-password-2026');
wp_set_current_user(get_user_by('login', 'k8admin')->ID);
$result = activate_plugin('k8-produtos/k8-produtos.php');
if (is_wp_error($result)) throw new RuntimeException($result->get_error_message());
switch_theme('bmt-educacional');
echo "WordPress " . get_bloginfo('version') . " / PHP " . PHP_VERSION . " / banco $db.\n";
