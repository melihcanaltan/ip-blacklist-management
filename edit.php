<?php
session_start();

// Config dosyasını yükle
$config = require_once 'config/config.php';
$file_path = $config['file_paths']['blacklist'];

// Düzenlenecek IP'yi al
$edit_ip = isset($_GET['ip']) ? trim($_GET['ip']) : '';
$entry_data = null;

if (empty($edit_ip)) {
    $_SESSION['message'] = "Düzenlenecek IP adresi belirtilmedi.";
    header("Location: index.php");
    exit();
}

// Dosyadan girişi bul
if (file_exists($file_path)) {
    $file_content = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($file_content as $line) {
        $entry_parts = explode("|", $line);
        if (count($entry_parts) >= 5 && $entry_parts[0] === $edit_ip) {
            $entry_data = [
                'ip' => $entry_parts[0],
                'comment' => $entry_parts[1],
                'date' => $entry_parts[2],
                'fqdn' => $entry_parts[3],
                'jira' => $entry_parts[4]
            ];
            break;
        }
    }
}

if (!$entry_data) {
    $_SESSION['message'] = "Belirtilen IP adresi bulunamadı.";
    header("Location: index.php");
    exit();
}

// Form gönderildiğinde güncelleme yap
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update'])) {
    $new_comment = trim($_POST['comment']);
    $new_fqdn = trim($_POST['fqdn']);
    $new_jira = trim($_POST['jira']);
    
    // Dosya içeriğini oku ve güncelle
    $file_content = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $new_content = [];
    $updated = false;
    
    foreach ($file_content as $line) {
        $entry_parts = explode("|", $line);
        if (count($entry_parts) >= 5 && $entry_parts[0] === $edit_ip) {
            // Bu satırı güncelle
            $updated_line = $entry_parts[0] . "|" . $new_comment . "|" . $entry_parts[2] . "|" . $new_fqdn . "|" . $new_jira;
            $new_content[] = $updated_line;
            $updated = true;
        } else {
            $new_content[] = $line;
        }
    }
    
    if ($updated) {
        file_put_contents($file_path, implode("\n", $new_content) . "\n");
        $_SESSION['message'] = "IP adresi başarıyla güncellendi: $edit_ip";
    } else {
        $_SESSION['message'] = "IP adresi güncellenemedi.";
    }
    
    header("Location: index.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title>IP Düzenle - <?php echo $config['app']['name']; ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<header style="background-color: rgba(0, 85, 136, 0.7); color: white; padding: 20px; position: relative;">
    <img src="assets/images/logo.png" alt="Şirket Logosu" style="position: absolute; top: 20px; right: 20px; height: 50px;">
    <h1 style="text-align: center; color: white;">IP Düzenle</h1>
    <a href="index.php" 
       style="position: absolute; top: 20px; left: 20px; padding: 10px 20px; background-color:#000000; color: white; border-radius: 5px; text-decoration: none;">
       ← Ana Sayfaya Dön
    </a>
</header>

<main>
    <section>
        <div class="form-group" style="background-color: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); margin: 20px auto; max-width: 600px;">
            <h2>IP Düzenle: <?php echo htmlspecialchars($entry_data['ip']); ?></h2>
            
            <form method="post" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . '?ip=' . urlencode($edit_ip); ?>">
                <div style="margin-bottom: 15px;">
                    <label for="ip_display">IP Adresi:</label>
                    <input type="text" id="ip_display" value="<?php echo htmlspecialchars($entry_data['ip']); ?>" readonly style="background-color: #f5f5f5;">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="comment">Yorum:</label>
                    <input type="text" name="comment" id="comment" value="<?php echo htmlspecialchars($entry_data['comment']); ?>" placeholder="Yorum">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="fqdn">FQDN:</label>
                    <input type="text" name="fqdn" id="fqdn" value="<?php echo htmlspecialchars($entry_data['fqdn']); ?>" placeholder="FQDN">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="jira">Jira Numarası/URL:</label>
                    <input type="text" name="jira" id="jira" value="<?php echo htmlspecialchars($entry_data['jira']); ?>" placeholder="Jira Numarası/URL">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label for="date_display">Ekleme Tarihi:</label>
                    <input type="text" id="date_display" value="<?php echo htmlspecialchars($entry_data['date']); ?>" readonly style="background-color: #f5f5f5;">
                </div>
                
                <div style="text-align: center;">
                    <input type="submit" name="update" value="Güncelle" style="padding: 10px 20px; background-color: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer;">
                    <a href="index.php" style="padding: 10px 20px; background-color: #6c757d; color: white; text-decoration: none; border-radius: 5px; margin-left: 10px;">İptal</a>
                </div>
            </form>
        </div>
    </section>
</main>

<footer>
    <p>&copy; <?php echo date('Y'); ?> <?php echo $config['app']['company']; ?>. Tüm hakları saklıdır.</p>
</footer>
</body>
</html>
