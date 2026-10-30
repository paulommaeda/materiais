<?php
if(PHP_SAPI!=='cli'||getenv('K8PROD_TESTING')!=='1'||!getenv('K8PROD_WP_LOAD'))exit("Fixture CLI requer banco _test.\n");
require getenv('K8PROD_WP_LOAD');
if(substr(DB_NAME,-5)!=='_test')exit("Banco _test obrigatório.\n");
require_once dirname(__DIR__).'/k8-produtos.php';
use K8Produtos\Support as S;
use K8Produtos\Service;
use K8Produtos\Discounts;
use K8Produtos\PaymentTerms;
use K8Produtos\Storefront;
use K8Produtos\Views;
use K8Produtos\Http;
use K8Produtos\Schema;
$checks=0;
function vd_check($ok,$name){global $checks;if(!$ok)throw new RuntimeException('FALHOU: '.$name);echo 'OK '.(++$checks).': '.$name."\n";}
function vd_rejects(callable $job,$name){try{$job();}catch(Throwable $e){vd_check(true,$name);return;}vd_check(false,$name);}
function vd_mutate($op,$id,array $data){$m=new ReflectionMethod(Http::class,'mutate');$m->setAccessible(true);return $m->invoke(null,$op,$id,$data);}
function vd_product($name,$price='1000',$active=1){$result=vd_mutate('save_product',0,['nome'=>$name,'turmas'=>['Turma A','Turma B'],'valor_padrao'=>$price,'ativo'=>$active,'publicado'=>1,'formas_pagamento'=>['Pix','Boleto','Cartao'],'cartao_parcelas'=>3]);return S::product($result['produto_id']);}
function vd_person($ra){return ['ra'=>$ra,'nome_aluno'=>'Aluno exemplo','turma'=>'Turma A','cpf_resp'=>'52998224725','nome_resp'=>'Responsável exemplo'];}
Schema::install();wp_set_current_user(get_user_by('login','k8admin')->ID);
$a=vd_product('Produto visibilidade A');$b=vd_product('Produto visibilidade B');
$wpdb->insert(S::table('valores_turma'),['produto_id'=>$a->id,'turma'=>'Turma A','valor'=>10]);
$wpdb->insert(S::table('valores_turma'),['produto_id'=>$a->id,'turma'=>'Turma B','valor'=>20]);
vd_check(Service::price($a,'Turma A')===1000.0&&Service::price($a,'Turma B')===1000.0,'preço normal é único e ignora preços legados por turma');
vd_rejects(fn()=>vd_mutate('save_price',(int)$a->id,['turma'=>'Turma A','valor'=>10]),'operação antiga de preço por turma foi retirada');
vd_rejects(fn()=>vd_mutate('remove_price',(int)$a->id,['id'=>1]),'operação antiga de remoção de preço por turma foi retirada');
vd_rejects(fn()=>vd_product('Ativo sem preço','',1),'produto ativo exige preço normal');
$draft=vd_product('Desativado sem preço','',0);
vd_check((int)$draft->ativo===0&&$draft->valor_padrao===null,'produto desativado pode ser salvo sem preço normal');
vd_rejects(fn()=>vd_mutate('set_product_status',(int)$draft->id,['ativo'=>1]),'reativação exige configurar preço normal');
$tomorrow=current_datetime()->modify('+1 day')->format('Y-m-d');
$discount=Discounts::save((int)$a->id,['titulo'=>'Faixa excluir preço','tipo'=>'preco','valor'=>800,'fim'=>$tomorrow]);
$period=PaymentTerms::save((int)$a->id,['titulo'=>'Faixa excluir parcelas','parcelas'=>10,'fim'=>$tomorrow]);
$other_discount=Discounts::save((int)$b->id,['titulo'=>'Faixa preservada B','tipo'=>'preco','valor'=>700,'fim'=>$tomorrow]);
$other_period=PaymentTerms::save((int)$b->id,['titulo'=>'Parcelas preservadas B','parcelas'=>6,'fim'=>$tomorrow]);
$quote=Service::quote($a,vd_person('NEW-A'));
vd_check($quote['valor']==800&&$quote['parcelas_cartao_max']===10,'faixas vigentes são aplicadas antes da exclusão');
// Pedido histórico de cartão anterior à exclusão, sem comunicação financeira real.
$wpdb->insert(S::table('pedidos'),array_merge(vd_person('HISTORY-A'),['produto_id'=>$a->id,'valor'=>800,'valor_original'=>1000,'metodo'=>'Cartao','origem'=>'k8_link','status'=>'gerado','status_pagamento'=>'Paga',
 'promocao_id'=>$discount,'promocao_titulo'=>'Faixa excluir preço','promocao_fim'=>$tomorrow,'valor_apos_data'=>800,'cartao_parcelas_max'=>10,
 'parcelamento_regra_id'=>$period,'parcelamento_regra_titulo'=>'Faixa excluir parcelas','parcelamento_regra_fim'=>$tomorrow]));
