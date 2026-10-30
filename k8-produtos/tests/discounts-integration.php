<?php
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Fixture CLI requer banco _test.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\Discounts as D;
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\Storefront;
use K8Produtos\Schema;

$checks = 0;
function discount_check($condition, $name) {
    global $checks;
    if (!$condition) throw new RuntimeException('FALHOU: ' . $name);
    echo 'OK ' . (++$checks) . ': ' . $name . "\n";
}
function discount_rejects(callable $job, $name) {
    try { $job(); } catch (Throwable $e) { discount_check(true, $name); return; }
    discount_check(false, $name);
}
function discount_product($name, $price = 1000) {
    global $wpdb;
    S::must($wpdb->insert(S::table('produtos'), ['nome'=>$name,'slug'=>sanitize_title($name).'-'.bin2hex(random_bytes(3)),'valor_padrao'=>$price,'turmas_disponiveis'=>wp_json_encode(['5º Ano A','6º Ano','Turma A','Turma B','Turma específica'])]));
    return S::product((int) $wpdb->insert_id);
}
function discount_rule($product, array $data = []) {
    return D::save((int) $product->id, array_merge(['titulo'=>'Antecipação','tipo'=>'percentual','valor'=>'10','inicio'=>'2026-10-30','fim'=>'2026-11-10'], $data));
}
function discount_person($ra = 'DESC-001', $class = '5º Ano A') {
    return ['ra'=>$ra,'nome_aluno'=>'Aluno desconto','turma'=>$class,'cpf_resp'=>'52998224725','nome_resp'=>'Responsável de teste'];
}

update_option('timezone_string','America/Sao_Paulo');
Schema::install();
$p=discount_product('Desconto por data');
$q=discount_product('Outro produto');
$id=discount_rule($p);
$tz=wp_timezone();
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-10-29 23:59:59',$tz))['valor']==1000,'antes do início mantém preço original');
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-10-30 00:00:00',$tz))['valor']==900,'desconto começa à meia-noite do início');
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-11-10 23:59:59',$tz))['valor']==900,'data limite inclui o dia inteiro');
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-11-11 00:00:00',$tz))['valor']==1000,'dia seguinte volta ao preço normal');
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-11-11 02:59:59',new DateTimeZone('UTC')))['valor']==900,'limite segue fuso do site e não UTC');
discount_check(D::price($q,'5º Ano A',new DateTimeImmutable('2026-11-05',$tz))['valor']==1000,'desconto isolado por produto');
D::toggle((int)$p->id,$id);
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-11-05',$tz))['valor']==1000,'desativação retira desconto');
D::toggle((int)$p->id,$id);
discount_rule($p,['titulo'=>'Preço antecipado','tipo'=>'preco','valor'=>'750,00','inicio'=>'2026-11-01','fim'=>'2026-11-05']);
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-11-03',$tz))['valor']==750,'faixas sobrepostas escolhem menor preço sem somar');
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-11-06',$tz))['valor']==900,'após primeira faixa aplica próxima regra válida');
discount_rule($p,['tipo'=>'preco','valor'=>'600','turma_restrita'=>'6º Ano','inicio'=>'2026-11-01','fim'=>'2026-11-03']);
discount_check(D::price($p,'5º Ano A',new DateTimeImmutable('2026-11-02',$tz))['valor']==750,'regra de outra turma não se aplica');
discount_check(D::price($p,'6º Ano',new DateTimeImmutable('2026-11-02',$tz))['valor']==600,'regra específica se aplica à turma correta');
discount_rejects(fn()=>discount_rule($p,['valor'=>'101']),'percentual acima de cem rejeitado');
discount_rejects(fn()=>discount_rule($p,['valor'=>'0']),'percentual zero rejeitado');
discount_rejects(fn()=>discount_rule($p,['inicio'=>'2026-11-15','fim'=>'2026-11-10']),'intervalo invertido rejeitado');
discount_rejects(fn()=>discount_rule($p,['fim'=>'2026-02-30']),'dia inexistente rejeitado');
discount_rejects(fn()=>discount_rule($p,['fim'=>'']),'data final obrigatória');
discount_rejects(fn()=>D::save((int)$q->id,['id'=>$id,'titulo'=>'Invasão','tipo'=>'preco','valor'=>1,'fim'=>'2026-12-01']),'edição de regra de outro produto rejeitada');
discount_rejects(fn()=>D::toggle((int)$q->id,$id),'ativação de regra de outro produto rejeitada');

