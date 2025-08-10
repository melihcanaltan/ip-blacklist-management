<?php
/**
 * IntegrationManager - Harici blacklist entegrasyonlarını yönetir
 * PHP 8.3 uyumlu - Null-safe - file_exists() uyarılarını önler
 */
class IntegrationManager
{
    private array $config;
    private array $integrations = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->loadIntegrations();
    }

    /**
     * Entegrasyonları config'den yükle
     */
    private function loadIntegrations(): void
    {
        if (!isset($this->config['integrations'])) {
            return;
        }

        foreach ($this->config['integrations'] as $key => $integration) {
            if (!empty($integration['enabled'])) {
                $this->integrations[$key] = $integration;
            }
        }
    }

    public function getEnabledIntegrations(): array
    {
        return $this->integrations;
    }

    public function getName(string $integrationKey): string
    {
        return $this->integrations[$integrationKey]['name'] ?? $integrationKey;
    }

    public function getDescription(string $integrationKey): string
    {
        return $this->integrations[$integrationKey]['description'] ?? '';
    }

    /**
     * Belirli bir entegrasyonun IP listesini getir
     */
    public function getIpList(string $integrationKey): array
    {
        if (!isset($this->integrations[$integrationKey])) {
            return [];
        }

        $filePath = $this->getFilePath($integrationKey);

        if (!$this->isValidFilePath($filePath)) {
            return [];
        }

        $content = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($content === false) {
            return [];
        }

        $ips = [];
        foreach ($content as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if ($this->isValidIpOrCidr($parts[0])) {
                $ips[] = $parts[0];
            }
        }

        return $ips;
    }

    /**
     * Entegrasyon dosya yolunu getir
     */
    private function getFilePath(string $integrationKey): ?string
    {
        if (isset($this->config['file_paths'][$integrationKey])) {
            $path = $this->config['file_paths'][$integrationKey];
            if (!empty($path) && is_string($path)) {
                return $path;
            }
        }

        if (isset($this->integrations[$integrationKey]['file_path'])) {
            $path = $this->integrations[$integrationKey]['file_path'];
            if (!empty($path) && is_string($path)) {
                return $path;
            }
        }

        $baseDir = dirname(dirname(__FILE__));
        $defaultPath = $baseDir . '/data/' . $integrationKey . '.txt';

        return is_string($defaultPath) && $defaultPath !== '' ? $defaultPath : null;
    }

    /**
     * Dosya yolu geçerliliğini kontrol et
     */
    private function isValidFilePath(?string $filePath): bool
    {
        return (
            $filePath !== null &&
            $filePath !== '' &&
            is_string($filePath) &&
            file_exists($filePath)
        );
    }

    /**
     * IP veya CIDR doğrulaması
     */
    private function isValidIpOrCidr(string $ip): bool
    {
        if ($ip === '') {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return true;
        }

        if (strpos($ip, '/') !== false) {
            [$ipPart, $cidrPart] = explode('/', $ip, 2);
            if (
                filter_var($ipPart, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) &&
                is_numeric($cidrPart) &&
                $cidrPart >= 0 && $cidrPart <= 32
            ) {
                return true;
            }
        }

        return false;
    }

    public function getAllIps(): array
    {
        $allIps = [];

        foreach ($this->integrations as $key => $integration) {
            $ips = $this->getIpList($key);
            foreach ($ips as $ip) {
                $allIps[] = [
                    'ip' => $ip,
                    'source' => $integration['name']
                ];
            }
        }

        return $allIps;
    }

    public function getStats(): array
    {
        $stats = [];

        foreach ($this->integrations as $key => $integration) {
            $ips = $this->getIpList($key);
            $filePath = $this->getFilePath($key);

            $fileExists = false;
            $fileSize = 0;
            $lastModified = 'N/A';

            if ($this->isValidFilePath($filePath)) {
                $fileExists = true;
                $fileSize = filesize($filePath);
                $lastModified = date('Y-m-d H:i:s', filemtime($filePath));
            }

            $stats[$key] = [
                'name' => $integration['name'],
                'description' => $integration['description'],
                'file_path' => $filePath ?? 'Yol bulunamadı',
                'file_exists' => $fileExists,
                'file_size' => $fileSize,
                'ip_count' => count($ips),
                'last_modified' => $lastModified
            ];
        }

        return $stats;
    }

    public function isEnabled(string $integrationKey): bool
    {
        return isset($this->integrations[$integrationKey]);
    }

    public function updateIntegration(string $integrationKey): bool
    {
        if (!isset($this->integrations[$integrationKey])) {
            throw new Exception("Entegrasyon bulunamadı: $integrationKey");
        }

        $integration = $this->integrations[$integrationKey];

        if (empty($integration['update_url'])) {
            throw new Exception("Güncelleme URL'si bulunamadı: $integrationKey");
        }

        $filePath = $this->getFilePath($integrationKey);
        if ($filePath === null || $filePath === '') {
            throw new Exception("Dosya yolu belirlenemedi: $integrationKey");
        }

        $content = @file_get_contents($integration['update_url']);
        if ($content === false) {
            throw new Exception("Dosya indirilemedi: " . $integration['update_url']);
        }

        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new Exception("Dosya kaydedilemedi: $filePath");
        }

        return true;
    }
}
?>
