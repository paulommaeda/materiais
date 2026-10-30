<?php
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Fixture CLI requer banco _test.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\ProductClasses as Classes;
use K8Produtos\Support as S;
use K8Produtos\Students;
use K8Produtos\StudentImport;
use K8Produtos\Service;
use K8Produtos\Discounts;
use K8Produtos\Schema;
use K8Produtos\Storefront;
use K8Produtos\Http;
use K8Produtos\Views;

$checks = 0;
function classes_check($ok, $name) {
    global $checks;
    if (!$ok) throw new RuntimeException('FALHOU: ' . $name);
    echo 'OK ' . (++$checks) . ': ' . $name . "\n";
}
function classes_rejects(callable $job, $name) {
    try { $job(); } catch (Throwable $e) { classes_check(true, $name); return; }
    classes_check(false, $name);
}
function classes_mutate($op, $product, array $data) {
    $method = new ReflectionMethod(Http::class, 'mutate'); $method->setAccessible(true);
    return $method->invoke(null, $op, $product, $data);
}
function classes_product($name, array $classes, $price = '1000', $id = 0) {
    $result = classes_mutate('save_product', $id, ['nome'=>$name,'valor_padrao'=>$price,'turmas'=>$classes,
        'ativo'=>1,'publicado'=>1,'etapas'=>1,'vencimento_dias'=>5,'categoria'=>'didatico',
        'formas_pagamento'=>['Pix','Boleto','Cartao'],'cartao_parcelas'=>1]);
    return S::product($result['produto_id']);
}
function classes_person($ra, $class) {
    return ['ra'=>$ra,'nome_aluno'=>'Aluno '.$ra,'turma'=>$class,'cpf_resp'=>'52998224725','nome_resp'=>'Responsável de exemplo'];
}
function classes_rule($product, $name, $class = '') {
    return Discounts::save((int)$product->id,['titulo'=>$name,'tipo'=>'preco','valor'=>'800','fim'=>current_datetime()->modify('+2 days')->format('Y-m-d'),'turma_restrita'=>$class]);
}
Schema::install();
update_option('k8prod_students', Students::defaults());
wp_set_current_user(get_user_by('login','k8admin')->ID);
$header=implode(';',StudentImport::COLUMNS)."\n";
$csv=$header."ESCOPO-A;Aluno apenas da turma A;;Turma A;Responsável;52998224725;;;11987654321;\n".
    "ESCOPO-B;Aluno apenas da turma B;;Turma B;Responsável;52998224725;;;11987654321;\n";
