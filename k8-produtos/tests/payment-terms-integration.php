<?php
if (PHP_SAPI !== 'cli' || getenv('K8PROD_TESTING') !== '1' || !getenv('K8PROD_WP_LOAD')) exit("Fixture CLI requer banco _test.\n");
require getenv('K8PROD_WP_LOAD');
if (substr(DB_NAME, -5) !== '_test') exit("Banco _test obrigatório.\n");
require_once dirname(__DIR__) . '/k8-produtos.php';

use K8Produtos\PaymentTerms as Terms;
use K8Produtos\CardPayments;
use K8Produtos\Client;
use K8Produtos\Service;
use K8Produtos\Schema;
use K8Produtos\Support as S;
use K8Produtos\Discounts;
use K8Produtos\Http;
use K8Produtos\Storefront;

$checks=0;
function pay_check($ok,$name){global $checks;if(!$ok)throw new RuntimeException('FALHOU: '.$name);echo 'OK '.(++$checks).': '.$name."\n";}
function pay_rejects(callable $job,$name){try{$job();}catch(Throwable $e){pay_check(true,$name);return;}pay_check(false,$name);}
function pay_mutate($op,$id,array $data){$m=new ReflectionMethod(Http::class,'mutate');$m->setAccessible(true);return $m->invoke(null,$op,$id,$data);}
function pay_product($name,array $methods=['Pix','Cartao'],$count=6){
 $result=pay_mutate('save_product',0,['nome'=>$name,'turmas'=>['5º Ano A'],'valor_padrao'=>'1000','ativo'=>1,'publicado'=>1,
  'etapas'=>1,'vencimento_dias'=>5,'formas_pagamento'=>$methods,'cartao_parcelas'=>$count]);return S::product($result['produto_id']);
}
function pay_rule($p,array $data=[]){return Terms::save((int)$p->id,array_merge(['titulo'=>'Condição antecipada','inicio'=>'2026-11-01','fim'=>'2026-11-30','parcelas'=>10],$data));}
function pay_person($ra){return ['ra'=>$ra,'nome_aluno'=>'Aluno exemplo','turma'=>'5º Ano A','cpf_resp'=>'52998224725','nome_resp'=>'Responsável exemplo'];}
function pay_start($p,$ra){$q=Service::quote($p,pay_person($ra));return Service::checkout($p,pay_person($ra),'Cartao','',$q['chave_preco']);}
function pay_mark_paid($order,$amount=null){
 $id=$order->link_pagamento_id;$charge='charge-'.$id;
 $GLOBALS['pay_links'][$id]['DadosPagamento']=['Situacao'=>'pago','FormaPagamento'=>'Cartao','CobrancaId'=>$charge,
  'NumeroCobrancas'=>1,'NumeroCobrancasPagas'=>1,'NumeroCobrancasPendentes'=>0,'NumeroCobrancasCanceladas'=>0];
 $GLOBALS['pay_charges'][$charge]=['Id'=>$charge,'Estado'=>'Paga','Valor'=>$amount??(float)$order->valor,'ClienteId'=>'payer-example'];
}
Schema::install();wp_set_current_user(get_user_by('login','k8admin')->ID);update_option('timezone_string','America/Sao_Paulo');
pay_rejects(fn()=>Terms::input(['formas_pagamento'=>[]]),'produto exige ao menos uma forma de pagamento');
pay_rejects(fn()=>Terms::input(['formas_pagamento'=>['Outro']]),'forma de pagamento desconhecida é rejeitada');
foreach ([0,13,'2.5',true,[],null] as $invalid)pay_rejects(fn()=>Terms::count($invalid),'quantidade inválida de parcelas é rejeitada');
$a=pay_product('Pagamento A',['Pix','Cartao'],6);$b=pay_product('Pagamento B',['Boleto'],1);
pay_check(Terms::methods($a)===['Pix','Cartao']&&Terms::methods($b)===['Boleto'],'formas de pagamento independentes por produto');
$id=pay_rule($a);$tz=wp_timezone();
pay_check(Terms::current($a,new DateTimeImmutable('2026-10-31 23:59:59',$tz))['parcelas_cartao_max']===6,'antes da faixa usa limite padrão do produto');
pay_check(Terms::current($a,new DateTimeImmutable('2026-11-01 00:00:00',$tz))['parcelas_cartao_max']===10,'parcelamento começa à meia-noite inicial');
pay_check(Terms::current($a,new DateTimeImmutable('2026-11-30 23:59:59',$tz))['parcelas_cartao_max']===10,'último dia incluído por inteiro');
pay_check(Terms::current($a,new DateTimeImmutable('2026-12-01 00:00:00',$tz))['parcelas_cartao_max']===6,'depois da faixa retorna ao limite padrão');
pay_check(Terms::current($a,new DateTimeImmutable('2026-12-01 02:59:59',new DateTimeZone('UTC')))['parcelas_cartao_max']===10,'limite de data segue o fuso do site');
pay_check(Terms::current($b,new DateTimeImmutable('2026-11-10',$tz))['parcelas_cartao_max']===0,'produto sem cartão não apresenta parcelamento');
pay_rejects(fn()=>pay_rule($a,['inicio'=>'2026-11-30','fim'=>'2026-12-10']),'faixas ativas não podem se sobrepor no mesmo dia');
pay_rejects(fn()=>pay_rule($a,['inicio'=>'2026-12-10','fim'=>'2026-11-30']),'intervalo invertido é rejeitado');
pay_rejects(fn()=>pay_rule($a,['fim'=>'2026-02-30']),'data inexistente é rejeitada');
pay_rejects(fn()=>pay_rule($a,['fim'=>'']),'data final é obrigatória');
pay_rejects(fn()=>Terms::save((int)$b->id,['id'=>$id,'titulo'=>'Outro','parcelas'=>5,'fim'=>'2026-12-10']),'edição de faixa de outro produto é rejeitada');
pay_rejects(fn()=>Terms::toggle((int)$b->id,$id),'ativação de faixa de outro produto é rejeitada');
Terms::toggle((int)$a->id,$id);pay_rule($a,['inicio'=>'2026-11-05','fim'=>'2026-11-10']);
pay_rejects(fn()=>Terms::toggle((int)$a->id,$id),'reativar faixa sobreposta é rejeitado');
pay_check((int)$wpdb->get_var($wpdb->prepare('SELECT ativo FROM '.S::table('parcelamento_data').' WHERE id=%d',$id))===0,'erro de ativação preserva faixa inativa');
$today=current_datetime()->format('Y-m-d');$tomorrow=current_datetime()->modify('+1 day')->format('Y-m-d');
$p=pay_product('Condição atual',['Pix','Cartao'],3);
$rule=pay_rule($p,['inicio'=>'','fim'=>$tomorrow,'parcelas'=>10]);
Discounts::save((int)$p->id,['titulo'=>'Preço antecipado','tipo'=>'preco','valor'=>800,'fim'=>$tomorrow]);
$q=Service::quote($p,pay_person('PAY-01'));
pay_check($q['valor']==800&&$q['parcelas_cartao_max']===10&&$q['formas_pagamento']===['Pix','Cartao'],'cotação reúne desconto, formas aceitas e parcelas atuais');
pay_check($q['parcelamento_ate']===Discounts::date_label($tomorrow),'cotação informa até quando vale o parcelamento');
$catalog=Storefront::catalog();$item=array_values(array_filter($catalog,fn($item)=>(int)$item->id===(int)$p->id))[0];
pay_check(strpos(Storefront::price_html($item),'até 10x')!==false,'catálogo exibe parcelamento vigente do produto');
Terms::save((int)$p->id,['id'=>$rule,'titulo'=>'Condição reduzida','inicio'=>'','fim'=>$tomorrow,'parcelas'=>6]);
$fresh=Service::quote($p,pay_person('PAY-01'));
pay_check($fresh['valor']===$q['valor']&&$fresh['chave_preco']!==$q['chave_preco'],'mudança de parcelas invalida cotação mesmo com preço igual');
pay_rejects(fn()=>Service::checkout($p,pay_person('PAY-01'),'Cartao','',$q['chave_preco']),'checkout exige confirmação de parcelas atualizadas');
pay_rejects(fn()=>Service::checkout($p,pay_person('PAY-02'),'Boleto','',$fresh['chave_preco']),'método desabilitado é recusado no servidor');
pay_check(Service::active((int)$p->id,'PAY-01')===null&&Service::active((int)$p->id,'PAY-02')===null,'condições inválidas não criam pedidos parciais');

