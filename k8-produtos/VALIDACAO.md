# Validação

O plugin é verificado em WordPress 7.1.4, PHP 8.3.6 e MariaDB 10.11.14. As fixtures cobrem migração, pedidos por produto, cupons, estoque, entregas, importação CSV e API autenticada, vínculos adicionais de responsáveis, catálogo, descontos por data e canal de atualização. Na versão 2.3.0 passaram 158 verificações de integração, além dos testes HTTP e de navegador com o formulário real e Senhas de aplicação. Os testes só podem ser executados em bancos descartáveis cujo nome termine em `_test`.

Os testes de K8 e metadados de atualização usam respostas simuladas, além das verificações de instalação e navegador descritas no relatório `docs/VALIDACAO.md` do repositório. Não houve cobrança nem envio de mensagem real.

A comunicação com a conta K8 precisa de homologação no ambiente do responsável pela instalação.
