<?php
/** Integração real com WordPress + MySQL/MariaDB. Somente banco de testes. */
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) {
    exit("Use K8PROD_TESTING=1 e K8PROD_WP_LOAD em um banco cujo nome termine em _test.\n");
}
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') {
    exit("Banco não autorizado para testes destrutivos de fixtures.\n");
}
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\Support as S;
use K8Produtos\Schema;
use K8Produtos\Service;
use K8Produtos\Students;
use K8Produtos\Http;

$checks = 0;
function check($condition, $name) {
    global $checks;
    if (!$condition) throw new RuntimeException('FALHOU: ' . $name);
    $checks++;
    echo "OK $checks: $name\n";
}
function rejects(callable $fn, $name) {
    try { $fn(); } catch (Throwable $e) { check(true, $name); return; }
    check(false, $name);
}
function reset_plugin() {
    global $wpdb;
    foreach (['produtos','valores_turma','pedidos','cupons','materiais','estoque_movimentos','entregas','entregas_log','descontos_data'] as $name) {
        $wpdb->query('DROP TABLE IF EXISTS ' . S::table($name));
    }
    delete_option('k8prod_schema');
    delete_option('k8prod_legacy_product_id');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'sba_guardians');
    $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'lista_alunos_v4');
    delete_option('k8prod_students_migrated');
    foreach (['k8pd_schema_v1','k8pd_schema_v2_assinatura','k8pd_schema_v3_desfazer','k8pd_schema_v4_entregas'] as $option) {
        delete_option($option);
    }
}
function insert_product($name, $price = 100, $stages = 1) {
    global $wpdb;
    S::must($wpdb->insert(S::table('produtos'), ['nome'=>$name,'slug'=>sanitize_title($name),'valor_padrao'=>$price,'etapas'=>$stages,'turmas_disponiveis'=>wp_json_encode(['5º Ano A','6º Ano','9º Ano'])]));
    return S::product((int)$wpdb->insert_id);
}
function person($ra = 'RA-001') {
    return ['ra'=>$ra,'nome_aluno'=>'Aluno de teste','turma'=>'5º Ano A','cpf_resp'=>'52998224725','nome_resp'=>'Responsável de teste'];
}
function mutate($op,$product,$data) {
    $method = new ReflectionMethod(Http::class, 'mutate');
    $method->setAccessible(true);
    return $method->invoke(null,$op,$product,$data);
}

reset_plugin();
Schema::install();
check(get_option('k8prod_schema') === K8PROD_VERSION, 'instalação limpa');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.S::table('produtos')) === 0, 'instalação limpa não cria produto legado');
Schema::install();
check($wpdb->last_error === '', 'schema repetível');

$legacy_dir = getenv('K8PROD_LEGACY_DIR');
if ($legacy_dir) {
    reset_plugin();
    require_once $legacy_dir.'/k8-cobrancas-pagamento.php';
    require_once $legacy_dir.'/k8-cobrancas-materiais.php';
    require_once $legacy_dir.'/k8-cobrancas-dashboard.php';
    k8p_criar_tabela_valores();
    k8pm_criar_tabelas();
    k8pd_atualizar_schema();
    k8pd_atualizar_schema_assinatura();
    k8pd_atualizar_schema_desfazer();
    k8pd_atualizar_schema_entregas_semestre();
    $wpdb->insert(S::table('valores_turma'),['turma'=>'5º Ano A','valor'=>800]);
    $wpdb->insert(S::table('cupons'),['codigo'=>'LEGADO','percentual'=>50,'usado_count'=>7]);
    $wpdb->insert(S::table('materiais'),['nome'=>'Livro legado','periodo_entrega'=>'ano','estoque_atual'=>23]);
    $legacy_material=(int)$wpdb->insert_id;
    $wpdb->insert(S::table('estoque_movimentos'),['material_id'=>$legacy_material,'tipo'=>'entrada','quantidade'=>23]);
    $wpdb->insert(S::table('pedidos'),array_merge(person('LEG-1'),['valor'=>800,'metodo'=>'Pix','status'=>'gerado','status_pagamento'=>'Paga','entregue'=>1,'assinatura'=>'assinatura-legada']));
    $legacy_order=(int)$wpdb->insert_id;
    $wpdb->insert(S::table('entregas_log'),['pedido_id'=>$legacy_order,'acao'=>'entregue','usuario'=>'Operador legado']);
    Schema::install();
    $legacy_product=(int)get_option('k8prod_legacy_product_id');
    check($legacy_product>0,'produto legado criado');
    check((int)Service::order($legacy_product,$legacy_order)->id===$legacy_order,'IDs de pedidos preservados');
    check((int)$wpdb->get_var('SELECT estoque_atual FROM '.S::table('materiais')." WHERE id=$legacy_material")===23,'migração preserva saldo existente');
    check((int)$wpdb->get_var('SELECT usado_count FROM '.S::table('cupons')." WHERE codigo='LEGADO'")===7,'migração preserva usos de cupom');
    check($wpdb->get_var('SELECT assinatura FROM '.S::table('entregas')." WHERE pedido_id=$legacy_order AND semestre=1")==='assinatura-legada','flag antiga de entrega e assinatura migradas');
    Schema::install();
    check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.S::table('entregas')." WHERE pedido_id=$legacy_order")===1,'migração não duplica entregas');
    check((int)$wpdb->get_var('SELECT produto_id FROM '.S::table('estoque_movimentos').' LIMIT 1')===$legacy_product,'movimentos vinculados ao produto legado');
}