StudentImport::save_import(StudentImport::parse_csv($csv),false);
$before=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.S::table('produtos'));
classes_rejects(fn()=>classes_product('Sem seleção',[]),'produto exige pelo menos uma turma antes de gravar');
classes_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.S::table('produtos'))===$before,'erro de turma não cria produto parcial');
classes_rejects(fn()=>Classes::input(['turmas'=>'Turma A']),'lista de turmas inválida é rejeitada');
classes_rejects(fn()=>Classes::input(['turmas'=>[str_repeat('x',101)]]),'turma acima do tamanho máximo é rejeitada');
classes_check(Classes::input(['turmas'=>['Turma A','Turma A'],'turmas_adicionais'=>"Turma B\r\nTurma A"])===['Turma A','Turma B'],'seleção e turmas adicionais são consolidadas no produto');
$a=classes_product('Escopo produto A',['Turma A']);
$b=classes_product('Escopo produto B',['Turma B'],'2000');
classes_check(Classes::selected($a)===['Turma A']&&Classes::selected($b)===['Turma B'],'cada produto guarda sua própria seleção');
classes_check(Service::price($a,'Turma A')===1000.0&&Service::price($a,'Turma B')===null,'preço normal não libera turma fora da seleção');
classes_check(Service::price($b,'Turma B')===2000.0&&Service::price($b,'Turma A')===null,'seleção de um produto não interfere no outro');
classes_rejects(fn()=>classes_mutate('save_price',(int)$a->id,['turma'=>'Turma B','valor'=>1]),'operação antiga de preço por turma não está mais disponível');
$rule=classes_rule($a,'Promoção exclusiva produto A');
$quote=Service::quote($a,classes_person('ESCOPO-A','Turma A'));
classes_check($quote['original']==1000&&$quote['valor']==800&&$quote['promocao_id']===$rule,'preço por data fica vinculado ao produto');
classes_check(Service::quote($b,classes_person('ESCOPO-B','Turma B'))['valor']==2000,'promoção do produto A não altera produto B');
classes_rejects(fn()=>classes_rule($a,'Turma não liberada','Turma B'),'promoção específica exige turma selecionada no produto');
classes_rejects(fn()=>Service::quote($a,classes_person('ESCOPO-B','Turma B')),'cotação rejeita aluno de turma não selecionada');
classes_rejects(fn()=>Service::checkout($a,classes_person('ESCOPO-B','Turma B'),'Pix','',''),'checkout rejeita turma não selecionada antes da K8');
classes_check(Service::active((int)$a->id,'ESCOPO-B')===null,'turma bloqueada não deixa pedido iniciado');
// Um preço antigo ou inserido fora do editor nunca deve reabrir uma turma.
$wpdb->insert(S::table('valores_turma'),['produto_id'=>$a->id,'turma'=>'Turma B','valor'=>1]);
$prices=Discounts::catalog_prices([$a]);
classes_check($prices[$a->id]['original']==1000&&$prices[$a->id]['valor']==800,'catálogo ignora preço de turma não selecionada');
$ids=array_map('intval',array_column(Storefront::catalog(),'id'));
classes_check(in_array((int)$a->id,$ids,true)&&in_array((int)$b->id,$ids,true),'catálogo publica produtos com turma liberada e preço');
$wpdb->insert(S::table('produtos'),['nome'=>'Sem turmas explícitas','slug'=>'sem-turmas-'.time(),'valor_padrao'=>100,'turmas_disponiveis'=>'[]']);$empty=(int)$wpdb->insert_id;
$wpdb->insert(S::table('produtos'),['nome'=>'Preço somente em turma excluída','slug'=>'preco-excluido-'.time(),'turmas_disponiveis'=>wp_json_encode(['Turma A'])]);$no_price=(int)$wpdb->insert_id;
$wpdb->insert(S::table('valores_turma'),['produto_id'=>$no_price,'turma'=>'Turma B','valor'=>50]);
$ids=array_map('intval',array_column(Storefront::catalog(),'id'));
classes_check(!in_array($empty,$ids,true)&&!in_array($no_price,$ids,true),'catálogo oculta produto sem turmas ou sem preço para as turmas liberadas');
$_GET=['k8_product'=>$a->id,'k8_tab'=>'overview'];
$html=Views::dashboard_shortcode([]);
classes_check(strpos($html,'Aluno apenas da turma A')!==false&&strpos($html,'Aluno apenas da turma B')===false,'dashboard de adesão e pendências usa as turmas do produto');
$wpdb->insert(S::table('cupons'),['produto_id'=>$a->id,'codigo'=>'ESCOPO100','percentual'=>100]);
$paid=Service::checkout($a,classes_person('ESCOPO-A','Turma A'),'Cortesia','ESCOPO100');
$stored=Service::order((int)$a->id,$paid['id']);
$token=Students::issue((int)$a->id,Students::find('52998224725','ESCOPO-A')[0]);
$a=classes_product('Escopo produto A',['Turma B'],'1000',(int)$a->id);
classes_check(Service::price($a,'Turma A')===null&&Service::price($a,'Turma B')===1000.0,'editar turmas altera somente a disponibilidade do produto salvo');
classes_check(Classes::selected(S::product((int)$b->id))===['Turma B'],'editar produto A preserva seleção do B');
$person=Students::context((int)$a->id,$token);
classes_rejects(fn()=>Service::quote($a,$person),'consulta antiga não contorna mudança das turmas');
classes_rejects(fn()=>Service::checkout($a,classes_person('NOVO-A','Turma A'),'Pix','',$quote['chave_preco']),'nova compra com cotação antiga é bloqueada após remover turma');
classes_check(Service::checkout($a,classes_person('ESCOPO-A','Turma A'),'Cortesia','ESCOPO100')['id']===$paid['id'],'pedido já emitido continua acessível após remover turma');
$after=Service::order((int)$a->id,$paid['id']);
classes_check($stored->valor===$after->valor&&$stored->valor_original===$after->valor_original&&$stored->status_pagamento===$after->status_pagamento,'troca de turmas preserva valores e pagamento históricos');
$csv=$header."ESCOPO-C;Aluno importado depois;;Turma C;Responsável;52998224725;;;11987654321;\n";
StudentImport::save_import(StudentImport::parse_csv($csv),false);
classes_check(!Classes::allows($a,'Turma C')&&in_array('Turma C',Classes::options($a),true),'nova turma importada aparece como opção sem liberação automática');
$wpdb->insert(S::table('produtos'),['nome'=>'Legado com preço normal','slug'=>'legado-normal-'.time(),'valor_padrao'=>100]);$legacy=(int)$wpdb->insert_id;
$wpdb->insert(S::table('produtos'),['nome'=>'Legado com preço por turma','slug'=>'legado-turma-'.time()]);$legacy_class=(int)$wpdb->insert_id;
$wpdb->insert(S::table('valores_turma'),['produto_id'=>$legacy_class,'turma'=>'Turma A','valor'=>90]);
Schema::install();
classes_check(Classes::allows(S::product($legacy),'Turma A')&&Classes::allows(S::product($legacy),'Turma C'),'migração converte o alcance existente em seleção explícita');
classes_check(Classes::selected(S::product($legacy_class))===['Turma A'],'migração de produto sem preço normal usa somente suas turmas com preço');
classes_check(Classes::selected(S::product((int)$a->id))===['Turma B'],'migração repetida não repõe turmas removidas do produto');
$b_rule=classes_rule($b,'Regra que só aparece no produto B');
$_GET=['produto_id'=>$a->id];ob_start();Views::admin_products();$html=ob_get_clean();
classes_check(strpos($html,'Preço por data deste produto')!==false&&strpos($html,'Promoção exclusiva produto A')!==false&&strpos($html,'Regra que só aparece no produto B')===false,'editor exibe preço por data e regras apenas do produto aberto');
classes_check(strpos($html,'name="turmas[]"')!==false&&strpos($html,'name="turma_restrita"')!==false,'turmas e faixas de preço ficam no editor do produto');
classes_check(Discounts::refresh_after([])===300,'consulta sem produtos não utiliza campanhas de outros produtos');
echo "\n$checks verificações de turmas e preços por produto passaram.\n";
