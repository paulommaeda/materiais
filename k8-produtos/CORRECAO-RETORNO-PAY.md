# K8 Produtos 2.8.2 — confirmação e retorno PAY

> Guia histórico da 2.8.2. A consulta que confundia checkout e transação foi substituída na 2.8.3. Use o [guia de vínculo atual](CORRECAO-VINCULO-PAY.md) para recuperar um pagamento já feito.

Esta atualização corrige uma limitação da consulta de pagamento: a versão anterior dependia somente de `ConsultaTransacaoReferencia`. Quando o PAY não encontra essa referência, o plugin não consegue confirmar o pagamento, mesmo que o cartão já tenha sido aprovado.

A versão 2.8.2 consulta primeiro o identificador da transação já vinculada ou o identificador recebido na criação do checkout, pelo método documentado `ConsultaTransacao`. A consulta por referência continua disponível quando o identificador inicial ainda não é consultável. A confirmação exige resposta autenticada do PAY e correspondência de identificador, número do pedido, comprador, valor integral e forma de pagamento. Uma referência ausente na resposta é aceita somente na consulta por ID com as demais correspondências verificadas.

## Recuperar o pedido que já foi pago

1. Mantenha o pedido existente. **Não cancele essa compra nem pague novamente para corrigir o retorno.**
2. Faça backup e instale `k8-produtos-2.8.2.zip` em **Plugins → Adicionar plugin → Enviar plugin**, substituindo a versão atual. Pedidos, checkout, referências, credenciais, ambiente e limites de parcelas são preservados.
3. No produto, abra **Pedidos → Consultar este pedido** na compra já paga. A atualização passa a tentar a consulta pelo ID que já está salvo, sem criar outro pagamento.
4. Se a consulta ainda não localizar a operação, abra **Vincular transação PAY existente**. No painel PAY, localize o pagamento correto e copie seu **ID da transação (UUID)** para esse campo. Clique em **Conferir e vincular transação PAY**. Use o identificador da transação aprovada, não a referência da loja. O plugin só confirma o vínculo depois de consultar o PAY e validar os dados.
5. Após a conferência positiva, o mesmo pedido passa a **Paga** e pode seguir para a entrega. Se a validação apontar divergência, mantenha o pedido em conferência e encaminhe à secretaria os detalhes da tentativa; não marque como pago apenas com base no retorno do navegador.

O ID do checkout e o ID da transação vinculada ficam visíveis na administração para ajudar a comparar com o painel PAY. Não é necessário enviar chaves, token ou dados do cartão pelo chat.

## Novas compras

Novos checkouts recebem uma `URLConfirmacao` própria e um retorno à loja vinculado ao pedido por assinatura. A notificação e a volta do comprador provocam uma consulta autenticada ao PAY; o corpo do aviso não tem autoridade para declarar que o pagamento está aprovado.

A nova página de retorno mostra **Pagamento confirmado** quando a consulta confirmar o pagamento. Caso contrário, mostra **Pagamento em conferência**, com opção de atualizar a confirmação e voltar à loja. Ela não exibe dados pessoais do comprador ou do aluno.

Os checkouts criados na 2.8.1 conservam a URL de retorno original já registrada na K8. A atualização não modifica nem recria esses checkouts. Para o pagamento já feito, use a consulta ou o vínculo pelo ID descritos acima. A nova página e a notificação passam a ser enviadas nos próximos checkouts.

A confirmação automática depende de o servidor do WordPress receber as chamadas e conseguir consultar a API PAY. Se houver bloqueio no firewall ou no serviço de hospedagem, a consulta administrativa e o WP-Cron continuam disponíveis. O parcelamento por data, inclusive 12x quando vigente, permanece registrado em cada pedido.

## Validação

O erro de referência não localizada foi reproduzido com a versão 2.8.1 e respostas PAY simuladas, embora a transação correspondente estivesse aprovada e disponível por ID. Os testes da correção cobrem confirmação por ID, vínculo manual com validação, assinatura inválida, aviso de pagamento forjado, notificações repetidas, página de retorno sem dados pessoais e preservação das funções anteriores. Não houve acesso à conta K8 do usuário nem novo pagamento real.

A causa interna de a K8 não localizar a referência na conta de produção não foi demonstrada. A atualização acrescenta as consultas e o vínculo que permitem recuperar o pagamento existente sem depender exclusivamente dessa referência.

Referências oficiais: [consulta por identificador](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacao_consultatransacao_post), [consulta por referência](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacaoreferencia_consultatransacaoreferencia_post) e [contrato do checkout PAY v2](https://pagamentos.meuk8.com.br/PagamentosAPI/swagger/docs/v1).
