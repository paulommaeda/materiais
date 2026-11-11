<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

final class PayException extends \RuntimeException
{
    private string $detail;
    public function __construct(string $message, string $detail)
    {
        parent::__construct($message);
        $this->detail = $detail;
    }
    public function diagnostic(): string { return $this->detail; }
}

/** Cliente PAY separado do COB: credenciais e contratos independentes. */
final class PayClient
{
    private string $base;
    private string $id;
    private string $secret;
    private string $aes;
    private ?string $token = null;

    public static function environment(): string
    {
        return get_option('k8p_producao', '0') === '1' ? 'production' : 'sandbox';
    }

    public static function credential(string $environment, string $name): string
    {
        $prefix = $environment === 'sandbox' ? 'SANDBOX_' : '';
        $constant = 'K8PROD_PAY_' . $prefix . strtoupper($name);
        $option = 'k8prod_pay_' . ($environment === 'sandbox' ? 'sandbox_' : '') . $name;
        return defined($constant) ? (string) constant($constant) : (string) get_option($option, '');
    }

    public static function configured(string $environment): bool
    {
        foreach (['client_id', 'client_secret', 'aes_key'] as $name) {
            if (self::credential($environment, $name) === '') return false;
        }
        return true;
    }

    public function __construct(?string $environment = null)
    {
        $environment = $environment ?? self::environment();
        if (!in_array($environment, ['production', 'sandbox'], true)) throw new \InvalidArgumentException('Ambiente PAY inválido.');
        $this->id = self::credential($environment, 'client_id');
        $this->secret = self::credential($environment, 'client_secret');
        $this->aes = self::credential($environment, 'aes_key');
        $this->base = rtrim((string) get_option('k8prod_pay_api_' . $environment,
            $environment === 'production' ? 'https://pagamentos.meuk8.com.br/PagamentosAPI' : 'https://sandbox.meuk8.com.br/PagamentosAPI'), '/');
        if (!self::configured($environment) || !extension_loaded('openssl')) throw new \RuntimeException('Configure as credenciais próprias do K8 PAY para este ambiente e a extensão OpenSSL.');
    }

    private function decode(string $raw): ?array
    {
        $decoded = json_decode(trim($raw), true);
        if (is_array($decoded)) return $decoded;
        $encoded = is_string($decoded) ? $decoded : trim($raw);
        $binary = base64_decode($encoded, true);
        if ($binary === false || strlen($binary) === 0 || strlen($binary) % 16 !== 0) return null;
        $plain = @openssl_decrypt($binary, 'AES-128-CBC', $this->aes, OPENSSL_RAW_DATA, str_repeat("\0", 16));
        $decoded = $plain !== false ? json_decode($plain, true) : null;
        return is_array($decoded) ? $decoded : null;
    }

    private function failure(string $method, string $path, int $code, ?array $body, array $request): PayException
    {
        $parts = [];
        foreach (['DetalhesErro', 'Message', 'message', 'error_description', 'Mensagem'] as $key) {
            if (isset($body[$key]) && is_string($body[$key])) $parts[] = $body[$key];
        }
        if (!empty($body['ModelState']) && is_array($body['ModelState'])) {
            foreach (array_slice($body['ModelState'], 0, 10, true) as $field => $messages) {
                foreach ((array) $messages as $message) if (is_string($message)) $parts[] = $field . ': ' . $message;
            }
        }
        $detail = implode(' | ', $parts) ?: 'Sem detalhe de validação reconhecido.';
        $sensitive = [$this->id, $this->secret, $this->aes, $this->token];
        foreach ($request as $key => $value) {
            if (is_string($value) && strlen($value) > 3 && strpos($key, 'Cliente') === 0) $sensitive[] = $value;
        }
        foreach ($sensitive as $value) if (is_string($value) && $value !== '') $detail = str_replace($value, '[oculto]', $detail);
        $detail = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[e-mail oculto]', $detail);
        $detail = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[documento oculto]', $detail);
        $detail = wp_check_invalid_utf8(substr(sanitize_text_field($detail), 0, 1500), true);
        $public = 'A K8 PAY não confirmou esta operação. Confira os detalhes no painel administrativo.';
        return new PayException($public, $method . ' ' . $path . ' — HTTP ' . $code . ': ' . $detail);
    }