$today=D::today();$yesterday=current_datetime()->modify('-1 day')->format('Y-m-d');$tomorrow=current_datetime()->modify('+1 day')->format('Y-m-d');
$active=discount_product('Promoção vigente');
$active_id=discount_rule($active,['tipo'=>'preco','valor'=>'800','inicio'=>$yesterday,'fim'=>$tomorrow]);
$quote=Service::quote($active,discount_person());
discount_check($quote['original']==1000&&$quote['valor']==800&&$quote['promocao_ate']===D::date_label($tomorrow),'cotação retorna preço original, promocional e prazo');
$wpdb->insert(S::table('cupons'),['produto_id'=>$active->id,'codigo'=>'DEZ','percentual'=>10]);
$coupon=Service::quote($active,discount_person(),'DEZ');
discount_check($coupon['valor']==800&&$coupon['cupom']===''&&$coupon['aviso_cupom']!=='','cupom menor não acumula nem é consumido');
$wpdb->insert(S::table('cupons'),['produto_id'=>$active->id,'codigo'=>'TRINTA','percentual'=>30]);
$coupon=Service::quote($active,discount_person(),'TRINTA');
discount_check($coupon['valor']==700&&$coupon['cupom']==='TRINTA'&&$coupon['promocao_id']===null,'cupom melhor prevalece sem acumulação');
D::save((int)$active->id,['id'=>$active_id,'titulo'=>'Acumulável','tipo'=>'preco','valor'=>800,'fim'=>$tomorrow,'acumular_cupom'=>1]);
$coupon=Service::quote($active,discount_person(),'DEZ');
discount_check($coupon['valor']==720&&$coupon['cupom']==='DEZ'&&$coupon['promocao_id']===$active_id,'acumulação só acontece quando autorizada no cadastro');
$wpdb->insert(S::table('cupons'),['produto_id'=>$active->id,'codigo'=>'CORTESIA','percentual'=>100]);
$coupon=Service::quote($active,discount_person(),'CORTESIA');
$order=Service::checkout($active,discount_person(),'Cortesia','CORTESIA',$coupon['chave_preco']);
$stored=Service::order((int)$active->id,$order['id']);
discount_check((float)$stored->valor_original==1000&&(float)$stored->valor_apos_data==800&&$stored->promocao_titulo==='Acumulável','pedido preserva origem e valores do desconto');
D::toggle((int)$active->id,$active_id);
$same=Service::checkout($active,discount_person(),'Cortesia','CORTESIA',$coupon['chave_preco']);
discount_check($same['id']===$order['id'],'alterar regra não modifica nem duplica pedido já gerado');
$stale=discount_product('Preço mudou');
$stale_id=discount_rule($stale,['tipo'=>'preco','valor'=>500,'fim'=>$tomorrow,'inicio'=>'']);
$old_quote=Service::quote($stale,discount_person('DESC-002'));
D::save((int)$stale->id,['id'=>$stale_id,'titulo'=>'Preço novo','tipo'=>'preco','valor'=>700,'fim'=>$tomorrow]);
discount_rejects(fn()=>Service::checkout($stale,discount_person('DESC-002'),'Pix','',$old_quote['chave_preco']),'preço alterado exige nova confirmação antes da cobrança');
discount_check(Service::active((int)$stale->id,'DESC-002')===null,'mudança de preço não deixa pedido iniciado');
discount_rejects(fn()=>Service::checkout($stale,discount_person('DESC-003'),'Pix','',''),'requisição sem cotação confirmada não cria cobrança');

