<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Views
{
    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_shortcode('k8_produto', [self::class, 'product_shortcode']);
        add_shortcode('k8_dashboard', [self::class, 'dashboard_shortcode']);
        // As páginas antigas continuam funcionando após a migração.
        add_shortcode('pagamento_material_didatico', static function ($atts) {
            $atts = is_array($atts) ? $atts : [];
            $atts['id'] = $atts['produto'] ?? get_option('k8prod_legacy_product_id', 0);
            return self::product_shortcode($atts);
        });
        foreach (['dashboard_material_didatico' => 'overview', 'entrega_material_didatico' => 'deliveries', 'k8pm_dashboard' => 'stock'] as $tag => $tab) {
            add_shortcode($tag, static function ($atts) use ($tab) {
                return self::dashboard_shortcode(array_merge(is_array($atts) ? $atts : [], ['aba' => $tab]));
            });
        }
    }

    public static function menu(): void
    {
        add_menu_page('K8 Produtos', 'K8 Produtos', 'k8_manage_products', 'k8prod', [self::class, 'admin_dashboard'], 'dashicons-products', 31);
        add_submenu_page('k8prod', 'Dashboard', 'Dashboard', 'k8_manage_products', 'k8prod', [self::class, 'admin_dashboard']);
        add_submenu_page('k8prod', 'Produtos e preços', 'Produtos e preços', 'manage_options', 'k8prod-products', [self::class, 'admin_products']);
        add_submenu_page('k8prod', 'Configurações K8', 'Configurações', 'manage_options', 'k8prod-settings', [self::class, 'admin_settings']);
    }

    private static function assets(): void
    {
        wp_enqueue_style('k8prod', plugins_url('assets/app.css', K8PROD_FILE), [], K8PROD_VERSION);
        wp_enqueue_script('k8prod', plugins_url('assets/app.js', K8PROD_FILE), [], K8PROD_VERSION, true);
    }

    private static function shell(string $extra = ''): void
    {
        self::assets();
        echo '<div class="k8prod ' . esc_attr($extra) . '" data-ajax="' . esc_url(admin_url('admin-ajax.php')) . '" data-nonce="' . esc_attr(wp_create_nonce('k8prod_manage')) . '"><div class="k8-notice" role="status" aria-live="polite" hidden></div>';
    }

    private static function form(string $op, int $product, string $class = ''): void
    {
        echo '<form class="k8-form ' . esc_attr($class) . '" data-k8-manage="' . esc_attr($op) . '"><input type="hidden" name="produto_id" value="' . (int) $product . '">';
    }

    private static function field(string $name, string $label, $value = '', string $type = 'text', string $extra = ''): void
    {
        echo '<label>' . esc_html($label) . '<input name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr((string) $value) . '" ' . $extra . '></label>';
    }

    private static function button(string $label): void
    {
        echo '<button class="k8-btn" type="submit">' . esc_html($label) . '</button>';
    }

    private static function currency($value): string
    {
        return 'R$ ' . number_format((float) $value, 2, ',', '.');
    }

    public static function product_shortcode($atts): string
    {
        $atts = shortcode_atts(['id' => 0, 'produto' => 0], $atts, 'k8_produto');
        try {
            $product = Support::product(absint($atts['id'] ?: $atts['produto']), true);
        } catch (\Throwable $e) {
            return '<p>' . esc_html($e->getMessage()) . '</p>';
        }
        self::assets();
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        ob_start();
        ?>
        <section class="k8prod k8-public" data-k8-product="<?php echo (int) $product->id; ?>" data-stages="<?php echo (int) $product->etapas; ?>" data-ajax="<?php echo esc_url(admin_url('admin-ajax.php')); ?>">
            <div class="k8-eyebrow">Compra e acompanhamento</div>
            <h2><?php echo esc_html($product->nome); ?></h2>
            <?php if ($product->descricao): ?><p class="k8-muted"><?php echo nl2br(esc_html($product->descricao)); ?></p><?php endif; ?>
            <ol class="k8-steps"><li>Identificação</li><li>Aluno e pagamento</li><li>Acompanhamento</li></ol>
            <div class="k8-notice" role="status" aria-live="polite" hidden></div>
            <form class="k8-form" data-k8-lookup>
                <label>CPF do responsável<input name="cpf" inputmode="numeric" autocomplete="off" maxlength="14" placeholder="000.000.000-00" required></label>
                <button class="k8-btn" type="submit">Consultar alunos</button>
            </form>
            <div data-k8-students></div>
            <div data-k8-checkout hidden>
                <div data-k8-summary></div>
                <form class="k8-form k8-inline" data-k8-coupon><label>Cupom de desconto<input name="cupom" maxlength="50" autocomplete="off" placeholder="Opcional"></label><button type="submit" class="k8-btn secondary">Aplicar cupom</button></form>
                <p class="k8-price" data-k8-price></p>
                <div class="k8-methods"><button class="k8-btn" data-method="Pix" type="button">Pagar com Pix</button><button class="k8-btn secondary" data-method="Boleto" type="button">Boleto</button><button class="k8-btn secondary" data-method="Cartao" type="button">Cartão de crédito</button></div>
                <button class="k8-btn" data-method="Cortesia" type="button" hidden>Finalizar sem custo</button>
            </div>
            <div data-k8-status aria-live="polite"></div>
        </section>
        <?php
        return ob_get_clean();
    }

    public static function admin_dashboard(): void
    {
        echo self::dashboard_shortcode([]);
    }

    public static function dashboard_shortcode($atts): string
    {
        if (!current_user_can('manage_options') && !current_user_can('k8_manage_products')) {
            return '<p>Entre com uma conta autorizada para administrar os produtos.</p>';
        }
        $atts = shortcode_atts(['produto' => 0, 'aba' => 'overview'], $atts, 'k8_dashboard');
        $products = Support::products();
        $id = absint($_GET['k8_product'] ?? $atts['produto']);
        if (!$id && $products) {
            $id = (int) $products[0]->id;
        }
        if ($id && !Support::can_manage($id)) {
            return '<p>Sem permissão para este produto.</p>';
        }
        ob_start();
        self::shell('k8-dashboard');
        echo '<div class="k8-heading"><div><div class="k8-eyebrow">K8 Produtos</div><h1>Administração por produto</h1></div></div>';
        if (!$products) {
            echo '<div class="k8-card"><p>Cadastre o primeiro produto para começar.</p>';
            if (current_user_can('manage_options')) {
                echo '<a class="k8-btn" href="' . esc_url(admin_url('admin.php?page=k8prod-products')) . '">Criar produto</a>';
            }
            echo '</div></div>';
            return ob_get_clean();
        }
        try {
            $product = Support::product($id);
            $tabs = ['overview' => 'Visão geral', 'orders' => 'Pedidos', 'stock' => 'Estoque', 'deliveries' => 'Entregas', 'coupons' => 'Cupons', 'history' => 'Histórico'];
            $tab = sanitize_key($_GET['k8_tab'] ?? $atts['aba']);
            if (!isset($tabs[$tab])) {
                $tab = 'overview';
            }
            echo '<form method="get" class="k8-filters">';
            if (is_admin()) {
                echo '<input type="hidden" name="page" value="k8prod">';
            }
            echo '<input type="hidden" name="k8_tab" value="' . esc_attr($tab) . '"><label>Produto<select name="k8_product">';
            foreach ($products as $p) {
                echo '<option value="' . (int) $p->id . '" ' . selected($id, $p->id, false) . '>' . esc_html($p->nome . ($p->ativo ? '' : ' (inativo)')) . '</option>';
            }
            echo '</select></label><button type="submit" class="k8-btn secondary">Selecionar produto</button></form>';
            echo '<nav class="k8-tabs" aria-label="Seções do produto">';
            foreach ($tabs as $key => $label) {
                $url = add_query_arg(['k8_product' => $id, 'k8_tab' => $key, 'k8_page' => 1]);
                echo '<a ' . ($key === $tab ? 'aria-current="page"' : '') . ' href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            }
            echo '</nav><div class="k8-product-title"><h2>' . esc_html($product->nome) . '</h2><code>[k8_produto id="' . $id . '"]</code></div>';
            if ($tab === 'overview') {
                self::overview($product);
            } elseif ($tab === 'orders') {
                self::orders($product);
            } elseif ($tab === 'stock') {
                self::stock($product);
            } elseif ($tab === 'deliveries') {
                self::deliveries($product);
            } elseif ($tab === 'coupons') {
                self::coupons($product);
            } else {
                self::history($product);
            }
        } catch (\Throwable $e) {
            echo '<p class="k8-error">' . esc_html($e->getMessage()) . '</p>';
        }
        echo '</div>';
        return ob_get_clean();
    }

    private static function overview(object $p): void
    {
        global $wpdb;
        $orders = Support::table('pedidos');
        $deliveries = Support::table('entregas');
        $materials = Support::table('materiais');
        $counts = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS pedidos, COUNT(DISTINCT CASE WHEN status_pagamento = 'Paga' THEN ra END) AS pagos,
            COALESCE(SUM(CASE WHEN status_pagamento = 'Paga' THEN valor ELSE 0 END),0) AS recebido,
            SUM(CASE WHEN status IN ('gerado','iniciado','verificacao') AND (status_pagamento IS NULL OR status_pagamento NOT IN ('Paga','Cancelada')) THEN 1 ELSE 0 END) AS pendentes
            FROM $orders WHERE produto_id = %d", $p->id));
        $delivered = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $deliveries WHERE produto_id = %d AND status = 'entregue'", $p->id));
        $low = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $materials WHERE produto_id = %d AND ativo = 1 AND estoque_atual <= estoque_minimo", $p->id));
        echo '<div class="k8-metrics">';
        foreach (['Alunos com pagamento confirmado' => (int) $counts->pagos, 'Valor confirmado' => self::currency($counts->recebido),
            'Pedidos aguardando pagamento' => (int) $counts->pendentes, 'Entregas realizadas' => $delivered, 'Materiais para reposição' => $low] as $label => $value) {
            echo '<div class="k8-card"><span class="k8-muted">' . esc_html($label) . '</span><strong class="k8-number">' . esc_html((string) $value) . '</strong></div>';
        }
        echo '</div>';
        $paid = array_fill_keys($wpdb->get_col($wpdb->prepare("SELECT DISTINCT ra FROM $orders WHERE produto_id = %d AND status_pagamento = 'Paga'", $p->id)), true);
        $prices = Support::table('valores_turma');
        $classes = $wpdb->get_col($wpdb->prepare("SELECT turma FROM $prices WHERE produto_id = %d", $p->id));
        $summary = [];
        $pending = [];
        try {
            foreach (Students::all() as $student) {
                if ($p->valor_padrao === null && !in_array($student->turma, $classes, true) && !isset($paid[$student->ra])) {
                    continue;
                }
                if (!isset($summary[$student->turma])) {
                    $summary[$student->turma] = ['total' => 0, 'paid' => 0];
                }
                $summary[$student->turma]['total']++;
                if (isset($paid[$student->ra])) {
                    $summary[$student->turma]['paid']++;
                } else {
                    $pending[] = $student;
                }
            }
        } catch (\Throwable $e) {
            echo '<p class="k8-muted">' . esc_html($e->getMessage()) . '</p>';
        }
        echo '<div class="k8-grid"><section class="k8-card"><h3>Adesão por turma</h3>';
        foreach ($summary as $class => $row) {
            $percent = $row['total'] ? round(100 * $row['paid'] / $row['total']) : 0;
            echo '<p>' . esc_html($class) . ' <span class="k8-muted">' . (int) $row['paid'] . ' / ' . (int) $row['total'] . '</span></p><progress value="' . $percent . '" max="100">' . $percent . '%</progress>';
        }
        if (!$summary) {
            echo '<p>Aguardando a configuração da base e dos preços por turma.</p>';
        }
        echo '</section><section class="k8-card"><h3>Pagamentos por método</h3>';
        $methods = $wpdb->get_results($wpdb->prepare("SELECT metodo,COUNT(*) AS total,SUM(valor) AS valor FROM $orders WHERE produto_id = %d AND status_pagamento = 'Paga' GROUP BY metodo ORDER BY total DESC", $p->id));
        foreach ($methods as $m) {
            echo '<p><strong>' . esc_html($m->metodo) . '</strong> · ' . (int) $m->total . ' pedido(s) · ' . esc_html(self::currency($m->valor)) . '</p>';
        }
        echo '</section></div><details class="k8-card"><summary>Alunos sem pagamento confirmado (' . count($pending) . ')</summary><p class="k8-muted">Situação atual dos alunos elegíveis ao produto. Exibição de até 200 registros.</p>';
        self::table_start(['Aluno', 'RA', 'Turma']);
        foreach (array_slice($pending, 0, 200) as $student) {
            echo '<tr><td>' . esc_html($student->nome_aluno) . '</td><td>' . esc_html($student->ra) . '</td><td>' . esc_html($student->turma) . '</td></tr>';
        }
        self::table_end();
        echo '</details>';
    }

    private static function filters(int $product, string $tab, bool $delivery = false): array
    {
        $class = sanitize_text_field(wp_unslash($_GET['k8_class'] ?? ''));
        $search = sanitize_text_field(wp_unslash($_GET['k8_search'] ?? ''));
        $state = sanitize_key($_GET['k8_state'] ?? '');
        $from = sanitize_text_field($_GET['k8_from'] ?? '');
        $to = sanitize_text_field($_GET['k8_to'] ?? '');
        foreach (['from', 'to'] as $key) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $$key)) {
                $$key = '';
            }
        }
        echo '<form method="get" class="k8-filters">';
        if (is_admin()) {
            echo '<input type="hidden" name="page" value="k8prod">';
        }
        echo '<input type="hidden" name="k8_product" value="' . $product . '"><input type="hidden" name="k8_tab" value="' . esc_attr($tab) . '">';
        self::field('k8_class', 'Turma', $class);
        self::field('k8_search', 'Aluno ou RA', $search);
        if (!$delivery) {
            self::field('k8_from', 'Pedido a partir de', $from, 'date');
            self::field('k8_to', 'Pedido até', $to, 'date');
        } else {
            echo '<input type="hidden" name="k8_stage" value="' . max(1, min(2, absint($_GET['k8_stage'] ?? 1))) . '">';
            echo '<label>Situação<select name="k8_state"><option value="">Todas</option><option value="pendente" ' . selected($state, 'pendente', false) . '>Pendente</option><option value="entregue" ' . selected($state, 'entregue', false) . '>Entregue</option></select></label>';
        }
        self::button('Filtrar');
        echo '</form>';
        return compact('class', 'search', 'state', 'from', 'to');
    }

    private static function where(int $product, array $filter): string
    {
        global $wpdb;
        $where = $wpdb->prepare('p.produto_id = %d', $product);
        if ($filter['class']) {
            $where .= $wpdb->prepare(' AND p.turma = %s', $filter['class']);
        }
        if ($filter['search']) {
            $like = '%' . $wpdb->esc_like($filter['search']) . '%';
            $where .= $wpdb->prepare(' AND (p.ra LIKE %s OR p.nome_aluno LIKE %s)', $like, $like);
        }
        if ($filter['from']) {
            $where .= $wpdb->prepare(' AND p.criado_em >= %s', $filter['from'] . ' 00:00:00');
        }
        if ($filter['to']) {
            $where .= $wpdb->prepare(' AND p.criado_em <= %s', $filter['to'] . ' 23:59:59');
        }
        return $where;
    }

    private static function table_start(array $headers): void
    {
        echo '<div class="k8-scroll"><table><thead><tr>';
        foreach ($headers as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
    }

    private static function table_end(): void
    {
        echo '</tbody></table></div>';
    }

    private static function pagination(int $total, int $page): void
    {
        echo '<div class="k8-pagination"><span>' . $total . ' registro(s) · página ' . $page . '</span>';
        if ($page > 1) {
            echo '<a href="' . esc_url(add_query_arg('k8_page', $page - 1)) . '">Anterior</a>';
        }
        if ($page * 50 < $total) {
            echo '<a href="' . esc_url(add_query_arg('k8_page', $page + 1)) . '">Próxima</a>';
        }
        echo '</div>';
    }

    private static function orders(object $p): void
    {
        global $wpdb;
        $filter = self::filters((int) $p->id, 'orders');
        self::form('sync', (int) $p->id, 'k8-inline');
        self::button('Consultar pagamentos agora');
        echo '<span class="k8-muted">Até 30 por vez; consulta automática a cada cinco minutos pelo WP-Cron.</span></form>';
        $where = self::where((int) $p->id, $filter);
        $table = Support::table('pedidos');
        $page = max(1, absint($_GET['k8_page'] ?? 1));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table p WHERE $where");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.* FROM $table p WHERE $where ORDER BY p.id DESC LIMIT 50 OFFSET %d", ($page - 1) * 50));
        self::table_start(['Pedido', 'Aluno', 'Responsável', 'Pagamento', 'Ações']);
        foreach ($rows as $o) {
            echo '<tr><td>#' . (int) $o->id . '<br><small>' . esc_html($o->criado_em) . '</small></td><td><strong>' . esc_html($o->nome_aluno) . '</strong><br>RA ' . esc_html($o->ra) . ' · ' . esc_html($o->turma) . '</td><td>' . esc_html($o->nome_resp) . '</td><td>' . esc_html(self::currency($o->valor)) . '<br>' . esc_html($o->metodo) . '<br><span class="k8-badge">' . esc_html($o->status_pagamento ?: $o->status) . '</span></td><td>';
            if ($o->erro_mensagem) {
                echo '<details><summary>Detalhes da tentativa</summary><p>' . esc_html($o->erro_mensagem) . '</p><p>Referência na K8: K8P-' . (int) $o->id . '</p></details>';
            }
            if ($o->cobranca_id && !in_array($o->status_pagamento, ['Paga', 'Cancelada'], true)) {
                echo '<a href="' . esc_url(Client::checkout($o->cobranca_id)) . '" target="_blank" rel="noopener">Abrir checkout K8</a>';
                self::form('sync', (int) $p->id);
                echo '<input type="hidden" name="pedido_id" value="' . (int) $o->id . '">';
                self::button('Consultar este pedido');
                echo '</form>';
            }
            if ($o->status === 'verificacao' && !$o->cobranca_id) {
                echo '<details><summary>Vincular cobrança existente</summary>';
                self::form('reconcile', (int) $p->id);
                echo '<input type="hidden" name="pedido_id" value="' . (int) $o->id . '">';
                self::field('cobranca_id', 'ID localizado na K8', '', 'text', 'required');
                self::button('Conferir e vincular');
                echo '</form></details>';
            }
            if ($o->status_pagamento !== 'Paga' && $o->status_pagamento !== 'Cancelada') {
                echo '<details><summary>Cancelar pedido</summary>';
                self::form('cancel', (int) $p->id);
                echo '<input type="hidden" name="pedido_id" value="' . (int) $o->id . '">';
                self::field('motivo', 'Motivo do cancelamento', '', 'text', 'required minlength="5"');
                if ($o->status === 'verificacao' && !$o->cobranca_id) {
                    echo '<label class="k8-check"><input type="checkbox" name="confirmar_sem_cobranca" value="1" required>Conferi no painel K8 e não há cobrança ativa para esta tentativa.</label>';
                }
                self::button('Confirmar cancelamento');
                echo '</form></details>';
            }
            echo '</td></tr>';
        }
        self::table_end();
        self::pagination($total, $page);
    }

    private static function stock(object $p): void
    {
        global $wpdb;
        $table = Support::table('materiais');
        $materials = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d ORDER BY ativo DESC,nome", $p->id));
        echo '<div class="k8-grid"><section class="k8-card"><h3>Novo material</h3>';
        self::form('save_material', (int) $p->id);
        self::field('nome', 'Nome do material', '', 'text', 'required maxlength="255"');
        self::field('turma_restrita', 'Turma (vazio = todas)');
        echo '<label>Período de entrega<select name="periodo_entrega">';
        foreach (Support::periods() as $period => $label) {
            if ((int) $p->etapas === 1 && Support::semester($period) === 2) {
                continue;
            }
            echo '<option value="' . esc_attr($period) . '">' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        self::field('quantidade_por_aluno', 'Unidades por aluno', 1, 'number', 'min="1" max="10000" required');
        self::field('estoque_inicial', 'Estoque inicial', 0, 'number', 'min="0" max="1000000" required');
        self::field('estoque_minimo', 'Alerta de reposição até', 0, 'number', 'min="0" max="1000000" required');
        self::button('Cadastrar material');
        echo '</form></section><section class="k8-card"><h3>Movimentar estoque</h3><p class="k8-muted">' . ($p->permitir_negativo ? 'O estoque é informativo e pode ficar negativo.' : 'Saídas e entregas exigem estoque disponível.') . '</p>';
        self::form('stock', (int) $p->id);
        echo '<label>Material<select name="material_id" required>';
        foreach ($materials as $m) {
            if ($m->ativo) {
                echo '<option value="' . (int) $m->id . '">' . esc_html($m->nome) . '</option>';
            }
        }
        echo '</select></label><label>Tipo<select name="tipo"><option value="entrada">Entrada</option><option value="saida">Saída</option></select></label>';
        self::field('quantidade', 'Quantidade', 1, 'number', 'min="1" max="1000000" required');
        self::field('observacao', 'Observação', '', 'text', 'maxlength="255"');
        self::button('Registrar movimento');
        echo '</form></section></div>';
        self::table_start(['Material', 'Turma', 'Período', 'Unidades/aluno', 'Saldo', 'Situação']);
        foreach ($materials as $m) {
            echo '<tr><td>' . esc_html($m->nome) . '</td><td>' . esc_html($m->turma_restrita ?: 'Todas') . '</td><td>' . esc_html(Support::periods()[$m->periodo_entrega] ?? $m->periodo_entrega) . '</td><td>' . (int) $m->quantidade_por_aluno . '</td><td class="' . ($m->estoque_atual <= $m->estoque_minimo ? 'k8-error' : '') . '">' . (int) $m->estoque_atual . '</td><td>';
            self::form('toggle_material', (int) $p->id);
            echo '<input type="hidden" name="id" value="' . (int) $m->id . '">';
            self::button($m->ativo ? 'Desativar' : 'Ativar');
            echo '</form></td></tr>';
        }
        self::table_end();
    }

    private static function deliveries(object $p): void
    {
        global $wpdb;
        $filter = self::filters((int) $p->id, 'deliveries', true);
        $stage = max(1, min((int) $p->etapas, absint($_GET['k8_stage'] ?? 1)));
        echo '<div class="k8-tabs">';
        for ($i = 1; $i <= (int) $p->etapas; $i++) {
            echo '<a href="' . esc_url(add_query_arg(['k8_stage' => $i, 'k8_page' => 1])) . '" ' . ($stage === $i ? 'aria-current="page"' : '') . '>' . ((int) $p->etapas === 1 ? 'Entrega única' : $i . 'º semestre') . '</a>';
        }
        echo '</div>';
        $orders = Support::table('pedidos');
        $deliveries = Support::table('entregas');
        $where = self::where((int) $p->id, $filter) . " AND p.status_pagamento = 'Paga'";
        if (in_array($filter['state'], ['entregue', 'pendente'], true)) {
            $where .= $wpdb->prepare(" AND COALESCE(e.status,'pendente') = %s", $filter['state']);
        }
        $join = $wpdb->prepare("FROM $orders p LEFT JOIN $deliveries e ON e.pedido_id = p.id AND e.produto_id = p.produto_id AND e.semestre = %d WHERE $where", $stage);
        $page = max(1, absint($_GET['k8_page'] ?? 1));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) $join");
        $rows = $wpdb->get_results($wpdb->prepare("SELECT p.*,e.id AS entrega_id,e.status AS entrega_status,e.assinatura AS entrega_assinatura,e.materiais_entregues AS delivery_items,e.entregue_em AS data_entrega,e.recebido_por $join ORDER BY p.criado_em ASC,p.id ASC LIMIT 50 OFFSET %d", ($page - 1) * 50));
        self::table_start(['Aluno / pedido', 'Materiais desta entrega', 'Situação', 'Registrar']);
        foreach ($rows as $o) {
            echo '<tr><td><strong>' . esc_html($o->nome_aluno) . '</strong><br>' . esc_html($o->turma) . ' · RA ' . esc_html($o->ra) . '<br>Pedido #' . (int) $o->id . '</td><td><ul>';
            if ($o->entrega_status === 'entregue') {
                foreach ((json_decode((string) $o->delivery_items, true) ?: []) as $m) {
                    echo '<li>' . (int) ($m['quantidade'] ?? 1) . ' × ' . esc_html($m['nome'] ?? '#' . $m['id']) . '</li>';
                }
            } else {
                foreach (Service::materials((int) $p->id, $o->turma, $stage) as $m) {
                    echo '<li>' . (int) $m->quantidade_por_aluno . ' × ' . esc_html($m->nome) . '</li>';
                }
            }
            echo '</ul></td><td>' . esc_html($o->entrega_status ?: 'pendente');
            if ($o->entrega_status === 'entregue') {
                echo '<br><small>' . esc_html($o->data_entrega) . '<br>' . esc_html($o->recebido_por ?: '') . '</small>';
                if (strpos((string) $o->entrega_assinatura, 'data:image/png;base64,') === 0) {
                    echo '<details><summary>Assinatura</summary><img class="k8-sign-preview" alt="Assinatura do recebimento" src="' . esc_attr($o->entrega_assinatura) . '"></details>';
                }
            }
            echo '</td><td>';
            self::form('delivery', (int) $p->id);
            echo '<input type="hidden" name="pedido_id" value="' . (int) $o->id . '"><input type="hidden" name="semestre" value="' . $stage . '">';
            if ($o->entrega_status === 'entregue') {
                echo '<input type="hidden" name="acao" value="desfazer">';
                self::field('motivo', 'Justificativa do estorno', '', 'text', 'required minlength="5"');
                self::button('Desfazer entrega');
            } else {
                echo '<input type="hidden" name="acao" value="entregar"><input type="hidden" name="assinatura"><button type="button" class="k8-btn" data-k8-sign data-student="' . esc_attr($o->nome_aluno) . '">Confirmar entrega e assinar</button>';
            }
            echo '</form></td></tr>';
        }
        self::table_end();
        self::pagination($total, $page);
        ?>
        <dialog class="k8-sign-dialog"><form class="k8-form" data-k8-sign-form><h3>Confirmar recebimento</h3><p data-k8-sign-name></p><label>Nome de quem está recebendo<input name="recebido_por" required maxlength="255"></label><p class="k8-muted">Confira os materiais da linha antes de confirmar. Assine abaixo:</p><canvas width="600" height="220" aria-label="Área de assinatura"></canvas><div class="k8-inline"><button type="button" class="k8-btn secondary" data-k8-clear>Limpar</button><button type="button" class="k8-btn secondary" data-k8-close>Voltar</button><button type="submit" class="k8-btn">Registrar entrega</button></div><p data-k8-sign-error class="k8-error" role="alert"></p></form></dialog>
        <?php
    }

    private static function coupons(object $p): void
    {
        global $wpdb;
        echo '<section class="k8-card"><h3>Criar ou atualizar cupom</h3><p class="k8-muted">Reutilize o código para atualizar as regras. O histórico de usos será mantido.</p>';
        self::form('save_coupon', (int) $p->id, 'k8-form-grid');
        self::field('codigo', 'Código', '', 'text', 'required maxlength="50" pattern="[A-Za-z0-9_-]+"');
        self::field('percentual', 'Desconto (%)', '', 'number', 'required min="0.01" max="100" step="0.01"');
        self::field('turma_restrita', 'Turma (vazio = todas)');
        self::field('validade', 'Validade', '', 'date');
        self::field('uso_maximo', 'Limite de usos (vazio = ilimitado)', '', 'number', 'min="1"');
        self::button('Salvar cupom');
        echo '</form></section>';
        $table = Support::table('cupons');
        self::table_start(['Código', 'Desconto', 'Turma', 'Validade', 'Usos', 'Situação']);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d ORDER BY id DESC", $p->id)) as $c) {
            echo '<tr><td>' . esc_html($c->codigo) . '</td><td>' . esc_html($c->percentual) . '%</td><td>' . esc_html($c->turma_restrita ?: 'Todas') . '</td><td>' . esc_html($c->validade ?: 'Sem prazo') . '</td><td>' . (int) $c->usado_count . ' / ' . esc_html($c->uso_maximo ?? '∞') . '</td><td>';
            self::form('toggle_coupon', (int) $p->id);
            echo '<input type="hidden" name="id" value="' . (int) $c->id . '">';
            self::button($c->ativo ? 'Desativar' : 'Ativar');
            echo '</form></td></tr>';
        }
        self::table_end();
    }

    private static function history(object $p): void
    {
        global $wpdb;
        $table = Support::table('estoque_movimentos');
        $materials = Support::table('materiais');
        $page = max(1, absint($_GET['k8_page'] ?? 1));
        $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE produto_id = %d", $p->id));
        echo '<h3>Movimentações de estoque</h3>';
        self::table_start(['Data', 'Material', 'Movimento', 'Observação', 'Operador']);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT s.*,m.nome FROM $table s LEFT JOIN $materials m ON m.id = s.material_id AND m.produto_id = s.produto_id WHERE s.produto_id = %d ORDER BY s.id DESC LIMIT 50 OFFSET %d", $p->id, ($page - 1) * 50)) as $row) {
            echo '<tr><td>' . esc_html($row->criado_em) . '</td><td>' . esc_html($row->nome ?: '#' . $row->material_id) . '</td><td>' . esc_html($row->tipo) . ' · ' . (int) $row->quantidade . '</td><td>' . esc_html($row->observacao) . '</td><td>' . esc_html($row->usuario) . '</td></tr>';
        }
        self::table_end();
        self::pagination($total, $page);
        echo '<h3>Entregas, estornos e cancelamentos recentes</h3>';
        $table = Support::table('entregas_log');
        self::table_start(['Data', 'Pedido', 'Ação', 'Operador', 'Detalhes']);
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d ORDER BY id DESC LIMIT 100", $p->id)) as $row) {
            echo '<tr><td>' . esc_html($row->criado_em) . '</td><td>#' . (int) $row->pedido_id . '</td><td>' . esc_html($row->acao) . '</td><td>' . esc_html($row->usuario) . '</td><td>' . esc_html($row->motivo ?: '—');
            $details = json_decode((string) $row->dados, true) ?: [];
            if (!empty($details['semestre'])) {
                echo '<br>Etapa ' . (int) $details['semestre'];
            }
            foreach (($details['materiais'] ?? []) as $m) {
                echo '<br>' . (int) ($m['quantidade'] ?? 1) . ' × ' . esc_html($m['nome'] ?? '#' . $m['id']);
            }
            if (strpos((string) $row->assinatura, 'data:image/png;base64,') === 0) {
                echo '<details><summary>Assinatura</summary><img class="k8-sign-preview" alt="Assinatura registrada" src="' . esc_attr($row->assinatura) . '"></details>';
            }
            echo '</td></tr>';
        }
        self::table_end();
    }

    public static function admin_products(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        self::shell();
        wp_enqueue_media();
        echo '<h1>Produtos e preços</h1><div class="k8-grid"><section class="k8-card"><h2>Produtos cadastrados</h2><a href="' . esc_url(admin_url('admin.php?page=k8prod-products')) . '">+ Novo produto</a><ul class="k8-product-list">';
        foreach (Support::products() as $product) {
            echo '<li><a href="' . esc_url(admin_url('admin.php?page=k8prod-products&produto_id=' . $product->id)) . '">' . esc_html($product->nome) . '</a> ' . ($product->ativo ? '' : '<span class="k8-muted">Inativo</span>') . '<br><code>[k8_produto id="' . (int) $product->id . '"]</code></li>';
        }
        echo '</ul></section><section class="k8-card">';
        $id = absint($_GET['produto_id'] ?? 0);
        $p = (object) ['nome' => '', 'descricao' => '', 'valor_padrao' => null, 'etapas' => 1, 'permitir_negativo' => 0, 'vencimento_dias' => 5, 'ativo' => 1,
            'categoria' => 'didatico', 'imagem_id' => 0, 'publicado' => 1, 'ordem' => 0];
        if ($id) {
            try {
                $p = Support::product($id);
            } catch (\Throwable $e) {
                echo '<p>' . esc_html($e->getMessage()) . '</p></section></div></div>';
                return;
            }
        }
        echo '<h2>' . ($id ? 'Editar produto' : 'Criar produto') . '</h2>';
        self::form('save_product', $id);
        self::field('nome', 'Nome', $p->nome, 'text', 'required maxlength="255"');
        echo '<label>Descrição<textarea name="descricao" rows="3">' . esc_textarea($p->descricao) . '</textarea></label>';
        echo '<label>Categoria<select name="categoria">';
        foreach (Storefront::categories() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($p->categoria, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label><input type="hidden" name="imagem_id" value="' . (int) $p->imagem_id . '"><div data-k8-image-preview>';
        if ($p->imagem_id) {
            echo wp_get_attachment_image((int) $p->imagem_id, 'thumbnail');
        }
        echo '</div><div class="k8-inline"><button class="k8-btn secondary" type="button" data-k8-image>Escolher imagem</button><button class="k8-btn secondary" type="button" data-k8-image-remove>Remover imagem</button></div>';
        self::field('ordem', 'Ordem de exibição no catálogo', $p->ordem, 'number');
        echo '<label class="k8-check"><input type="checkbox" name="publicado" value="1" ' . checked($p->publicado, 1, false) . '>Exibir este produto na home e no catálogo</label>';
        self::field('valor_padrao', 'Preço padrão (vazio = somente turmas com preço)', $p->valor_padrao ?? '', 'text', 'inputmode="decimal"');
        echo '<label>Modelo de entrega<select name="etapas"><option value="1" ' . selected($p->etapas, 1, false) . '>Entrega única</option><option value="2" ' . selected($p->etapas, 2, false) . '>Duas etapas / semestres</option></select></label>';
        self::field('vencimento_dias', 'Dias para vencimento da cobrança', $p->vencimento_dias, 'number', 'min="1" max="365" required');
        echo '<label class="k8-check"><input type="checkbox" name="permitir_negativo" value="1" ' . checked($p->permitir_negativo, 1, false) . '>Permitir saídas com estoque negativo</label><label class="k8-check"><input type="checkbox" name="ativo" value="1" ' . checked($p->ativo, 1, false) . '>Produto ativo para novas compras</label>';
        self::button('Salvar produto');
        echo '</form></section></div>';
        if ($id) {
            echo '<section class="k8-card"><h2>Publicar e administrar</h2><p><a target="_blank" rel="noopener" href="' . esc_url(Storefront::url($id)) . '">Abrir página de compra deste produto ↗</a></p><p>Fluxo de compra: <code>[k8_produto id="' . $id . '"]</code></p><p>Painel com seletor: <code>[k8_dashboard]</code></p><p>Painel iniciando neste produto: <code>[k8_dashboard produto="' . $id . '"]</code></p></section><section class="k8-card"><h2>Preço por turma</h2><p class="k8-muted">O preço da turma prevalece sobre o preço padrão. Sem preço padrão, somente as turmas cadastradas podem comprar.</p>';
            self::form('save_price', $id, 'k8-inline');
            self::field('turma', 'Turma (igual à base de alunos)', '', 'text', 'required maxlength="100"');
            self::field('valor', 'Valor (R$)', '', 'text', 'required inputmode="decimal"');
            self::button('Salvar preço');
            echo '</form>';
            $table = Support::table('valores_turma');
            self::table_start(['Turma', 'Preço', 'Ação']);
            foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d ORDER BY turma", $id)) as $price) {
                echo '<tr><td>' . esc_html($price->turma) . '</td><td>' . esc_html(self::currency($price->valor)) . '</td><td>';
                self::form('remove_price', $id);
                echo '<input type="hidden" name="id" value="' . (int) $price->id . '">';
                self::button('Remover preço');
                echo '</form></td></tr>';
            }
            self::table_end();
            echo '</section>';
            self::discounts_form($id);
        }
        echo '</div>';
    }

    private static function discounts_form(int $product): void
    {
        global $wpdb;
        $table = Support::table('descontos_data');
        $edit = absint($_GET['k8_discount'] ?? 0);
        $rule = $edit ? $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d AND produto_id = %d", $edit, $product)) : null;
        $rule = $rule ?: (object) ['id' => 0, 'titulo' => '', 'tipo' => 'percentual', 'valor' => '', 'turma_restrita' => '', 'inicio' => '', 'fim' => '', 'acumular_cupom' => 0];
        echo '<section class="k8-card" id="descontos-data"><h2>Descontos por data</h2><p>Cadastre quantas faixas precisar. Durante o período, o catálogo e a compra mostram o preço normal, o preço com desconto e a data limite.</p><p class="k8-muted">A data final inclui o dia inteiro. Fuso do site: ' . esc_html(wp_timezone_string()) . '. Faixas sobrepostas usam o menor preço, sem somar descontos. Pedidos já gerados mantêm seu valor.</p>';
        self::form('save_discount', $product, 'k8-form-grid');
        echo '<input type="hidden" name="id" value="' . (int) $rule->id . '">';
        self::field('titulo', 'Nome do desconto', $rule->titulo, 'text', 'required maxlength="160" placeholder="Ex.: Compra antecipada"');
        echo '<label>Tipo<select name="tipo"><option value="percentual" ' . selected($rule->tipo, 'percentual', false) . '>Desconto percentual (%)</option><option value="preco" ' . selected($rule->tipo, 'preco', false) . '>Preço promocional (R$)</option></select></label>';
        self::field('valor', 'Percentual ou preço final', $rule->valor, 'text', 'required inputmode="decimal" placeholder="Ex.: 10 ou 890,00"');
        self::field('turma_restrita', 'Turma (vazio = todas)', $rule->turma_restrita, 'text', 'maxlength="100"');
        self::field('inicio', 'Início (vazio = imediato)', $rule->inicio, 'date');
        self::field('fim', 'Válido até (inclusive)', $rule->fim, 'date', 'required');
        echo '<label class="k8-check"><input type="checkbox" name="acumular_cupom" value="1" ' . checked($rule->acumular_cupom, 1, false) . '>Permitir acumular com cupom</label>';
        self::button($rule->id ? 'Atualizar desconto' : 'Cadastrar desconto');
        echo '</form><p class="k8-muted">Sem acumulação, vale o melhor preço entre a promoção e o cupom; um cupom que não melhorar o valor não será consumido.</p>';
        if ($rule->id) {
            echo '<a href="' . esc_url(admin_url('admin.php?page=k8prod-products&produto_id=' . $product . '#descontos-data')) . '">Cadastrar outro desconto</a>';
        }
        self::table_start(['Nome / turma', 'Desconto', 'Período', 'Situação', 'Ações']);
        $today = Discounts::today();
        foreach ($wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE produto_id = %d ORDER BY fim ASC,id ASC", $product)) as $row) {
            $status = !$row->ativo ? 'Inativo' : ($row->fim < $today ? 'Encerrado' : ($row->inicio && $row->inicio > $today ? 'Agendado' : 'Em vigor'));
            echo '<tr><td><strong>' . esc_html($row->titulo) . '</strong><br>' . esc_html($row->turma_restrita ?: 'Todas as turmas') . '</td><td>' .
                esc_html($row->tipo === 'percentual' ? number_format((float) $row->valor, 2, ',', '.') . '%' : self::currency($row->valor)) . '<br><small>' .
                ($row->acumular_cupom ? 'Acumula com cupom' : 'Não acumula') . '</small></td><td>' .
                esc_html(($row->inicio ? Discounts::date_label($row->inicio) : 'Imediato') . ' até ' . Discounts::date_label($row->fim)) . '</td><td>' . esc_html($status) . '</td><td>';
            echo '<a href="' . esc_url(add_query_arg(['page' => 'k8prod-products', 'produto_id' => $product, 'k8_discount' => $row->id], admin_url('admin.php')) . '#descontos-data') . '">Editar</a>';
            self::form('toggle_discount', $product);
            echo '<input type="hidden" name="id" value="' . (int) $row->id . '">';
            self::button($row->ativo ? 'Desativar' : 'Ativar');
            echo '</form></td></tr>';
        }
        self::table_end();
        echo '</section>';
    }

    public static function admin_settings(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        self::shell();
        echo '<h1>Configurações K8</h1>';
        self::form('settings', 0);
        echo '<section class="k8-card"><h2>Credenciais</h2><p>As credenciais existentes são reaproveitadas. Deixe os campos vazios para mantê-las.</p>';
        self::field('client_id', 'Client ID', '', 'text', 'autocomplete="off"');
        self::field('client_secret', 'Client Secret', '', 'password', 'autocomplete="new-password"');
        self::field('aes_key', 'Chave AES', '', 'password', 'autocomplete="new-password"');
        echo '<label class="k8-check"><input type="checkbox" name="producao" value="1" ' . checked(get_option('k8p_producao', '0'), '1', false) . '>Usar ambiente de produção</label></section><section class="k8-card"><h2>Base de alunos</h2><p>O nome da tabela recebe o prefixo do WordPress automaticamente. As colunas do segundo responsável são opcionais.</p>';
        $labels = ['table' => 'Tabela (sem prefixo)', 'ra' => 'Coluna RA', 'name' => 'Nome do aluno', 'class' => 'Turma',
            'cpf1' => 'CPF do responsável 1', 'parent1' => 'Nome do responsável 1', 'cpf2' => 'CPF do responsável 2', 'parent2' => 'Nome do responsável 2'];
        foreach (Students::config() as $key => $value) {
            self::field('student_' . $key, $labels[$key], $value);
        }
        echo '</section><details class="k8-card"><summary>Endpoints K8 e URLs de checkout</summary><p>Os padrões foram mantidos do cliente fornecido. Confirme os caminhos de consulta, cancelamento e sandbox com a documentação da sua conta K8.</p>';
        $defaults = ['api_production' => 'https://pagamentos.meuk8.com.br/CobrancasAPI', 'api_sandbox' => 'https://sandbox.meuk8.com.br/CobrancasAPI',
            'checkout_production' => 'https://pagamentos.k8bank.com.br/cobrancasui/checkout', 'checkout_sandbox' => 'https://pagamentos.k8bank.com.br/cobrancasui/checkout',
            'query_path' => '/api/Cobranca?Id={id}', 'cancel_path' => '/api/Cobranca/{id}'];
        foreach ($defaults as $key => $value) {
            self::field($key, $key, get_option('k8prod_' . $key, $value), strpos($key, 'path') !== false ? 'text' : 'url', 'required');
        }
        echo '</details>';
        self::button('Salvar configurações');
        echo '</form>';
        Updates::status();
        echo '</div>';
    }
}