    private function request(string $method, string $path, ?array $data = null): array
    {
        if ($this->token === null) {
            $auth = wp_remote_post($this->base . '/token', ['timeout' => 25, 'redirection' => 0,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => ['username' => $this->id, 'password' => $this->secret, 'grant_type' => 'password']]);
            if (is_wp_error($auth) || wp_remote_retrieve_response_code($auth) !== 200) throw new \RuntimeException('Falha ao autenticar no K8 PAY. Confira as credenciais próprias e o ambiente.');
            $token = json_decode(wp_remote_retrieve_body($auth), true);
            $value = $token['access_token'] ?? $token['token'] ?? null;
            if (!is_string($value) || $value === '') throw new \RuntimeException('O K8 PAY não retornou um token válido.');
            $this->token = $value;
        }
        $args = ['method' => $method, 'timeout' => 30, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->token, 'Aeskey' => $this->aes]];
        if ($data !== null) {
            $encrypted = openssl_encrypt(wp_json_encode($data), 'AES-128-CBC', $this->aes, OPENSSL_RAW_DATA, str_repeat("\0", 16));
            if ($encrypted === false) throw new \RuntimeException('Não foi possível preparar a requisição PAY.');
            $args['body'] = base64_encode($encrypted);
        }
        $response = wp_remote_request($this->base . $path, $args);
        if (is_wp_error($response)) throw new \RuntimeException('Sem resposta do K8 PAY. Confira a referência do pedido antes de repetir qualquer operação.');
        $code = (int) wp_remote_retrieve_response_code($response);
        $decoded = $this->decode((string) wp_remote_retrieve_body($response));
        if ($code < 200 || $code >= 300 || ($decoded['Retorno'] ?? '') === 'ERRO') throw $this->failure($method, $path, $code, $decoded, $data ?? []);
        if (!is_array($decoded)) throw new \RuntimeException('Resposta PAY inválida. Confira a chave AES e a integração.');
        return $decoded;
    }

    public function checkout(array $data): array
    {
        $reference = $data['Referencia'] ?? null;
        if (!is_string($reference) || $reference === '' || strlen($reference) > 36) throw new \InvalidArgumentException('A referência do novo checkout PAY deve ter entre 1 e 36 caracteres.');
        $result = $this->request('POST', '/v2/CriaTransacaoCheckout', $data);
        if (($result['Retorno'] ?? '') !== 'OK') throw $this->failure('POST', '/v2/CriaTransacaoCheckout', 200, $result, $data);
        return $result;
    }

    public function consult(string $reference): array
    {
        $result = $this->request('POST', '/api/ConsultaTransacaoReferencia', ['Referencia' => $reference]);
        if (($result['Retorno'] ?? '') !== 'OK') throw $this->failure('POST', '/api/ConsultaTransacaoReferencia', 200, $result, []);
        return $result;
    }

    public function transaction(string $id): array
    {
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id)) throw new \InvalidArgumentException('Informe o identificador da transação PAY no formato UUID.');
        $data = ['Identificador' => $id];
        $result = $this->request('POST', '/api/ConsultaTransacao', $data);
        if (($result['Retorno'] ?? '') !== 'OK') throw $this->failure('POST', '/api/ConsultaTransacao', 200, $result, $data);
        return $result;
    }

    public static function valid_id($id): bool
    {
        return is_string($id) && (bool) preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $id);
    }

    public function transactions(array $filters): array
    {
        $result = $this->request('POST', '/api/ConsultaTransacoes', $filters);
        if (($result['Retorno'] ?? '') !== 'OK') throw $this->failure('POST', '/api/ConsultaTransacoes', 200, $result, $filters);
        $items = $result['Transacoes'] ?? [];
        if (!is_array($items) || count($items) > 1000) throw new \RuntimeException('A consulta PAY retornou uma lista inválida ou extensa. Use o ID da transação para conferir este pedido.');
        return $items;
    }

    public function notification(string $raw): ?array
    {
        return strlen($raw) <= 131072 ? $this->decode($raw) : null;
    }

    public function configuration(): array
    {
        return $this->request('GET', '/api/ParcelamentoConfiguracao');
    }
}