$order_id=(int)$wpdb->insert_id;$before=Service::order((int)$a->id,$order_id);
vd_rejects(fn()=>Discounts::delete((int)$a->id,$other_discount),'não exclui faixa de preço de outro produto');
vd_rejects(fn()=>PaymentTerms::delete((int)$a->id,$other_period),'não exclui faixa de parcelas de outro produto');
vd_mutate('delete_discount',(int)$a->id,['id'=>$discount]);
vd_check(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.S::table('descontos_data').' WHERE id=%d',$discount)),'excluir preço remove fisicamente a faixa');
vd_check(Service::quote($a,vd_person('NEW-A'))['valor']==1000,'excluir faixa ativa retorna ao preço normal');
vd_mutate('delete_payment_period',(int)$a->id,['id'=>$period]);
vd_check(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.S::table('parcelamento_data').' WHERE id=%d',$period)),'excluir parcelamento remove fisicamente a faixa');
$after_quote=Service::quote($a,vd_person('NEW-A'));
vd_check($after_quote['parcelas_cartao_max']===3&&$after_quote['parcelamento_regra_id']===null,'excluir faixa ativa retorna ao parcelamento padrão');
vd_rejects(fn()=>Service::checkout($a,vd_person('NEW-A'),'Cartao','',$quote['chave_preco']),'cotação anterior à exclusão é rejeitada antes de emitir pagamento');
$after=Service::order((int)$a->id,$order_id);
vd_check($before->valor===$after->valor&&$before->valor_original===$after->valor_original&&$after->promocao_titulo==='Faixa excluir preço','excluir preço conserva valor e promoção do pedido histórico');
vd_check($after->cartao_parcelas_max==10&&$after->parcelamento_regra_titulo==='Faixa excluir parcelas'&&$after->status_pagamento==='Paga','excluir parcelamento conserva limite e pagamento do pedido histórico');
vd_check(Discounts::rules((int)$b->id)[0]->id==$other_discount&&PaymentTerms::current($b)['parcelamento_regra_id']===$other_period,'exclusões preservam faixas do outro produto');
$new_period=PaymentTerms::save((int)$a->id,['titulo'=>'Período liberado','parcelas'=>8,'fim'=>$tomorrow]);
vd_check($new_period>0,'período excluído fica disponível para outra faixa');
PaymentTerms::toggle((int)$a->id,$new_period);PaymentTerms::delete((int)$a->id,$new_period);
vd_check(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.S::table('parcelamento_data').' WHERE id=%d',$new_period)),'faixa de parcelamento inativa também pode ser excluída');
$inactive=Discounts::save((int)$a->id,['titulo'=>'Inativa excluir','tipo'=>'preco','valor'=>900,'fim'=>$tomorrow]);Discounts::toggle((int)$a->id,$inactive);Discounts::delete((int)$a->id,$inactive);
vd_check(!$wpdb->get_var($wpdb->prepare('SELECT id FROM '.S::table('descontos_data').' WHERE id=%d',$inactive)),'faixa de preço inativa também pode ser excluída');
vd_rejects(fn()=>Discounts::delete((int)$a->id,$discount),'excluir faixa inexistente informa erro');
vd_rejects(fn()=>PaymentTerms::delete((int)$a->id,$period),'excluir período inexistente informa erro');
$material=Service::create_material((int)$a->id,['nome'=>'Material preservado','periodo_entrega'=>'ano','estoque_inicial'=>9]);
vd_mutate('set_product_status',(int)$a->id,['ativo'=>0]);
vd_check((int)S::product((int)$a->id)->ativo===0&&(int)S::product((int)$b->id)->ativo===1,'desativar altera somente o produto escolhido');
vd_check(!in_array((int)$a->id,array_map('intval',array_column(Storefront::catalog(),'id')),true),'produto desativado desaparece do catálogo');
vd_check(Views::product_shortcode(['id'=>$a->id])==='','shortcode de produto desativado retorna vazio');
Views::register();Storefront::register();
vd_check(do_shortcode('[buscar_aluno produto="'.$a->id.'"]')===''&&do_shortcode('[pagamento_material_didatico produto="'.$a->id.'"]')==='','shortcodes compatíveis também ocultam produto desativado');
vd_rejects(fn()=>S::product((int)$a->id,true),'acesso público por ID é bloqueado');
vd_rejects(fn()=>Service::quote($a,vd_person('NEW-A')),'objeto antigo não permite cotar produto desativado');
vd_rejects(fn()=>Service::checkout($a,vd_person('NEW-A'),'Pix','',$after_quote['chave_preco']),'objeto antigo não permite comprar produto desativado');
vd_rejects(fn()=>Service::checkout($a,vd_person('HISTORY-A'),'Cartao',''),'produto oculto não expõe nem pedido anterior no checkout público');
vd_check(Service::order((int)$a->id,$order_id)->status_pagamento==='Paga'&&(int)$wpdb->get_var($wpdb->prepare('SELECT estoque_atual FROM '.S::table('materiais').' WHERE id=%d',$material))===9,'desativar preserva histórico financeiro e estoque no painel');
set_query_var('bmt_produto',$a->id);
vd_check(apply_filters('template_include','fallback.php')!==K8PROD_DIR.'templates/product.php','link direto oculto usa template de página não encontrada');
vd_check(strpos(wp_json_encode(apply_filters('document_title_parts',['title'=>$a->nome])),$a->nome)===false,'título público não revela nome do produto desativado');
set_query_var('bmt_produto','');
vd_mutate('set_product_status',(int)$a->id,['ativo'=>1]);
vd_check(in_array((int)$a->id,array_map('intval',array_column(Storefront::catalog(),'id')),true)&&strpos(Views::product_shortcode(['id'=>$a->id]),$a->nome)!==false,'reativar devolve produto ao catálogo e ao shortcode');
$_GET=['produto_id'=>$a->id];ob_start();Views::admin_products();$html=ob_get_clean();
vd_check(strpos($html,'Preço específico por turma deste produto')===false&&strpos($html,'data-k8-manage="save_price"')===false,'editor não contém preço específico por turma');
vd_check(strpos($html,'data-k8-product-status')!==false&&strpos($html,'Desativar produto')!==false,'editor oferece situação e ação rápida de desativar');
vd_check(strpos($html,'data-k8-manage="delete_discount"')===false&&strpos($html,'data-k8-manage="delete_payment_period"')===false,'faixas excluídas não reaparecem no editor');
echo "\n$checks verificações de ocultação e exclusão passaram.\n";
