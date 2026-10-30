# Validação — versão 2.5.0 — 30/10/2026

Ambiente local descartável: WordPress 7.1.4, PHP 8.3.6, MariaDB 10.11.14 e Chromium headless 151. Nenhum acesso ao WordPress de produção.

- **245 verificações de integração aprovadas:** 55 novas de formas de pagamento/parcelamento e as 190 verificações anteriores do núcleo, alunos, catálogo, atualização, descontos e turmas por produto.
- Formas aceitas independentes por produto, quantidade de parcelas válida, início/fim inclusive no fuso do site, retorno ao padrão, rejeição de datas inválidas e de faixas sobrepostas, incluindo reativação de faixa conflitante.
- Cotação associa preço com desconto, métodos habilitados e limite atual. Alterar apenas parcelas já invalida a cotação; método desabilitado ou cotação antiga não deixa pedido parcial nem inicia chamada financeira.
- Mock K8 decodifica o corpo AES para conferir `FormasPagamento=Cartao`, valor com desconto, `TipoValor=fixo`, `LinkReutilizavel=false`, `ParcelaFixa=true` e `ParcelaFixaQtd` vigente. GET de retorno também é comparado ao pedido antes do redirecionamento.
- Confirmação do cartão exige link correspondente e cobrança paga com valor correto. Testes cobrem URL inesperada, link de outro pedido, limite divergente, falha após POST, preservação do ID retornado, conciliação sem repetir criação e sincronização em lote sem cobrança inicialmente vinculada.
- Cancelamento desativa link remoto; transação pendente mantém o pedido ativo até conferência. Pedido pago preserva a proteção de reembolso e o histórico. Repetir a migração conserva limites registrados.
- Navegador: cadastro de produto com Pix/cartão e outro somente com boleto; inclusão de preço promocional e período de 10x; catálogo e compra respeitam as formas e os limites de cada produto.
- Com a compra aberta, redução de 10x para 6x rejeita a tentativa antiga. O próximo clique envia R$ 800 e limite de 6x à K8 simulada; o retorno confirma o pagamento e conserva o limite registrado. Encerrar o período devolve novas compras e catálogo ao padrão de 3x.
- Atualizador nativo 2.4.0 → 2.5.0 conferiu o SHA-256 do pacote e preservou pedido pago, aluno e checkout de cartão anterior. As novas formas e faixas ficaram disponíveis após a atualização.
- Compatibilidade no navegador com HTML sem o novo bloco informativo de parcelas, mantendo as opções de pagamento funcionais e sem erros.
- Inspeção visual do editor e compra em 1440 px e 390 px, sem transbordamento da página ou erros JavaScript.

Contrato conferido nas referências e Swagger oficiais K8 de produção/sandbox em 30/10/2026. Todos os registros e pagamentos de teste são sintéticos; nenhum cartão foi coletado e nenhuma cobrança real foi enviada. A homologação da nova rota com as credenciais, permissões e checkout da conta K8 permanece a cargo do administrador antes de liberar pagamentos reais.

---

## Histórico da validação — versão 2.4.0 — 29/10/2026

Ambiente local descartável: WordPress 7.1.4, PHP 8.3.6, MariaDB 10.11.14 e Chromium headless 151. Sem acesso ao WordPress de produção.

- **190 verificações de integração aprovadas:** 32 novas de turmas/preços por produto, além de 50 do núcleo, 27 de alunos/catálogo/atualizações, 37 de descontos por data e 44 de importação/API.
- Seleção obrigatória de turmas ao salvar produto, consolidação de seleção com turmas informadas manualmente, tipos e tamanhos válidos e ausência de gravação parcial quando a seleção é inválida.
- Preço normal, preço específico e promoção conferidos por produto. Turma excluída não pode cotar nem criar nova compra, mesmo com preço antigo cadastrado ou contexto de consulta anterior.
- Catálogo ignora preços de turmas não selecionadas e oculta produtos sem turma/preço válido. Adesão e pendências do dashboard incluem somente alunos elegíveis ou com pedido histórico pago.
- Alterar a seleção de um produto preserva o outro. Importar turma nova apenas a disponibiliza como opção, sem liberar compra automaticamente.
- Migração converte a disponibilidade anterior em seleção explícita por produto; repetir a migração preserva a seleção editada. Pedidos anteriores conservam valores e pagamento e continuam acessíveis após remover uma turma.
- No navegador: criação de dois produtos com turmas diferentes, cadastro de preço por data no primeiro, catálogo com valores independentes e consulta de CPF compartilhado retornando apenas o aluno da turma liberada para cada produto.
- Atualização nativa 2.3.0 → 2.4.0 pelo `Plugin_Upgrader`, com SHA-256 conferido: pedido e aluno preservados, desconto funcionando e turmas convertidas em seleção explícita.
- Mudança de turma com checkout aberto retorna erro antes de emitir cobrança. Editor de produto inspecionado em 1440 px e 390 px sem transbordamento da página ou erros JavaScript.