$a=insert_product('Produto A');
$b=insert_product('Produto B');
foreach ([$a,$b] as $p) {
    mutate('save_price',(int)$p->id,['turma'=>'5º Ano A','valor'=>'1.250,50']);
    mutate('save_coupon',(int)$p->id,['codigo'=>'BOLSA100','percentual'=>100,'uso_maximo'=>2]);
}
check(Service::price($a,'5º Ano A')===1250.50,'preço por turma e número brasileiro');
check(Service::price($a,'6º Ano')===100.0,'preço padrão do produto');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.S::table('valores_turma')." WHERE turma='5º Ano A'")>=2,'mesma turma aceita preços em produtos diferentes');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.S::table('cupons')." WHERE codigo='BOLSA100'")===2,'mesmo código de cupom em dois produtos');
$order_a=Service::checkout($a,person(),'Cortesia','BOLSA100');
$again=Service::checkout($a,person(),'Cortesia','BOLSA100');
$order_b=Service::checkout($b,person(),'Cortesia','BOLSA100');
check($order_a['id']===$again['id'],'repetir compra retorna o mesmo pedido');
check($order_a['id']!==$order_b['id'],'mesmo RA pode comprar produtos diferentes');
check($order_a['pagamento']==='Paga','cupom 100% confirma cortesia sem K8');
check((int)$wpdb->get_var($wpdb->prepare('SELECT usado_count FROM '.S::table('cupons').' WHERE produto_id=%d AND codigo=%s',$a->id,'BOLSA100'))===1,'duplo clique não consome cupom novamente');
mutate('save_coupon',(int)$a->id,['codigo'=>'BOLSA100','percentual'=>100,'uso_maximo'=>2]);
check((int)$wpdb->get_var($wpdb->prepare('SELECT usado_count FROM '.S::table('cupons').' WHERE produto_id=%d AND codigo=%s',$a->id,'BOLSA100'))===1,'editar cupom preserva contador');
Service::checkout($a,person('RA-002'),'Cortesia','BOLSA100');
rejects(fn()=>Service::checkout($a,person('RA-003'),'Cortesia','BOLSA100'),'limite de cupom aplicado no servidor');
rejects(fn()=>Service::order((int)$b->id,$order_a['id']),'pedido de outro produto não é acessível');

