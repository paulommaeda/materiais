<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class StudentImport
{
    public static function register(): void
    {
        add_action('admin_menu', static function () {
            add_submenu_page('k8prod', 'Alunos e responsáveis', 'Alunos', 'manage_options', 'k8prod-students', [self::class, 'admin']);
        });
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

    public static function save_import(array $compiled, bool $replace_all): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'lista_alunos_v4';
        $guardians = $wpdb->prefix . 'sba_guardians';
        Support::lock('students-import', static function () use ($wpdb, $table, $guardians, $compiled, $replace_all) {
            Support::transaction(static function () use ($wpdb, $table, $guardians, $compiled, $replace_all) {
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
                    } else {
                        Support::must($wpdb->insert($table, $row));
                    }
                }
                foreach ($compiled['links'] as $link) {
                    Support::must($wpdb->insert($guardians, $link));
                }
            });
            update_option('k8prod_student_revision', (int) get_option('k8prod_student_revision', 1) + 1, false);
        });
        $report = ['time' => current_time('mysql'), 'students' => count($compiled['rows']),
            'guardians' => count($compiled['links']), 'source_rows' => $compiled['source_rows'], 'warnings' => $compiled['warnings']];
        update_option('sba_import_report', $report, false);
        return $report;
    }

    public static function upload(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão.', '', ['response' => 403]);
        }
        check_admin_referer('k8prod_import_students');
        try {
            $f = $_FILES['arquivo_csv'] ?? [];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || empty($f['tmp_name']) || !is_uploaded_file($f['tmp_name']) ||
                strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'csv' || $f['size'] > 10 * 1024 * 1024) {
                throw new \InvalidArgumentException('Envie um arquivo CSV de até 10 MB.');
            }
            $compiled = self::parse_csv((string) file_get_contents($f['tmp_name']));
            if (is_wp_error($compiled)) {
                throw new \InvalidArgumentException($compiled->get_error_message());
            }
            self::save_import($compiled, !empty($_POST['limpar_tabela']));
            $message = 'Importação concluída: ' . count($compiled['rows']) . ' aluno(s), ' . count($compiled['links']) . ' vínculo(s).';
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
        echo '<section class="k8-card"><h2>Importar CSV</h2><p>Dez colunas: RA, aluno, CPF do aluno, turma, nome/CPF dos responsáveis 1 e 2 e telefone dos responsáveis 1 e 2. Repita o RA para acrescentar outros responsáveis.</p><a href="' . esc_url(plugins_url('assets/modelo-alunos.csv', K8PROD_FILE)) . '" download>Baixar modelo CSV</a>';
        echo '<form class="k8-form" method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="k8prod_import_students">';
        wp_nonce_field('k8prod_import_students');
        echo '<label>Arquivo CSV<input type="file" name="arquivo_csv" accept=".csv,text/csv" required></label><label class="k8-check"><input type="checkbox" name="limpar_tabela" value="1">Substituir toda a lista. Alunos ausentes no arquivo serão removidos.</label><p class="k8-muted">Sem essa opção, cada RA enviado substitui seus dados e todos os seus vínculos; outros alunos são preservados. Pedidos e entregas existentes permanecem no histórico.</p><button class="k8-btn" type="submit">Importar alunos</button></form></section>';
        $report = get_option('sba_import_report', []);
        if (!empty($report['warnings'])) {
            echo '<details class="k8-card"><summary>Avisos da última importação (' . count($report['warnings']) . ')</summary><div class="k8-scroll"><table><thead><tr><th>Registro</th><th>RA</th><th>Campo</th><th>Aviso</th></tr></thead><tbody>';
            foreach ($report['warnings'] as $warning) {
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
public static function digits($value) { return preg_replace('/\D/', '', is_scalar($value) ? (string) $value : ''); }

public static function valid_cpf($cpf) {
    if (!preg_match('/^\d{11}$/D', $cpf) || preg_match('/^(\d)\1{10}$/D', $cpf)) return false;
    for ($t = 9; $t < 11; $t++) {
        $sum = 0;
        for ($i = 0; $i < $t; $i++) $sum += (int) $cpf[$i] * ($t + 1 - $i);
        $digit = ($sum * 10) % 11;
        if ($digit === 10) $digit = 0;
        if ((int) $cpf[$t] !== $digit) return false;
    }
    return true;
}

public static function phone($value) {
    $n = self::digits($value);
    if (strlen($n) === 10 || strlen($n) === 11) $n = '55' . $n;
    return preg_match('/^55[1-9][0-9](?:[2-5][0-9]{7}|9[0-9]{8})$/D', $n) ? '+' . $n : '';
}

public static function compile_rows($rows, $strict = true) {
    $students = array(); $groups = array(); $warnings = array();
    foreach ($rows as $offset => $row) {
        $line = $row['_line'] ?? $offset + 1;
        $ra = trim((string) ($row['ra'] ?? ''));
        $name = trim((string) ($row['nome_aluno'] ?? ''));
        if ($ra === '' || $name === '' || strlen($ra) > 100 || strlen($name) > 255) {
            return new \WP_Error('row', "Registro $line: informe RA e nome válidos. Nada foi importado.");
        }
        if (strlen((string) ($row['turma'] ?? '')) > 100 || strlen((string) ($row['nome_resp1'] ?? '')) > 255 || strlen((string) ($row['nome_resp2'] ?? '')) > 255) return new \WP_Error('length', "Registro $line: turma ou responsável excede o tamanho permitido.");
        $row['ra'] = $ra; $row['nome_aluno'] = $name;
        foreach (array('cpf_aluno','cpf_resp1','cpf_resp2') as $key) $row[$key] = self::digits($row[$key] ?? '');
        if (isset($students[$ra])) {
            foreach (array('nome_aluno','cpf_aluno','turma') as $field) {
                $old = trim((string) ($students[$ra][$field] ?? ''));
                $new = trim((string) ($row[$field] ?? ''));
                if ($old !== '' && $new !== '' && $old !== $new) return new \WP_Error('conflict', "Registro $line: RA $ra tem dados divergentes em $field. Corrija antes de importar. Nada foi alterado.");
                if ($old === '' && $new !== '') $students[$ra][$field] = $new;
            }
        } else {
            $students[$ra] = array_intersect_key($row, array_flip(array('ra','nome_aluno','cpf_aluno','turma','nome_resp1','cpf_resp1','nome_resp2','cpf_resp2','telefone_resp1','telefone_resp2')));
            foreach (array(1,2) as $n) $students[$ra]['telefone_resp'.$n] = self::phone($row['telefone_resp'.$n] ?? '');
        }
        foreach (array(1,2) as $n) {
            $cpf = $row['cpf_resp'.$n];
            $raw = trim((string) ($row['telefone_resp'.$n] ?? ''));
            $responsible = trim((string) ($row['nome_resp'.$n] ?? ''));
            if (!$cpf) {
                if ($raw || $responsible) $warnings[] = array('line'=>$line,'ra'=>$ra,'field'=>'Responsável '.$n,'message'=>'Sem CPF.');
                continue;
            }
            if (!self::valid_cpf($cpf)) {
                if ($strict) return new \WP_Error('cpf', "Registro $line: CPF do responsável $n inválido. Corrija antes de importar. Nada foi alterado.");
                continue;
            }
            $phone = self::phone($raw);
            if (!$phone) $warnings[] = array('line'=>$line,'ra'=>$ra,'field'=>'Telefone responsável '.$n,'message'=>$raw === '' ? 'Telefone ausente.' : 'Telefone inválido.');
            $key = hash('sha256', $ra . '|' . $cpf);
            if (!isset($groups[$key])) $groups[$key] = array('ra'=>$ra,'cpf'=>$cpf,'nome'=>$responsible,'phones'=>array(),'details'=>array());
            if (!$groups[$key]['nome'] && $responsible) $groups[$key]['nome'] = $responsible;
            if ($phone) $groups[$key]['phones'][$phone] = true;
            $detail = array('nome'=>$responsible,'telefone_original'=>$raw);
            $groups[$key]['details'][hash('sha256', wp_json_encode($detail))] = $detail;
        }
    }
    $links = array();
    foreach ($groups as $key => $g) {
        $count = count($g['phones']);
        $links[] = array(
            'link_hash'       => $key,
            'ra'              => $g['ra'],
            'cpf'             => $g['cpf'],
            'nome'            => $g['nome'],
            'telefone'        => $count === 1 ? (string) array_key_first($g['phones']) : '',
            'telefone_status' => $count > 1 ? 'conflict' : ($count === 1 ? 'valid' : 'missing'),
            'source_details'  => wp_json_encode(array_values($g['details']))
        );
    }
    return array('rows'=>array_values($students),'links'=>$links,'warnings'=>$warnings,'source_rows'=>count($rows));
}

public static function parse_csv($raw) {
    if (strlen($raw) > 10 * 1024 * 1024) return new \WP_Error('size', 'O CSV deve ter até 10 MB.');
    if (!preg_match('//u', $raw)) {
        if (!function_exists('iconv')) return new \WP_Error('encoding', 'Exporte o CSV em UTF-8.');
        $raw = iconv('Windows-1252', 'UTF-8', $raw);
        if ($raw === false) return new \WP_Error('encoding', 'Exporte o CSV em UTF-8.');
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $first = strtok($raw, "\r\n");
    if ($first === false) return new \WP_Error('empty', 'O arquivo está vazio.');
    $delimiter = count(str_getcsv($first, ';', '"', '')) >= count(str_getcsv($first, ',', '"', '')) ? ';' : ',';
    $handle = fopen('php://temp', 'w+'); fwrite($handle, $raw); rewind($handle);
    $rows = array(); $line = 0;
    $columns = array('ra','nome_aluno','cpf_aluno','turma','nome_resp1','cpf_resp1','nome_resp2','cpf_resp2','telefone_resp1','telefone_resp2');
    while (($data = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $line++;
        if (count($data) === 1 && trim((string) $data[0]) === '') continue;
        if (count($data) !== 10) { fclose($handle); return new \WP_Error('columns', "Registro $line: são necessárias 10 colunas. Nada foi importado."); }
        if ($line === 1 && strtolower(trim((string) $data[0])) === 'ra') continue;
        $row = array_combine($columns, array_map(function($v) { return sanitize_text_field(trim($v)); }, $data));
        $row['_line'] = $line; $rows[] = $row;
    }
    fclose($handle);
    if (!$rows) return new \WP_Error('empty', 'Nenhum aluno encontrado. A lista atual foi preservada.');
    return self::compile_rows($rows);
}


}
