# Formas de pagamento e parcelas por data — versão 2.6.0

## Configurar o produto

Abra **K8 Produtos → Produtos e preços** e escolha o produto.

1. Em **Formas de pagamento deste produto**, marque Pix, boleto e/ou cartão de crédito. Ao menos uma forma deve estar selecionada.
2. Escolha **Parcelamento no cartão fora dos períodos especiais**, de 1 a 12 parcelas. Esse é o limite padrão do produto.
3. Salve o produto. Em **Parcelamento por data deste produto**, cadastre o nome, o máximo de parcelas e o período de validade.

Exemplo: para permitir até **10x até 30/11** e **6x depois**, configure 6x como padrão e uma faixa de 10x com término em 30/11. Você também pode agendar outros períodos com começo e término. Início vazio significa imediato; o término é obrigatório e inclui o dia inteiro no fuso do WordPress.

Os períodos ativos de parcelamento do mesmo produto não podem se sobrepor. Ao terminar uma faixa, volta a valer o limite padrão, ou a próxima faixa agendada. As faixas de preço e de parcelamento são independentes: podem ter as mesmas datas ou datas diferentes.

O número configurado é o **máximo de parcelas**. O comprador escolhe a quantidade efetiva no checkout da K8, dentro desse limite. Pix e boleto continuam com pagamento à vista; essa configuração não cria carnê de boletos. A quantidade efetiva e as condições do cartão são confirmadas no checkout da K8.

Cada produto conserva suas formas aceitas, seus limites de parcelas, suas faixas de preço e suas turmas. Desabilitar cartão em um produto esconde essa opção e recusa novas tentativas no servidor, mantendo os outros produtos independentes. Uma faixa de cartão cadastrada fica sem efeito enquanto o cartão estiver desabilitado.

## Excluir faixas e desativar o produto

Nas tabelas de preço e parcelamento por data, **Excluir faixa** remove a faixa escolhida depois da confirmação. Os pedidos já emitidos conservam seus valores e limites. As novas compras usam as condições restantes ou os padrões do produto.

Em **Situação do produto**, escolha **Desativado — ocultar totalmente do site**. A lista de produtos também oferece uma ação rápida de desativar/reativar. Um produto desativado fica fora do catálogo e dos shortcodes, seu link direto retorna 404 e as operações públicas são bloqueadas. Os pedidos e os pagamentos já emitidos mantêm o registro e a conferência no painel.

O preço normal agora é único por produto; o cadastro de preço específico por turma foi retirado. Se o produto só tinha valores por turma, configure seu preço normal antes de reativar as vendas.

## Compra e condições registradas

O catálogo e a compra mostram o limite vigente no cartão e, quando houver faixa por data, a data final. Métodos desabilitados ficam ocultos no fluxo de compra. O servidor calcula e confere novamente preço, turmas, métodos e parcelas ao emitir o pedido.

Se a condição mudar com a página aberta, a tentativa antiga é recusada antes de criar o pagamento; a tela consulta a condição atual e exige nova confirmação. Os períodos usam o fuso configurado em **Configurações → Geral** do WordPress. Não dependem da execução pontual do WP-Cron para entrar ou sair de vigor.

Pedidos já emitidos conservam o valor, o método e o limite de parcelas registrados. Terminar a campanha ou alterar o produto não modifica o pagamento já gerado. O link do cartão tem sua própria expiração, calculada pelo prazo de vencimento do produto no momento da emissão. O relatório de pedidos exibe o limite registrado; ele não afirma quantas parcelas o comprador efetivamente escolheu na K8.

## Integração do cartão

Para novos pedidos de cartão, o plugin cria um **link individual de valor fixo na K8**, com somente cartão e limite de parcelas próprio. O link recebe uma referência única do pedido e não é reutilizável após pago. O plugin consulta o link criado e compara as condições devolvidas antes de redirecionar o comprador.

A implementação utiliza os campos documentados `ParcelaFixa` e `ParcelaFixaQtd` de `POST /api/LinkPagamento`. O endereço de checkout vem da consulta `GET /api/LinkPagamento/{Id}`. Os dados do cartão são preenchidos no ambiente hospedado da K8; não passam pelo WordPress.

A confirmação consulta o link e depois a cobrança indicada pela K8. Referência, valor, método e limite precisam corresponder ao pedido; a cobrança precisa estar paga para liberar a entrega. O WP-Cron e a consulta manual do dashboard incluem esses pedidos.

Pix e boleto continuam usando a cobrança avulsa com a forma escolhida. Pedidos de cartão gerados antes da versão 2.5.0 conservam o fluxo de cobrança que já possuíam.

Referências oficiais: [criação de link](https://docs.meuk8.com.br/reference/linkpagamento_inserelinkpagamento), [consulta de link](https://docs.meuk8.com.br/reference/linkpagamento_consultalinkpagamento) e [consulta de cobrança](https://docs.meuk8.com.br/reference/cobranca_consultacobranca). Contrato conferido também nos Swagger de produção e sandbox em 30/10/2026.

## Conferência e cancelamento

Uma falha depois de iniciar a criação remota deixa o pedido **em conferência**, preservando a tentativa para evitar pagamentos duplicados. Se o ID do link já foi retornado, ele fica salvo e pode ser consultado novamente. Se o POST não tiver retornado ID, localize a referência exibida nos detalhes do pedido no painel K8 e use **Vincular link de cartão existente**, informando o ID do link, não o da cobrança. A referência e as condições são conferidas antes de vincular.

Cancelar um pedido de cartão com link conhecido desativa o link na K8 e confere suas transações. Se houver pagamento confirmado, o pedido é atualizado para pago e o reembolso deve ser tratado na K8. Se houver transação pendente, resolva-a na K8 antes de concluir o cancelamento e liberar uma nova compra. Pedidos, valores e histórico de entregas são preservados.

## Atualização e homologação

Depois de atualizar, revise as formas de pagamento e o limite padrão de cada produto. Os produtos anteriores mantêm Pix, boleto e cartão habilitados; o limite padrão para **novos pedidos** de cartão começa em **1x** até que você configure outro valor ou uma faixa por data. Pedidos anteriores não recebem um limite retroativo.

Valide no sandbox da conta K8 uma compra com cartão, a escolha das parcelas, o retorno de pagamento e a atualização do pedido antes de liberar o novo fluxo em produção. A conta precisa permitir os endpoints de links de pagamento. A validação do desenvolvimento usa respostas K8 simuladas com os campos do contrato oficial, sem cobrança real. O controle de parcela não é enviado como campo inventado de cobrança avulsa.

Após atualizar, limpe o cache do catálogo e das páginas de compra para carregar os novos recursos. Páginas de compra e respostas de `admin-ajax.php` devem permanecer fora do cache de página/CDN.
