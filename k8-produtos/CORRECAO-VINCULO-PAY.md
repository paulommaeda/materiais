# K8 Produtos 2.8.4 — vínculo entre checkout e transação paga

O checkout e o pagamento aprovado têm identificadores próprios. A versão 2.8.2 ainda tentava consultar o ID do checkout como transação e descartava o identificador enviado na notificação. Também exigia número e referência na resposta, mesmo quando a descrição preservava a identificação do pedido.

A 2.8.4 acrescenta recuperação de vínculos antigos de tentativas pendentes, verificação da gravação do status e o painel **Última consulta PAY**. Veja [pagamento conferido e pedido pendente](CORRECAO-STATUS-PAY.md).

## Recuperar a compra já paga

1. Preserve o pedido existente. Não cancele nem faça outro pagamento para corrigir a confirmação.
2. No WordPress, use **K8 Produtos → Configurações → Verificar atualizações** e instale a **2.8.4** em **Painel → Atualizações**.
3. No pedido pago, clique em **Consultar este pedido**. A consulta procura a transação efetiva e confere seus dados antes de registrá-la no mesmo pedido. O ID do checkout continua separado do ID do pagamento.
4. A opção **Vincular transação PAY existente** continua disponível. Informe o ID da transação aprovada mostrado no PAY, e não o ID do checkout. A conferência aceita a descrição exata do pedido quando número e referência não foram devolvidos, mantendo as verificações de CPF, valor, método, estado e identificador.
5. Se houver divergência, a mensagem passa a indicar quais campos precisam ser conferidos, sem mostrar os valores dos documentos. Uma recusa da API guarda o método, a rota e o detalhe mascarado em **Detalhes da tentativa** e na resposta administrativa. O erro público continua genérico.

## Busca e notificação automáticas

Quando a referência não é encontrada, o plugin usa `ConsultaTransacoes` com intervalo de datas e tipo de pagamento. Só escolhe uma transação aprovada quando a identificação do pedido, o pagador, o valor integral e o método correspondem. A consulta individual pelo ID real verifica novamente a transação antes da confirmação. Duas aprovações para o mesmo pedido exigem conferência; o plugin não escolhe a primeira.

A busca usa períodos de até 31 dias: para pedidos antigos consulta o período inicial e o período recente. Se a operação não estiver nesses períodos ou a lista exceder o limite de segurança, use o ID da transação no vínculo administrativo. Não há busca por CPF e valor isoladamente.

A descrição enviada é registrada no pedido. Renomear o produto depois da compra não muda essa descrição. Pedidos anteriores ainda sem esse registro podem ser conferidos pela descrição do produto correspondente; se houve mudança de nome antes da atualização, confira a identificação retornada pelo PAY com a secretaria.

O callback PAY documenta `Identificador` (transação) e `IdentificadorCheckout` (checkout). A versão 2.8.3 lê o ID recebido em JSON, formulário ou GET de recuperação; avisos cifrados compatíveis com a chave AES também podem fornecer a pista do ID. Um checkout explicitamente diferente é recusado. Esses dados servem apenas para localizar a operação: aprovação, CPF e valores são sempre conferidos pela consulta autenticada à API.

Uma tentativa de cartão pendente, rejeitada ou cancelada não é gravada como pagamento final nem libera automaticamente outra compra. Se houver outra tentativa aprovada no mesmo checkout, a busca considera seu ID próprio. Uma tentativa antiga ainda não aprovada pode ser substituída após consulta e conferência na 2.8.4, com registro no histórico. Pagamentos já confirmados ou em conferência mantêm seu vínculo protegido.

Os testes usam identidades fictícias e respostas K8 simuladas, incluindo compra de R$ 0,23, IDs distintos e ausência de número/referência com descrição correta. Isso não confirma que a compra específica da conta de produção já está conciliada; após atualizar, consulte o pedido no site.

Documentação oficial: [callback PAY](https://docs.meuk8.com.br/docs/payload-callback), [consulta por critérios](https://docs.meuk8.com.br/reference/consultatransacoes_consultatransacoes_consultatransacoes_post) e [consulta por identificador](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacao_consultatransacao_post).