// Provedor simulado: o protocolo AES real é decodificado e os campos documentados são conferidos.
update_option('k8p_client_id','fixture');update_option('k8p_client_secret','fixture');update_option('k8p_aes_key','1234567890123456');
$GLOBALS['pay_links']=[];$GLOBALS['pay_charges']=[];$GLOBALS['pay_posts']=0;$GLOBALS['pay_mode']='normal';$GLOBALS['pay_token']=bin2hex(random_bytes(4));
add_filter('pre_http_request',static function($pre,$args,$url){
 if(strpos($url,'meuk8.com.br')===false)return $pre;
 if(substr($url,-6)==='/token')return ['headers'=>[],'body'=>'{"access_token":"fixture"}','response'=>['code'=>200,'message'=>'OK']];
 $path=parse_url($url,PHP_URL_PATH);$method=$args['method']??'GET';$response=[];
 if($method==='POST'&&substr($path,-18)==='/api/LinkPagamento'){
  $payload=json_decode(openssl_decrypt(base64_decode($args['body']),'AES-128-CBC','1234567890123456',OPENSSL_RAW_DATA,str_repeat("\0",16)),true);
  $id='link-'.$GLOBALS['pay_token'].'-'.(++$GLOBALS['pay_posts']);$GLOBALS['pay_last_payload']=$payload;$GLOBALS['pay_last_id']=$id;
  $GLOBALS['pay_links'][$id]=$payload+['Id'=>$id,'LinkUrl'=>'https://sandbox.meuk8.com.br/checkout-card?ID='.$id,'DadosPagamento'=>['NumeroCobrancas'=>0,'NumeroCobrancasPagas'=>0,'NumeroCobrancasCanceladas'=>0]];
  if($GLOBALS['pay_mode']==='timeout')return new WP_Error('timeout','simulated timeout');
  $response=['Id'=>$id];
 }elseif($method==='GET'&&preg_match('#/api/LinkPagamento/([^/]+)$#',$path,$m)){
  $response=$GLOBALS['pay_links'][$m[1]];
  if($GLOBALS['pay_mode']==='wrong_limit')$response['ParcelaFixaQtd']=12;
 }elseif($method==='PUT'&&substr($path,-18)==='/api/LinkPagamento'){
  $payload=json_decode(openssl_decrypt(base64_decode($args['body']),'AES-128-CBC','1234567890123456',OPENSSL_RAW_DATA,str_repeat("\0",16)),true);
  $GLOBALS['pay_links'][$payload['Id']]=array_merge($GLOBALS['pay_links'][$payload['Id']],$payload);$response=['Retorno'=>'OK'];
 }elseif($method==='GET'&&preg_match('#/api/Cobranca/([^/]+)$#',$path,$m)){$response=$GLOBALS['pay_charges'][$m[1]];}
 else throw new RuntimeException('Rota não simulada: '.$path);
 return ['headers'=>[],'body'=>base64_encode(openssl_encrypt(wp_json_encode($response),'AES-128-CBC','1234567890123456',OPENSSL_RAW_DATA,str_repeat("\0",16))),'response'=>['code'=>200,'message'=>'OK']];
},10,3);
$order=pay_start($p,'CARD-01');$stored=Service::order((int)$p->id,$order['id']);$sent=$GLOBALS['pay_last_payload'];
pay_check($sent['ParcelaFixa']===true&&$sent['ParcelaFixaQtd']===6&&$sent['Valor']==800&&$sent['FormasPagamento']==='Cartao','K8 recebe limite atual, valor com desconto e somente cartão no corpo AES');
pay_check($sent['LinkReutilizavel']===false&&$sent['TipoValor']==='fixo'&&$sent['Observacoes']===CardPayments::reference($stored),'link individual tem valor fixo e referência única do pedido');
pay_check($stored->cartao_parcelas_max==6&&$stored->parcelamento_regra_titulo==='Condição reduzida'&&$stored->link_pagamento_id,'pedido conserva parcelas, período e ID remoto');
pay_check($order['redirect']===$GLOBALS['pay_links'][$stored->link_pagamento_id]['LinkUrl'],'redirecionamento usa URL devolvida pela K8');
$posts=$GLOBALS['pay_posts'];Terms::save((int)$p->id,['id'=>$rule,'titulo'=>'Nova condição','inicio'=>'','fim'=>$tomorrow,'parcelas'=>2]);
$same=pay_start($p,'CARD-01');
pay_check($same['id']===$order['id']&&$same['parcelas_cartao_max']===6&&$GLOBALS['pay_posts']===$posts,'pedido emitido preserva limite e não gera outro link após mudar regra');
pay_mark_paid($stored);$paid=Service::sync_order((int)$p->id,$stored->id);
pay_check($paid->status_pagamento==='Paga'&&$paid->cobranca_id==='charge-'.$stored->link_pagamento_id,'pagamento exige confirmação do link e da cobrança correspondente');
pay_rejects(fn()=>Service::cancel_order((int)$p->id,$paid->id,'Teste de cancelamento'),'pedido já pago mantém proteção contra cancelamento');

