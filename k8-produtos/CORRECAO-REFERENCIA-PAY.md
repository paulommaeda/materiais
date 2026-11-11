# K8 Produtos 2.8.1 — correção da referência PAY

A versão 2.8.0 montava a referência acrescentando `K8PAY-`, o número do pedido e 32 caracteres aleatórios. No pedido #10, isso gerou **41 caracteres**, acima dos **36 aceitos pelo PAY**. A falha estava no plugin. A resposta HTTP 200 continha uma recusa da operação, e não a confirmação de criação do checkout. Esta mensagem específica trata da referência; não indica falha no parcelamento configurado.

A versão 2.8.1 usa um identificador UUID de **36 caracteres** em novos pedidos de cartão, Pix e boleto. O número do pedido continua sendo enviado separadamente. A referência fica salva integralmente e é reutilizada nas consultas. O plugin também bloqueia localmente uma nova criação se a referência ultrapassar o limite.

## Atualizar e repetir a compra

1. Faça backup do WordPress e do banco. Em **Plugins → Adicionar plugin → Enviar plugin**, envie `k8-produtos-2.8.1.zip` e escolha substituir a versão atual. Credenciais, provedor, ambiente e pedidos são preservados.
2. Confirme no painel PAY que a tentativa do **pedido #10** não gerou pagamento nem checkout pagável. A referência antiga aparece nos detalhes da tentativa.
3. Na aba **Pedidos** do produto, abra **Cancelar pedido** do #10, informe o motivo da recusa e marque a confirmação de conferência somente depois de verificar no PAY. Se existir operação ativa, encerre-a antes de confirmar a baixa local; pagamentos aprovados exigem tratamento no PAY.
4. Faça uma nova compra para o aluno. Ela receberá outro número de pedido e uma referência de 36 caracteres. Confira a abertura do checkout e o limite de parcelas; após o pagamento, use **Consultar este pedido** para confirmar a situação.

A atualização mantém a referência antiga do pedido #10 e não repete sua criação automaticamente. Isso preserva a consulta do histórico e evita gerar duas operações caso uma tentativa anterior tenha sido aceita pelo provedor. As referências anteriores maiores que 36 caracteres permanecem disponíveis para consulta; o limite local aplica-se à criação de novos checkouts.

Os testes reproduzem a mesma recusa de tamanho na versão anterior e verificam o novo limite nos três meios. Também verificam atualização da versão 2.8.0, consulta de pagamento antigo, ausência de repetição automática, parcelamento e abertura do checkout. Os pagamentos são simulados: a abertura e a confirmação real precisam ser conferidas na sua conta PAY.
