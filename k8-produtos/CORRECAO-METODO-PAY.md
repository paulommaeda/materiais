# K8 Produtos 2.8.5 — conferência da forma de pagamento PAY

## Atualizar os pedidos já pagos

1. Em **K8 Produtos → Configurações**, clique em **Verificar atualizações**.
2. Instale a **2.8.5** em **Painel → Atualizações**.
3. No pedido existente, clique em **Consultar este pedido**. A busca e a consulta por ID usam a mesma conferência de método.
4. Se necessário, use **Vincular transação PAY existente** com o ID da transação aprovada para aquele pedido. O ID do checkout é diferente do ID do pagamento. Não associe a transação de uma compra a outro pedido.
5. Confira **Última consulta PAY** e o status na coluna **Pagamento**. O resumo inclui **Tipo da transação** e **Tipo de pagamento**, além de estado, valores, identificador e situação gravada.

Preserve os pedidos já pagos. Não cancele nem faça outro pagamento para corrigir o retorno.

## Por que a conferência podia recusar um cartão

A [consulta oficial por identificador](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacao_consultatransacao_post) documenta campos diferentes: **Tipo**, que identifica o instrumento da transação, e **TipoPagamento**, que também pode vir não definido. A 2.8.4 exigia que TipoPagamento fosse exatamente cartao, pix ou boleto e ignorava Tipo na conferência. Isso também descartava a operação durante a busca automática.

Na 2.8.5, pelo menos um dos campos precisa identificar um instrumento reconhecido que corresponda ao método registrado no pedido. Se ambos identificarem instrumentos, eles precisam concordar. Tipo=Cartao com TipoPagamento não definido pode confirmar cartão; Tipo=Pix com TipoPagamento=cartao continua bloqueado. Dois campos ausentes, não definidos ou apenas numéricos não bastam. O código não deduz instrumentos pela posição de valores em uma lista de enums.

Rótulos de cartão de crédito e diferenças de caixa, acentos e espaços são normalizados. Métodos incompatíveis, pagamento parcial, CPF/valor/pedido divergentes e busca ambígua continuam recusados. Um método compatível só permite registrar **Paga** quando a consulta autenticada também confirma o ID, a identificação do pedido, o pagador, o estado e o valor integral.

Em caso de divergência, **Detalhes da tentativa** informa o método esperado e os rótulos seguros de Tipo e TipoPagamento. Valores arbitrários não são impressos. O resumo administrativo não aparece na resposta pública do comprador.

Os testes financeiros são simulados. Depois de atualizar, consulte os pagamentos existentes na instalação de produção para confirmar a recuperação efetiva.
