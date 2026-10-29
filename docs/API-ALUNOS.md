# API de importação de alunos — plugin 2.3.0

## Endereço e autenticação

Use **POST** na URL exibida em **K8 Produtos → Alunos → Importar pela API**. Com links permanentes ativos, o endereço tem este formato:

```text
https://SEU-SITE/wp-json/k8-produtos/v1/alunos/importar
```

Sem links permanentes, use `https://SEU-SITE/?rest_route=/k8-produtos/v1/alunos/importar`. Instalações em subdiretórios podem incluir esse caminho; copie a URL do painel.

1. Atualize o plugin para 2.3.0 ou superior.
2. No WordPress, entre com uma conta administradora e acesse **Usuários → Perfil → Senhas de aplicação**.
3. Crie uma senha, por exemplo com o nome “Integração de alunos”. Guarde o valor exibido; ele pode ser revogado nessa tela.
4. No sistema de origem, use **Basic Auth** com o login dessa conta e a **Senha de aplicação**, sempre em HTTPS. A senha normal de login não é aceita por esse mecanismo. A conta precisa da capacidade `manage_options`, a mesma exigida pelo importador no painel.

O recurso usa a autenticação nativa do WordPress. Não é necessário instalar outro plugin de autenticação. Se a seção de Senhas de aplicação estiver indisponível, confira HTTPS e as políticas de autenticação da hospedagem. Em caso de 401 com credenciais corretas, confira se o servidor/proxy encaminha o cabeçalho `Authorization` ao PHP.

## Importar JSON

Salve o exemplo abaixo como `alunos.json`, substituindo pelos seus cadastros. Os dados mostrados são exemplos, não cadastros reais. Envie RA, CPF e telefones como **texto entre aspas**, para preservar zeros à esquerda.

```json
{
  "modo": "atualizar",
  "validar_apenas": false,
  "alunos": [
    {
      "ra": "000123",
      "nome_aluno": "Aluno de exemplo",
      "cpf_aluno": "",
      "turma": "5º Ano A",
      "responsaveis": [
        {
          "nome": "Responsável de exemplo",
          "cpf": "52998224725",
          "telefone": "11987654321"
        }
      ]
    }
  ]
}
```

```bash
curl --fail-with-body --request POST \
  'https://SEU-SITE/wp-json/k8-produtos/v1/alunos/importar' \
  --user 'LOGIN_WORDPRESS:SENHA_DE_APLICACAO' \
  --header 'Content-Type: application/json' \
  --data-binary @alunos.json
```

Use o login do usuário, não o nome escolhido para a senha. Mantenha a credencial no sistema de origem; não a coloque no código público ou em scripts do navegador.

Cada aluno deve ter `ra`, `nome_aluno` e `turma`. `cpf_aluno` é opcional. A lista `responsaveis` aceita até 100 responsáveis por aluno; cada um usa `nome`, `cpf` e `telefone`. CPF de responsável informado precisa ter dígitos verificadores válidos. Sem CPF de responsável o aluno é importado com aviso e não poderá ser encontrado na compra por CPF. Telefone ausente ou inválido gera aviso, sem impedir o cadastro.

Também é aceito um objeto por aluno com os campos planos do CSV: `ra`, `nome_aluno`, `cpf_aluno`, `turma`, `nome_resp1`, `cpf_resp1`, `nome_resp2`, `cpf_resp2`, `telefone_resp1`, `telefone_resp2`. Não misture esses campos de responsáveis com `responsaveis` no mesmo objeto. Para acrescentar mais responsáveis no formato plano, repita o RA no mesmo lote com os mesmos dados do aluno.

## Importar CSV pela API

Envie o arquivo no campo **`arquivo_csv`**, usando `multipart/form-data`. Deixe o cURL ou seu cliente definir o boundary do formulário; não adicione `Content-Type: application/json` nesse envio.

```bash
curl --fail-with-body --request POST \
  'https://SEU-SITE/wp-json/k8-produtos/v1/alunos/importar' \
  --user 'LOGIN_WORDPRESS:SENHA_DE_APLICACAO' \
  --form 'modo=atualizar' \
  --form 'validar_apenas=false' \
  --form 'arquivo_csv=@alunos.csv;type=text/csv'
```

Use o [modelo CSV](../k8-produtos/assets/modelo-alunos.csv). Cabeçalhos podem mudar de ordem; as colunas `ra`, `nome_aluno` e `turma` são necessárias e as demais são opcionais. Também são reconhecidos títulos como `Matrícula`, `Nome do aluno`, `CPF do responsável 1`, `Responsável 1` e `Telefone 1`. Colunas extras são ignoradas. Prefira os títulos do modelo para evitar ambiguidades.

São aceitos ponto e vírgula, vírgula ou tabulação; UTF-8 com ou sem BOM, Windows-1252 e UTF-16 com BOM do Excel; linha inicial `sep=;` e registros com colunas opcionais vazias no final. O formato legado de dez colunas sem cabeçalho continua aceito na ordem do modelo. Um arquivo XLSX renomeado para CSV é rejeitado: use “Salvar como CSV” no Excel.

