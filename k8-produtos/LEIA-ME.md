# K8 Produtos, Alunos, Cobranças e Entregas — 2.2.0

Plugin único criado a partir dos cinco arquivos de Paulo Maeda. Reúne cadastro de produtos, página pública de compra, cobrança K8, cupons, estoque, entregas com assinatura e painel por produto. Não exige WooCommerce.

## Site BMT e alunos incorporados

A versão 2.1.0 incorpora o Sistema de Alunos — Busca Direta 4.1.0. A base `lista_alunos_v4` e os responsáveis de `sba_guardians` são mantidos. O menu **K8 Produtos → Alunos** permite importar o CSV de dez colunas e consultar a base. RAs repetidos consolidam responsáveis adicionais; todos os vínculos válidos participam da consulta por CPF. Não há OTP, SMS ou NX nesta versão.

O tema instalável **BMT Educacional** apresenta automaticamente os produtos ativos, publicados e com preço. Categoria, imagem e ordem de exibição são configuradas no cadastro. O link de compra é gerado para cada produto; o shortcode continua disponível.

Plugin e tema consultam atualizações no repositório público `paulommaeda/materiais`. A versão 2.1.0 precisa ser instalada uma vez para acrescentar esse canal à antiga versão 2.0.0. Novas versões aparecem em **Painel → Atualizações**, com conferência SHA-256 do ZIP. A preferência de atualização automática fica sob controle do administrador.

## Instalação e migração

1. Faça um backup do banco e teste o ZIP em uma cópia do seu WordPress.
2. Em **Plugins → Adicionar plugin → Enviar plugin**, envie `k8-produtos-2.2.0.zip` e ative.
3. A ativação identifica e desativa os plugins antigos de pagamento, dashboard, materiais e Sistema de Alunos — Busca Direta pelos nomes exatos dos cabeçalhos. O arquivo do dashboard de estoque era um complemento e agora está incorporado. Não mantenha cópias dos arquivos antigos executando em tema, mu-plugin ou gerenciador de snippets.
4. Se a tabela antiga de pedidos existir, os registros atuais são vinculados ao produto **Material Didático — legado**. Abra **K8 Produtos → Produtos e preços** para renomeá-lo, por exemplo, para “Material Didático 2027”.
5. Em **K8 Produtos → Configurações**, confira o mapeamento de alunos e os endpoints K8. As credenciais salvas pelos plugins anteriores são reaproveitadas.
6. Abra **K8 Produtos → Dashboard**, selecione o produto e confira pedidos, saldos e entregas antes de publicar novas páginas.

A atualização reaproveita as tabelas `PREFIXO_k8p_*`, preservando IDs e os dados existentes. Acrescenta a tabela `k8p_produtos` e o vínculo `produto_id`. Os índices únicos de turma e código de cupom passam a incluir o produto. Histórico de estoque e assinaturas são preservados; uma entrega antiga registrada somente na coluna `pedidos.entregue` é convertida em entrega da primeira etapa quando não existir registro correspondente.

Os saldos existentes são mantidos exatamente como estão. O erro de duplicação do estoque inicial foi corrigido para **novos cadastros**. Saldos antigos que já tenham sido duplicados precisam de ajuste após conferência física; o plugin não tenta adivinhar o saldo correto.

Não reative os plugins antigos depois da migração: eles não conhecem o vínculo de produto e podem misturar dados ou tentar recriar índices globais. Para desfazer a implantação e voltar ao modelo anterior, restaure o backup completo. Desativar ou excluir este plugin pelo WordPress não apaga suas tabelas.

## Descontos por data

Abra o produto em **K8 Produtos → Produtos e preços** e use o bloco **Descontos por data**. É possível informar um percentual ou um preço promocional, data de início opcional, data final obrigatória e turma opcional. Deixe o início vazio para aplicar imediatamente. O prazo vale até o fim do dia informado, conforme o fuso do WordPress.

O catálogo e a compra exibem o preço original riscado, o preço com desconto e a validade. Faixas sobrepostas usam o menor preço válido, sem somá-las. O preço por turma continua prevalecendo sobre o preço padrão antes do cálculo do desconto. O catálogo mantém o preço original e o promocional sempre pareados à mesma turma.