Dados de teste sintéticos; nenhuma cobrança real ou mensagem enviada. O relatório anterior permanece abaixo como histórico.

---

## Histórico da validação — versão 2.3.0 — 29/10/2026

Ambiente local descartável: WordPress 7.1.4, PHP 8.3.6, MariaDB 10.11.14 e Chromium headless 151. Nenhum acesso ao WordPress de produção.

- Reproduzida a falha de acesso à página Alunos: registrar o submenu antes do menu pai gerava o hook incorreto e resposta 403. Corrigida a prioridade do registro e acrescentada verificação de regressão.
- 44 verificações de importação e API: CSV legado, cabeçalhos acentuados e fora de ordem, campos opcionais, colunas extras, diretiva `sep=;`, tabulação, UTF-16LE/BE, erros de upload, identidade, CPF, tipos JSON e limites de permissão.
- JSON com vários responsáveis, validação sem gravação, atualização sem duplicar, preservação de ID, remoção de vínculos substituídos, invalidação dos contextos de compra e relatório com origem API.
- Lista vazia, CPF inválido, conflito de identidade, opções desconhecidas e substituição sem flag explícita não alteram a base. Falha simulada de gravação reverte a transação inteira.
- 50 verificações do núcleo, 27 de alunos/catálogo/atualização e 37 de descontos também passaram: **158 verificações de integração no total**. Casos antigos dependentes dos anexos originais não foram repetidos.
- Testes HTTP com autenticação nativa por Senha de aplicação: JSON e multipart CSV reais. Requisições anônimas e com senha normal recebem 401; operador sem `manage_options` recebe 403.
- No navegador: login administrativo, acesso ao submenu Alunos, validação sem gravar, importação pelo formulário nativo e exibição dos cadastros. O terceiro responsável importado pela API encontra o aluno no fluxo de compra.
- Erros reais de `upload_max_filesize` e `post_max_size` testados com arquivos acima do limite. O formulário retorna ao painel com orientação; o servidor de teste usa `display_errors=Off`, necessário para que avisos emitidos pelo PHP antes do WordPress não prejudiquem os cabeçalhos HTTP.
- Atualização 2.2.0 → 2.3.0 pelo `Plugin_Upgrader` nativo, com download simulado dos bytes reais do pacote e conferência SHA-256: pedido e aluno anteriores preservados e API disponível após atualizar.
- Inspeção visual da página de alunos em 1440 px e 390 px, URL da API quebrando linha, sem transbordamento horizontal da página e sem erros JavaScript.

Dados de teste sintéticos; nenhum aluno real, cobrança ou mensagem real foi enviado. A Senha de aplicação do teste existe apenas no WordPress local. A autenticação HTTP sem TLS foi habilitada exclusivamente pela configuração `WP_ENVIRONMENT_TYPE=local` desse ambiente; a integração publicada orienta HTTPS.

---

## Histórico da validação — versão 2.2.0 — 29/10/2026

Ambiente: WordPress 7.1.4, PHP 8.3.6, MariaDB 10.11.14 e Chromium headless 151.

