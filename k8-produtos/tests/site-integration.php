<?php
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) {
    exit("Somente fixture CLI em banco _test.\n");
}
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') {
    exit("Banco de testes obrigatório.\n");
}
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\StudentImport as Import;
use K8Produtos\Students;
use K8Produtos\Support as S;
use K8Produtos\Storefront;

$checks = 0;
function site_check($condition, $name) {
    global $checks;
    if (!$condition) throw new RuntimeException('FALHOU: '.$name);
    echo 'OK '.(++$checks).': '.$name."\n";
}
Import::install();
$table=$wpdb->prefix.'lista_alunos_v4';
$guardians=$wpdb->prefix.'sba_guardians';
$header="ra;nome_aluno;cpf_aluno;turma;nome_resp1;cpf_resp1;nome_resp2;cpf_resp2;telefone_resp1;telefone_resp2\n";
$raw=$header."CSV-01;Aluno CSV;;5º Ano A;Responsável A;52998224725;Responsável B;11144477735;11987654321;11987654322\n".
    "CSV-01;Aluno CSV;;5º Ano A;Responsável C;12345678909;;;11987654323;\n";
$compiled=Import::parse_csv($raw);
site_check(!is_wp_error($compiled)&&count($compiled['rows'])===1&&count($compiled['links'])===3,'CSV consolida RA repetido e três responsáveis');
Import::save_import($compiled,false);
site_check(Students::find('12345678909','CSV-01')[0]['nome_resp']==='Responsável C','terceiro responsável pode consultar o aluno');
$student_id=(int)$wpdb->get_var("SELECT id FROM $table WHERE ra='CSV-01'");
Import::save_import($compiled,false);
site_check((int)$wpdb->get_var("SELECT id FROM $table WHERE ra='CSV-01'")===$student_id,'reimportação mantém ID do aluno');
site_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $guardians WHERE ra='CSV-01'")===3,'reimportação não duplica vínculos');
$product=(int)$wpdb->get_var('SELECT id FROM '.S::table('produtos').' ORDER BY id LIMIT 1');
$token=Students::issue($product,Students::find('12345678909','CSV-01')[0]);
$partial=Import::parse_csv($header."CSV-01;Aluno CSV;;5º Ano A;Responsável A;52998224725;;;11987654321;\n");
Import::save_import($partial,false);
site_check(Students::find('12345678909','CSV-01')===[],'responsável removido do CSV perde o vínculo');
try { Students::context($product,$token);site_check(false,'contexto anterior'); } catch (InvalidArgumentException $e) { site_check(true,'importação invalida contexto temporário anterior'); }
$before=(int)$wpdb->get_var("SELECT COUNT(*) FROM $table");
$conflict=Import::parse_csv($raw."CSV-01;Nome divergente;;5º Ano A;Responsável A;52998224725;;;11987654321;\n");
site_check(is_wp_error($conflict)&&$conflict->get_error_code()==='conflict','identidade conflitante interrompe parsing');
site_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===$before,'erro de parsing preserva a base');
site_check(is_wp_error(Import::parse_csv($header."CSV-02;Aluno;;5º Ano A;Nome;11111111111;;;;\n")),'CPF inválido interrompe importação');
site_check(is_wp_error(Import::parse_csv('ra;nome' . "\n01;Aluno\n")),'CSV fora do modelo rejeitado');
$comma=str_replace(';',',',$header)."CSV-03,Aluno com vírgula,,5º Ano A,Resp,52998224725,,,11987654321,\n";
site_check(!is_wp_error(Import::parse_csv("\xEF\xBB\xBF".$comma)),'CSV com vírgula e BOM aceito');
$latin=iconv('UTF-8','Windows-1252',$header."CSV-04;João;;5º Ano A;Resp;52998224725;;;11987654321;\n");
site_check(Import::parse_csv($latin)['rows'][0]['nome_aluno']==='João','CSV Windows-1252 convertido');
// Simula falha do banco na gravação de vínculos, após modificar a linha do aluno.
$guard_backup=$guardians.'_fixture_backup';
$wpdb->query("RENAME TABLE $guardians TO $guard_backup");
$saved_suppression=$wpdb->suppress_errors(true);
try { Import::save_import($compiled,false);site_check(false,'rollback esperado'); } catch (Throwable $e) { site_check(true,'falha de gravação interrompe importação'); }
$wpdb->suppress_errors($saved_suppression);
$wpdb->query("RENAME TABLE $guard_backup TO $guardians");
site_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $guardians WHERE ra='CSV-01'")===1,'falha de banco preserva vínculos anteriores');
Import::save_import($partial,true);
site_check((int)$wpdb->get_var("SELECT COUNT(*) FROM $table")===1,'substituição total remove alunos ausentes');