$cancelled=pay_start($p,'CARD-CANCEL');$cancel_order=Service::order((int)$p->id,$cancelled['id']);Service::cancel_order((int)$p->id,$cancelled['id'],'Cancelamento de teste');
pay_check($GLOBALS['pay_links'][$cancel_order->link_pagamento_id]['Ativo']===false&&Service::active((int)$p->id,'CARD-CANCEL')===null,'cancelar desativa link remoto antes de liberar nova compra');
$pending=pay_start($p,'CARD-PENDING');$pending_order=Service::order((int)$p->id,$pending['id']);
$GLOBALS['pay_links'][$pending_order->link_pagamento_id]['DadosPagamento']=['NumeroCobrancas'=>1,'NumeroCobrancasPendentes'=>1,'NumeroCobrancasPagas'=>0,'NumeroCobrancasCanceladas'=>0];
pay_rejects(fn()=>Service::cancel_order((int)$p->id,$pending_order->id,'Cancelamento de teste'),'transação pendente no cartão impede liberação de nova compra');
pay_check(Service::active((int)$p->id,'CARD-PENDING')!==null&&Service::public_order(Service::order((int)$p->id,$pending_order->id))['redirect']===null,'pendência mantém pedido e remove link desativado da tela');
$GLOBALS['pay_links'][$pending_order->link_pagamento_id]['DadosPagamento']=['NumeroCobrancas'=>1,'NumeroCobrancasPendentes'=>0,'NumeroCobrancasPagas'=>0,'NumeroCobrancasCanceladas'=>1];
Service::cancel_order((int)$p->id,$pending_order->id,'Cancelado na K8 após conferência');
pay_check(Service::active((int)$p->id,'CARD-PENDING')===null,'após resolver a transação o cancelamento pode concluir');

