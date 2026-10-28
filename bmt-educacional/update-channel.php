<?php
/** Canal GitHub comum ao plugin e ao tema, sem dependência de um no outro. */
defined('ABSPATH') || exit;

if (!class_exists('BMT_Update_Channel', false)) {
    final class BMT_Update_Channel
    {
        private string $slug;
        private string $type;
        private string $version;
        private string $file;
        private string $base = 'https://raw.githubusercontent.com/paulommaeda/materiais/main/';

        public function __construct(string $slug, string $type, string $version, string $file)
        {
            $this->slug = $slug;
            $this->type = $type;
            $this->version = $version;
            $this->file = $file;
            add_filter($type === 'plugin' ? 'update_plugins_github.com' : 'update_themes_github.com', [$this, 'offer'], 10, 3);
            add_filter('pre_set_site_transient_update_' . ($type === 'plugin' ? 'plugins' : 'themes'), [$this, 'transient']);
            add_filter('upgrader_pre_download', [$this, 'verify_download'], 10, 4);
            if ($type === 'plugin') {
                add_filter('plugins_api', [$this, 'information'], 20, 3);
            }
        }

        public function metadata(bool $force = false): ?array
        {
            $key = 'bmt_update_' . $this->slug;
            $cached = $force ? false : get_transient($key);
            if (is_array($cached)) {
                return $cached ?: null;
            }
            $response = wp_safe_remote_get($this->base . 'updates/' . $this->type . '.json', [
                'timeout' => 8, 'limit_response_size' => 65536, 'headers' => ['Accept' => 'application/json'],
            ]);
            $data = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200
                ? json_decode(wp_remote_retrieve_body($response), true) : null;
            if (!is_array($data) || ($data['slug'] ?? '') !== $this->slug ||
                !is_string($data['version'] ?? null) || !is_string($data['sha256'] ?? null) ||
                !preg_match('/^\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/D', $data['version'] ?? '') ||
                ($data['package'] ?? '') !== $this->base . 'dist/' . $this->slug . '-' . $data['version'] . '.zip' ||
                !preg_match('/^[a-f0-9]{64}$/D', $data['sha256'] ?? '')) {
                set_transient($key, [], 5 * MINUTE_IN_SECONDS);
                return null;
            }
            set_transient($key, $data, HOUR_IN_SECONDS);
            return $data;
        }

        public function offer($update, array $headers, string $file)
        {
            $matches = $this->type === 'plugin' ? $file === plugin_basename($this->file) : $file === $this->slug;
            if (!$matches) {
                return $update;
            }
            $data = $this->metadata();
            if (!$data) {
                return $update;
            }
            return ['id' => 'https://github.com/paulommaeda/materiais/' . $this->slug, 'slug' => $this->slug,
                'theme' => $this->slug, 'version' => $data['version'], 'new_version' => $data['version'],
                'url' => 'https://github.com/paulommaeda/materiais', 'package' => $data['package'],
                'requires' => $data['requires'] ?? '6.2', 'requires_php' => $data['requires_php'] ?? '7.4',
                'tested' => $data['tested'] ?? '', 'autoupdate' => false];
        }

        public function transient($transient)
        {
            if (!is_object($transient) || empty($transient->checked)) {
                return $transient;
            }
            $key = $this->type === 'plugin' ? plugin_basename($this->file) : $this->slug;
            if (!isset($transient->checked[$key])) {
                return $transient;
            }
            $data = $this->offer(false, [], $key);
            if (!$data) {
                return $transient;
            }
            $offer = $this->type === 'plugin' ? (object) ($data + ['plugin' => $key]) : $data;
            $version = (string) $transient->checked[$key];
            if (version_compare($data['version'], $version, '>')) {
                $transient->response[$key] = $offer;
                unset($transient->no_update[$key]);
            } else {
                $transient->no_update[$key] = $offer;
                unset($transient->response[$key]);
            }
            return $transient;
        }

        public function information($result, string $action, $args)
        {
            if ($action !== 'plugin_information' || ($args->slug ?? '') !== $this->slug) {
                return $result;
            }
            $data = $this->metadata();
            if (!$data) {
                return $result;
            }
            return (object) ['name' => $data['name'] ?? $this->slug, 'slug' => $this->slug, 'version' => $data['version'],
                'author' => 'Paulo Maeda', 'homepage' => 'https://github.com/paulommaeda/materiais',
                'download_link' => $data['package'], 'requires' => $data['requires'] ?? '6.2',
                'requires_php' => $data['requires_php'] ?? '7.4', 'tested' => $data['tested'] ?? '',
                'sections' => ['description' => wp_kses_post($data['description'] ?? ''), 'changelog' => wp_kses_post($data['changelog'] ?? '')]];
        }

        public function verify_download($reply, string $package, $upgrader, array $extra = [])
        {
            if ($reply !== false || strpos($package, $this->base . 'dist/' . $this->slug . '-') !== 0) {
                return $reply;
            }
            $data = $this->metadata();
            if (!$data || $package !== $data['package']) {
                return new WP_Error('bmt_update_manifest', 'O pacote de atualização não corresponde à versão publicada. Verifique novamente as atualizações.');
            }
            require_once ABSPATH . 'wp-admin/includes/file.php';
            $file = download_url($package, 60);
            if (is_wp_error($file)) {
                return $file;
            }
            if (!hash_equals($data['sha256'], hash_file('sha256', $file))) {
                wp_delete_file($file);
                return new WP_Error('bmt_update_checksum', 'O arquivo de atualização não passou na conferência de integridade.');
            }
            return $file;
        }
    }
}