$m1=Service::create_material((int)$a->id,['nome'=>'Livro 1','periodo_entrega'=>'ano','estoque_inicial'=>5,'quantidade_por_aluno'=>2]);
$m2=Service::create_material((int)$a->id,['nome'=>'Livro 2','periodo_entrega'=>'ano','estoque_inicial'=>0]);
check((int)$wpdb->get_var('SELECT estoque_atual FROM '.S::table('materiais')." WHERE id=$m1")===5,'estoque inicial contado uma vez');
$png = base64_encode(file_get_contents(__DIR__.'/signature.png'));
$signature='data:image/png;base64,'.$png;
rejects(fn()=>Service::delivery((int)$a->id,$order_a['id'],1,'entregar',$signature,'Recebedor',''),'falta de estoque impede entrega completa');
check((int)$wpdb->get_var('SELECT estoque_atual FROM '.S::table('materiais')." WHERE id=$m1")===5,'falha no segundo item reverte baixa do primeiro');
check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.S::table('entregas')." WHERE pedido_id={$order_a['id']}")===0,'entrega parcial não é gravada');
Service::move_stock((int)$a->id,$m2,'entrada',4,'Reposição de teste');
Service::delivery((int)$a->id,$order_a['id'],1,'entregar',$signature,'Recebedor','');
Service::delivery((int)$a->id,$order_a['id'],1,'entregar',$signature,'Recebedor','');
check((int)$wpdb->get_var('SELECT estoque_atual FROM '.S::table('materiais')." WHERE id=$m1")===3,'entrega repetida baixa uma vez e usa quantidade por aluno');
check((int)$wpdb->get_var('SELECT estoque_atual FROM '.S::table('materiais')." WHERE id=$m2")===3,'segundo material baixado');
Service::delivery((int)$a->id,$order_a['id'],1,'desfazer','','','Correção da entrega');
Service::delivery((int)$a->id,$order_a['id'],1,'desfazer','','','Correção da entrega');
check((int)$wpdb->get_var('SELECT estoque_atual FROM '.S::table('materiais')." WHERE id=$m1")===5,'estorno repetido devolve uma vez');
rejects(fn()=>Service::move_stock((int)$b->id,$m1,'saida',1,'Teste'),'estoque de outro produto protegido');
$restricted=Service::create_material((int)$a->id,['nome'=>'Restrito a outra turma','periodo_entrega'=>'ano','estoque_inicial'=>1,'turma_restrita'=>'9º Ano']);
check(count(Service::materials((int)$a->id,''))===2,'turma vazia não inclui materiais restritos a outras turmas');
rejects(fn()=>Service::delivery((int)$a->id,$order_a['id'],2,'entregar',$signature,'Recebedor',''),'produto de entrega única rejeita segundo semestre');
rejects(fn()=>Service::delivery((int)$a->id,$order_a['id'],1,'entregar','data:image/png;base64,aW52YWxpZA==','Recebedor',''),'PNG inválido rejeitado');
rejects(fn()=>Service::cancel_order((int)$a->id,$order_a['id'],'Teste de cancelamento'),'pedido pago não é cancelado silenciosamente');

$table=$wpdb->prefix.'lista_alunos_v4';
$wpdb->query("DROP TABLE IF EXISTS $table");
$wpdb->query("CREATE TABLE $table (ra varchar(50),nome_aluno varchar(255),turma varchar(100),cpf_resp1 varchar(20),nome_resp1 varchar(255),cpf_resp2 varchar(20),nome_resp2 varchar(255)) ENGINE=InnoDB");
$wpdb->insert($table,['ra'=>'ALUNO-1','nome_aluno'=>'Aluno fonte','turma'=>'Turma fonte','cpf_resp1'=>'529.982.247-25','nome_resp1'=>'Responsável fonte','cpf_resp2'=>'111.444.777-35','nome_resp2'=>'Segundo responsável']);
check(Students::find('52998224725')[0]['ra']==='ALUNO-1','busca por CPF pontuado da fonte existente');
check(Students::find('11144477735')[0]['nome_resp']==='Segundo responsável','busca do segundo responsável');
$token=Students::issue((int)$a->id,Students::find('52998224725')[0]);
check(Students::context((int)$a->id,$token)['turma']==='Turma fonte','contexto usa turma da fonte no servidor');
rejects(fn()=>Students::context((int)$b->id,$token),'token de checkout não funciona em outro produto');
rejects(fn()=>Students::context((int)$a->id,str_repeat('a',64)),'token forjado rejeitado');
check(S::valid_cpf('52998224725')&&!S::valid_cpf('11111111111'),'validação de CPF');

