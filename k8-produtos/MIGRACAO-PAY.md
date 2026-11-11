# K8 Produtos 2.8.3 — migração para o PAY

Esta versão permite usar **K8 PAY em novas compras de cartão, Pix e boleto**, pelo checkout hospedado v2. Pedidos antigos continuam no COB, com seus valores, referências e limites de parcelas. A instalação não muda o provedor automaticamente.

## Instalar e configurar

1. Faça backup dos arquivos e do banco do WordPress. Valide primeiro em uma cópia do site.
2. **Mantenha o ambiente atual do COB durante a atualização.** As versões anteriores não guardavam o ambiente por pedido; a atualização registra o ambiente em uso nos pedidos COB existentes. Para este site, que está em produção, mantenha Produção marcada ao instalar.
3. Em **Plugins → Adicionar plugin → Enviar plugin**, envie `k8-produtos-2.8.3.zip` e escolha substituir a versão atual. Não exclua pedidos nem recrie produtos. O tema não precisa ser atualizado para esta migração. Se já pagou e a consulta não localiza a referência, siga [a recuperação do pagamento existente](CORRECAO-VINCULO-PAY.md). O guia da [referência longa da 2.8.0](CORRECAO-REFERENCIA-PAY.md) se aplica somente àquela recusa de criação, antes do pagamento.
4. Abra **K8 Produtos → Configurações**. Em **K8 PAY**, preencha as três credenciais próprias do ambiente desejado: Client ID, Client Secret e chave AES. Os campos de Produção e Testes são separados. Campos vazios preservam as credenciais salvas; os segredos não aparecem preenchidos.
5. Em **Novas compras → Provedor de pagamento**, selecione **K8 PAY — checkout hospedado v2**, escolha o ambiente e salve. Preserve as credenciais COB para consultar o histórico. Não troque somente os endereços da API COB para PAY.
6. Use **Verificar conexão PAY** depois de salvar. A verificação consulta a configuração de parcelamento da conta; não cria pagamento e não altera os limites da K8. Ela confirma acesso à consulta, mas não substitui os testes dos três meios de pagamento.
7. Limpe o cache do site/CDN para carregar o formulário e os arquivos atualizados. Confira que o agendador do WordPress continua executando.

Não envie credenciais por chat. Cadastre-as diretamente no WordPress ou com as constantes indicadas abaixo.

## Conferir antes de liberar as vendas

No ambiente de testes da sua conta PAY, use um produto e alunos de teste sem compra ativa. Confira:

| Operação | Resultado esperado |
| --- | --- |
| Cartão com padrão 6x e faixa vigente de 12x | Checkout oferece até 12x, sujeito à habilitação da conta PAY. |
| Nova compra fora da faixa de 12x | Limite volta a 6x; o pedido anterior continua com 12x registrados. |
| Pix | Checkout oferece Pix e o pedido é confirmado após consulta ao PAY. |
| Boleto | A loja exige endereço completo do pagador; checkout emite boleto com o vencimento configurado. |
| Pagamento em análise, recusado ou pré-autorizado | Pedido não libera entrega. |
| Pagamento integral aprovado | Atualizar situação confirma o pedido e permite entrega. |
| Fechar a janela e continuar pagamento | Retoma o mesmo checkout, sem criar outro pedido. |
| Resposta perdida durante criação | Pedido fica em conferência; repetir o clique não cria outro checkout. |
| Pedido COB anterior | Continua sendo consultado no COB, mesmo com PAY selecionado. |

Depois da homologação, cadastre as credenciais PAY de produção, marque Produção e salve. Faça uma compra controlada de cada meio habilitado, conferindo no painel PAY a referência, o comprador, o valor e, no cartão, o limite de parcelas. Essas operações reais ficam sob responsabilidade do administrador; o desenvolvimento desta versão não acessou sua conta nem criou cobrança real.

## Pedidos existentes e tentativas em conferência

**O pedido #6 e as outras tentativas anteriores não são convertidos para PAY.** O plugin preserva a compra ativa do aluno para impedir duas cobranças. Antes de tentar novamente pelo PAY para o mesmo aluno e produto, confira no COB se existe cobrança ou link pagável. Encerre a operação existente ou, se não houve criação, registre o cancelamento da tentativa no plugin com o motivo e a confirmação de conferência exigida na tela. Depois disso a nova compra usa PAY.

Nos novos pedidos PAY, a aba Pedidos identifica provedor e ambiente e apresenta uma referência UUID de 36 caracteres. Os pedidos criados na 2.8.0 mantêm suas referências `K8PAY-ID-...`. Use **Consultar este pedido** para buscar a transação real pelo ID vinculado, pela referência ou pelos critérios do pedido. O ID do checkout não é usado como ID da transação. A opção **Vincular transação PAY existente** permite informar o ID da transação já aprovada para conferência pela API, quando a referência não for localizada. O retorno do navegador não confirma sozinho o pagamento: a confirmação depende da consulta feita pelo servidor.

