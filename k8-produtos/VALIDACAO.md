# Validação

## Versão 2.7.0

Foram aprovadas **318 verificações de integração** em WordPress 7.1.4, PHP 8.3.6 e MariaDB 10.11.19 no GitHub Actions, incluindo 107 de e-mails e 211 do núcleo, descontos, turmas, pagamentos/parcelas e ocultação/exclusão. Também passaram **97 verificações JavaScript** de abertura da K8 e contato no checkout.

Os e-mails são interceptados por pre_wp_mail. A K8 é simulada no limite HTTP. Os casos cobrem captura antes do pedido, preferência de lembrete, confirmação após pagamento, personalização/escape, ausência de envios retroativos, consulta antes da recuperação, cancelamento dos lembretes, correção de contato, prazos, falhas, até três tentativas, processamento interrompido e permissões das rotas AJAX. Não houve cobrança, mensagem real, teste de entrega pelo SMTP do usuário nem navegação em navegador real nesta versão.

Os resultados e comandos estão em docs/VALIDACAO.md e docs/EMAILS-POR-PRODUTO.md no repositório. As fixtures só executam com K8PROD_TESTING=1 em bancos descartáveis terminados em _test. Nunca use um banco de produção.

## Histórico

O plugin é verificado em WordPress 7.1.4, PHP 8.3.6 e MariaDB 10.11.14. As fixtures cobrem migração, pedidos por produto, cupons, estoque, entregas, importação CSV e API autenticada, vínculos adicionais de responsáveis, catálogo, descontos por data, seleção de turmas por produto, formas de pagamento, parcelamento por data e canal de atualização. Na versão 2.6.0 passaram 282 verificações de integração, além dos testes HTTP e de navegador com o formulário real e Senhas de aplicação. Os testes só podem ser executados em bancos descartáveis cujo nome termine em `_test`.

Os testes de K8 e metadados de atualização usam respostas simuladas, além das verificações de instalação e navegador descritas no relatório `docs/VALIDACAO.md` do repositório. Não houve cobrança nem envio de mensagem real.

A comunicação com a conta K8 precisa de homologação no ambiente do responsável pela instalação.

A integração nova de cartão usa os campos documentados de links K8 e foi testada com criação, leitura, confirmação de pagamento, cancelamento e conciliação simulados. Homologue no sandbox da sua conta K8 antes de liberar o fluxo novo em produção.

A versão 2.6.0 também valida ocultação pública total, exclusão física de faixas com preservação de pedidos anteriores e preço normal único por produto, com valores antigos por turma fora do cálculo.

Na versão 2.6.1, 77 verificações JavaScript com DOM, janelas e RPC simulados cobrem abertura antes da requisição, Pix/boleto/cartão, bloqueio, fechamento pelo comprador, falhas, cortesia, retorno à loja e cliques repetidos. O código anterior reprova o teste de regressão. Não houve nova execução de WordPress nem de navegador real nesta versão; o ambiente local estava indisponível. O workflow do GitHub também executa os testes JavaScript em Node.js e a conferência de sintaxe PHP antes de gerar os pacotes. Os resultados da versão 2.6.0 acima são históricos.

Para executar: `node k8-produtos/tests/payment-window.js` (Node.js 18+).
