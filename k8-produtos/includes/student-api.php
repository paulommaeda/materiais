<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class StudentApi
{
    public static function register(): void
    {
        add_action('rest_api_init', static function () {
            register_rest_route('k8-produtos/v1', '/alunos/importar', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [self::class, 'import'],
                'permission_callback' => [self::class, 'permission'],
            ]);
        });
    }

    public static function permission()
    {
        if (!is_user_logged_in()) {
            return new \WP_Error('k8prod_auth_required', 'Autentique com usuário e Senha de aplicação do WordPress por HTTPS.', ['status' => 401]);
        }
        if (!current_user_can('manage_options')) {
            return new \WP_Error('k8prod_import_forbidden', 'Somente administradores podem importar alunos.', ['status' => 403]);
        }
        return true;
    }

    private static function error(\WP_Error $error, int $status = 422): \WP_Error
    {
        return new \WP_Error('k8prod_' . $error->get_error_code(), $error->get_error_message(), ['status' => $status]);
    }

    private static function boolean($value, bool $json)
    {
        if (is_bool($value)) {
            return $value;
        }
        if (!$json && in_array($value, ['true', 'false', '1', '0'], true)) {
            return $value === 'true' || $value === '1';
        }
        return new \WP_Error('boolean', 'Use true ou false em validar_apenas e confirmar_substituicao.', ['status' => 400]);
    }

    public static function parse_students($students)
    {
        if (!is_array($students) || !$students || array_keys($students) !== range(0, count($students) - 1)) {
            return new \WP_Error('students', 'Envie alunos como uma lista JSON não vazia.');
        }
        if (count($students) > StudentImport::MAX_ROWS) {
            return new \WP_Error('rows_limit', 'Envie até ' . StudentImport::MAX_ROWS . ' registros por lote.');
        }
        $rows = [];
        foreach ($students as $offset => $student) {
            $line = $offset + 1;
            if (!is_array($student)) {
                return new \WP_Error('student', "Aluno $line: envie um objeto JSON.");
            }
            if (array_diff(array_keys($student), array_merge(StudentImport::COLUMNS, ['responsaveis']))) {
                return new \WP_Error('field', "Aluno $line: campo desconhecido. Use os nomes do modelo da API.");
            }
            foreach ($student as $field => $value) {
                if ($field !== 'responsaveis' && !is_string($value)) {
                    return new \WP_Error('field', "Aluno $line: envie $field como texto, entre aspas, para preservar zeros à esquerda.");
                }
            }
            if (!array_key_exists('responsaveis', $student)) {
                $student['_line'] = $line;
                $rows[] = $student;
                continue;
            }
            if (array_intersect(array_keys($student), ['nome_resp1', 'cpf_resp1', 'nome_resp2', 'cpf_resp2', 'telefone_resp1', 'telefone_resp2'])) {
                return new \WP_Error('guardians', "Aluno $line: use responsaveis ou os campos resp1/resp2, sem misturar os dois formatos.");
            }
            $guardians = $student['responsaveis'];
            if (!is_array($guardians) || count($guardians) > 100 || ($guardians && array_keys($guardians) !== range(0, count($guardians) - 1))) {
                return new \WP_Error('guardians', "Aluno $line: responsaveis deve ser uma lista com até 100 objetos.");
            }
            unset($student['responsaveis']);
            foreach ($guardians as $n => $guardian) {
                if (!is_array($guardian) || array_diff(array_keys($guardian), ['nome', 'cpf', 'telefone'])) {
                    return new \WP_Error('guardian', "Aluno $line: cada responsável deve ter somente nome, cpf e telefone.");
                }
                foreach ($guardian as $field => $value) {
                    if (!is_string($value)) {
                        return new \WP_Error('guardian', "Aluno $line: os campos do responsável devem ser texto, entre aspas.");
                    }
                }
            }
            // A mesma consolidação do CSV lida com dois ou mais responsáveis.
            foreach (array_chunk($guardians ?: [[]], 2) as $pair) {
                $row = $student + ['_line' => $line];
                foreach ($pair as $n => $guardian) {
                    $suffix = $n + 1;
                    $row['nome_resp' . $suffix] = $guardian['nome'] ?? '';
                    $row['cpf_resp' . $suffix] = $guardian['cpf'] ?? '';
                    $row['telefone_resp' . $suffix] = $guardian['telefone'] ?? '';
                }
                $rows[] = $row;
            }
            if (count($rows) > StudentImport::MAX_ROWS) {
                return new \WP_Error('rows_limit', 'O lote excede ' . StudentImport::MAX_ROWS . ' registros após expandir os responsáveis. Mantenha todos os responsáveis de um RA no mesmo lote.');
            }
        }
        return StudentImport::compile_rows($rows);
    }

    public static function import(\WP_REST_Request $request)
    {
        // Também protege chamadas internas diretas ao callback.
        $permission = self::permission();
        if (is_wp_error($permission)) {
            return $permission;
        }
        if (StudentImport::post_too_large() || strlen($request->get_body()) > StudentImport::MAX_BYTES) {
            return new \WP_Error('k8prod_size', 'Envio maior que o limite. Divida a lista em lotes menores.', ['status' => 413]);
        }
        $json = $request->is_json_content_type();
        $files = $request->get_file_params();
        if (!$json && !$files) {
            return new \WP_Error('k8prod_content_type', 'Envie application/json com alunos, ou multipart/form-data com arquivo_csv.', ['status' => 415]);
        }
        $data = $json ? $request->get_json_params() : $request->get_body_params();
        if (!is_array($data) || ($json && $files) || array_diff(array_keys($data), ['alunos', 'modo', 'validar_apenas', 'confirmar_substituicao'])) {
            return new \WP_Error('k8prod_payload', 'Corpo inválido. Use alunos, modo, validar_apenas e confirmar_substituicao conforme a documentação.', ['status' => 400]);
        }
        $mode = $data['modo'] ?? 'atualizar';
        if (!in_array($mode, ['atualizar', 'substituir'], true)) {
            return new \WP_Error('k8prod_mode', 'modo deve ser atualizar ou substituir.', ['status' => 400]);
        }
        $preview = self::boolean($data['validar_apenas'] ?? false, $json);
        $confirm = self::boolean($data['confirmar_substituicao'] ?? false, $json);
        if (is_wp_error($preview) || is_wp_error($confirm)) {
            return self::error(is_wp_error($preview) ? $preview : $confirm, 400);
        }
        if ($mode === 'substituir' && !$preview && !$confirm) {
            return new \WP_Error('k8prod_confirm_replace', 'Para remover alunos ausentes, envie confirmar_substituicao: true. Para atualizar sem remover outros alunos, use modo: atualizar.', ['status' => 400]);
        }
        if ($json) {
            $compiled = self::parse_students($data['alunos'] ?? null);
        } else {
            if (isset($data['alunos']) || count($files) !== 1 || !isset($files['arquivo_csv'])) {
                return new \WP_Error('k8prod_file', 'Envie somente o arquivo no campo arquivo_csv, sem o campo alunos.', ['status' => 400]);
            }
            $raw = StudentImport::read_upload($files['arquivo_csv']);
            if (is_wp_error($raw)) {
                return self::error($raw, $raw->get_error_code() === 'upload_size' ? 413 : 400);
            }
            $compiled = StudentImport::parse_csv($raw);
        }
        if (is_wp_error($compiled)) {
            return self::error($compiled, in_array($compiled->get_error_code(), ['size', 'rows_limit'], true) ? 413 : 422);
        }
        global $wpdb;
        $suppression = $wpdb->suppress_errors(true);
        try {
            $report = $preview ? [] : StudentImport::save_import($compiled, $mode === 'substituir', 'api');
        } catch (\Throwable $e) {
            return new \WP_Error('k8prod_import_failed', 'Não foi possível concluir a importação. A transação foi cancelada; tente novamente ou consulte o administrador.', ['status' => 500]);
        } finally {
            $wpdb->suppress_errors($suppression);
        }
        return new \WP_REST_Response([
            'sucesso' => true, 'validar_apenas' => $preview, 'modo' => $mode,
            'alunos' => count($compiled['rows']), 'vinculos' => count($compiled['links']),
            'registros' => $compiled['source_rows'], 'novos' => $report['created'] ?? null,
            'atualizados' => $report['updated'] ?? null, 'importado_em' => $report['time'] ?? null,
            'avisos' => $compiled['warnings'],
            'base_consultada' => Students::uses_builtin() ? 'integrada' : 'externa',
            'aviso_base' => Students::uses_builtin() ? null : 'A importação atualiza a base integrada; a busca por CPF está configurada para uma base externa. Confira K8 Produtos → Configurações.',
        ], 200, ['Cache-Control' => 'no-store, private']);
    }
}