A acumulação com cupons só ocorre se você marcar essa opção no desconto. Sem acumulação, o sistema escolhe o melhor preço entre promoção e cupom, sem consumir um cupom que não melhorar a oferta. Um preço promocional nunca aumenta o preço normal. A opção de preço final zero permite registrar uma cortesia por período.

Novos pedidos conferem a condição no servidor. Se o preço mudar após a consulta, a cobrança não é criada até a pessoa conferir e confirmar o valor atualizado. Pedidos já gerados mantêm sua condição original; a data da campanha não altera uma cobrança já emitida. A promoção e os valores original/promocional ficam registrados no pedido.

Após instalar esta versão, limpe o cache da home/CDN para carregar a atualização do catálogo. As páginas abertas consultam os preços novamente, inclusive ao voltar para a aba e nas mudanças de período.

## Criar e publicar produtos

1. Abra **K8 Produtos → Produtos e preços** e clique em **Novo produto**.
2. Informe nome, descrição, preço padrão, prazo do vencimento e modelo de entrega: única ou duas etapas/semestres.
3. Cadastre os valores por turma quando necessário. O preço por turma substitui o preço padrão. Se deixar o padrão vazio, apenas as turmas com preço cadastrado poderão comprar.
4. Copie o shortcode exibido no cadastro para um bloco **Shortcode** de uma página do WordPress.

Exemplos, substituindo os IDs pelos exibidos no seu painel:

| Publicação | Shortcode |
| --- | --- |
| Compra do produto 1 | `[k8_produto id="1"]` |
| Compra do produto 2 | `[k8_produto id="2"]` |
| Painel com seletor de produtos | `[k8_dashboard]` |
| Painel iniciando no produto 2 | `[k8_dashboard produto="2"]` |
| Painel iniciando nas entregas | `[k8_dashboard produto="2" aba="deliveries"]` |
| Painel iniciando no estoque | `[k8_dashboard produto="2" aba="stock"]` |

O fluxo público completo é **CPF do responsável → seleção do aluno → preço e cupom → Pix, boleto, cartão ou cortesia → acompanhamento do pagamento e da entrega**. O pagamento é realizado no checkout hospedado da K8. O plugin não coleta dados de cartão.

O mesmo aluno pode comprar produtos diferentes. Para cada combinação **produto + RA**, existe somente uma compra ativa. Trocar o método ou repetir o clique recupera o pedido existente. Um pedido inadimplente continua ativo, pois sua cobrança ainda pode ser paga. Uma nova compra só é liberada após o cancelamento confirmado da anterior. Para outra campanha ou ano letivo, crie outro produto.

Os shortcodes antigos continuam reconhecidos:

| Shortcode antigo | Comportamento |
| --- | --- |
| `[pagamento_material_didatico]` | Fluxo completo do produto legado |
| `[dashboard_material_didatico]` | Dashboard com seletor |
| `[entrega_material_didatico]` | Aba de entregas com seletor |
| `[k8pm_dashboard]` | Aba de estoque com seletor |

A identificação agora acontece no próprio shortcode. Parâmetros de URL com RA, turma, CPF ou valor não são usados para autorizar compras.

## Base de alunos

A busca inicial usa a base de alunos incorporada ao plugin. Sua atualização pode ser feita pelo importador de CSV no menu Alunos. O mapeamento abaixo também permite conectar uma base externa; a importação integrada grava sempre nas tabelas padrão. Se optar por uma base externa, mantenha o mapeamento coerente com essa escolha.

Mapeamento inicial:

| Campo | Padrão |
| --- | --- |
| Tabela, sem prefixo WordPress | `lista_alunos_v4` |
| RA | `ra` |
| Aluno | `nome_aluno` |
| Turma | `turma` |
| CPF do primeiro responsável | `cpf_resp1` |
| Nome do primeiro responsável | `nome_resp1` |
| CPF do segundo responsável | `cpf_resp2` |
| Nome do segundo responsável | `nome_resp2` |

