# Publicação deste plugin

O usuário determinou que as alterações deste plugin sejam sempre publicadas em `paulommaeda/materiais`, com atualização disponível pelo WordPress.

- Aumente a versão do plugin no cabeçalho e em `K8PROD_VERSION` quando houver alteração distribuída.
- Publique código, ZIP instalável em `dist/` e manifesto `updates/plugin.json`, com URL e SHA-256 correspondentes.
- Execute as verificações pertinentes, acompanhe o GitHub Actions até concluir e confirme a disponibilidade pública do pacote e do manifesto.
- Preserve pacotes de versões anteriores e rejeite alterações no conteúdo de um pacote já versionado.
- Conclua a publicação em `main`; um ZIP local ou uma branch de preparação, por si só, não atendem ao pedido.
- O diretório `work/github-release` pode conter a preparação local desta publicação. Consulte o estado remoto atual antes de novas alterações.
