<?php
namespace K8Produtos;
defined('ABSPATH') || exit;

/** Mantém o contrato AES e os endpoints do cliente K8 fornecido. */
final class Client
{
    private string $base;
    private string $client_id;
    private string $secret;
    private string $aes;
    private ?string $token = null;

    public function __construct()
    {
        $this->client_id = defined('K8PROD_CLIENT_ID') ? K8PROD_CLIENT_ID : (string) get_option('k8p_client_id', '');
        $this->secret = defined('K8PROD_CLIENT_SECRET') ? K8PROD_CLIENT_SECRET : (string) get_option('k8p_client_secret', '');
        $this->aes = defined('K8PROD_AES_KEY') ? K8PROD_AES_KEY : (string) get_option('k8p_aes_key', '');
        $production = get_option('k8p_producao', '0') === '1';
        $this->base = rtrim((string) get_option($production ? 'k8prod_api_production' : 'k8prod_api_sandbox',
            $production ? 'https://pagamentos.meuk8.com.br/CobrancasAPI' : 'https://sandbox.meuk8.com.br/CobrancasAPI'), '/');
        if ($this->client_id === '' || $this->secret === '' || $this->aes === '' || !extension_loaded('openssl')) {
            throw new \RuntimeException('Configure as credenciais K8 e a extensão OpenSSL antes de gerar cobranças.');
        }
    }

