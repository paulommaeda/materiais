# BMT Comercial — materiais e eventos para escolas

Site WordPress com um plugin unificado e um tema próprio. Cadastro e importação de alunos, catálogo público, compra por aluno, pagamentos K8, cupons, estoque e entregas ficam no mesmo sistema.

## Instalar

1. Faça backup do WordPress e do banco antes de migrar os plugins anteriores.
2. Baixe [k8-produtos-2.4.0.zip](https://raw.githubusercontent.com/paulommaeda/materiais/main/dist/k8-produtos-2.4.0.zip) e instale em **Plugins → Adicionar plugin → Enviar plugin**. Se já usa a versão 2.0.0, substitua por esta uma vez para habilitar o canal de atualização.
3. Baixe [bmt-educacional-1.0.0.zip](https://raw.githubusercontent.com/paulommaeda/materiais/main/dist/bmt-educacional-1.0.0.zip), instale em **Aparência → Temas → Adicionar tema → Enviar tema** e ative **BMT Educacional**.
4. Abra **K8 Produtos → Alunos**. A base anterior `lista_alunos_v4` e os vínculos de `sba_guardians` são reaproveitados. Importe o CSV quando precisar atualizar o cadastro.
5. Em **K8 Produtos → Produtos e preços**, abra ou crie um produto, selecione suas turmas e cadastre categoria, descrição, imagem opcional e preço normal ou valores por turma. Marque **Exibir este produto na home e no catálogo**.
6. Confira as credenciais e os endpoints em **K8 Produtos → Configurações**. Homologue uma cobrança na sua conta K8 antes de liberar pagamentos reais.

O tema assume a apresentação da página inicial ao ser ativado. Produtos ativos, publicados e com preço configurado aparecem automaticamente na home, com filtros para didáticos, paradidáticos e eventos. Cada produto abre sua página de compra sem exigir criar uma página ou colar um shortcode. As páginas antigas com shortcode continuam disponíveis.

Não instale o ZIP do repositório inteiro como plugin ou tema: os dois pacotes de `dist/` são instalados separadamente. Nenhuma instalação no seu site é realizada pelo simples envio do código ao GitHub.

## Atualizações pelo painel

Plugin e tema usam o sistema de atualização do WordPress, com os manifestos `updates/plugin.json` e `updates/theme.json` deste repositório público. As atualizações aparecem em **Painel → Atualizações**; no plugin também há um botão **Verificar atualizações** em Configurações.

A atualização automática pode ser ligada ou desligada pelo administrador na tela de plugins ou temas. O código não muda essa preferência. Cada pacote é conferido por SHA-256 antes de ser instalado. O repositório não exige token no WordPress enquanto permanecer público.

Para publicar uma próxima versão:

1. Faça as alterações e aumente o número da versão. No plugin, atualize tanto o cabeçalho quanto `K8PROD_VERSION`; no tema, o cabeçalho de `style.css`.
2. Atualize `CHANGELOG.md` e execute `python3 scripts/build.py`.
3. Faça commit e push do código, dos novos ZIPs e dos manifestos na branch `main`. Preserve os pacotes de versões já publicadas.
4. No WordPress, use **Verificar atualizações**. A consulta normal usa cache de até uma hora e segue a agenda do próprio WordPress.

Não basta editar arquivos PHP sem gerar os pacotes e aumentar a versão. Os sites só recebem uma atualização quando o manifesto anunciar uma versão maior. O canal depende de acesso HTTPS do servidor a `raw.githubusercontent.com`.

## Turmas e preços por produto

Em **K8 Produtos → Produtos e preços**, abra o produto que deseja configurar. No cadastro ficam **Turmas disponíveis para este produto**, **Preço normal deste produto** e **Preço por data deste produto**. Cada produto tem sua própria seleção, preços e faixas de promoção; não há liberação geral de turmas por preço.

1. Marque as turmas que poderão comprar. A lista usa as turmas da base de alunos; se necessário, informe outras turmas no campo próprio, uma por linha. É necessário escolher ao menos uma turma para salvar.
2. Cadastre o preço normal do produto. Esse valor só vale para as turmas selecionadas. Você também pode deixar o valor vazio e cadastrar preços específicos para as turmas desse produto.
3. Salve o produto e, na mesma tela, use **Preço por data deste produto**. A faixa pode abranger as turmas selecionadas nesse produto ou apenas uma delas.

Uma turma não selecionada não pode gerar nova compra, mesmo que exista um preço ou desconto antigo para ela. O catálogo calcula o menor preço somente entre as turmas liberadas com preço, e o dashboard usa essa seleção nas pendências e na adesão. Pedidos já emitidos permanecem acessíveis para acompanhamento e entrega, com seus valores históricos.

Turmas novas importadas pelo painel ou pela API aparecem como opções para seleção; não são liberadas automaticamente nos produtos. Na atualização da versão anterior, os produtos com preço normal recebem uma seleção explícita das turmas atuais da base e das turmas com preço próprio; produtos sem preço normal recebem somente suas turmas com preço. Revise essa seleção em cada produto. Se não houver turmas conhecidas nem preços por turma, o produto aguarda a seleção antes de aparecer no catálogo.

### Preço por data

No produto aberto, use **Preço por data deste produto**. Cadastre o nome, o tipo (percentual ou preço promocional), a data inicial opcional e a data final. Você pode criar várias faixas e restringi-las a uma turma.

Durante a vigência, o catálogo e a compra mostram o preço original riscado, o valor menor e **“Até DD/MM/AAAA”**. O último dia é incluído por inteiro no fuso configurado em **Configurações → Geral** do WordPress. Depois, o preço normal volta automaticamente. Quando houver mais de uma faixa válida, vale o menor preço; as faixas não são somadas.

A opção **Permitir acumular com cupom** começa desmarcada. Sem ela, aplica-se o melhor preço entre a promoção e o cupom, e um cupom que não reduzir o valor não é consumido. Com a opção marcada, o cupom é aplicado sobre o preço promocional.

O valor é conferido no servidor ao gerar o pedido. Se a promoção vencer ou o preço mudar depois da consulta, o comprador precisa conferir e confirmar o novo valor. Pedidos já gerados conservam os valores registrados, mesmo após o fim da campanha. O catálogo consulta novamente os preços ao abrir ou voltar à página e nas mudanças de período; após atualizar o plugin, limpe o cache da home/CDN para carregar os novos recursos.

## Sistema de alunos incorporado

A integração foi feita a partir do **PHP Sistema de Alunos — Busca Direta 4.1.0** fornecido por Paulo Maeda. O guia antigo anexado descrevia SMS/NX de uma versão anterior; o código atual não usa OTP, SMS, NX nem consulta financeira externa, e esse funcionamento foi mantido.

- Importação CSV com cabeçalhos em qualquer ordem, colunas opcionais, separadores e codificações do Excel; [modelo](k8-produtos/assets/modelo-alunos.csv). O formato antigo de dez colunas continua aceito.
- Validação do arquivo sem gravar, limite de upload real da hospedagem e mensagens específicas de erro.
- API autenticada de importação por JSON ou CSV: **POST `/wp-json/k8-produtos/v1/alunos/importar`**, com Senha de aplicação de administrador. A URL do site aparece no menu Alunos; consulte os [exemplos e o contrato da API](docs/API-ALUNOS.md).
- RA repetido consolida vários responsáveis. O terceiro responsável e os seguintes também podem consultar e comprar para o aluno.
- CPF com dígito inválido, colunas incorretas ou dados conflitantes de um mesmo RA interrompem a importação antes de alterar a base.
- Importação incremental substitui todos os vínculos de cada RA enviado. A opção de substituição total remove alunos e vínculos ausentes no CSV, preservando pedidos e entregas históricos.
- A gravação é transacional. Uma importação bem-sucedida encerra os contextos temporários de compra, exigindo nova consulta por CPF.
- A base já existente é mantida; vínculos adicionais não são reduzidos às duas colunas antigas.

Os antigos plugins de pagamento, materiais, dashboard e o sistema de alunos são desativados ao ativar o plugin unificado. Remova também cópias avulsas desses códigos em temas, mu-plugins ou snippets. Não reative os plugins antigos sobre a base unificada.

A versão 2.3.0 também corrige a ordem de registro do submenu Alunos, que podia fazer o WordPress negar acesso à página de importação.

## Shortcodes e administração

| Uso | Shortcode |
| --- | --- |
| Catálogo completo | `[k8_catalogo]` |
| Somente eventos | `[k8_catalogo categoria="evento"]` |
| Fluxo completo de um produto | `[k8_produto id="1"]` |
| Dashboard com seletor de produto | `[k8_dashboard]` |
| Painel iniciando nas entregas | `[k8_dashboard produto="1" aba="deliveries"]` |
| Busca antiga vinculada a um produto | `[buscar_aluno produto="1"]` |

Os IDs são exemplos. Use os IDs exibidos no painel. `[buscar_aluno]` sem atributo abre o produto legado quando ele existir; sem produto legado, apresenta o catálogo. Não há redirecionamento para uma página fixa nem CPF/RA em parâmetros de URL.

No dashboard, selecione o produto para administrar pedidos, estoque, entregas, cupons e histórico. Produtos podem ter entrega única ou duas etapas/semestres. A assinatura registra quem recebeu; desfazer exige justificativa e estorna as quantidades da entrega. A venda não reserva estoque.

## Identidade e conteúdo

O tema usa a logo BMT fornecida, com azul, ciano e tons neutros. Em **Aparência → Personalizar → BMT — Página inicial**, é possível alterar o título, a apresentação e os canais de contato. Logo e menu também são configuráveis no Personalizador.

Nenhum produto, aluno, estoque, telefone ou e-mail fictício é criado na instalação. A home mostra um estado vazio quando não há ofertas. A logo original está em `bmt-educacional/assets/logo-bmt.webp`.

## Requisitos e validação

WordPress 6.2+, PHP 7.4+ com OpenSSL, MySQL/MariaDB com InnoDB e GET_LOCK, e permissão de criar/alterar tabelas. Ative por site em redes multisite. Exclua páginas de compra e respostas de `admin-ajax.php` do cache de página/CDN.

Confira o [guia do plugin](k8-produtos/LEIA-ME.md), o [histórico](CHANGELOG.md) e o [relatório de validação](docs/VALIDACAO.md). Os testes usam bancos descartáveis cujo nome termina em `_test`; nunca execute as fixtures em produção.

O contrato K8 foi mantido do cliente original. Consulta, cancelamento, sandbox e URL do checkout precisam ser confirmados na documentação da conta K8. Testes com respostas simuladas não substituem essa homologação.

Referências do WordPress: [Update URI de plugins](https://developer.wordpress.org/reference/hooks/update_plugins_hostname/), [Update URI de temas](https://developer.wordpress.org/reference/hooks/update_themes_hostname/) e [tabelas com dbDelta](https://developer.wordpress.org/plugins/creating-tables-with-plugins/).
