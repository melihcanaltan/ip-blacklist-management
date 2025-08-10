<?php
// config/config.php - IP Blacklist Management System Konfigürasyonu
return [
    // ===== UYGULAMA AYARLARI =====
    'app' => [
        'name' => 'IP Blacklist Management System',
        'company' => 'Şirket Adı',
        'timezone' => 'Europe/Istanbul'
    ],
    
    // ===== DOSYA YOL AYARLARI =====
    'file_paths' => [
        // Ana dosyalar
        'blacklist' => __DIR__ . '/../data/blacklist.txt',
        'whitelist' => __DIR__ . '/../data/whitelist.txt',
        'output' => __DIR__ . '/../data/output/blacklist_output.txt',
        
        // Entegrasyon dosyaları (opsiyonel)
        'ci_badguys' => __DIR__ . '/../data/ci-badguys.txt',
        'firehol' => __DIR__ . '/../data/firehol_level1.txt'
    ],
    
    // ===== ŞİRKET IP BLOKLARI =====
    'company_blocks' => [
        // Private IP aralıkları (RFC 1918)
        '10.0.0.0/8',          // Class A private
        '172.16.0.0/12',       // Class B private
        '192.168.0.0/16',      // Class C private
        
        // Özel şirket IP blokları (gerekirse ekleyin)
        // '203.0.113.0/24',   // Örnek public IP bloku
        // '198.51.100.0/24',  // Örnek public IP bloku
    ],
    
    // ===== DİĞER SİSTEM ENTEGRASYONU =====
    'other_system' => [
        'enabled' => false,    // true/false ile aktif/pasif
        'name' => 'Diğer Blacklist Sistemi',
        'url' => 'http://your-server/other-blacklist/management.php'
    ],
    
    // ===== SAYFALAMA AYARLARI =====
    'pagination' => [
        'default_per_page' => 25,
        'options' => [10, 25, 50, 100]
    ],
    
    // ===== HARICI BLACKLIST ENTEGRASYONLARI =====
    'integrations' => [
        'ci_badguys' => [
            'enabled' => true,
            'name' => 'CI Army Bad Guys',
            'description' => 'CI Army kötü IP listesi',
            'update_url' => 'http://cinsscore.com/list/ci-badguys.txt'
        ],
        
        'firehol' => [
            'enabled' => true,
            'name' => 'FireHOL Level 1',
            'description' => 'FireHOL seviye 1 blacklist',
            'update_url' => 'https://iplists.firehol.org/files/firehol_level1.netset'
        ],
        
        // Gelecekte kullanılabilecek entegrasyonlar
        'abuseipdb' => [
            'enabled' => false,
            'name' => 'AbuseIPDB',
            'description' => 'AbuseIPDB kötü IP listesi',
            'api_key' => '',  // API key gerektiğinde
            'confidence_threshold' => 75
        ],
        
        'threatstop' => [
            'enabled' => false,
            'name' => 'ThreatStop',
            'description' => 'ThreatStop IP listesi',
            'api_key' => '',
            'update_url' => ''
        ]
    ],
    
    // ===== SİSTEM AYARLARI =====
    'system' => [
        'max_file_size' => '50MB',      // Maksimum dosya boyutu
        'backup_enabled' => true,       // Otomatik yedekleme
        'backup_retention' => 30,       // Yedek saklama süresi (gün)
        'log_level' => 'info',         // debug, info, warning, error
        'auto_update_interval' => 3600  // Otomatik güncelleme aralığı (saniye)
    ]
];
?>
