<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class StudentImport
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_ROWS = 10000;
    public const COLUMNS = ['ra', 'nome_aluno', 'cpf_aluno', 'turma', 'nome_resp1', 'cpf_resp1',
        'nome_resp2', 'cpf_resp2', 'telefone_resp1', 'telefone_resp2'];

    public static function register(): void
    {
        // O menu pai precisa existir antes de calcular o hook da página de alunos.
        add_action('admin_menu', static function () {
            add_submenu_page('k8prod', 'Alunos e responsáveis', 'Alunos', 'manage_options', 'k8prod-students', [self::class, 'admin']);
        }, 20);
        add_action('admin_post_k8prod_import_students', [self::class, 'upload']);
    }

    public static function install(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = $wpdb->prefix . 'lista_alunos_v4';
        $guardians = $wpdb->prefix . 'sba_guardians';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM $table", 0);
            if (!in_array('id', $columns, true)) {
                $primary = $wpdb->get_results("SHOW INDEX FROM $table WHERE Key_name = 'PRIMARY'");
                if ($primary) {
                    throw new \RuntimeException('A base lista_alunos_v4 usa uma chave primária diferente do sistema enviado. Confira a estrutura antes de importar.');
                }
                // dbDelta separa coluna e índice; AUTO_INCREMENT precisa dos dois no mesmo ALTER.
                Support::must($wpdb->query("ALTER TABLE $table ADD COLUMN id mediumint(9) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST"));
            }
        }
        dbDelta("CREATE TABLE $table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            ra varchar(100) NOT NULL,
            nome_aluno varchar(255) NOT NULL,
            cpf_aluno varchar(50) NOT NULL DEFAULT '',
            turma varchar(100) NOT NULL,
            nome_resp1 varchar(255) DEFAULT NULL,
            cpf_resp1 varchar(50) DEFAULT NULL,
            nome_resp2 varchar(255) DEFAULT NULL,
            cpf_resp2 varchar(50) DEFAULT NULL,
            telefone_resp1 varchar(20) DEFAULT NULL,
            telefone_resp2 varchar(20) DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY ra (ra)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE $guardians (
            link_hash varchar(64) NOT NULL,
            ra varchar(100) NOT NULL,
            cpf varchar(11) NOT NULL,
            nome varchar(255) NOT NULL,
            telefone varchar(20) NOT NULL,
            telefone_status varchar(20) NOT NULL,
            source_details longtext NOT NULL,
            PRIMARY KEY  (link_hash),
            KEY cpf (cpf),
            KEY ra (ra)
        ) ENGINE=InnoDB $charset;");
        foreach ([$table, $guardians] as $name) {
            $engine = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($name)));
            if (!$engine) {
                throw new \RuntimeException('Não foi possível preparar a base de alunos.');
            }
            if (strcasecmp($engine->Engine, 'InnoDB') !== 0) {
                Support::must($wpdb->query("ALTER TABLE $name ENGINE=InnoDB"));
            }
        }
        // Recupera vínculos das duas colunas antigas sem sobrescrever responsáveis adicionais.
        if (!get_option('k8prod_students_migrated')) {
            Support::transaction(static function () use ($wpdb, $table, $guardians) {
                $rows = $wpdb->get_results("SELECT * FROM $table", ARRAY_A);
                $compiled = self::compile_rows($rows, false);
                if (is_wp_error($compiled)) {
                    throw new \RuntimeException($compiled->get_error_message());
                }
                foreach ($compiled['links'] as $link) {
                    $exists = $wpdb->get_var($wpdb->prepare("SELECT link_hash FROM $guardians WHERE link_hash = %s", $link['link_hash']));
                    if (!$exists) {
                        Support::must($wpdb->insert($guardians, $link));
                    }
                }
            });
            update_option('k8prod_students_migrated', '1', false);
        }
    }

    public static function save_import(array $compiled, bool $replace_all, string $source = 'painel'): array
    {
        global $wpdb;
        if (empty($compiled['rows'])) {
            throw new \InvalidArgumentException('Nenhum aluno encontrado. A lista atual foi preservada.');
        }
        $table = $wpdb->prefix . 'lista_alunos_v4';
        $guardians = $wpdb->prefix . 'sba_guardians';
        return Support::lock('students-import', static function () use ($wpdb, $table, $guardians, $compiled, $replace_all, $source) {
            $counts = Support::transaction(static function () use ($wpdb, $table, $guardians, $compiled, $replace_all) {
                $created = 0;
                $updated = 0;
                if ($replace_all) {
                    Support::must($wpdb->query("DELETE FROM $guardians"));
                    Support::must($wpdb->query("DELETE FROM $table"));
                }
                foreach ($compiled['rows'] as $row) {
                    if (!$replace_all) {
                        Support::must($wpdb->delete($guardians, ['ra' => $row['ra']]));
                    }
                    $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE ra = %s", $row['ra']));
                    if ($existing) {
                        Support::must($wpdb->update($table, $row, ['id' => $existing]));
                        $updated++;
                    } else {
                        Support::must($wpdb->insert($table, $row));
                        $created++;
                    }
                }
                foreach ($compiled['links'] as $link) {
                    Support::must($wpdb->insert($guardians, $link));
                }
                return ['created' => $created, 'updated' => $updated];
            });
            update_option('k8prod_student_revision', (int) get_option('k8prod_student_revision', 1) + 1, false);
            $report = $counts + ['time' => current_time('mysql'), 'students' => count($compiled['rows']),
                'guardians' => count($compiled['links']), 'source_rows' => $compiled['source_rows'],
                'warnings' => $compiled['warnings'], 'source' => $source, 'user_id' => get_current_user_id(),
                'mode' => $replace_all ? 'substituir' : 'atualizar'];
            update_option('sba_import_report', $report, false);
            return $report;
        });
    }

    public static function upload_limit(): int
    {
        return min(self::MAX_BYTES, wp_max_upload_size());
    }

    public static function post_too_large(): bool
    {
        $limit = wp_convert_hr_to_bytes(ini_get('post_max_size'));
        return $limit > 0 && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit;
    }

    public static function read_upload(array $file)
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || (int) ($file['size'] ?? 0) > self::upload_limit()) {
            return new \WP_Error('upload_size', 'O arquivo excede o limite de ' . size_format(self::upload_limit()) . ' desta hospedagem. Divida a lista em arquivos menores.');
        }
        if ($error === UPLOAD_ERR_NO_FILE) {
            return new \WP_Error('upload_missing', 'Selecione um arquivo CSV antes de importar.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            return new \WP_Error('upload_failed', 'O envio do arquivo falhou (código ' . $error . '). Selecione o CSV e tente novamente; confira o diretório temporário da hospedagem se o erro continuar.');
        }
        if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) ||
            strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
            return new \WP_Error('upload_type', 'Envie um arquivo .csv. No Excel, use Salvar como CSV; renomear um XLSX não o converte.');
        }
        $raw = file_get_contents($file['tmp_name']);
        return $raw === false ? new \WP_Error('upload_read', 'Não foi possível ler o arquivo enviado. Tente novamente.') : $raw;
    }

    public static function upload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.', '', ['response' => 403]);
        }
        try {
            if (self::post_too_large()) {
                throw new \InvalidArgumentException('O envio excedeu o limite da hospedagem. Envie um CSV de até ' . size_format(self::upload_limit()) . ' ou divida a lista em arquivos menores.');
            }
            if (!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['_wpnonce'] ?? '')), 'k8prod_import_students')) {
                throw new \InvalidArgumentException('Sua sessão de importação expirou. Selecione o arquivo e tente novamente.');
            }
            $raw = self::read_upload($_FILES['arquivo_csv'] ?? []);
            if (is_wp_error($raw)) {
                throw new \InvalidArgumentException($raw->get_error_message());
            }
            $compiled = self::parse_csv($raw);
            if (is_wp_error($compiled)) {
                throw new \InvalidArgumentException($compiled->get_error_message());
            }
            $preview = !empty($_POST['validar_apenas']);
            if (!$preview) {
                self::save_import($compiled, !empty($_POST['limpar_tabela']));
            }
            $message = ($preview ? 'Arquivo validado; nenhum cadastro foi alterado: ' : 'Importação concluída: ') .
                count($compiled['rows']) . ' aluno(s), ' . count($compiled['links']) . ' vínculo(s), ' . count($compiled['warnings']) . ' aviso(s).';
            if ($preview) {
                set_transient('k8prod_import_preview_' . get_current_user_id(), $compiled['warnings'], 120);
            }
            $ok = true;
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $ok = false;
        }
        set_transient('k8prod_import_notice_' . get_current_user_id(), ['message' => $message, 'ok' => $ok], 120);
        wp_safe_redirect(admin_url('admin.php?page=k8prod-students'));
        exit;
    }

    public static function admin(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        global $wpdb;
        wp_enqueue_style('k8prod', plugins_url('assets/app.css', K8PROD_FILE), [], K8PROD_VERSION);
        $table = $wpdb->prefix . 'lista_alunos_v4';
        $guardians = $wpdb->prefix . 'sba_guardians';
        echo '<div class="k8prod"><h1>Alunos e responsáveis</h1><p>Importe, consulte e mantenha os vínculos usados em todas as compras.</p>';
        $notice = get_transient('k8prod_import_notice_' . get_current_user_id());
        if ($notice) {
            delete_transient('k8prod_import_notice_' . get_current_user_id());
            echo '<div role="status" class="k8-notice ' . ($notice['ok'] ? 'success' : '') . '">' . esc_html($notice['message']) . '</div>';
        }
        if (!Students::uses_builtin()) {
            echo '<div class="k8-notice">A busca está usando uma base externa. Esta importação e a API atualizam a base integrada lista_alunos_v4. Confira o mapeamento em K8 Produtos → Configurações para que a compra consulte a base desejada.</div>';
        }
        echo '<section class="k8-card"><h2>Importar CSV</h2><p>Use um CSV com cabeçalho. RA, nome do aluno e turma são obrigatórios; CPF do aluno, responsáveis e telefones são opcionais. A ordem das colunas pode variar. Repita o RA para acrescentar outros responsáveis.</p><p>São aceitos ponto e vírgula, vírgula ou tabulação, em UTF-8, Windows-1252 ou UTF-16 do Excel. Limite desta hospedagem: <strong>' . esc_html(size_format(self::upload_limit())) . '</strong>, até ' . self::MAX_ROWS . ' registros por envio.</p><a href="' . esc_url(plugins_url('assets/modelo-alunos.csv', K8PROD_FILE)) . '" download>Baixar modelo CSV</a>';
        // Mantém action na URL mesmo quando o PHP descarta POST/FILES por post_max_size.
        echo '<form class="k8-form" method="post" enctype="multipart/form-data" action="' . esc_url(add_query_arg('action', 'k8prod_import_students', admin_url('admin-post.php'))) . '"><input type="hidden" name="action" value="k8prod_import_students"><input type="hidden" name="MAX_FILE_SIZE" value="' . self::upload_limit() . '">';
        wp_nonce_field('k8prod_import_students');
        echo '<label>Arquivo CSV<input type="file" name="arquivo_csv" accept=".csv,text/csv" required></label><label class="k8-check"><input type="checkbox" name="limpar_tabela" value="1">Substituir toda a lista. Alunos ausentes no arquivo serão removidos.</label><p class="k8-muted">Sem essa opção, cada RA enviado substitui seus dados e todos os seus vínculos; outros alunos são preservados. Envie todos os responsáveis de cada aluno no mesmo arquivo. Pedidos e entregas existentes permanecem no histórico.</p><button class="k8-btn" type="submit" name="validar_apenas" value="1">Validar arquivo sem importar</button><button class="k8-btn" type="submit">Importar alunos</button></form></section>';
        echo '<section class="k8-card"><h2>Importar pela API</h2><p>Envie cadastros em JSON ou o arquivo CSV por <strong>POST</strong> para:</p><p><code class="k8-api-url">' . esc_html(rest_url('k8-produtos/v1/alunos/importar')) . '</code></p><p>Autentique com o usuário administrador e uma <strong>Senha de aplicação</strong> criada em <a href="' . esc_url(admin_url('profile.php#application-passwords-section')) . '">Usuários → Perfil</a>, usando HTTPS. A API também permite validar sem gravar.</p><p><a href="https://github.com/paulommaeda/materiais/blob/main/docs/API-ALUNOS.md" target="_blank" rel="noopener noreferrer">Ver exemplos de JSON, CSV, cURL e respostas</a></p></section>';
        $report = get_option('sba_import_report', []);
        if (!empty($report['time'])) {
            echo '<p class="k8-muted">Última importação: ' . esc_html($report['time']) . ' · ' . (int) ($report['students'] ?? 0) . ' aluno(s) · ' . (int) ($report['guardians'] ?? 0) . ' vínculo(s) · origem: ' . esc_html($report['source'] ?? 'painel') . '.</p>';
        }
        $preview_warnings = get_transient('k8prod_import_preview_' . get_current_user_id());
        delete_transient('k8prod_import_preview_' . get_current_user_id());
        $warnings = $preview_warnings !== false ? $preview_warnings : ($report['warnings'] ?? []);
        if ($warnings) {
            echo '<details class="k8-card"><summary>Avisos da última ' . ($preview_warnings !== false ? 'validação' : 'importação') . ' (' . count($warnings) . ')</summary><div class="k8-scroll"><table><thead><tr><th>Registro</th><th>RA</th><th>Campo</th><th>Aviso</th></tr></thead><tbody>';
            foreach ($warnings as $warning) {
                echo '<tr>';
                foreach (['line', 'ra', 'field', 'message'] as $key) {
                    echo '<td>' . esc_html((string) $warning[$key]) . '</td>';
                }
                echo '</tr>';
            }
            echo '</tbody></table></div></details>';
        }
        $search = sanitize_text_field(wp_unslash($_GET['s'] ?? ''));
        $where = $search ? $wpdb->prepare(' WHERE a.ra LIKE %s OR a.nome_aluno LIKE %s OR a.turma LIKE %s',
            '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%', '%' . $wpdb->esc_like($search) . '%') : '';
        $page = max(1, absint($_GET['alunos_page'] ?? 1));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table a $where");
        echo '<section class="k8-card"><h2>Base de alunos (' . $total . ')</h2><form class="k8-filters" method="get"><input type="hidden" name="page" value="k8prod-students"><label>Aluno, turma ou RA<input name="s" value="' . esc_attr($search) . '"></label><button class="k8-btn" type="submit">Buscar</button></form><div class="k8-scroll"><table><thead><tr><th>RA</th><th>Aluno</th><th>Turma</th><th>Responsáveis</th></tr></thead><tbody>';
        foreach ($wpdb->get_results($wpdb->prepare("SELECT a.* FROM $table a $where ORDER BY a.nome_aluno LIMIT 50 OFFSET %d", ($page - 1) * 50)) as $row) {
            echo '<tr><td>' . esc_html($row->ra) . '</td><td>' . esc_html($row->nome_aluno) . '</td><td>' . esc_html($row->turma) . '</td><td>';
            $links = $wpdb->get_results($wpdb->prepare("SELECT cpf,nome,telefone FROM $guardians WHERE ra = %s ORDER BY nome", $row->ra));
            foreach ($links as $g) {
                echo esc_html($g->nome ?: 'Responsável') . ' · CPF ' . esc_html(substr($g->cpf, 0, 3) . '.***.***-' . substr($g->cpf, -2)) . '<br>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><div class="k8-pagination"><span>Página ' . $page . '</span>';
        if ($page > 1) {
            echo '<a href="' . esc_url(add_query_arg('alunos_page', $page - 1)) . '">Anterior</a>';
        }
        if ($page * 50 < $total) {
            echo '<a href="' . esc_url(add_query_arg('alunos_page', $page + 1)) . '">Próxima</a>';
        }
        echo '</div></section></div>';
    }
    public static function digits($value): string
    {
        return preg_replace('/\D/', '', is_scalar($value) ? (string) $value : '');
    }

    public static function valid_cpf($cpf): bool
    {
        return Support::valid_cpf((string) $cpf);
    }

    public static function phone($value): string
    {
        $number = self::digits($value);
        if (strlen($number) === 10 || strlen($number) === 11) {
            $number = '55' . $number;
        }
        return preg_match('/^55[1-9][0-9](?:[2-5][0-9]{7}|9[0-9]{8})$/D', $number) ? '+' . $number : '';
    }

    public static function compile_rows($rows, $strict = true)
    {
        if (!is_array($rows) || (!$rows && $strict)) {
            return new \WP_Error('empty', 'Nenhum aluno encontrado. A lista atual foi preservada.');
        }
        if ($strict && count($rows) > self::MAX_ROWS) {
            return new \WP_Error('rows_limit', 'Envie até ' . self::MAX_ROWS . ' registros por vez. Divida a lista mantendo todos os responsáveis de cada RA no mesmo lote.');
        }
        $students = [];
        $groups = [];
        $warnings = [];
        foreach ($rows as $offset => $input) {
            if (!is_array($input)) {
                return new \WP_Error('row', 'Registro ' . ($offset + 1) . ': formato inválido. Nada foi importado.');
            }
            $line = $input['_line'] ?? $offset + 1;
            $row = [];
            foreach (self::COLUMNS as $field) {
                $value = $input[$field] ?? '';
                if (!is_scalar($value)) {
                    return new \WP_Error('field', "Registro $line: o campo $field precisa ser texto.");
                }
                $row[$field] = sanitize_text_field(trim((string) $value));
            }
            $ra = $row['ra'];
            if ($ra === '' || $row['nome_aluno'] === '' || strlen($ra) > 100 || strlen($row['nome_aluno']) > 255) {
                return new \WP_Error('row', "Registro $line: informe RA e nome válidos. Nada foi importado.");
            }
            if ($strict && $row['turma'] === '') {
                return new \WP_Error('class', "Registro $line: informe a turma do aluno. Nada foi importado.");
            }
            if (strlen($row['turma']) > 100 || strlen($row['nome_resp1']) > 255 || strlen($row['nome_resp2']) > 255) {
                return new \WP_Error('length', "Registro $line: turma ou responsável excede o tamanho permitido.");
            }
            foreach (['cpf_aluno', 'cpf_resp1', 'cpf_resp2'] as $field) {
                $raw_cpf = $row[$field];
                $row[$field] = self::digits($row[$field]);
                if ($strict && $field !== 'cpf_aluno' && $raw_cpf !== '' && !self::valid_cpf($row[$field])) {
                    return new \WP_Error('cpf', "Registro $line: CPF do responsável " . substr($field, -1) . ' inválido. Corrija antes de importar. Nada foi alterado.');
                }
            }
            if (isset($students[$ra])) {
                foreach (['nome_aluno', 'cpf_aluno', 'turma'] as $field) {
                    $old = $students[$ra][$field];
                    $new = $row[$field];
                    if ($old !== '' && $new !== '' && $old !== $new) {
                        return new \WP_Error('conflict', "Registro $line: RA $ra tem dados divergentes em $field. Corrija antes de importar. Nada foi alterado.");
                    }
                    if ($old === '' && $new !== '') {
                        $students[$ra][$field] = $new;
                    }
                }
            } else {
                $students[$ra] = $row;
                foreach ([1, 2] as $n) {
                    $students[$ra]['telefone_resp' . $n] = self::phone($row['telefone_resp' . $n]);
                }
            }
            foreach ([1, 2] as $n) {
                $cpf = $row['cpf_resp' . $n];
                $raw_phone = $row['telefone_resp' . $n];
                $name = $row['nome_resp' . $n];
                if ($cpf === '') {
                    if ($raw_phone !== '' || $name !== '') {
                        $warnings[] = ['line' => $line, 'ra' => $ra, 'field' => 'Responsável ' . $n, 'message' => 'Sem CPF; este responsável não poderá consultar o aluno.'];
                    }
                    continue;
                }
                if (!self::valid_cpf($cpf)) {
                    if ($strict) {
                        return new \WP_Error('cpf', "Registro $line: CPF do responsável $n inválido. Corrija antes de importar. Nada foi alterado.");
                    }
                    continue;
                }
                $phone = self::phone($raw_phone);
                if ($phone === '') {
                    $warnings[] = ['line' => $line, 'ra' => $ra, 'field' => 'Telefone responsável ' . $n,
                        'message' => $raw_phone === '' ? 'Telefone ausente.' : 'Telefone inválido.'];
                }
                $key = hash('sha256', $ra . '|' . $cpf);
                if (!isset($groups[$key])) {
                    $groups[$key] = ['ra' => $ra, 'cpf' => $cpf, 'nome' => $name, 'phones' => [], 'details' => []];
                }
                if ($groups[$key]['nome'] === '' && $name !== '') {
                    $groups[$key]['nome'] = $name;
                }
                if ($phone !== '') {
                    $groups[$key]['phones'][$phone] = true;
                }
                $detail = ['nome' => $name, 'telefone_original' => $raw_phone];
                $groups[$key]['details'][hash('sha256', wp_json_encode($detail))] = $detail;
            }
        }
        $linked_ras = [];
        $links = [];
        foreach ($groups as $key => $group) {
            $count = count($group['phones']);
            $links[] = ['link_hash' => $key, 'ra' => $group['ra'], 'cpf' => $group['cpf'], 'nome' => $group['nome'],
                'telefone' => $count === 1 ? (string) array_key_first($group['phones']) : '',
                'telefone_status' => $count > 1 ? 'conflict' : ($count === 1 ? 'valid' : 'missing'),
                'source_details' => wp_json_encode(array_values($group['details']))];
            $linked_ras[$group['ra']] = true;
        }
        foreach ($students as $ra => $student) {
            if (!isset($linked_ras[$ra])) {
                $warnings[] = ['line' => '—', 'ra' => (string) $ra, 'field' => 'Responsáveis',
                    'message' => 'Aluno sem responsável com CPF. A compra por CPF ficará indisponível até cadastrar um vínculo.'];
            }
        }
        return ['rows' => array_values($students), 'links' => $links, 'warnings' => $warnings, 'source_rows' => count($rows)];
    }

    private static function header_field(string $value): string
    {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', remove_accents(trim($value))));
        $aliases = [
            'ra' => ['ra', 'matricula', 'registroacademico', 'registroaluno', 'codaluno', 'codigoaluno'],
            'nome_aluno' => ['nomealuno', 'nomedoaluno', 'aluno', 'nome'],
            'cpf_aluno' => ['cpfaluno', 'cpfdoaluno'],
            'turma' => ['turma', 'classe', 'nometurma', 'descricaoturma'],
            'nome_resp1' => ['nomeresp1', 'nomeresponsavel1', 'nomedoresponsavel1', 'responsavel1', 'responsavel', 'nomeresponsavel', 'nomedoresponsavel', 'responsavelfinanceiro', 'nomeresponsavelfinanceiro'],
            'cpf_resp1' => ['cpfresp1', 'cpfresponsavel1', 'cpfdoresponsavel1', 'cpfresponsavel', 'cpfdoresponsavel', 'cpfresponsavelfinanceiro', 'cpfresp'],
            'nome_resp2' => ['nomeresp2', 'nomeresponsavel2', 'nomedoresponsavel2', 'responsavel2'],
            'cpf_resp2' => ['cpfresp2', 'cpfresponsavel2', 'cpfdoresponsavel2'],
            'telefone_resp1' => ['telefoneresp1', 'telefoneresponsavel1', 'telefonedoresponsavel1', 'telefoneresponsavel', 'telefoneresponsavelfinanceiro', 'telefoneresp', 'telefone1', 'telefone', 'celular1', 'celular'],
            'telefone_resp2' => ['telefoneresp2', 'telefoneresponsavel2', 'telefonedoresponsavel2', 'telefone2', 'celular2'],
        ];
        foreach ($aliases as $field => $names) {
            if (in_array($key, $names, true)) {
                return $field;
            }
        }
        return '';
    }

    public static function parse_csv($raw)
    {
        if (!is_string($raw) || strlen($raw) > self::MAX_BYTES) {
            return new \WP_Error('size', 'O CSV deve ter até 10 MB.');
        }
        if (substr($raw, 0, 2) === "\xFF\xFE" || substr($raw, 0, 2) === "\xFE\xFF") {
            $encoding = substr($raw, 0, 2) === "\xFF\xFE" ? 'UTF-16LE' : 'UTF-16BE';
            $raw = function_exists('iconv') ? @iconv($encoding, 'UTF-8', substr($raw, 2)) : false;
        } elseif (!preg_match('//u', $raw)) {
            $raw = function_exists('iconv') ? @iconv('Windows-1252', 'UTF-8', $raw) : false;
        }
        if ($raw === false) {
            return new \WP_Error('encoding', 'Não foi possível converter o arquivo. Exporte o CSV em UTF-8.');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (strpos($raw, "\0") !== false || substr($raw, 0, 4) === "PK\x03\x04") {
            return new \WP_Error('format', 'O arquivo não é um CSV de texto. No Excel, use Salvar como CSV, sem apenas renomear o XLSX.');
        }
        $raw = preg_replace('/\A(?:[ \t]*[\r\n])+/', '', $raw);
        $delimiter = '';
        if (preg_match('/^sep=([;,\t])(?:\r\n|\r|\n)/i', $raw, $separator)) {
            $delimiter = $separator[1];
            $raw = substr($raw, strlen($separator[0]));
        }
        $first = strtok($raw, "\r\n");
        if ($first === false) {
            return new \WP_Error('empty', 'O arquivo está vazio.');
        }
        if ($delimiter === '') {
            $best = 0;
            foreach ([';', ',', "\t"] as $candidate) {
                $count = count(str_getcsv($first, $candidate, '"', ''));
                if ($count > $best) {
                    $best = $count;
                    $delimiter = $candidate;
                }
            }
        }
        $handle = fopen('php://temp', 'w+');
        fwrite($handle, $raw);
        rewind($handle);
        $rows = [];
        $line = 0;
        $mapping = null;
        $width = 0;
        try {
            while (($data = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if (count(array_filter($data, static function ($v) { return trim((string) $v) !== ''; })) === 0) {
                    continue;
                }
                if ($mapping === null) {
                    $fields = array_map([self::class, 'header_field'], $data);
                    $is_header = in_array('ra', $fields, true) || count(array_filter($fields)) >= 2;
                    if ($is_header) {
                        $mapping = [];
                        foreach ($fields as $index => $field) {
                            if ($field === '') {
                                continue; // Colunas extras não participam da importação.
                            }
                            if (in_array($field, $mapping, true)) {
                                return new \WP_Error('header', "Cabeçalho duplicado para $field. Nada foi importado.");
                            }
                            $mapping[$index] = $field;
                        }
                        foreach (['ra', 'nome_aluno', 'turma'] as $field) {
                            if (!in_array($field, $mapping, true)) {
                                return new \WP_Error('header', "Cabeçalho sem a coluna $field. Use o modelo CSV; a ordem pode variar.");
                            }
                        }
                        $width = count($data);
                        continue;
                    }
                    if (count($data) !== 10) {
                        return new \WP_Error('columns', "Registro $line: use um cabeçalho com RA, nome_aluno e turma, ou as 10 colunas do modelo na ordem original. Nada foi importado.");
                    }
                    $mapping = self::COLUMNS;
                    $width = 10;
                }
                $required_indexes = array_keys(array_intersect($mapping, ['ra', 'nome_aluno', 'turma']));
                if (count($data) > $width || count($data) <= max($required_indexes)) {
                    return new \WP_Error('columns', "Registro $line: quantidade de colunas diferente do cabeçalho. Confira o separador e coloque entre aspas valores que contenham o separador. Nada foi importado.");
                }
                $row = array_fill_keys(self::COLUMNS, '');
                foreach ($mapping as $index => $field) {
                    $row[$field] = (string) ($data[$index] ?? '');
                }
                $row['_line'] = $line;
                $rows[] = $row;
                if (count($rows) > self::MAX_ROWS) {
                    return new \WP_Error('rows_limit', 'Envie até ' . self::MAX_ROWS . ' registros por vez. Divida a lista mantendo todos os responsáveis de cada RA no mesmo lote.');
                }
            }
        } finally {
            fclose($handle);
        }
        return self::compile_rows($rows);
    }
}
