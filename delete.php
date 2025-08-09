<?php
session_start();

// Config dosyasını yükle
$config = require_once 'config/config.php';
$file_path = $config['file_paths']['blacklist'];
$output_file = $config['file_paths']['output'];

// Silme işlemi
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete']) && isset($_POST['selected_ips'])) {
    $selected_ips = $_POST['selected_ips'];
    $deleted_count = 0;
    
    if (!file_exists($file_path)) {
        $_SESSION['message'] = "Blacklist dosyası bulunamadı.";
        header("Location: index.php");
        exit();
    }
    
    // Dosya içeriğini oku
    $file_content = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $new_content = [];
    
    foreach ($file_content as $line) {
        $entry_parts = explode("|", $line);
        if (count($entry_parts) >= 1) {
            $ip = $entry_parts[0];
            
            // Seçili IP'ler arasında değilse, yeni içeriğe ekle
            if (!in_array($ip, $selected_ips)) {
                $new_content[] = $line;
            } else {
                $deleted_count++;
                // Output dosyasından da sil
                remove_from_output_file($ip, $output_file);
            }
        }
    }
    
    // Dosyayı güncelle
    file_put_contents($file_path, implode("\n", $new_content) . "\n");
    
    $_SESSION['message'] = "$deleted_count IP adresi başarıyla silindi.";
} else {
    $_SESSION['message'] = "Silinecek IP adresi seçilmedi.";
}

// Output dosyasından IP'yi silme fonksiyonu
function remove_from_output_file($ip_to_remove, $output_file) {
    if (!file_exists($output_file)) {
        return;
    }
    
    $output_content = file($output_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $new_output_content = [];
    
    foreach ($output_content as $line) {
        $line = trim($line);
        // IP'yi prefix ile karşılaştır
        if (strpos($ip_to_remove, '/') !== false) {
            $ip_without_prefix = explode('/', $ip_to_remove)[0];
            if ($line !== $ip_to_remove && $line !== $ip_without_prefix) {
                $new_output_content[] = $line;
            }
        } else {
            if ($line !== $ip_to_remove) {
                $new_output_content[] = $line;
            }
        }
    }
    
    file_put_contents($output_file, implode("\n", $new_output_content) . "\n");
}

// Ana sayfaya yönlendir
header("Location: index.php");
exit();
?>