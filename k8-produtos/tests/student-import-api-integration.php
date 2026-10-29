<?php
/** Executar somente por CLI em WordPress com banco descartável _test. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Fixture CLI requer banco _test.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\StudentImport as Import;
use K8Produtos\StudentApi as Api;
use K8Produtos\Students;
use K8Produtos\Support;

$checks = 0;
function import_check($ok, $name) {
    global $checks;
    if (!$ok) throw new RuntimeException('FALHOU: ' . $name);
    echo 'OK ' . (++$checks) . ': ' . $name . "\n";
}
function import_request(array $data, $user = 1) {
    wp_set_current_user($user);
    $request = new WP_REST_Request('POST', '/k8-produtos/v1/alunos/importar');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode($data));
    return rest_do_request($request);
}
function import_person($ra, $name = 'Aluno de exemplo') {
    return ['ra' => $ra, 'nome_aluno' => $name, 'turma' => '5º Ano A', 'responsaveis' => [
        ['nome' => 'Responsável A', 'cpf' => '52998224725', 'telefone' => '11987654321'],
        ['nome' => 'Responsável B', 'cpf' => '11144477735', 'telefone' => '11987654322'],
        ['nome' => 'Responsável C', 'cpf' => '12345678909', 'telefone' => '11987654323'],
    ]];
}
Import::install();
update_option('k8prod_students', Students::defaults());
$table = $wpdb->prefix . 'lista_alunos_v4';
$guardians = $wpdb->prefix . 'sba_guardians';
$header = implode(';', Import::COLUMNS) . "\n";
$row = "000123;João de exemplo;;5º Ano A;Responsável A;52998224725;;;11987654321;\n";
$csv = $header . $row;
import_check(!is_wp_error(Import::parse_csv($csv)), 'modelo de dez colunas continua aceito');
$mapped = "Turma;Matrícula;Nome do aluno;CPF do responsável 1;Responsável 1;Observação\n5º Ano A;000123;João de exemplo;52998224725;Responsável A;ignorar\n";
$result = Import::parse_csv($mapped);
import_check(!is_wp_error($result) && $result['rows'][0]['ra'] === '000123' && $result['links'][0]['cpf'] === '52998224725', 'cabeçalhos acentuados e fora de ordem, colunas extras e opcionais');
import_check(!is_wp_error(Import::parse_csv("\n\nsep=;\r\n" . $csv)), 'diretiva sep do Excel e linhas vazias iniciais');
$utf16 = "\xFF\xFE" . iconv('UTF-8', 'UTF-16LE', str_replace(';', "\t", $csv));
$result = Import::parse_csv($utf16);
import_check(!is_wp_error($result) && $result['rows'][0]['nome_aluno'] === 'João de exemplo', 'UTF-16LE tabulado do Excel mantém os acentos');
import_check(!is_wp_error(Import::parse_csv("\xFE\xFF" . iconv('UTF-8', 'UTF-16BE', $csv))), 'UTF-16BE com BOM');
import_check(!is_wp_error(Import::parse_csv($header . rtrim($row, ";\n") . "\n")), 'colunas opcionais vazias no fim podem ser omitidas');
import_check(!is_wp_error(Import::parse_csv($row)), 'CSV legado sem cabeçalho continua aceito');
import_check(is_wp_error(Import::parse_csv("RA;Nome do aluno\n123;Aluno\n")), 'falta de turma no cabeçalho informa erro');
import_check(is_wp_error(Import::parse_csv("RA;Nome;Turma;Matrícula\n123;Aluno;5º Ano;456\n")), 'cabeçalho duplicado não altera a base');
import_check(is_wp_error(Import::parse_csv("PK\x03\x04falso-xlsx")), 'arquivo Excel renomeado não é aceito como CSV');
import_check(is_wp_error(Import::parse_csv($header . "01;Aluno;;;Nome;11111111111;;;;\n")), 'turma vazia é rejeitada');
import_check(Import::read_upload(['error' => UPLOAD_ERR_INI_SIZE])->get_error_code() === 'upload_size', 'erro de limite PHP tem mensagem específica');
import_check(Import::read_upload([])->get_error_code() === 'upload_missing', 'arquivo não enviado tem mensagem específica');
import_check(Import::read_upload(['error' => UPLOAD_ERR_PARTIAL])->get_error_code() === 'upload_failed', 'envio parcial tem mensagem específica');
import_check(Import::upload_limit() <= wp_max_upload_size(), 'limite exibido respeita a hospedagem');
$result = Api::parse_students([import_person('API-001')]);
import_check(!is_wp_error($result) && count($result['rows']) === 1 && count($result['links']) === 3, 'JSON com três responsáveis usa consolidação do CSV');
import_check(is_wp_error(Api::parse_students([['ra' => 123, 'nome_aluno' => 'Aluno', 'turma' => 'A']])), 'identificador numérico JSON exige texto para preservar zeros');
import_check(is_wp_error(Api::parse_students([import_person('API-001') + ['cpf_resp1' => '52998224725']])), 'formatos de responsáveis misturados são rejeitados');
import_check(is_wp_error(Api::parse_students([import_person('API-001'), import_person('API-001', 'Outro nome')])), 'conflito do mesmo RA no JSON é rejeitado');
$bad_cpf = import_person('API-CPF');
$bad_cpf['responsaveis'][0]['cpf'] = 'abc';
import_check(is_wp_error(Api::parse_students([$bad_cpf])), 'CPF sem dígitos não é tratado como campo vazio');
$admin = get_user_by('login', 'k8admin');
$admin_id = (int) $admin->ID;
// Garante que o hook seja registrado também quando o plugin for carregado manualmente.
Api::register();
$response = import_request(['alunos' => [import_person('API-001')]], 0);
import_check($response->get_status() === 401, 'REST bloqueia requisição anônima');
$subscriber_id = wp_create_user('import-subscriber-' . time(), wp_generate_password(), 'subscriber-' . time() . '@example.invalid');
$subscriber = new WP_User($subscriber_id); $subscriber->set_role('subscriber'); $subscriber->add_cap('k8_manage_products');
$response = import_request(['alunos' => [import_person('API-001')]], $subscriber_id);
import_check($response->get_status() === 403, 'operador de produtos sem manage_options não pode importar alunos');
$before_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
$before_revision = get_option('k8prod_student_revision');
$before_report = get_option('sba_import_report');
$response = import_request(['alunos' => [import_person('API-001')], 'validar_apenas' => true], $admin_id);
import_check($response->get_status() === 200 && $response->get_data()['vinculos'] === 3 && $response->get_data()['importado_em'] === null, 'administrador valida o lote sem gravar');
import_check($before_count === (int) $wpdb->get_var("SELECT COUNT(*) FROM $table") && $before_revision === get_option('k8prod_student_revision') && $before_report === get_option('sba_import_report'), 'pré-validação preserva alunos, revisão e relatório');
$response = import_request(['alunos' => [import_person('API-001')]], $admin_id);
$data = $response->get_data();
import_check($response->get_status() === 200 && $data['novos'] === 1 && $data['atualizados'] === 0 && $data['modo'] === 'atualizar', 'API grava lote e devolve resumo');
import_check(count(Students::find('12345678909', 'API-001')) === 1, 'terceiro responsável importado por API pode consultar');
$student_id = $wpdb->get_var("SELECT id FROM $table WHERE ra='API-001'");
$token = Students::issue(1, Students::find('12345678909', 'API-001')[0]);
$updated = import_person('API-001', 'Aluno atualizado');
$updated['responsaveis'] = array_slice($updated['responsaveis'], 0, 1);
$response = import_request(['alunos' => [$updated]], $admin_id);
import_check($response->get_data()['atualizados'] === 1 && $wpdb->get_var("SELECT id FROM $table WHERE ra='API-001'") === $student_id, 'reimportação preserva ID e contabiliza atualização');
import_check(Students::find('12345678909', 'API-001') === [] && (int) $wpdb->get_var("SELECT COUNT(*) FROM $guardians WHERE ra='API-001'") === 1, 'cada RA substitui seus vínculos e não duplica');
try { Students::context(1, $token); import_check(false, 'contexto antigo'); } catch (InvalidArgumentException $e) { import_check(true, 'API invalida os contextos de compra anteriores'); }
import_check(get_option('sba_import_report')['source'] === 'api', 'relatório do painel identifica a origem API');
$before_revision = get_option('k8prod_student_revision');
$response = import_request(['alunos' => [], 'modo' => 'substituir', 'confirmar_substituicao' => true], $admin_id);
import_check($response->get_status() === 422 && $wpdb->get_var("SELECT id FROM $table WHERE ra='API-001'") === $student_id, 'lista vazia nunca apaga a base');
$response = import_request(['alunos' => [import_person('API-002')], 'modo' => 'substituir'], $admin_id);
import_check($response->get_status() === 400, 'substituição total exige flag explícita');
$response = import_request(['alunos' => [import_person('API-002')], 'validar_apenas' => 'false'], $admin_id);
import_check($response->get_status() === 400, 'booleano JSON em texto é rejeitado em vez de gravar por engano');
$response = import_request(['alunos' => [import_person('API-002')], 'validar_apens' => true], $admin_id);
import_check($response->get_status() === 400, 'opção desconhecida é rejeitada');
$invalid = import_person('API-002'); $invalid['responsaveis'][1]['cpf'] = '11111111111';
$response = import_request(['alunos' => [import_person('API-003'), $invalid]], $admin_id);
import_check($response->get_status() === 422 && !$wpdb->get_var("SELECT id FROM $table WHERE ra='API-003'"), 'CPF inválido impede a gravação de todo o lote');
import_check($before_revision === get_option('k8prod_student_revision'), 'falhas de validação não alteram revisão');
$flat = ['ra' => 'API-FLAT', 'nome_aluno' => 'Aluno formato plano', 'turma' => 'A', 'nome_resp1' => 'Responsável A', 'cpf_resp1' => '52998224725'];
import_check(import_request(['alunos' => [$flat]], $admin_id)->get_status() === 200, 'formato JSON com campos das dez colunas também funciona');
$backup = $guardians . '_api_backup';
$wpdb->query("RENAME TABLE $guardians TO $backup");
$response = import_request(['alunos' => [import_person('API-ROLLBACK')]], $admin_id);
$wpdb->query("RENAME TABLE $backup TO $guardians");
import_check($response->get_status() === 500 && !$wpdb->get_var("SELECT id FROM $table WHERE ra='API-ROLLBACK'"), 'falha no banco reverte o lote inteiro via API');
$request = new WP_REST_Request('POST', '/k8-produtos/v1/alunos/importar');
$request->set_header('Content-Type', 'text/plain'); $request->set_body('test');
import_check(rest_do_request($request)->get_status() === 415, 'formato de corpo não suportado recebe 415');
$request = new WP_REST_Request('POST', '/k8-produtos/v1/alunos/importar');
$request->set_header('Content-Type', 'application/json'); $request->set_body('{');
import_check(rest_do_request($request)->get_status() === 400, 'JSON malformado recebe 400');
update_option('k8prod_students', ['table' => 'alunos_externos']);
$response = import_request(['alunos' => [import_person('API-EXTERNA')], 'validar_apenas' => true], $admin_id);
import_check($response->get_data()['base_consultada'] === 'externa' && $response->get_data()['aviso_base'], 'mapeamento externo é informado para evitar importação invisível na compra');
update_option('k8prod_students', Students::defaults());
$response = import_request(['alunos' => [import_person('API-SUBSTITUIR')], 'modo' => 'substituir', 'confirmar_substituicao' => true], $admin_id);
import_check($response->get_status() === 200 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $table") === 1 && (int) $wpdb->get_var("SELECT COUNT(*) FROM $guardians") === 3, 'substituição total explícita grava somente a nova lista');
import_check($response->get_headers()['Cache-Control'] === 'no-store, private', 'resposta não pode ser armazenada em cache');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
foreach (['menu', 'submenu', 'admin_page_hooks', '_registered_pages', '_parent_pages'] as $name) {
    if (!isset($GLOBALS[$name])) $GLOBALS[$name] = [];
}
wp_set_current_user($admin_id);
// Registra na mesma ordem do bootstrap, com o importador antes do menu pai.
Import::register();
K8Produtos\Views::register();
do_action('admin_menu', '');
$hook = get_plugin_page_hookname('k8prod-students', 'k8prod');
import_check(isset($GLOBALS['_registered_pages'][$hook]), 'submenu de alunos registra o hook correto depois do menu pai');
echo "\n$checks verificações de importação e API passaram.\n";
