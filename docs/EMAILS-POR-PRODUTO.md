# E-mails por produto — versão 2.7.0

O plugin envia pelo **wp_mail() do WordPress**, usando o SMTP que já estiver configurado no site. Não há nova conta de SMTP para cadastrar no K8 Produtos.

## Ativar e personalizar

1. Atualize o plugin e abra **K8 Produtos → Produtos e preços**.
2. Edite o produto e vá até **E-mails deste produto**.
3. Marque **Enviar confirmação após o pagamento confirmado** e/ou **Enviar lembrete de carrinho abandonado**.
4. Escolha o prazo de abandono, em minutos. O padrão é **60 minutos**; são aceitos de 15 minutos até sete dias.
5. Edite o assunto e o texto de cada mensagem. O editor aceita parágrafos, negrito e links. Use os marcadores abaixo para personalizar.
6. Clique em **Salvar e-mails deste produto**.

As duas opções começam desligadas. A ativação vale para os contatos e pedidos capturados pelo novo fluxo; **não dispara mensagens retroativas para pedidos antigos**. Cada produto tem sua própria configuração e modelos.

A confirmação é enviada quando o pedido estiver **Paga**, inclusive em compras gratuitas/cortesias. Apenas gerar um Pix, boleto ou link de cartão ainda não confirma pagamento. A quantidade de parcelas é um limite registrado no pedido, pois a escolha efetiva acontece na K8.

## Contato na compra

A base de alunos utilizada atualmente não possui e-mail. Por isso o checkout coleta o endereço do comprador, vinculado ao responsável, aluno e produto já identificados por CPF.

- Com confirmação habilitada, o e-mail é obrigatório antes de concluir um novo pedido.
- Com somente recuperação habilitada, o e-mail é opcional. A pessoa escolhe **Quero receber um lembrete por e-mail se não concluir esta compra**.
- Informar o endereço sem escolher receber lembrete não autoriza recuperação.
- Se os dois envios estiverem desligados, o campo fica oculto e o fluxo de compra anterior é preservado.

O contato é salvo antes de gerar um pedido, quando a pessoa informa um endereço válido e altera o campo ou a preferência. Assim a recuperação também funciona quando a compra é interrompida antes de escolher Pix, boleto ou cartão. Quem não informou um e-mail válido não pode receber a mensagem.

Os pedidos registram o endereço usado na sua criação. Repetir um checkout já concluído não troca esse endereço e não gera outra confirmação.

## Marcadores disponíveis

Copie o marcador completo, incluindo os colchetes, para o assunto ou texto. Os marcadores de link podem ser usados como endereço de um link no editor.

| Marcador | Conteúdo |
| --- | --- |
| [nome_responsavel] | Nome do responsável identificado na compra |
| [email_responsavel] | E-mail informado na compra |
| [nome_aluno] | Nome do aluno |
| [ra] | RA do aluno |
| [turma] | Turma do aluno |
| [produto] | Nome do produto |
| [numero_pedido] | Número do pedido; antes de criá-lo, “Ainda não gerado” |
| [valor] | Valor emitido no pedido ou preço atual do carrinho |
| [valor_original] | Preço antes dos descontos |
| [desconto] | Economia em reais |
| [cupom] | Cupom aplicado, quando houver |
| [forma_pagamento] | Forma de pagamento do pedido; no carrinho sem pedido, “A escolher” |
| [parcelas] | Limite autorizado do cartão, como “Até 6x”; não informa as parcelas efetivamente escolhidas |
| [data_compra] | Data do pedido ou do registro inicial do carrinho, no fuso do site |
| [site] | Nome do site configurado no WordPress |
| [link_pagamento] | Checkout K8 de pedido pendente; sem pedido, página pública do produto |
| [link_produto] | Página pública do produto |
| [link_acompanhamento] | Página do produto para informar o CPF e consultar pagamento/entrega |
| [link_descadastro] | Página para cancelar os lembretes desse produto |

