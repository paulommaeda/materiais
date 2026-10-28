<?php
/** Execute somente numa cópia descartável: sobrescreve os arquivos instalados. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || getenv('K8PROD_ALLOW_FILE_UPGRADE') !== '1' || !getenv('K8PROD_WP_LOAD') || !getenv('K8PROD_REPO_DIR')) {
    exit("Fixture CLI exige K8PROD_TESTING, K8PROD_ALLOW_FILE_UPGRADE, K8PROD_WP_LOAD e K8PROD_REPO_DIR.\n");
}
define('FS_METHOD', 'direct');
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
if (is_link(WP_PLUGIN_DIR.'/k8-produtos') || is_link(get_theme_root().'/bmt-educacional')) exit("Use cópias reais, não links para o repositório.\n");
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/template.php';
require_once ABSPATH.'wp-admin/includes/misc.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
$admin=get_user_by('login','k8admin');wp_set_current_user($admin->ID);
$repo=rtrim(getenv('K8PROD_REPO_DIR'),'/');
$metadata=[];
foreach(['plugin','theme'] as $type){$metadata[$type]=json_decode(file_get_contents($repo.'/updates/'.$type.'.json'),true);}
delete_transient('bmt_update_k8-produtos');delete_transient('bmt_update_bmt-educacional');
add_filter('pre_http_request',function($pre,$args,$url)use($repo,$metadata){
    $prefix='https://raw.githubusercontent.com/paulommaeda/materiais/main/';
    if(strpos($url,$prefix)!==0)return $pre;
    $relative=substr($url,strlen($prefix));
    if(!preg_match('#^(?:updates/(?:plugin|theme)\.json|dist/[a-z0-9.-]+\.zip)$#D',$relative))return $pre;
    $bytes=file_get_contents($repo.'/'.$relative);
    if(!empty($args['stream'])&&!empty($args['filename'])){file_put_contents($args['filename'],$bytes);$body='';}else{$body=$bytes;}
    return ['headers'=>['content-type'=>substr($relative,-4)==='.zip'?'application/zip':'application/json'],'body'=>$body,'filename'=>$args['filename']??null,'response'=>['code'=>200,'message'=>'OK']];
},10,3);
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.K8Produtos\Support::table('pedidos'));
$plugin_file='k8-produtos/k8-produtos.php';
$transient=(object)['checked'=>[$plugin_file=>'2.0.0'],'response'=>[],'no_update'=>[],'last_checked'=>time()];
set_site_transient('update_plugins',$transient);
$updates=get_site_transient('update_plugins');
if(empty($updates->response[$plugin_file]))throw new RuntimeException('Oferta do plugin não entrou no mecanismo nativo');
$skin=new Automatic_Upgrader_Skin();$upgrader=new Plugin_Upgrader($skin);
$ok=$upgrader->upgrade($plugin_file);
if(is_wp_error($ok)||$ok!==true)throw new RuntimeException('Falha no Plugin_Upgrader: '.print_r($skin->get_errors(),true));
wp_clean_plugins_cache();
$headers=get_plugin_data(WP_PLUGIN_DIR.'/'.$plugin_file,false,false);
if($headers['Version']!==$metadata['plugin']['version'])throw new RuntimeException('Versão do plugin incorreta após atualização');
echo "OK: Plugin_Upgrader instalou o ZIP com verificação SHA-256 e pasta k8-produtos preservada.\n";
$theme_transient=(object)['checked'=>['bmt-educacional'=>'0.9.0'],'response'=>[],'no_update'=>[],'last_checked'=>time()];
set_site_transient('update_themes',$theme_transient);
$skin=new Automatic_Upgrader_Skin();$upgrader=new Theme_Upgrader($skin);
$ok=$upgrader->upgrade('bmt-educacional');
if(is_wp_error($ok)||$ok!==true)throw new RuntimeException('Falha no Theme_Upgrader: '.print_r($skin->get_errors(),true));
wp_clean_themes_cache();
if(wp_get_theme('bmt-educacional')->get('Version')!==$metadata['theme']['version'])throw new RuntimeException('Versão do tema incorreta após atualização');
echo "OK: Theme_Upgrader instalou o ZIP com verificação SHA-256 e pasta bmt-educacional preservada.\n";
if((int)$wpdb->get_var('SELECT COUNT(*) FROM '.K8Produtos\Support::table('pedidos'))!==$before)throw new RuntimeException('Atualização alterou pedidos');
echo "OK: os pedidos permaneceram no banco após as duas atualizações.\n";