$GLOBALS['pay_mode']='timeout';$before=$GLOBALS['pay_posts'];
pay_rejects(fn()=>pay_start($p,'CARD-TIMEOUT'),'timeout remoto conserva tentativa em conferência');
$uncertain=Service::active((int)$p->id,'CARD-TIMEOUT');$remote=$GLOBALS['pay_last_id'];
pay_check($uncertain->status==='verificacao'&&!$uncertain->link_pagamento_id,'link não confirmado não é inventado localmente');
$GLOBALS['pay_mode']='normal';pay_start($p,'CARD-TIMEOUT');
pay_check($GLOBALS['pay_posts']===$before+1,'timeout não provoca segundo POST de link');
Service::reconcile((int)$p->id,(int)$uncertain->id,$remote);
pay_check(Service::order((int)$p->id,$uncertain->id)->link_pagamento_id===$remote,'conciliação recupera link pela referência e pelas condições do pedido');
$GLOBALS['pay_mode']='wrong_limit';
pay_rejects(fn()=>pay_start($p,'CARD-WRONG-LIMIT'),'limite diferente no retorno da K8 impede redirecionamento');
$wrong=Service::active((int)$p->id,'CARD-WRONG-LIMIT');
pay_check($wrong->link_pagamento_id&&$wrong->status==='verificacao'&&!$wrong->checkout_url,'ID remoto é preservado quando leitura das condições falha');
$GLOBALS['pay_mode']='normal';Service::sync_order((int)$p->id,$wrong->id);
pay_check(Service::order((int)$p->id,$wrong->id)->status==='gerado','consulta recupera link conhecido sem repetir criação');
$bad=pay_start($p,'CARD-BAD-VALUE');$bad_order=Service::order((int)$p->id,$bad['id']);pay_mark_paid($bad_order,999);
pay_rejects(fn()=>Service::sync_order((int)$p->id,$bad_order->id),'valor da cobrança divergente não libera entrega');
pay_check(Service::order((int)$p->id,$bad_order->id)->status_pagamento==='Pendente','erro de conferência mantém estado de pagamento anterior');
$other=pay_start($p,'CARD-OTHER');$other_order=Service::order((int)$p->id,$other['id']);
pay_rejects(fn()=>CardPayments::validate($other_order,$GLOBALS['pay_links'][$stored->link_pagamento_id],$stored->link_pagamento_id),'link de outro pedido não pode ser vinculado');
pay_rejects(fn()=>Client::card_checkout(['LinkUrl'=>'https://example.invalid/phishing']),'URL de checkout de domínio não autorizado é rejeitada');
$batch=pay_product('Sincronização cartão',['Cartao'],4);$batch_public=pay_start($batch,'CARD-BATCH');$batch_order=Service::order((int)$batch->id,$batch_public['id']);pay_mark_paid($batch_order);
$result=Service::sync_batch((int)$batch->id);
pay_check($result['atualizados']===1&&$result['falhas']===0&&Service::order((int)$batch->id,$batch_order->id)->status_pagamento==='Paga','sincronização em lote inclui pedidos de cartão sem cobrança vinculada inicialmente');
Schema::install();
pay_check(Service::order((int)$p->id,$stored->id)->cartao_parcelas_max==6,'migração repetida preserva parcelas históricas');
pay_check(Terms::refresh_after([])===300,'atualização de condições não consulta faixas gerais sem produto');
echo "\n$checks verificações de formas de pagamento e parcelamento passaram.\n";
