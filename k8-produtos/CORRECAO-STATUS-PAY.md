# K8 Produtos 2.8.4 — pagamento conferido e pedido pendente

A 2.8.5 também corrige a leitura dos campos de método. Consulte [conferência da forma de pagamento](CORRECAO-METODO-PAY.md).

## Atualizar e consultar o pedido existente

1. Em **K8 Produtos → Configurações**, clique em **Verificar atualizações**.
2. Instale a **2.8.5** em **Painel → Atualizações**.
3. Abra o pedido que já foi pago e clique em **Consultar este pedido**. Preserve o pedido; não faça outro pagamento para corrigir sua confirmação.
4. Confira a situação na coluna **Pagamento** e abra **Última consulta PAY**. O painel mostra o estado retornado pela K8, o ID consultado, os valores e a situação gravada no pedido, mesmo sem mensagem de erro.
5. Se a busca não localizar a aprovação, use **Vincular transação PAY existente** com o ID da operação aprovada no PAY. Checkout e transação possuem IDs próprios. A consulta autenticada precisa confirmar pedido, CPF, valor integral, método e estado.

## O que foi corrigido

Uma versão anterior podia guardar uma tentativa pendente como vínculo do pedido. A 2.8.3 consultava esse ID e não pesquisava outra tentativa aprovada enquanto o vínculo estivesse preenchido. Esse cenário foi reproduzido em WordPress local: a K8 simulada informava uma aprovação para o mesmo pedido, mas o pedido permanecia pendente.

A 2.8.4 consulta a tentativa antiga e permite recuperar uma aprovação única e correspondente quando o pedido ainda está pendente e a tentativa vinculada não foi aprovada. A consulta individual verifica o ID encontrado antes de gravar **Paga**. O histórico registra a substituição da tentativa antiga. Duas aprovações correspondentes exigem conferência; pagamentos já confirmados ou em conferência não têm seu vínculo trocado por esse mecanismo.

A gravação do status e do identificador é conferida dentro da transação do banco. Se a escrita não produzir o resultado esperado, a operação retorna erro e não anuncia pagamento confirmado.

Antes, a consulta individual respondia apenas “Alteração salva”, inclusive quando continuava pendente. Agora ela informa a situação persistida e guarda um resumo administrativo. Esse resumo contém apenas horário, estado, identificador e valores; não guarda CPF, nome, endereço ou dados do cartão e não aparece na resposta pública do comprador.

## Como interpretar a consulta

- **Estado Aprovada ou Liquidado e valor integral correspondente:** a conferência pode registrar o pedido como **Paga**.
- **Estado Pendente, análise ou pré-autorização:** a consulta não confirma o pagamento. O pedido continua bloqueado até aprovação.
- **Nenhuma transação aprovada e correspondente localizada:** a busca não identificou uma aprovação única; consulte o ID real pelo vínculo administrativo.
- **Erro de consulta ou gravação:** consulte **Detalhes da tentativa**. Não force a entrega a partir de uma mensagem de conexão bem-sucedida.

A [consulta oficial por identificador](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacao_consultatransacao_post) documenta Estado, Valor e ValorPago. A [consulta por critérios](https://docs.meuk8.com.br/reference/consultatransacoes_consultatransacoes_consultatransacoes_post) permite localizar operações por período e tipo. O plugin sempre consulta a API autenticada; o navegador e o callback não podem declarar uma compra paga.

Os testes usam dados fictícios e respostas financeiras simuladas. A recuperação da compra específica em produção precisa ser conferida no site após atualizar.
