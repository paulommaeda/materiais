# Validação — 28/10/2026

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