// Provedor simulado no limite HTTP; protocolo AES e WordPress HTTP API reais.
update_option('k8p_client_id','fixture');update_option('k8p_client_secret','fixture');update_option('k8p_aes_key','1234567890123456');
$GLOBALS['k8_fixture_mode']='normal';$GLOBALS['k8_fixture_posts']=0;
add_filter('pre_http_request',function($pre,$args,$url){
    if (strpos($url,'meuk8.com.br')===false) return $pre;
    if (substr($url,-6)==='/token') return ['headers'=>[],'body'=>'{"access_token":"fixture-token"}','response'=>['code'=>200,'message'=>'OK']];
    $body=[];
    if (strpos($url,'/api/Cliente?')!==false) $body=[];
    elseif (substr($url,-12)==='/api/Cliente') $body=['Id'=>'customer-fixture'];
    elseif (substr($url,-13)==='/api/Cobranca' && $args['method']==='POST') {
        $GLOBALS['k8_fixture_posts']++;
        if ($GLOBALS['k8_fixture_mode']==='timeout') return new WP_Error('timeout','simulated timeout');
        $body=['Id'=>'charge-'.$GLOBALS['k8_fixture_posts']];
    } elseif (strpos($url,'/api/Cobranca?')!==false) {
        parse_str(parse_url($url,PHP_URL_QUERY),$query);
        $body=['Id'=>$query['Id'],'Estado'=>$GLOBALS['k8_fixture_mode']==='paid'?'Paga':'Pendente','ClienteId'=>'customer-fixture','Valor'=>100];
    }
    $encoded=base64_encode(openssl_encrypt(wp_json_encode($body),'AES-128-CBC','1234567890123456',OPENSSL_RAW_DATA,str_repeat("\0",16)));
    return ['headers'=>[],'body'=>$encoded,'response'=>['code'=>200,'message'=>'OK']];
},10,3);
$c=insert_product('Produto K8',100);
$charged=Service::checkout($c,person('K8-001'),'Pix','');
check($charged['redirect']!==null && $GLOBALS['k8_fixture_posts']===1,'K8: cliente não encontrado é criado e cobrança gerada');
Service::checkout($c,person('K8-001'),'Boleto','');
check($GLOBALS['k8_fixture_posts']===1,'K8: mudar método não cria cobrança duplicada');
$GLOBALS['k8_fixture_mode']='paid';
check(Service::sync_order((int)$c->id,$charged['id'])->status_pagamento==='Paga','sincronização reconhece pagamento confirmado');
$GLOBALS['k8_fixture_mode']='timeout';
rejects(fn()=>Service::checkout($c,person('K8-002'),'Pix',''),'timeout mantém tentativa em conferência');
$uncertain=Service::active((int)$c->id,'K8-002');
check($uncertain->status==='verificacao' && !$uncertain->cobranca_id,'tentativa ambígua mantém trava de compra');
Service::checkout($c,person('K8-002'),'Pix','');
check($GLOBALS['k8_fixture_posts']===2,'timeout não causa repetição automática de cobrança');
$GLOBALS['k8_fixture_mode']='normal';
Service::reconcile((int)$c->id,(int)$uncertain->id,'charge-recovered');
check(Service::order((int)$c->id,(int)$uncertain->id)->cobranca_id==='charge-recovered','conciliação recupera cobrança sem criar outra');
Service::cancel_order((int)$c->id,(int)$uncertain->id,'Cancelamento solicitado');
check(Service::active((int)$c->id,'K8-002')===null,'cancelamento confirmado libera nova compra');

$admin=get_user_by('login','k8admin');
wp_set_current_user($admin->ID);$admin->add_cap('k8_manage_products');
$subscriber=username_exists('k8fixture-sub') ?: wp_create_user('k8fixture-sub','local-fixture-password','subscriber@example.invalid');
wp_set_current_user($subscriber);
check(!S::can_manage((int)$a->id),'assinante não administra produtos');
check(strpos(K8Produtos\Views::dashboard_shortcode([]),'autorizada')!==false,'shortcode administrativo protegido');
$staff_role=get_role('subscriber');$staff_role->add_cap('k8_manage_products');
update_user_meta($subscriber,'k8prod_product_ids',[(int)$a->id]);
wp_set_current_user(0);wp_set_current_user($subscriber);
check(S::can_manage((int)$a->id)&&!S::can_manage((int)$b->id),'operador restrito ao produto permitido');
$staff_role->remove_cap('k8_manage_products');
wp_set_current_user($admin->ID);
foreach (['overview','orders','stock','deliveries','coupons','history'] as $tab) {
    $_GET=['k8_product'=>$a->id,'k8_tab'=>$tab];
    $html=K8Produtos\Views::dashboard_shortcode([]);
    check(strlen($html)>1000 && strpos($html,'k8-dashboard')!==false,'renderização do painel: '.$tab);
}
check(strpos(K8Produtos\Views::product_shortcode(['id'=>$a->id]),'data-k8-lookup')!==false,'shortcode público contém o fluxo desde a busca');

echo "\n$checks verificações passaram em WordPress ".get_bloginfo('version')." e PHP ".PHP_VERSION.".\n";