Se a criação perder a resposta, não faça um novo pagamento por fora para contornar a trava. Localize a referência no painel PAY e consulte a situação no plugin. O plugin consegue reconhecer um pagamento pela referência mesmo sem ter recebido o identificador inicial do checkout.

## Cancelamento, estorno e entrega

Esta versão não solicita estornos. A documentação consultada descreve `CancelaTransacao` como estorno de transação aprovada e não oferece uma rota para encerrar checkout pendente. Por isso, para liberar outra compra, o operador precisa **conferir no PAY que não houve pagamento e que o checkout foi encerrado ou não pode mais ser pago**, informar motivo e marcar a confirmação explícita. Se precisar, confirme com a K8 como encerrar a operação. Cancelar somente no WordPress não encerra o checkout remoto.

A consulta é tentada antes da baixa manual: se encontrar pagamento aprovado, o pedido é atualizado para pago e o cancelamento é impedido. Quando ainda não existe transação consultável, a baixa depende da conferência humana declarada; a liberação e o resultado da consulta ficam no histórico. Não marque a confirmação enquanto houver checkout pagável.

Estornos devem ser feitos no PAY. Uma consulta posterior reconhece `Cancelada` ou `Estornado` e impede nova entrega. Pedidos PAY pagos voltam à fila de consulta após seis horas; use a consulta manual para refletir uma alteração imediatamente. Uma entrega já realizada continua visível e pode ser desfeita para registrar a devolução física ao estoque. O estorno financeiro não devolve o material ao estoque automaticamente.

Pedidos pendentes são consultados pelo WP-Cron a cada cinco minutos, em lotes de até 30, conforme a execução do agendador da hospedagem. A atualização pública e a consulta administrativa também verificam o pagamento. Novos checkouts da 2.8.2 incluem URL de confirmação assinada e página de retorno vinculadas ao pedido; ambas consultam a API antes de confirmar o pagamento. Checkouts anteriores mantêm suas URLs originais. Lembretes de carrinho consultam o PAY antes de enviar e não cobram novamente uma compra que essa consulta identifique como paga.

## Credenciais por configuração do servidor

Alternativa aos campos do painel, em `wp-config.php`:

```php
define('K8PROD_PAY_CLIENT_ID', 'CLIENT_ID_PAY_PRODUCAO');
define('K8PROD_PAY_CLIENT_SECRET', 'CLIENT_SECRET_PAY_PRODUCAO');
define('K8PROD_PAY_AES_KEY', 'AES_PAY_PRODUCAO');
define('K8PROD_PAY_SANDBOX_CLIENT_ID', 'CLIENT_ID_PAY_TESTES');
define('K8PROD_PAY_SANDBOX_CLIENT_SECRET', 'CLIENT_SECRET_PAY_TESTES');
define('K8PROD_PAY_SANDBOX_AES_KEY', 'AES_PAY_TESTES');
```

As constantes prevalecem sobre os campos correspondentes. As constantes e credenciais COB anteriores continuam sendo utilizadas no histórico COB.

## Validação e limites

Os testes usam WordPress, PHP e MariaDB locais, com respostas financeiras e e-mails simulados. O relatório está em `VALIDACAO.md`. O pacote não foi instalado nem homologado na sua conta K8. Os limites comerciais e a habilitação de cada meio de pagamento continuam dependendo da conta PAY; a migração não demonstra a causa do erro COB que menciona 7 quando o pedido enviou 12.

Não reduza a versão do plugin depois de criar pedidos PAY. Para pausar novas vendas pelo PAY, selecione COB nas configurações e mantenha a versão 2.8.2 para administrar o histórico dos dois provedores e a confirmação dos pagamentos.

Documentação oficial consultada em 10/11/2026: [contrato PAY / Swagger](https://pagamentos.meuk8.com.br/PagamentosAPI/swagger/docs/v1), [credenciais PAY](https://docs.meuk8.com.br/docs/gerar-credenciais-1) e [consulta por referência](https://docs.meuk8.com.br/reference/consultatransacao_consultatransacaoreferencia_consultatransacaoreferencia_post). A integração usa `POST /v2/CriaTransacaoCheckout`, `POST /api/ConsultaTransacao`, `POST /api/ConsultaTransacaoReferencia` e `POST /api/ConsultaTransacoes`; não usa a rota antiga de checkout marcada como obsoleta.
