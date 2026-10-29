<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class Students
{
    public static function defaults(): array
    {
        return ['table' => 'lista_alunos_v4', 'ra' => 'ra', 'name' => 'nome_aluno', 'class' => 'turma',
            'cpf1' => 'cpf_resp1', 'parent1' => 'nome_resp1', 'cpf2' => 'cpf_resp2', 'parent2' => 'nome_resp2'];
    }

    public static function config(): array
    {
        return array_merge(self::defaults(), (array) get_option('k8prod_students', []));
    }

    public static function uses_builtin(): bool
    {
        return self::config() === self::defaults();
    }

    private static function source(): array
    {
        global $wpdb;
        $map = self::config();
        foreach ($map as $key => $value) {
            if (($value === '' && in_array($key, ['cpf2', 'parent2'], true))) {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $value)) {
                throw new \RuntimeException('Mapeamento de alunos inválido. Confira as configurações.');
            }
        }
        $table = $wpdb->prefix . $map['table'];
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) !== $table) {
            throw new \RuntimeException('A base de alunos ainda não está configurada. Contate a secretaria.');
        }
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `$table`", 0);
        foreach (['ra', 'name', 'class', 'cpf1', 'parent1'] as $key) {
            if (!in_array($map[$key], $columns, true)) {
                throw new \RuntimeException('Confira o mapeamento das colunas de alunos nas configurações K8.');
            }
        }
        if (!$map['cpf2'] || !$map['parent2'] || !in_array($map['cpf2'], $columns, true) || !in_array($map['parent2'], $columns, true)) {
            $map['cpf2'] = $map['parent2'] = '';
        }
        return [$table, $map];
    }

    private static function normalized(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(`$column`,'.',''),'-',''),' ',''),'/','')";
    }

    public static function find(string $cpf, string $ra = ''): array
    {
        global $wpdb;
        [$table, $map] = self::source();
        $where = self::normalized($map['cpf1']) . ' = %s';
        $args = [$cpf];
        if ($map['cpf2']) {
            $where .= ' OR ' . self::normalized($map['cpf2']) . ' = %s';
            $args[] = $cpf;
        }
        $where = '(' . $where . ')';
        if ($ra !== '') {
            $where .= " AND `{$map['ra']}` = %s";
            $args[] = $ra;
        }
        if (self::uses_builtin()) {
            $guardians = $wpdb->prefix . 'sba_guardians';
            // A tabela de vínculos inclui o terceiro responsável e os seguintes.
            $sql = "SELECT a.*,g.nome AS matched_name FROM `$table` a INNER JOIN $guardians g ON BINARY g.ra = BINARY a.ra WHERE g.cpf = %s";
            $guardian_args = [$cpf];
            if ($ra !== '') {
                $sql .= ' AND a.ra = %s';
                $guardian_args[] = $ra;
            }
            $linked = $wpdb->get_results($wpdb->prepare($sql, $guardian_args), ARRAY_A);
            // Só usa as duas colunas antigas quando o aluno ainda não possui vínculos consolidados.
            $where .= " AND NOT EXISTS (SELECT 1 FROM $guardians g WHERE BINARY g.ra = BINARY `$table`.`{$map['ra']}`)";
            $rows = array_merge($linked, $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE $where ORDER BY `{$map['name']}` LIMIT 100", $args), ARRAY_A));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `$table` WHERE $where ORDER BY `{$map['name']}` LIMIT 100", $args), ARRAY_A);
        }
        $result = [];
        foreach ($rows as $row) {
            $first = Support::cpf((string) $row[$map['cpf1']]) === $cpf;
            $result[(string) $row[$map['ra']]] = [
                'ra' => (string) $row[$map['ra']], 'nome_aluno' => (string) $row[$map['name']],
                'turma' => (string) $row[$map['class']], 'cpf_resp' => $cpf,
                'nome_resp' => isset($row['matched_name']) ? (string) $row['matched_name'] : (string) $row[$map[$first ? 'parent1' : 'parent2']],
            ];
        }
        return array_values($result);
    }

    public static function all(): array
    {
        global $wpdb;
        [$table, $map] = self::source();
        // Apenas os campos necessários ao dashboard; CPF não entra no relatório.
        return $wpdb->get_results("SELECT `{$map['ra']}` AS ra, MAX(`{$map['name']}`) AS nome_aluno,
            MAX(`{$map['class']}`) AS turma FROM `$table` GROUP BY `{$map['ra']}` ORDER BY turma,nome_aluno");
    }

    public static function classes(): array
    {
        global $wpdb;
        [$table, $map] = self::source();
        return $wpdb->get_col("SELECT DISTINCT `{$map['class']}` FROM `$table` WHERE `{$map['class']}` <> '' ORDER BY `{$map['class']}`");
    }

    public static function issue(int $product, array $person): string
    {
        $token = bin2hex(random_bytes(32));
        set_transient('k8prod_ctx_' . hash('sha256', $token), ['produto_id' => $product, 'person' => $person,
            'revision' => (int) get_option('k8prod_student_revision', 1)], 30 * MINUTE_IN_SECONDS);
        return $token;
    }

    public static function context(int $product, string $token): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
            throw new \InvalidArgumentException('Refaça a busca pelo CPF para continuar.');
        }
        $context = get_transient('k8prod_ctx_' . hash('sha256', $token));
        if (!$context || (int) $context['produto_id'] !== $product ||
            (int) ($context['revision'] ?? 0) !== (int) get_option('k8prod_student_revision', 1)) {
            throw new \InvalidArgumentException('Sua consulta expirou. Busque o CPF novamente.');
        }
        $people = self::find($context['person']['cpf_resp'], $context['person']['ra']);
        if (!$people) {
            throw new \InvalidArgumentException('Vínculo do responsável não encontrado. Contate a secretaria.');
        }
        return $people[0];
    }
}