## Validar sem gravar

Envie **`validar_apenas: true`** no JSON ou **`validar_apenas=true`** no multipart. A API valida o conteúdo e devolve o número de alunos, vínculos e avisos. Não altera alunos, vínculos, contextos de compra ou relatório da última importação.

JSON exige booleanos reais (`true` ou `false`), sem aspas. Campos desconhecidos são rejeitados para evitar que um erro de digitação na opção de validação provoque uma importação. Não envie arquivos e JSON juntos.

## Atualização e substituição

| Opção | Comportamento |
| --- | --- |
| `modo: "atualizar"` ou omitido | Cria novos RAs e atualiza os enviados. Preserva alunos ausentes no lote. |
| `modo: "substituir"` | Substitui a lista inteira. Exige `confirmar_substituicao: true` quando for gravar. |
| `validar_apenas: true` | Confere o conteúdo sem gravar; pode ser usado com qualquer modo. |

**Para cada RA enviado, os dados e todos os vínculos de responsáveis são substituídos pelos do lote.** Envie todos os responsáveis de um aluno no mesmo lote. Repetir a mesma importação não duplica alunos ou vínculos; no modo de atualização o ID do aluno é preservado. Não divida os responsáveis de um RA entre chamadas diferentes.

No modo de substituição total, alunos e vínculos ausentes são removidos, e os registros da lista são recriados. Pedidos, cobranças, estoque, entregas e histórico permanecem. Uma lista vazia é sempre rejeitada, inclusive no modo de substituição.

O lote inteiro é validado antes da gravação e salvo em uma transação. CPF inválido de responsável, divergência de nome/turma/CPF do aluno em RA repetido ou falha de banco impede a conclusão do lote. Não há importação parcial silenciosa. Uma gravação bem-sucedida invalida consultas temporárias de compra anteriores; o responsável deve buscar seu CPF novamente.

A API e o importador do painel gravam nas tabelas integradas `lista_alunos_v4` e `sba_guardians`, com o prefixo do site. Se o mapeamento estiver apontando para uma base externa, o painel e a resposta da API avisam que a compra está consultando outra base. Ajuste em **K8 Produtos → Configurações** quando quiser usar a lista integrada.

## Resposta de sucesso

HTTP **200**:

```json
{
  "sucesso": true,
  "validar_apenas": false,
  "modo": "atualizar",
  "alunos": 1,
  "vinculos": 1,
  "registros": 1,
  "novos": 1,
  "atualizados": 0,
  "importado_em": "2026-10-29 14:00:00",
  "avisos": [],
  "base_consultada": "integrada",
  "aviso_base": null
}
```

`importado_em` usa o fuso do WordPress. Na validação sem gravação, `novos`, `atualizados` e `importado_em` são `null`. `registros` conta as linhas consolidadas antes de agrupar os RAs, incluindo a expansão de responsáveis do JSON; `alunos` conta RAs únicos. No modo de substituição, os alunos recriados contam como `novos`.

Avisos contêm `line`, `ra`, `field` e `message`. O relatório não devolve os CPFs nem os cadastros completos. O último resultado gravado também aparece em **K8 Produtos → Alunos**, identificado como origem **api**.

## Limites e erros

Até **10.000 registros por envio**, antes da consolidação dos RAs e depois de expandir a lista de responsáveis em grupos de dois. Corpo JSON de até **10 MB**. O CSV respeita o menor limite entre 10 MB e a capacidade de upload da hospedagem, mostrada no painel. `post_max_size`, proxy e limites do servidor também podem restringir o envio. Divida lotes grandes por RA, preservando todos os responsáveis de cada aluno juntos.

Erros seguem o padrão REST do WordPress:

```json
{
  "code": "k8prod_cpf",
  "message": "Registro 1: CPF do responsável 1 inválido. Corrija antes de importar. Nada foi alterado.",
  "data": { "status": 422 }
}
```

| HTTP | Significado |
| --- | --- |
| 400 | JSON malformado, opção inválida ou substituição sem confirmação. |
| 401 | Autenticação ausente ou inválida. |
| 403 | Usuário autenticado sem permissão administrativa. |
| 413 | Arquivo, corpo ou número de registros acima do limite. |
| 415 | Formato de envio não suportado. |
| 422 | Cadastro, CPF, cabeçalho ou identidade de RA inválidos; lote não gravado. |
| 500 | Falha ao gravar; transação cancelada. |

Se houver timeout sem resposta, consulte a lista/relatório no painel antes de repetir. Reenviar o mesmo lote em `atualizar` preserva IDs e substitui os mesmos vínculos. Evite substituir a lista inteira em várias chamadas: cada chamada de `substituir` representa a lista completa.

Referência oficial: [autenticação da REST API do WordPress](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/).