Todos os campos podem ser mapeados em Configurações. Se a sua base tiver apenas `cpf_resp` e `nome_resp`, use essas colunas como responsável 1 e deixe o responsável 2 vazio. O nome da turma precisa corresponder ao utilizado nos preços e materiais. A consulta aceita CPF armazenado com ou sem pontuação e valida seus dígitos verificadores.

O vínculo CPF/RA/turma é consultado novamente no servidor antes de operações públicas. Tokens temporários são vinculados ao produto e expiram em 30 minutos. A identificação por CPF conserva a lógica do fluxo escolar anterior; ela não oferece autenticação adicional por código ou senha. Evite cache de página/CDN nas páginas de compra e no painel. O plugin usa respostas sem cache e busca o nonce ao abrir a página, mas regras externas de cache precisam respeitar as exclusões.

## Painel, estoque e entregas

Selecione o produto no topo do dashboard. As abas são:

- **Visão geral:** alunos pagos, valor confirmado, pedidos pendentes, entregas realizadas, materiais para reposição, adesão por turma e alunos ainda sem pagamento confirmado.
- **Pedidos:** filtros por aluno/RA, turma e data; consulta de pagamentos; cancelamento de pedidos pendentes com motivo; conciliação de tentativas em conferência.
- **Estoque:** materiais próprios do produto, restrição por turma, período, unidades por aluno, saldo e alerta de reposição. É possível ativar e desativar materiais mantendo o histórico.
- **Entregas:** pedidos pagos, filtro por turma/aluno, primeira ou segunda etapa, assinatura em tela e nome de quem recebeu.
- **Cupons:** percentual, turma, prazo, limite e ativação, sempre por produto. O mesmo código pode existir em produtos diferentes. Atualizar um código preserva seu contador. Cupom de 100% cria cortesia sem chamar a K8.
- **Histórico:** entradas, saídas, entregas, assinaturas, estornos, conciliações e cancelamentos.

A venda não reserva estoque. A baixa ocorre na entrega física, de acordo com a quantidade por aluno de cada material aplicável à turma e etapa. A confirmação entrega **todo o conjunto configurado para a etapa**; não há retirada parcial nesta versão. Sem materiais cadastrados, é possível registrar apenas o recebimento do produto.

“Anual”, primeiro semestre e primeiro/segundo bimestres pertencem à primeira etapa. Segundo semestre e terceiro/quarto bimestres pertencem à segunda etapa. O produto de entrega única aceita somente materiais da primeira etapa.

Por padrão, produtos novos impedem saídas com saldo insuficiente. O produto legado permite saldo negativo para conservar o comportamento anterior; essa opção pode ser alterada no cadastro. A baixa, o registro da entrega e o histórico são transacionais: se um item falhar, os demais não são baixados. Repetir a confirmação não repete a baixa. Desfazer exige motivo e estorna as quantidades registradas na entrega, mesmo que o cadastro do material tenha mudado.

## K8 e pagamentos

O contrato foi mantido do cliente enviado: autenticação em `/token`, AES-128-CBC, busca/criação de cliente e cobrança avulsa em `/api/Cobranca`. Houve uma correção adicional: uma lista vazia de clientes passa a ser aceita, permitindo cadastrar um responsável novo.

As credenciais podem ser mantidas nas opções já existentes ou definidas no `wp-config.php`:

```php
define('K8PROD_CLIENT_ID', 'SEU_CLIENT_ID');
define('K8PROD_CLIENT_SECRET', 'SEU_CLIENT_SECRET');
define('K8PROD_AES_KEY', 'SUA_CHAVE_AES');
```

As constantes prevalecem sobre as opções do painel. Os campos de credenciais não expõem os valores salvos; deixe-os vazios para manter os valores atuais.

Os arquivos originais indicam expressamente que **o endpoint de consulta, o de cancelamento e o caminho do sandbox não haviam sido confirmados no Swagger**. Esses padrões foram preservados e tornados configuráveis. A URL de checkout do sandbox também pode ser definida separadamente. Confirme-os com a K8 e faça uma cobrança de homologação antes de liberar o fluxo em produção. O produto foi testado localmente com respostas K8 simuladas; não houve conexão com a conta bancária nem criação de cobranças reais.