$c=discount_product('Preço normal único e promoções',1000);
$wpdb->insert(S::table('valores_turma'),['produto_id'=>$c->id,'turma'=>'Turma A','valor'=>1000]);
$wpdb->insert(S::table('valores_turma'),['produto_id'=>$c->id,'turma'=>'Turma B','valor'=>600]);
discount_rule($c,['tipo'=>'preco','valor'=>800,'turma_restrita'=>'Turma A','inicio'=>'','fim'=>$tomorrow]);
$prices=D::catalog_prices([$c]);
discount_check($prices[$c->id]['original']==1000&&$prices[$c->id]['valor']==800,'catálogo usa o preço normal do produto e ignora preços antigos por turma');
discount_rule($c,['tipo'=>'percentual','valor'=>50,'turma_restrita'=>'Turma B','inicio'=>'','fim'=>$tomorrow]);
$prices=D::catalog_prices([$c]);
discount_check($prices[$c->id]['original']==1000&&$prices[$c->id]['valor']==500,'preço normal e promocional do catálogo pertencem à mesma turma');
$html=D::render_price($prices[$c->id]);
discount_check(strpos($html,'<del')!==false&&strpos($html,'R$ 1.000,00')!==false&&strpos($html,'R$ 500,00')!==false&&strpos($html,D::date_label($tomorrow))!==false,'card mostra preço real riscado, preço menor e data');
$fallback=discount_product('Turma com preço padrão');
discount_rule($fallback,['tipo'=>'preco','valor'=>450,'turma_restrita'=>'Turma específica','inicio'=>'','fim'=>$tomorrow]);
discount_check(D::catalog_prices([$fallback])[$fallback->id]['valor']==450,'promoção específica usa padrão quando turma não tem preço próprio');
$higher=discount_product('Promoção acima do preço');
discount_rule($higher,['tipo'=>'preco','valor'=>1100,'inicio'=>'','fim'=>$tomorrow]);
discount_check(Service::quote($higher,discount_person())['valor']==1000,'regra nunca aumenta o preço normal');
$rounding=discount_product('Centavos',19.99);
discount_rule($rounding,['valor'=>'12,50','inicio'=>'','fim'=>$tomorrow]);
discount_check(Service::quote($rounding,discount_person())['valor']==17.49,'arredondamento monetário em centavos');
$free=discount_product('Evento gratuito por período',50);
discount_rule($free,['tipo'=>'preco','valor'=>'0','inicio'=>'','fim'=>$tomorrow]);
$free_quote=Service::quote($free,discount_person('DESC-FREE'));
$free_order=Service::checkout($free,discount_person('DESC-FREE'),'Cortesia','',$free_quote['chave_preco']);
discount_check($free_order['pagamento']==='Paga'&&(float)$free_order['valor']===0.0,'preço promocional zero funciona sem chamada bancária');
// Confere o valor efetivamente enviado ao banco, decodificando o corpo AES.
update_option('k8p_client_id','fixture');update_option('k8p_client_secret','fixture');update_option('k8p_aes_key','1234567890123456');
add_filter('pre_http_request',static function($pre,$args,$url){
    if(strpos($url,'meuk8.com.br')===false)return $pre;
    if(substr($url,-6)==='/token')return ['headers'=>[],'body'=>'{"access_token":"fixture"}','response'=>['code'=>200,'message'=>'OK']];
    if(strpos($url,'/api/Cliente?')!==false){$data=['Id'=>'discount-customer'];}
    else{
        $body=json_decode(openssl_decrypt(base64_decode($args['body']),'AES-128-CBC','1234567890123456',OPENSSL_RAW_DATA,str_repeat("\0",16)),true);
        $GLOBALS['discount_sent_value']=$body['Valor']??null;
        $data=['Id'=>'discount-charge'];
    }
    return ['headers'=>[],'body'=>base64_encode(openssl_encrypt(wp_json_encode($data),'AES-128-CBC','1234567890123456',OPENSSL_RAW_DATA,str_repeat("\0",16))),'response'=>['code'=>200,'message'=>'OK']];
},10,3);
$bank=discount_product('Preço enviado à K8');
discount_rule($bank,['tipo'=>'preco','valor'=>800,'inicio'=>'','fim'=>$tomorrow]);
$bank_quote=Service::quote($bank,discount_person('DESC-K8'));
$bank_order=Service::checkout($bank,discount_person('DESC-K8'),'Pix','',$bank_quote['chave_preco']);
discount_check((float)$GLOBALS['discount_sent_value']===800.0,'K8 recebe o preço com desconto e não o preço original');
discount_check($bank_order['valor_original']==1000&&$bank_order['valor']==800,'pedido pendente conserva os dois valores');
Schema::install();
discount_check(Service::order((int)$active->id,$order['id'])->promocao_titulo==='Acumulável','migração repetida conserva valores históricos');
echo "\n$checks verificações de descontos por data passaram.\n";