$wpdb->insert(S::table('produtos'),['nome'=>'Catálogo didático','slug'=>'site-didatico','valor_padrao'=>120,'categoria'=>'didatico']);$did=(int)$wpdb->insert_id;
$wpdb->insert(S::table('produtos'),['nome'=>'Evento do catálogo','slug'=>'site-evento','valor_padrao'=>35,'categoria'=>'evento']);$evt=(int)$wpdb->insert_id;
$wpdb->insert(S::table('produtos'),['nome'=>'Oculto','slug'=>'site-oculto','valor_padrao'=>10,'publicado'=>0]);$hidden=(int)$wpdb->insert_id;
$wpdb->insert(S::table('produtos'),['nome'=>'Sem preço','slug'=>'site-sem-preco']);$no_price=(int)$wpdb->insert_id;
$ids=array_map('intval',array_column(Storefront::catalog(),'id'));
site_check(in_array($did,$ids,true)&&in_array($evt,$ids,true),'catálogo inclui ofertas configuradas');
site_check(!in_array($hidden,$ids,true)&&!in_array($no_price,$ids,true),'catálogo oculta produto despublicado e sem preço');
site_check(count(array_filter(Storefront::catalog('evento'),fn($p)=>(int)$p->id===$evt))===1,'categoria de evento filtrada');
$html=Storefront::shortcode(['categoria'=>'evento']);
site_check(strpos($html,'Evento do catálogo')!==false&&strpos($html,'Catálogo didático')===false,'catálogo renderiza a categoria pedida');
site_check(strpos(Storefront::url($evt),'bmt_produto='.$evt)!==false,'produto possui URL pública sem página manual');

// Atualizações simuladas pela HTTP API do WordPress, incluindo os metadados oficiais.
$manifest=['name'=>'Fixture','slug'=>'k8-produtos','version'=>'9.9.9','package'=>'https://raw.githubusercontent.com/paulommaeda/materiais/main/dist/k8-produtos-9.9.9.zip','sha256'=>str_repeat('a',64)];
$GLOBALS['bmt_fixture_manifest']=$manifest;
add_filter('pre_http_request',function($pre,$args,$url){
    if(strpos($url,'raw.githubusercontent.com/paulommaeda/materiais/main/updates/')===false)return $pre;
    $data=$GLOBALS['bmt_fixture_manifest'];
    if(strpos($url,'theme.json')!==false){$data['slug']='bmt-educacional';$data['version']='9.0.0';$data['package']='https://raw.githubusercontent.com/paulommaeda/materiais/main/dist/bmt-educacional-9.0.0.zip';}
    return ['headers'=>[],'body'=>wp_json_encode($data),'response'=>['code'=>200,'message'=>'OK']];
},10,3);
delete_transient('bmt_update_k8-produtos');delete_transient('bmt_update_bmt-educacional');
$channel=new BMT_Update_Channel('k8-produtos','plugin',K8PROD_VERSION,K8PROD_FILE);
$offer=$channel->offer(false,[],plugin_basename(K8PROD_FILE));
site_check($offer['version']==='9.9.9','canal identifica nova versão do plugin');
$transient=(object)['checked'=>[plugin_basename(K8PROD_FILE)=>K8PROD_VERSION],'response'=>[],'no_update'=>[]];
$result=$channel->transient($transient);
site_check(isset($result->response[plugin_basename(K8PROD_FILE)]),'oferta entra no painel nativo do WordPress');
site_check($channel->offer(false,[],'outro-plugin/outro.php')===false,'canal não altera outros plugins');
$info=$channel->information(false,'plugin_information',(object)['slug'=>'k8-produtos']);
site_check($info->download_link===$manifest['package'],'tela de detalhes oferece pacote correto');
$GLOBALS['bmt_fixture_manifest']['package']='https://example.invalid/arbitrary.zip';
site_check($channel->metadata(true)===null,'manifesto não pode redirecionar instalação para outro domínio');
$GLOBALS['bmt_fixture_manifest']=$manifest;$GLOBALS['bmt_fixture_manifest']['version']=K8PROD_VERSION;$GLOBALS['bmt_fixture_manifest']['package']='https://raw.githubusercontent.com/paulommaeda/materiais/main/dist/k8-produtos-'.K8PROD_VERSION.'.zip';
$channel->metadata(true);
$result=$channel->transient((object)['checked'=>[plugin_basename(K8PROD_FILE)=>K8PROD_VERSION],'response'=>[],'no_update'=>[]]);
site_check(isset($result->no_update[plugin_basename(K8PROD_FILE)]),'mesma versão não oferece reinstalação');
$theme_channel=new BMT_Update_Channel('bmt-educacional','theme','1.0.0',__FILE__);
site_check($theme_channel->offer(false,[],'bmt-educacional')['version']==='9.0.0','tema tem canal próprio de atualização');
delete_transient('bmt_update_k8-produtos');delete_transient('bmt_update_bmt-educacional');
echo "\n$checks verificações do site, alunos e atualizações passaram.\n";
