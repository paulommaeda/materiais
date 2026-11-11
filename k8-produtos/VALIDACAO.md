# Validação

## Versão 2.8.3 — checkout e transação distintos

A documentação PAY de callback fornece Identificador e IdentificadorCheckout como campos separados. Os testes agora proíbem consultar o ID do checkout como transação e usam IDs distintos em todos os meios. A referência e o número podem não estar disponíveis, desde que a descrição exata do pedido e os dados financeiros permitam a conferência.

Passaram 130 verificações PAY, incluindo R$ 0,23 nos três métodos, descoberta por período e método, confirmação individual do ID encontrado, JSON/GET/callback cifrado, descrição congelada, vínculo de pedido anterior, campos divergentes identificados sem imprimir CPF, erro de API mascarado, múltiplas aprovações e tentativa cancelada seguida de aprovação em outro ID. São simulações financeiras em WordPress e MariaDB descartáveis, sem dados do pagador real nem acesso à conta K8 do usuário.

A fixture pay-checkout-upgrade.php reproduz o erro de vínculo da 2.8.2 com CPF e valor corretos, número/referência ausentes e descrição exata. A verificação após atualizar deve preservar o pedido e recuperar seu pagamento sem criar outra cobrança.


## Versão 2.8.2 — confirmação e retorno PAY

A versão 2.8.1 foi executada em WordPress e banco descartáveis com uma transação aprovada, consulta por referência recusada e consulta por ID disponível. Reproduziu-se a falha de referência não localizada. O pacote original usado nessa reprodução teve seu conteúdo conferido pelo SHA-256 da entrega anterior.

Na correção passaram 109 verificações PAY, incluindo consulta por ID com referência ausente, identificador final distinto, vínculo manual validado, divergência de número do pedido/CPF/valor pago/método/ID, assinatura inválida ou de outro pedido, aprovação forjada no corpo da notificação, consulta autenticada antes de confirmar, notificações repetidas sem criar checkout e página de retorno sem dados pessoais.

Foram executadas novamente as 389 verificações das funções anteriores do plugin. A fixture `pay-return-upgrade.php` testa a atualização da 2.8.1 com um cartão já pago e pedido local pendente, preservando a tentativa e recuperando a aprovação por ID sem outra criação remota.

Todos os testes financeiros interceptam a HTTP API do WordPress e os e-mails são simulados. O pagamento real foi informado pelo usuário; o desenvolvimento desta correção não acessou a conta K8 e não confirma que a operação específica já foi conciliada em produção. A página e as notificações novas não substituem a conferência pela API.

## Versão 2.8.1 — referência PAY

Correção preparada após a resposta de produção limitar `Referencia` a 36 caracteres. A referência do pedido #10 enviada pela versão 2.8.0 possuía 41. Esse limite não era verificado pelos testes anteriores e não aparece como `maxLength` no campo do Swagger consultado em 10/11/2026.

Foi acrescentada ao simulador a recusa com HTTP 200 e `Retorno=ERRO` para referência maior que 36 caracteres. A versão anterior falhou com a mesma mensagem da produção; depois da correção, passaram 90 verificações de integração PAY, incluindo referências UUID de 36 caracteres nos três meios, validação local antes de autenticar/criar checkout, unicidade, crescimento do número de pedido e preservação do formato antigo nas consultas.

Também passaram novamente 105 verificações do núcleo e das regras de pagamento/parcelamento, 108 verificações JavaScript e a sintaxe dos 35 arquivos PHP e dos dois arquivos JavaScript. As demais verificações da 2.8.0 abaixo são histórico, não uma nova execução completa nesta correção.

A fixture `pay-reference-upgrade.php` valida a passagem da 2.8.0 para 2.8.1 em processos separados, preservando todos os campos de pedidos PAY e credenciais, sem repetir a criação da tentativa #10, e consultando pagamento anterior pela referência antiga integral. As respostas PAY são simuladas; não houve chamada à conta do usuário nem pagamento real.

## Versão 2.8.0 — PAY

Conferência local em 10/11/2026 com WordPress 7.1.4, PHP 8.5.12 e MariaDB 10.11.19. As respostas financeiras são simuladas na HTTP API do WordPress; todos os e-mails são interceptados. Nenhuma credencial ou dado de produção é usado.

- 389 verificações das funções existentes: núcleo (50), descontos (37), turmas (32), pagamentos/parcelas (55), visibilidade/exclusão (37), e-mails (107), importação/API de alunos (44) e site/atualizações (27).
- 82 verificações de integração PAY: credenciais separadas, ambientes, checkout v2 dos três métodos, 12x por data sobre padrão 6x, endereço de boleto, referência única, timeout sem repetição, conferência de comprador/valor/método/referência, análise e pré-autorização sem entrega, estorno, estoque, e-mails e histórico COB.
- 27 verificações de atualização do código original 2.7.0 para 2.8.0 em processos separados, preservando todos os campos dos pedidos, produtos, cupons, estoque, movimentos, entregas, assinaturas, histórico, alunos e credenciais; instalação repetida sem mudar o ambiente histórico.
- 108 verificações JavaScript com DOM, janelas e comunicação simulados, incluindo endereço exigido somente para boleto PAY.
- Sintaxe dos 34 arquivos PHP e dos dois arquivos JavaScript conferida.

As fixtures estão em `tests/`. `pay-integration.php` usa banco vazio terminado em `_test`; `pay-upgrade.php` exige uma fase `seed` carregada com a versão original 2.7.0 e outra `verify` em novo processo carregando 2.8.0. Não execute as fixtures em produção.

Estes testes não equivalem à homologação da conta PAY, à liquidação bancária real, à entrega pelo SMTP ou à navegação em navegador real. A instalação e a validação dos três meios no ambiente do usuário continuam pendentes. Consulte `MIGRACAO-PAY.md` para os passos e os limites do cancelamento manual de checkout.


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