Esses marcadores são substituições específicas das mensagens. O plugin **não executa outros shortcodes do WordPress** dentro do e-mail. Um marcador desconhecido é rejeitado ao salvar, para evitar mensagens com campos esquecidos. Dados de alunos e responsáveis são escapados no HTML; os modelos aceitam somente o HTML permitido pelo WordPress.

Exemplo de assunto: **Compra confirmada: [produto] — pedido #[numero_pedido]**.

## Recuperação e acompanhamento

É enviado **um lembrete por combinação de produto, aluno e responsável**, após o período sem atividade. Não há sequência de vários lembretes. Uma correção de contato, alteração da preferência ou aplicação de cupom salva o contato e reinicia a espera. A consulta automática de preços não reinicia a espera. Carrinhos deixam de ser elegíveis após 30 dias sem atividade.

Antes de enviar, o plugin confere a preferência, a validade do contato, a disponibilidade do produto e o vínculo atual do responsável e da turma. Havendo pedido, consulta o pagamento na K8. Pagamento confirmado, pedido cancelado ou produto desativado impedem a recuperação. Se houver falha de consulta ou pedido em conferência, a mensagem é adiada.

A confirmação de uma compra já paga continua possível mesmo se o produto tiver sido ocultado da vitrine.

O lembrete sempre recebe um rodapé para **Cancelar lembretes deste produto**, mesmo quando o texto é personalizado. Abrir o link mostra uma confirmação; o cancelamento acontece ao clicar no botão, para evitar descadastro apenas pela leitura automática do link por serviços de e-mail. Esse cancelamento não desativa confirmações transacionais.

Em carrinhos sem pedido, a mensagem usa o preço vigente na preparação do envio. Ao voltar ao site, valores, cupons, turmas e parcelamento são conferidos novamente. O lembrete não reserva preço ou estoque. Pedidos já emitidos mantêm o valor e as condições registrados.

O link da página de acompanhamento não autentica automaticamente o responsável: é necessário informar novamente o CPF e selecionar o aluno. O link de pagamento de um pedido pendente usa o checkout K8 já gerado, sem criar outra cobrança.

## Fila, falhas e SMTP

A fila é processada pelo **WP-Cron a cada cinco minutos**, em lotes de até dez mensagens. Isso depende da execução do agendador do WordPress. Se o site tem pouco tráfego ou usa cron externo, configure a hospedagem para chamar o cron regularmente.

No próprio produto, o **Histórico de envios** mostra destinatário, tipo, pedido, situação e tentativas:

- **Na fila:** aguardando processamento ou o horário de nova tentativa.
- **Enviando:** tentativa em andamento.
- **Enviado:** o WordPress/SMTP aceitou a mensagem; isso não comprova entrega na caixa de entrada.
- **Falha após três tentativas:** o transporte recusou o envio. São feitas até três tentativas, com novas esperas de cinco e quinze minutos.
- **Conferir no SMTP:** a execução foi interrompida e o resultado é incerto. O sistema não repete automaticamente para evitar duplicação.
- **Cancelado:** a mensagem deixou de ser aplicável, por exemplo após pagamento ou descadastro.

Após corrigir o SMTP, use **Tentar novamente** em uma falha. Em casos incertos, confira primeiro o log do seu plugin de SMTP: a mensagem pode já ter sido aceita. Os controles impedem duplicação no processamento normal; o resultado de uma conexão interrompida depois da aceitação precisa dessa conferência.

O remetente, a autenticação e a entrega continuam sob a configuração do SMTP do WordPress. O plugin não substitui o remetente nem coleta credenciais de e-mail. Não foram enviados e-mails reais durante os testes.

Referências do WordPress: [wp_mail](https://developer.wordpress.org/reference/functions/wp_mail/) e [agendamento pelo WP-Cron](https://developer.wordpress.org/plugins/cron/).
