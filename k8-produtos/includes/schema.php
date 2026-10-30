<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Schema
{
    public static function activate(bool $network_wide = false): void
    {
        if (is_multisite() && $network_wide) {
            wp_die('Ative o K8 Produtos individualmente em cada site da rede.');
        }
        try {
            self::install();
        } catch (\Throwable $e) {
            wp_die(esc_html($e->getMessage()));
        }
        // Reconhece somente os três plugins fornecidos, pelo nome exato.
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        $legacy = ['K8 Cobranças - Materiais e Estoque', 'K8 Cobranças - Dashboard e Entrega', 'K8 Cobranças - Pagamento de Material Didático', 'Sistema de Alunos — Busca Direta', 'Sistema de Alunos — WhatsApp NX e SMS'];
        foreach (get_plugins() as $file => $info) {
            if (in_array($info['Name'], $legacy, true) && is_plugin_active($file)) {
                deactivate_plugins($file);
            }
        }
        wp_clear_scheduled_hook('k8p_verificar_status_pagamentos');
        $role = get_role('administrator');
        if ($role) {
            $role->add_cap('k8_manage_products');
        }
    }

    private static function exists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    public static function install(): void
    {
        Support::lock('schema', static function () {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $legacy = self::exists(Support::table('pedidos')) && get_option('k8prod_schema') === false;
            $collate = $wpdb->get_charset_collate();
            $definitions = [
                'produtos' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                    nome varchar(255) NOT NULL,
                    slug varchar(190) NOT NULL,
                    descricao text DEFAULT NULL,
                    categoria varchar(30) NOT NULL DEFAULT 'didatico',
                    imagem_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    publicado tinyint(1) NOT NULL DEFAULT 1,
                    ordem int NOT NULL DEFAULT 0,
                    valor_padrao decimal(10,2) DEFAULT NULL,
                    turmas_disponiveis longtext DEFAULT NULL,
                    formas_pagamento varchar(100) DEFAULT NULL,
                    cartao_parcelas tinyint(3) unsigned NOT NULL DEFAULT 1,
                    etapas tinyint(1) NOT NULL DEFAULT 1,
                    permitir_negativo tinyint(1) NOT NULL DEFAULT 0,
                    vencimento_dias int NOT NULL DEFAULT 5,
                    ativo tinyint(1) NOT NULL DEFAULT 1,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    UNIQUE KEY slug (slug)",
                'valores_turma' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    turma varchar(100) NOT NULL,
                    valor decimal(10,2) NOT NULL,
                    PRIMARY KEY  (id),
                    UNIQUE KEY produto_turma (produto_id,turma)",
                'pedidos' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    active_key varchar(64) DEFAULT NULL,
                    ra varchar(100) NOT NULL,
                    nome_aluno varchar(255) NOT NULL,
                    cpf_resp varchar(20) NOT NULL,
                    nome_resp varchar(255) NOT NULL,
                    turma varchar(100) NOT NULL,
                    valor decimal(10,2) NOT NULL,
                    valor_original decimal(10,2) DEFAULT NULL,
                    cupom_codigo varchar(50) DEFAULT NULL,
                    promocao_id mediumint(9) DEFAULT NULL,
                    promocao_titulo varchar(160) DEFAULT NULL,
                    promocao_fim date DEFAULT NULL,
                    valor_apos_data decimal(10,2) DEFAULT NULL,
                    cupom_reservado tinyint(1) NOT NULL DEFAULT 0,
                    origem varchar(30) NOT NULL DEFAULT 'k8',
                    metodo varchar(20) NOT NULL,
                    cartao_parcelas_max tinyint(3) unsigned DEFAULT NULL,
                    parcelamento_regra_id mediumint(9) DEFAULT NULL,
                    parcelamento_regra_titulo varchar(160) DEFAULT NULL,
                    parcelamento_regra_fim date DEFAULT NULL,
                    pagamento_referencia varchar(64) DEFAULT NULL,
                    link_pagamento_id varchar(50) DEFAULT NULL,
                    checkout_url text DEFAULT NULL,
                    cliente_id varchar(50) DEFAULT NULL,
                    cobranca_id varchar(50) DEFAULT NULL,
                    status varchar(30) NOT NULL DEFAULT 'iniciado',
                    status_pagamento varchar(30) DEFAULT NULL,
                    verificado_em datetime DEFAULT NULL,
                    erro_mensagem text DEFAULT NULL,
                    resposta_raw longtext DEFAULT NULL,
                    entregue tinyint(1) NOT NULL DEFAULT 0,
                    entregue_em datetime DEFAULT NULL,
                    entregue_por varchar(255) DEFAULT NULL,
                    assinatura longtext DEFAULT NULL,
                    motivo_desfazer text DEFAULT NULL,
                    desfeito_por varchar(255) DEFAULT NULL,
                    desfeito_em datetime DEFAULT NULL,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    UNIQUE KEY active_key (active_key),
                    UNIQUE KEY link_pagamento_id (link_pagamento_id),
                    KEY produto_ra (produto_id,ra),
                    KEY produto_status (produto_id,status_pagamento),
                    KEY cobranca_id (cobranca_id)",
                'descontos_data' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL,
                    titulo varchar(160) NOT NULL,
                    turma_restrita varchar(100) DEFAULT NULL,
                    tipo varchar(20) NOT NULL,
                    valor decimal(10,2) NOT NULL,
                    inicio date DEFAULT NULL,
                    fim date NOT NULL,
                    acumular_cupom tinyint(1) NOT NULL DEFAULT 0,
                    ativo tinyint(1) NOT NULL DEFAULT 1,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY produto_periodo (produto_id,ativo,fim)",
                'parcelamento_data' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL,
                    titulo varchar(160) NOT NULL,
                    inicio date DEFAULT NULL,
                    fim date NOT NULL,
                    parcelas tinyint(3) unsigned NOT NULL,
                    ativo tinyint(1) NOT NULL DEFAULT 1,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY produto_periodo (produto_id,ativo,fim)",
                'cupons' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    codigo varchar(50) NOT NULL,
                    percentual decimal(5,2) NOT NULL,
                    turma_restrita varchar(100) DEFAULT NULL,
                    validade date DEFAULT NULL,
                    uso_maximo int DEFAULT NULL,
                    usado_count int NOT NULL DEFAULT 0,
                    ativo tinyint(1) NOT NULL DEFAULT 1,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    UNIQUE KEY produto_codigo (produto_id,codigo)",
                'materiais' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    nome varchar(255) NOT NULL,
                    turma_restrita varchar(100) DEFAULT NULL,
                    periodo_entrega varchar(20) NOT NULL,
                    quantidade_por_aluno int NOT NULL DEFAULT 1,
                    estoque_atual int NOT NULL DEFAULT 0,
                    estoque_minimo int NOT NULL DEFAULT 0,
                    ativo tinyint(1) NOT NULL DEFAULT 1,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY produto_id (produto_id)",
                'estoque_movimentos' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    material_id mediumint(9) NOT NULL,
                    entrega_id mediumint(9) DEFAULT NULL,
                    tipo varchar(20) NOT NULL,
                    quantidade int NOT NULL,
                    observacao varchar(255) DEFAULT NULL,
                    usuario varchar(255) DEFAULT NULL,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY material_id (material_id),
                    KEY produto_id (produto_id)",
                'entregas' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    pedido_id mediumint(9) NOT NULL,
                    semestre tinyint(1) NOT NULL,
                    status varchar(20) NOT NULL DEFAULT 'pendente',
                    materiais_entregues longtext DEFAULT NULL,
                    assinatura longtext DEFAULT NULL,
                    recebido_por varchar(255) DEFAULT NULL,
                    entregue_por varchar(255) DEFAULT NULL,
                    entregue_em datetime DEFAULT NULL,
                    motivo_desfazer text DEFAULT NULL,
                    desfeito_por varchar(255) DEFAULT NULL,
                    desfeito_em datetime DEFAULT NULL,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    UNIQUE KEY pedido_semestre (pedido_id,semestre),
                    KEY produto_id (produto_id)",
                'entregas_log' => "id mediumint(9) NOT NULL AUTO_INCREMENT,
                    produto_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    pedido_id mediumint(9) NOT NULL,
                    entrega_id mediumint(9) NOT NULL DEFAULT 0,
                    acao varchar(20) NOT NULL,
                    usuario varchar(255) NOT NULL,
                    usuario_id bigint(20) unsigned NOT NULL DEFAULT 0,
                    motivo text DEFAULT NULL,
                    assinatura longtext DEFAULT NULL,
                    dados longtext DEFAULT NULL,
                    criado_em datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY  (id),
                    KEY pedido_id (pedido_id),
                    KEY produto_id (produto_id)"
            ];
            foreach ($definitions as $name => $definition) {
                $table = Support::table($name);
                dbDelta("CREATE TABLE $table (\n$definition\n) ENGINE=InnoDB $collate;");
                if (!self::exists($table)) {
                    throw new \RuntimeException('Falha ao criar as tabelas K8. Verifique a permissão do banco.');
                }
                $columns = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
                preg_match_all('/^\s*(\w+)\s+(?:bigint|mediumint|tinyint|int|varchar|decimal|text|longtext|datetime|date)\b/m', $definition, $required);
                if (array_diff($required[1], $columns)) {
                    throw new \RuntimeException('Falha ao atualizar as colunas K8. Verifique as permissões ALTER e CREATE do banco.');
                }
                $engine = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)));
                if ($engine && strcasecmp($engine->Engine, 'InnoDB') !== 0) {
                    Support::must($wpdb->query("ALTER TABLE $table ENGINE=InnoDB"));
                }
            }
            // Índices globais antigos impediriam repetir turma/cupom em produtos diferentes.
            foreach (['valores_turma' => 'turma', 'cupons' => 'codigo'] as $name => $column) {
                $table = Support::table($name);
                $indexes = [];
                foreach ($wpdb->get_results("SHOW INDEX FROM $table") as $index) {
                    if (!(int) $index->Non_unique) {
                        $indexes[$index->Key_name][] = $index->Column_name;
                    }
                }
                foreach ($indexes as $index => $columns) {
                    if ($columns === [$column] && preg_match('/^\w+$/D', $index)) {
                        Support::must($wpdb->query("ALTER TABLE $table DROP INDEX `$index`"));
                    }
                }
            }
            if ($legacy || get_option('k8prod_legacy_product_id')) {
                self::migrate_legacy();
            }
            StudentImport::install();
            ProductClasses::migrate();
            PaymentTerms::migrate();
            update_option('k8prod_schema', K8PROD_VERSION, false);
        });
    }

    private static function migrate_legacy(): void
    {
        global $wpdb;
        Support::transaction(static function () use ($wpdb) {
            $id = (int) get_option('k8prod_legacy_product_id', 0);
            $products = Support::table('produtos');
            if ($id && !$wpdb->get_var($wpdb->prepare("SELECT id FROM $products WHERE id = %d", $id))) {
                $id = 0;
            }
            if (!$id) {
                $id = (int) $wpdb->get_var("SELECT id FROM $products WHERE slug = 'material-didatico-legado'");
                if (!$id) {
                    Support::must($wpdb->insert($products, ['nome' => 'Material Didático — legado', 'slug' => 'material-didatico-legado',
                        'etapas' => 2, 'permitir_negativo' => 1]));
                    $id = (int) $wpdb->insert_id;
                }
                update_option('k8prod_legacy_product_id', $id, false);
            }
            foreach (['valores_turma', 'pedidos', 'cupons', 'materiais'] as $name) {
                $table = Support::table($name);
                Support::must($wpdb->query($wpdb->prepare("UPDATE $table SET produto_id = %d WHERE produto_id = 0", $id)));
            }
            $orders = Support::table('pedidos');
            $materials = Support::table('materiais');
            foreach (['entregas', 'entregas_log'] as $name) {
                $table = Support::table($name);
                Support::must($wpdb->query("UPDATE $table t INNER JOIN $orders p ON p.id = t.pedido_id SET t.produto_id = p.produto_id WHERE t.produto_id = 0"));
            }
            $movements = Support::table('estoque_movimentos');
            Support::must($wpdb->query("UPDATE $movements t INNER JOIN $materials m ON m.id = t.material_id SET t.produto_id = m.produto_id WHERE t.produto_id = 0"));
            $deliveries = Support::table('entregas');
            Support::must($wpdb->query("INSERT INTO $deliveries (produto_id,pedido_id,semestre,status,assinatura,entregue_em,entregue_por)
                SELECT p.produto_id,p.id,1,'entregue',p.assinatura,p.entregue_em,p.entregue_por FROM $orders p
                WHERE p.entregue = 1 AND NOT EXISTS (SELECT 1 FROM $deliveries e WHERE e.pedido_id = p.id AND e.semestre = 1)"));
            // Não altera saldos nem contadores de cupom já existentes.
            Support::must($wpdb->query("UPDATE $orders p INNER JOIN
                (SELECT produto_id,ra,MAX(id) AS latest FROM (SELECT * FROM $orders) source_orders
                 WHERE status IN ('gerado','iniciado','verificacao') AND (status_pagamento IS NULL OR status_pagamento <> 'Cancelada') GROUP BY produto_id,ra) chosen
                 ON p.id = chosen.latest SET p.active_key = SHA2(CONCAT(p.produto_id,':',p.ra),256) WHERE p.active_key IS NULL"));
        });
    }
}
