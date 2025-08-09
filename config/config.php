<?php
// config/config.php
return [
    'app' => [
        'name' => 'IP Blacklist Management System',
        'company' => 'Your Company Name',
        'other_system_url' => 'http://your-server/other-blacklist/management.php',
        'other_system_name' => 'Other Blacklist Management'
    ],
    
    'file_paths' => [
        'blacklist' => __DIR__ . "/../data/blacklist.txt",
        'ci_badguys' => __DIR__ . "/../data/ci-badguys.txt",
        'firehol' => __DIR__ . "/../data/firehol_level1.txt",
        'whitelist' => __DIR__ . "/../data/whitelist.txt",
        'output' => __DIR__ . '/../data/output/blacklist_output.txt'
    ],
    
    'company_blocks' => [
        // Örnek şirket IP blokları - gerçek IP'lerinizi buraya ekleyin
        "192.168.1.0/24",
        "10.0.0.0/8",
        "172.16.0.0/12",
        "203.0.113.0/24",  // Örnek public IP bloku
        "198.51.100.0/24", // Örnek public IP bloku
        "192.0.2.0/24"     // Örnek public IP bloku
    ],
    
    'timezone' => 'Europe/Istanbul',
    
    'pagination' => [
        'default_per_page' => 10,
        'options' => [10, 25, 50, 100]
    ]
];
