#!/usr/bin/env python3
"""Prepara WordPress descartável para CI. Sem acesso a sites ou dados de produção."""
from pathlib import Path
import os
import secrets
import sys
import tarfile
import urllib.request

if os.environ.get('K8PROD_TESTING') != '1':
    raise SystemExit('Use K8PROD_TESTING=1 somente no ambiente descartável.')
root = Path(__file__).resolve().parent.parent
destination = Path(sys.argv[1]).resolve()
if destination.exists():
    raise SystemExit('Escolha um diretório novo para o WordPress de testes.')
destination.parent.mkdir(parents=True, exist_ok=True)
archive = destination.parent / 'wordpress-email-test.tar.gz'
with urllib.request.urlopen('https://wordpress.org/wordpress-7.1.4.tar.gz', timeout=60) as response:
    archive.write_bytes(response.read())
with tarfile.open(archive) as bundle:
    bundle.extractall(destination.parent, filter='data')
if destination.name != 'wordpress' or not (destination / 'wp-load.php').is_file():
    raise SystemExit('O diretório de destino deve se chamar wordpress.')
config = """<?php
define('DB_NAME', getenv('K8PROD_CI_DB') ?: 'k8prod_emails_test');
define('DB_USER', 'root');
define('DB_PASSWORD', 'k8-test-only');
define('DB_HOST', '127.0.0.1:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
$table_prefix = 'wp_';
define('WP_HOME', 'http://127.0.0.1:8890');
define('WP_SITEURL', 'http://127.0.0.1:8890');
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('DISABLE_WP_CRON', true);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', false);
"""
for key in ('AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'):
    config += f"define('{key}', '{secrets.token_hex(32)}');\n"
config += "if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');\nrequire_once ABSPATH . 'wp-settings.php';\n"
(destination / 'wp-config.php').write_text(config, encoding='utf-8')
plugins = destination / 'wp-content/plugins'
(plugins / 'k8-produtos').symlink_to(root / 'k8-produtos', target_is_directory=True)
(destination / 'wp-content/themes/bmt-educacional').symlink_to(root / 'bmt-educacional', target_is_directory=True)
mu = destination / 'wp-content/mu-plugins'
mu.mkdir(exist_ok=True)
(mu / 'block-real-mail.php').write_text("""<?php
// Nenhuma mensagem sai do CI, mesmo quando uma fixture simula falha de envio.
add_filter('pre_wp_mail', static function ($result) { return $result === null ? true : $result; }, PHP_INT_MAX);
""", encoding='utf-8')
print('WordPress 7.1.4 preparado para fixtures isoladas; e-mails reais bloqueados.')