Os pagamentos pendentes e inadimplentes são consultados pelo WP-Cron a cada cinco minutos, até 30 por lote, priorizando os que estão há mais tempo sem consulta. A execução do lote para após aproximadamente 20 segundos entre consultas. Há também consulta manual e atualização solicitada pelo comprador. A frequência efetiva depende do WP-Cron do site; em sites sem acesso regular, configure o agendador da hospedagem para executar o cron do WordPress.

Em caso de falha após iniciar a criação remota, o pedido fica **em conferência** e não libera outra cobrança automaticamente. Na aba Pedidos:

1. Localize a operação no painel K8 pela descrição com o número do pedido ou pela referência `K8P-ID_DO_PEDIDO`.
2. Se a cobrança existir, use **Vincular cobrança existente**. A consulta precisa confirmar ID, cliente, valor e estado.
3. Se não existir cobrança ativa, a secretaria pode cancelar a tentativa informando motivo e confirmando a conferência.

Pedidos pagos permanecem no histórico. Reembolso deve ser tratado na K8; esta versão não faz reembolso nem baixa automática de pedidos reembolsados. Cancelamento de pedido pendente é enviado à K8 e só libera nova compra após resposta de sucesso. O plugin não apaga vendas nem históricos de entrega.

## Acesso administrativo

Administradores recebem a capacidade `k8_manage_products`. Outras contas precisam receber essa capacidade para acessar o painel; estar logado, por si só, não concede acesso. Criar produtos e alterar credenciais exige `manage_options`.

Para restringir um operador que já tenha `k8_manage_products` a produtos determinados, uma integração administrativa pode definir o metadado de usuário `k8prod_product_ids` como array de IDs. Sem esse metadado, o operador acessa todos os produtos. Array vazio não autoriza nenhum. Também está disponível o filtro `k8prod_can_manage_product`.

```php
// Executar em contexto administrativo próprio, trocando os IDs pelos reais.
$operator = get_user_by('id', 25);
if ($operator) {
    $operator->add_cap('k8_manage_products');
    update_user_meta(25, 'k8prod_product_ids', [1, 3]);
}
```

## Requisitos, arquivos e verificação

WordPress 6.2+, PHP 7.4+ com OpenSSL, MySQL/MariaDB com InnoDB e suporte a `GET_LOCK`, e permissão para criar e alterar tabelas. Ativação em rede multisite não é suportada; ative individualmente em cada site. Não há dependência de Chart.js, Composer ou CDN.

O pacote contém o arquivo principal `k8-produtos.php`, módulos em `includes/`, recursos locais em `assets/`, este guia e testes em `tests/`.

Os testes de integração executam em WordPress e MariaDB reais, com a K8 simulada no limite HTTP. Cobrem instalação limpa, migração a partir dos plugins fornecidos, conservação de IDs/saldos/cupons/assinaturas, mesmo RA em produtos distintos, proteção contra compras duplicadas, limites de cupom, transações de estoque, assinatura inválida, CPF de ambos os responsáveis, contexto por produto, conciliação, timeout, permissões e renderização de todas as abas.

Para executar os testes em um ambiente descartável, use um banco cujo nome termine em `_test`. O script substitui as tabelas K8 e a tabela de alunos desse ambiente:

```bash
K8PROD_TESTING=1 \
K8PROD_WP_LOAD=/caminho/wordpress/wp-load.php \
K8PROD_LEGACY_DIR=/caminho/arquivos-antigos \
php wp-content/plugins/k8-produtos/tests/integration.php
```

O usuário administrador da fixture se chama `k8admin`. Para um ambiente de testes próprio, crie esse usuário antes de executar. `K8PROD_LEGACY_DIR` é opcional; quando informado, deve conter os arquivos originais fornecidos. Nunca execute essa fixture no site de produção.

Referências técnicas do WordPress: [tabelas e dbDelta](https://developer.wordpress.org/plugins/creating-tables-with-plugins/), [nonces e permissões](https://developer.wordpress.org/apis/security/nonces/) e [HTTP API](https://developer.wordpress.org/reference/functions/wp_remote_request/).
