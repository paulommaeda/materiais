# Alterações

## Plugin 2.5.0

- Escolha de Pix, boleto e cartão de crédito no cadastro de cada produto, com validação no servidor e exibição somente das opções aceitas.
- Limite padrão de 1 a 12 parcelas no cartão e faixas por data independentes por produto, com último dia inteiro e rejeição de sobreposição.
- Catálogo e compra mostram o parcelamento vigente. Mudança de preço, métodos ou parcelas exige nova confirmação antes de gerar o pedido.
- Novo cartão usa link K8 individual de valor fixo, não reutilizável, com os campos documentados `ParcelaFixa`/`ParcelaFixaQtd`; leitura de retorno confere as condições antes de redirecionar.
- Pedido registra limite e período aplicados. Atualizar o produto não modifica condições de pedidos já emitidos, inclusive os cartões anteriores à atualização.
- Consulta de link e cobrança para confirmar pagamento, integração com WP-Cron, conciliação de falhas e cancelamento com desativação de link e conferência de transações pendentes.
- Guia de configuração e homologação em `docs/PAGAMENTO-POR-PRODUTO.md`.

## Plugin 2.4.0

- Seleção explícita das turmas disponíveis no cadastro de cada produto; novos produtos exigem pelo menos uma turma.
- Editor reúne cadastro, turmas, preço normal, preço por data, publicação e preços específicos por turma no contexto do produto aberto.
- O preço normal não libera novas turmas. Consulta por CPF, cotação e checkout conferem a seleção no servidor, inclusive quando o comprador manteve a página aberta.
- Preços e promoções para turmas não selecionadas ficam sem efeito; cadastro de novos preços e faixas só aceita as turmas do produto.
- Catálogo considera apenas preços de turmas liberadas e oculta produtos sem combinação válida de turma/preço. Dashboard filtra adesão e pendências pelo produto.
- Importar uma turma nova não a libera automaticamente nos produtos. A lista anterior é convertida em seleção explícita na atualização, sem repor turmas removidas depois.
- Pedidos e entregas anteriores continuam acessíveis, preservando valores e histórico.

## Plugin 2.3.0

- Corrige o registro da página Alunos após o menu pai, eliminando a falha de acesso ao importador no painel.
- Importação por cabeçalhos, com ordem variável, nomes usuais em português e colunas opcionais; mantém o CSV legado de dez colunas.
- Aceita UTF-16 e tabulação do Excel, diretiva `sep=;` e colunas opcionais vazias no final.
- Mostra o limite real de upload da hospedagem e trata erros de upload e POST acima do limite sem perder a página de retorno.
- Adiciona validação do arquivo sem importar e avisos para alunos sem vínculo por CPF ou com busca configurada em base externa.
- Nova API REST autenticada para JSON e CSV, com Senhas de aplicação, validação sem gravação, atualização por RA e substituição total explícita.
- API aceita vários responsáveis, preserva IDs na atualização, registra resumo no painel e mantém a gravação transacional.
- Exemplos de integração, formatos, limites e respostas em `docs/API-ALUNOS.md`.

## Plugin 2.2.0

- Cadastro de faixas de desconto por data, em percentual ou preço promocional, com início opcional, término inclusive e restrição por turma.
- Preço original riscado, preço com desconto e data limite no catálogo e no fluxo de compra.
- Cálculo no servidor e nova confirmação quando o preço mudar ou a promoção terminar com a página aberta.
- Controle explícito de acumulação com cupons; sem acumulação, prevalece o melhor preço sem consumir cupom desnecessário.
- Atualização dos preços no catálogo aberto, incluindo mudanças de período, e preservação da condição gravada em pedidos existentes.
- Registro da promoção e dos valores original e promocional no pedido.

## Plugin 2.1.0

- Incorpora o Sistema de Alunos — Busca Direta 4.1.0, com importação CSV, consolidação de responsáveis e reaproveitamento das tabelas existentes.
- Passa a usar todos os vínculos RA/CPF na consulta e na autorização de compra, incluindo responsáveis adicionais.
- Adiciona categoria, imagem, publicação na home e ordem de exibição por produto.
- Oferece catálogo público e páginas de compra geradas automaticamente, além dos shortcodes anteriores.
- Adiciona canal de atualizações pelo GitHub com conferência SHA-256 dos pacotes e consulta pelo painel WordPress.
- Preserva pedidos, cupons, saldos, assinaturas e histórico da versão 2.0.0.

## Tema BMT Educacional 1.0.0

- Logo BMT original e identidade em azul, ciano e tons neutros.
- Home responsiva com apresentação, categorias, produtos reais do plugin e orientação de compra.
- Catálogo filtrável, imagens opcionais e integração com o fluxo completo por produto.
- Logo, textos, menu e contatos configuráveis; funcionamento sem dependência de fontes ou bibliotecas externas.
- Atualizações pelo mesmo repositório, inclusive quando o plugin estiver desativado e o tema ativo.
