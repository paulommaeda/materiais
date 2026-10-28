# Validação

O plugin é verificado em WordPress 7.1.3, PHP 8.3.6 e MariaDB 10.11.14. As fixtures cobrem migração, pedidos por produto, cupons, estoque, entregas, importação CSV, vínculos adicionais de responsáveis, catálogo e canal de atualização. Os testes só podem ser executados em bancos descartáveis cujo nome termine em `_test`.

Os testes de K8 e metadados de atualização usam respostas simuladas, além das verificações de instalação e navegador descritas no relatório `docs/VALIDACAO.md` do repositório. Não houve cobrança nem envio de mensagem real.

A comunicação com a conta K8 precisa de homologação no ambiente do responsável pela instalação.