- 37 verificações novas de descontos por data aprovadas, incluindo início e fim do período, dia final inteiro, fuso do site, múltiplas faixas, restrição por turma, preço promocional, percentuais e arredondamento em centavos.
- Comparação correta entre preços de turmas distintas, exibição de preço original/promocional pareados, cupons sem acumulação automática e acumulação somente quando permitida.
- Conferência do preço ao gerar o pedido: uma cotação antiga é rejeitada antes de chamar a K8. O corpo AES da cobrança simulada foi decodificado para confirmar que a API recebe o preço com desconto.
- Pedidos antigos conservam valores e metadados. Promoções não aumentam o preço normal e podem gerar cortesia por período.
- 50 verificações anteriores do núcleo e 27 de alunos, catálogo e atualização também passaram. Os testes legados que dependem dos anexos originais não foram repetidos nesta execução.
- No navegador: cadastro e edição de desconto pelo painel, preço original riscado + preço com desconto + data limite na home e na compra, layout de 390 px sem transbordamento e nenhum erro JavaScript.
- Promoção encerrada com a página aberta: o catálogo consulta o preço atual, e o checkout exige nova confirmação sem emitir cobrança com a cotação antiga.
- Verificação de atualização pelo mecanismo nativo do WordPress: pacote 2.2.0 e SHA-256 conferidos, seguido da migração a partir da versão 2.1.0 com conservação de pedido anterior.

Nenhuma cobrança real foi criada. As chamadas K8 usam respostas simuladas na HTTP API do WordPress. Os registros e as telas de teste são sintéticos e não integram a instalação do plugin.

---

## Histórico da validação — 28/10/2026

Ambiente descartável com WordPress 7.1.3, PHP 8.3.6, MariaDB 10.11.14 e Chromium headless 151. Nenhum site de produção foi alterado durante a preparação.

## Integração

**84 verificações aprovadas:** 57 de cobrança, produto, migração e estoque; 27 de alunos, catálogo e canal de atualização.

- Instalação limpa e migração sobre tabelas criadas pelos plugins originais.
- Preservação de IDs, saldos existentes, cupons, assinaturas e histórico.
- Mesmo aluno em produtos diferentes; impedimento de cobranças duplicadas no mesmo produto.
- Cupom com limite e cortesia de 100%; edição do cupom mantém o contador.
- Baixa e estorno uma vez, quantidade por aluno, rollback do conjunto quando falta estoque e proteção entre produtos.
- CPF validado no servidor, contexto vinculado ao produto, permissões administrativas e renderização de todas as abas.
- Timeout da cobrança conserva tentativa em conferência; conciliação recupera a cobrança sem criar outra.
- CSV de dez colunas, separadores, UTF-8 com BOM e Windows-1252, RA repetido e múltiplos responsáveis.
- Terceiro responsável tem acesso ao aluno; retirar o responsável do CSV remove o vínculo; importação invalida contextos temporários anteriores.
- Importação incremental preserva IDs; erros de identidade/CPF/estrutura são rejeitados; falha de banco preserva os dados anteriores.
- Catálogo inclui somente produtos ativos, publicados e com preço, respeitando filtros.
- Canal reconhece versão maior, mantém a mesma versão como atual, oferece detalhes no painel e rejeita manifesto com pacote em outro domínio.

## Navegador

- Home BMT renderizada com a logo original, três produtos sintéticos somente no banco de testes, filtros e links automáticos de compra.
- CPF → aluno → cupom → cortesia → acompanhamento após recarregar a página.
- Login administrativo, seleção do produto, assinatura em canvas, entrega e estorno.
- Cadastro de produto, preço por turma e material com estoque pela interface.
- Inspeção visual em 1440 px e 390 px, sem erros JavaScript e sem transbordamento horizontal da home ou do checkout.

## Instalação e atualização

Os dois pacotes foram instalados em cópias reais dos diretórios do WordPress, usando **Plugin_Upgrader** e **Theme_Upgrader**. O processo percorreu o filtro de download, conferiu SHA-256, extraiu os ZIPs e manteve os diretórios `k8-produtos` e `bmt-educacional`. A contagem de pedidos foi preservada após os dois processos.

O servidor de teste simulou as respostas HTTP dos manifestos e dos pacotes, usando os bytes dos ZIPs produzidos neste repositório. Isso valida o fluxo nativo de atualização e a integridade dos arquivos, sem exigir uma instalação pública antes da publicação do código.

## Limites

As chamadas de cobrança, consulta, cancelamento, timeout e conciliação da K8 usam respostas simuladas pelo filtro HTTP do WordPress, com criptografia AES compatível com o cliente fornecido. Nenhuma cobrança ou mensagem real foi criada. Os endpoints e as credenciais K8 precisam de homologação na conta usada pelo site.

A validação não confirma a instalação no WordPress de produção, cuja hospedagem e painel não foram acessados. Depois de instalar os pacotes, confira o cadastro, faça o teste de pagamento K8 e verifique as atualizações a partir da hospedagem.