    private function request(string $method, string $path, ?array $data = null): array
    {
        if ($this->token === null) {
            $auth = wp_remote_post($this->base . '/token', [
                'timeout' => 25, 'redirection' => 0,
                'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => ['username' => $this->client_id, 'password' => $this->secret, 'grant_type' => 'password'],
            ]);
            if (is_wp_error($auth) || wp_remote_retrieve_response_code($auth) !== 200) {
                throw new \RuntimeException('Falha ao autenticar na K8. Confira as credenciais e o ambiente.');
            }
            $body = json_decode(wp_remote_retrieve_body($auth), true);
            $this->token = $body['access_token'] ?? $body['token'] ?? null;
            if (!$this->token) {
                throw new \RuntimeException('A K8 não retornou um token de autenticação.');
            }
        }
        $args = ['method' => $method, 'timeout' => 30, 'redirection' => 0,
            'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->token, 'Aeskey' => $this->aes]];
        if ($data !== null) {
            $encrypted = openssl_encrypt(wp_json_encode($data), 'AES-128-CBC', $this->aes, OPENSSL_RAW_DATA, str_repeat("\0", 16));
            if ($encrypted === false) {
                throw new \RuntimeException('Não foi possível preparar a requisição K8.');
            }
            $args['body'] = base64_encode($encrypted);
        }
        $response = wp_remote_request($this->base . $path, $args);
        if (is_wp_error($response)) {
            throw new \RuntimeException('Sem resposta da K8. Consulte o pedido antes de tentar novamente.');
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('A K8 retornou HTTP ' . $code . '. Verifique a operação no painel K8.');
        }
        $raw = trim(wp_remote_retrieve_body($response));
        if ($raw === '' && $method === 'DELETE') {
            return ['sucesso' => true];
        }
        // Algumas instalações encapsulam o base64 em uma string JSON.
        $unquoted = json_decode($raw, true);
        if (is_string($unquoted)) {
            $raw = $unquoted;
        }
        $binary = base64_decode($raw, true);
        $plain = $binary !== false ? openssl_decrypt($binary, 'AES-128-CBC', $this->aes, OPENSSL_RAW_DATA, str_repeat("\0", 16)) : false;
        $decoded = $plain !== false ? json_decode($plain, true) : null;
        // [] é uma resposta válida da busca de clientes.
        if (!is_array($decoded)) {
            throw new \RuntimeException('Resposta K8 inválida. Confira a chave AES e o endpoint configurado.');
        }
        return $decoded;
    }

    public function customer(array $person): array
    {
        $cpf = Support::cpf($person['cpf_resp']);
        $found = $this->request('GET', '/api/Cliente?Cpfcnpj=' . rawurlencode($cpf));
        if ($found) {
            return isset($found[0]) && is_array($found[0]) ? $found[0] : $found;
        }
        return $this->request('POST', '/api/Cliente', ['Nome' => $person['nome_resp'], 'CPFCNPJ' => $cpf]);
    }

    public function charge(object $order, object $product): array
    {
        $due = (new \DateTimeImmutable('now', wp_timezone()))->modify('+' . (int) $product->vencimento_dias . ' days');
        return $this->request('POST', '/api/Cobranca', [
            'ClienteId' => $order->cliente_id, 'ClienteNome' => $order->nome_resp,
            'Descricao' => $product->nome . ' - ' . $order->nome_aluno . ' (RA ' . $order->ra . ') - Pedido #' . $order->id,
            'Valor' => (float) $order->valor, 'Vencimento' => $due->format('Y-m-d\TH:i:sP'),
            'Tipo' => 'Avulsa', 'FormasPagamento' => $order->metodo, 'NumeroOperacao' => 'K8P-' . $order->id,
        ]);
    }

    public function consult(string $id): array
    {
        $path = (string) get_option('k8prod_query_path', '/api/Cobranca?Id={id}');
        $data = $this->request('GET', str_replace('{id}', rawurlencode($id), $path));
        return isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
    }

    public function create_card_link(object $order, object $product): array
    {
        return $this->request('POST', '/api/LinkPagamento', [
            'Nome' => $product->nome . ' — Pedido #' . $order->id,
            'Observacoes' => CardPayments::reference($order), 'TipoValor' => 'fixo', 'Valor' => (float) $order->valor,
            'FormasPagamento' => 'Cartao', 'Ativo' => true, 'LinkReutilizavel' => false,
            'ParcelaFixa' => true, 'ParcelaFixaQtd' => (int) $order->cartao_parcelas_max,
            'DataExpiracao' => (new \DateTimeImmutable('now', wp_timezone()))->modify('+' . (int) $product->vencimento_dias . ' days')->format('Y-m-d\TH:i:sP'),
        ]);
    }

    public function card_link(string $id): array
    {
        return $this->request('GET', '/api/LinkPagamento/' . rawurlencode($id));
    }

    public function card_charge(string $id): array
    {
        return $this->request('GET', '/api/Cobranca/' . rawurlencode($id));
    }

    public function disable_card_link(array $link): void
    {
        $record = array_intersect_key($link, array_flip(['Id', 'Nome', 'Observacoes', 'TipoValor', 'Valor', 'FormasPagamento',
            'LinkReutilizavel', 'ParcelaFixa', 'ParcelaFixaQtd', 'DataExpiracao']));
        $result = $this->request('PUT', '/api/LinkPagamento', $record + ['Ativo' => false]);
        if (isset($result['Retorno']) && $result['Retorno'] !== 'OK') throw new \RuntimeException('A K8 não confirmou a desativação do link.');
    }

    public static function card_checkout(array $link): string
    {
        $url = isset($link['LinkUrl']) && is_string($link['LinkUrl']) ? $link['LinkUrl'] : '';
        $parts = wp_parse_url($url);
        $allowed = ['pagamentos.meuk8.com.br', 'sandbox.meuk8.com.br', 'pagamentos.k8bank.com.br'];
        foreach (['k8prod_checkout_production', 'k8prod_checkout_sandbox'] as $setting) {
            $host = wp_parse_url((string) get_option($setting, ''), PHP_URL_HOST);
            if ($host) $allowed[] = strtolower($host);
        }
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || !in_array(strtolower($parts['host'] ?? ''), $allowed, true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \RuntimeException('A K8 não retornou um endereço de checkout válido para o cartão. Consulte o pedido na secretaria.');
        }
        return esc_url_raw($url);
    }

    public function cancel(string $id): array
    {
        $path = (string) get_option('k8prod_cancel_path', '/api/Cobranca/{id}');
        return $this->request('DELETE', str_replace('{id}', rawurlencode($id), $path));
    }

    public static function checkout(string $id): string
    {
        $key = get_option('k8p_producao', '0') === '1' ? 'k8prod_checkout_production' : 'k8prod_checkout_sandbox';
        $base = (string) get_option($key, 'https://pagamentos.k8bank.com.br/cobrancasui/checkout');
        return add_query_arg('ID', $id, $base);
    }
}
