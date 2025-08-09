<?php 
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Config dosyasını yükle
$config = require_once 'config/config.php';

// Eğer message tanımlı değilse, başlangıçta boş bir değer atayın
if (!isset($_SESSION['message'])) {
    $_SESSION['message'] = "";
}

require_once 'vendor/autoload.php';

// Config'den dosya yollarını al
$file_path = $config['file_paths']['blacklist'];
$ci_badguys_blacklist = $config['file_paths']['ci_badguys'];
$firehol_blacklist = $config['file_paths']['firehol'];

// Bildirimleri göster
function display_message() {
    if (isset($_SESSION['message']) && !empty($_SESSION['message'])) {
        echo "<div class='alert'>
                {$_SESSION['message']}
                <span class='close' onclick='this.parentElement.style.display=\"none\";'>&times;</span>
              </div>";
        unset($_SESSION['message']);
    }
}

// IP Doğrulama Fonksiyonu
function validate_ip($ip) {
    if (strpos($ip, '/') !== false) {
        list($subnet, $prefix) = explode('/', $ip);
        return (filter_var($subnet, FILTER_VALIDATE_IP) && is_numeric($prefix) && $prefix >= 0 && $prefix <= 32);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
}

// Private IP adreslerini kontrol eden fonksiyon
function is_private_ip($ip) {
    // IPv4 özel adres aralıkları
    $private_ips = [
        '10.0.0.0' => '10.255.255.255',   // 10.0.0.0/8
        '172.16.0.0' => '172.31.255.255',   // 172.16.0.0/12
        '192.168.0.0' => '192.168.255.255'  // 192.168.0.0/16
    ];

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip_long = ip2long($ip);
        // Özel IP aralıklarında kontrol
        foreach ($private_ips as $start => $end) {
            $start_long = ip2long($start);
            $end_long = ip2long($end);
            if ($ip_long >= $start_long && $ip_long <= $end_long) {
                return true; // IP özel aralıkta
            }
        }
    }
    return false; // IP özel aralıkta değil
}

// IP'yi CIDR formatında doğrulama
function validate_cidr($cidr) {
    if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d+$/', $cidr)) {
        list($ip, $prefix) = explode('/', $cidr);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            if (is_private_ip($ip)) {
                return false; // Özel IP adresi CIDR formatında eklenemez
            }
            return true;
        }
    }
    return false;
}

// FQDN Doğrulama Fonksiyonu
function validate_fqdn($fqdn) {
    if (substr($fqdn, -1) === '.') {
        return false;
    }
    return (filter_var($fqdn, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false);
}

// FQDN var mı kontrolü
function fqdn_exists($fqdn) {
    global $file_path;
    if (!file_exists($file_path)) {
        return false;
    }
    $file_content = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($file_content as $item) {
        $parts = explode("|", $item);
        if (count($parts) >= 4) {
            $existing_fqdn = $parts[3];
            if ($existing_fqdn == $fqdn) {
                return true;
            }
        }
    }
    return false;
}

// IP var mı kontrolü
function ip_exists($ip) {
    global $file_path;
    if (!file_exists($file_path)) {
        return false;
    }
    $file_content = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($file_content as $item) {
        list($existing_ip) = explode("|", $item);
        if (strpos($existing_ip, '/') !== false) {
            if (is_ip_in_subnet_range($ip, $existing_ip)) {
                return $existing_ip;
            }
        } else {
            if ($existing_ip == $ip) {
                return $existing_ip;
            }
        }
    }
    return false;
}

// Subnet var mı kontrolü
function subnet_exists($ip) {
    global $file_path;
    if (!file_exists($file_path)) {
        return false;
    }
    $file_content = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($file_content as $item) {
        list($existing_ip) = explode("|", $item);
        if (strpos($existing_ip, '/') !== false && $existing_ip == $ip) {
            return true;
        }
    }
    return false;
}

// CIDR'den IP aralığını elde eden fonksiyon
function get_ip_range_from_cidr($cidr) {
    list($ip, $mask) = explode('/', $cidr);
    $ip_long = ip2long($ip);
    $mask = (int)$mask;
    $mask_long = -1 << (32 - $mask);
    $network_start = $ip_long & $mask_long;
    $network_end = $network_start | (~$mask_long & 0xFFFFFFFF);
    return [long2ip($network_start), long2ip($network_end)];
}

// IP'nin CIDR bloğu içinde olup olmadığını kontrol etme
function is_ip_in_subnet_range($ip, $subnet) {
    list($start_ip, $end_ip) = get_ip_range_from_cidr($subnet);
    $ip_long = ip2long($ip);
    $start_long = ip2long($start_ip);
    $end_long = ip2long($end_ip);
    if ($ip_long === false || $start_long === false || $end_long === false) {
        return false;
    }
    return ($ip_long >= $start_long && $ip_long <= $end_long);
}

// Şirket IP bloklarını ve whitelist'teki IP'leri kontrol eden fonksiyon
function is_company_ip($ip) {
    global $config;
    $company_blocks = $config['company_blocks'];
    
    // Whitelist dosyasını oku ve bloklara ekle
    $whitelist_path = $config['file_paths']['whitelist'];
    if (file_exists($whitelist_path)) {
        $whitelist_content = file($whitelist_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($whitelist_content as $line) {
            // Yorum satırlarını atla
            $line = trim($line);
            if (!empty($line) && substr($line, 0, 1) !== '#') {
                // IP formatını kontrol et (basit bir kontrol)
                if (filter_var(explode('/', $line)[0], FILTER_VALIDATE_IP) || 
                    (strpos($line, '/') !== false && validate_cidr($line))) {
                    $company_blocks[] = $line;
                }
            }
        }
    }
    
    if (strpos($ip, '/') === false) {
        $ip = $ip . '/32';
    }
    
    foreach ($company_blocks as $block) {
        if (is_ip_in_subnet_range(explode('/', $ip)[0], $block)) {
            return true;
        }
    }
    return false;
}

// ********************
// Güncellenmiş Blacklist Görüntüleme Fonksiyonu
// ********************
function display_blacklist($search_ip = '', $per_page = 10, $page = 1, $list_filter = 'all') {
    global $file_path, $ci_badguys_blacklist, $firehol_blacklist;
    
    // Manuel güncellenebilen liste
    $manual_items = file_exists($file_path) ? file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    // Global listeler (sadece görüntülenebilir)
    $ci_badguys_items = [];
    if (file_exists($ci_badguys_blacklist)) {
        $lines = file($ci_badguys_blacklist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Yorum satırlarını atla ve IP formatında olan satırları al
            if (substr(trim($line), 0, 1) !== '#' && filter_var(trim($line), FILTER_VALIDATE_IP) || 
                strpos(trim($line), '/') !== false && validate_cidr(trim($line))) {
                $ci_badguys_items[] = trim($line);
            }
        }
    }
    
    $firehol_items = [];
    if (file_exists($firehol_blacklist)) {
        $lines = file($firehol_blacklist, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Yorum satırlarını atla ve IP formatında olan satırları al
            if (substr(trim($line), 0, 1) !== '#' && (filter_var(trim($line), FILTER_VALIDATE_IP) || 
                strpos(trim($line), '/') !== false && validate_cidr(trim($line)))) {
                $firehol_items[] = trim($line);
            }
        }
    }

    // Üç listeyi birleştirip, kaynağı belirterek işaretleyelim
    $combined_items = [];
    foreach ($manual_items as $item) {
        $combined_items[] = ['data' => $item, 'editable' => true, 'source' => 'Manuel'];
    }
    foreach ($ci_badguys_items as $item) {
        $combined_items[] = ['data' => $item, 'editable' => false, 'source' => 'ci-badguys'];
    }
    foreach ($firehol_items as $item) {
        $combined_items[] = ['data' => $item, 'editable' => false, 'source' => 'Firehol_level1'];
    }
    
    // Liste filtreleme
    if ($list_filter !== 'all') {
        $filtered_by_source = [];
        foreach ($combined_items as $item) {
            if ($item['source'] === $list_filter) {
                $filtered_by_source[] = $item;
            }
        }
        $combined_items = $filtered_by_source;
    }
    
    // Arama yapılıyorsa filtrele
    if ($search_ip) {
        $filtered_items = [];
        foreach ($combined_items as $item) {
            if (strpos($item['data'], $search_ip) !== false) {
                $filtered_items[] = $item;
            }
        }
    } else {
        $filtered_items = $combined_items;
    }
    
    $total_items = count($filtered_items);
    $total_pages = ceil($total_items / $per_page);
    if ($total_pages < 1) $total_pages = 1;
    $page = max(1, min($page, $total_pages));
    $start_index = ($page - 1) * $per_page;
    $displayed_items = array_slice($filtered_items, $start_index, $per_page);
    
    echo "<h2>Kara Liste</h2>";
    
    // Liste filtre seçenekleri
    echo "<div class='list-filter'>";
    echo "<form method='get' action='" . htmlspecialchars($_SERVER["PHP_SELF"]) . "'>";
    echo "<label for='list_filter'>Liste Filtresi:</label>";
    echo "<select name='list_filter' id='list_filter' onchange='this.form.submit()'>";
    echo "<option value='all'" . ($list_filter === 'all' ? ' selected' : '') . ">Tüm Listeler</option>";
    echo "<option value='Manuel'" . ($list_filter === 'Manuel' ? ' selected' : '') . ">Manuel Liste</option>";
    echo "<option value='ci-badguys'" . ($list_filter === 'ci-badguys' ? ' selected' : '') . ">ci-badguys</option>";
    echo "<option value='Firehol_level1'" . ($list_filter === 'Firehol_level1' ? ' selected' : '') . ">Firehol_level1</option>";
    echo "</select>";
    echo "<input type='hidden' name='search' value='" . htmlspecialchars($search_ip) . "'>";
    echo "<input type='hidden' name='per_page' value='" . $per_page . "'>";
    echo "<input type='hidden' name='page' value='1'>";
    echo "</form>";
    echo "</div>";
    
    echo "<form method='post' action='delete.php'>";
    echo "<table class='blacklist-table'>";
    echo "<tr>
            <th>Seç</th>
            <th>IP Adresi</th>
            <th>Yorum</th>
            <th>FQDN</th>
            <th>Jira Numarası/URL</th>
            <th>Tarih/Saat</th>
            <th>Liste</th>
            <th>İşlem</th>
          </tr>";
    
    foreach ($displayed_items as $item) {
        if (!empty($item['data'])) {
            // Global liste öğeleri için farklı bir işleme
            if ($item['source'] === 'ci-badguys' || $item['source'] === 'Firehol_level1') {
                $ip = $item['data']; // Data direkt IP'dir artık
                $comment = '';
                $fqdn = '';
                $jira = '';
                $date = '';
            } else {
                // Manuel liste için normal ayrıştırma
                $entry_parts = explode("|", $item['data']);
                if (count($entry_parts) < 5) {
                    // Bu satırı atla veya boş değerlerle doldur
                    $entry_parts = array_pad($entry_parts, 5, '');
                }
                list($ip, $comment, $date, $fqdn, $jira) = $entry_parts;
            }
            
            echo "<tr>";
            if ($item['editable']) {
                echo "<td><input type='checkbox' name='selected_ips[]' value='$ip'></td>";
            } else {
                echo "<td>-</td>";
            }
            echo "<td>$ip</td>
                  <td>$comment</td>
                  <td>$fqdn</td>
                  <td>$jira</td>
                  <td>$date</td>
                  <td>{$item['source']}</td>";
            if ($item['editable']) {
                echo "<td><a href='edit.php?ip=$ip'>Düzenle</a></td>";
            } else {
                echo "<td>Okunabilir</td>";
            }
            echo "</tr>";
        }
    }
    
    echo "</table>";
    echo "<input type='submit' name='delete' value='Sil' class='delete-button'>";
    echo "</form>";
    
    echo "<div>Toplam: $total_items öğe bulunmaktadır.</div>";
    
    // Sayfalama
    if ($total_pages > 1) {
        echo "<div class='pagination'>";
        if ($page > 1) {
            echo "<a href='?page=" . ($page - 1) . "&per_page=$per_page&search=$search_ip&list_filter=$list_filter'>Önceki</a>";
        }
        
        // Sayfa numaralarını göster
        $max_pages_to_show = 5;
        $start_page = max(1, min($page - floor($max_pages_to_show / 2), $total_pages - $max_pages_to_show + 1));
        $end_page = min($start_page + $max_pages_to_show - 1, $total_pages);
        
        if ($start_page > 1) {
            echo "<a href='?page=1&per_page=$per_page&search=$search_ip&list_filter=$list_filter'>1</a>";
            if ($start_page > 2) {
                echo "<span>...</span>";
            }
        }
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            if ($i == $page) {
                echo "<strong>$i</strong>";
            } else {
                echo "<a href='?page=$i&per_page=$per_page&search=$search_ip&list_filter=$list_filter'>$i</a>";
            }
        }
        
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo "<span>...</span>";
            }
            echo "<a href='?page=$total_pages&per_page=$per_page&search=$search_ip&list_filter=$list_filter'>$total_pages</a>";
        }
        
        if ($page < $total_pages) {
            echo "<a href='?page=" . ($page + 1) . "&per_page=$per_page&search=$search_ip&list_filter=$list_filter'>Sonraki</a>";
        }
        echo "</div>";
    }
}

// IP'yi prefix formatına çevir
function convert_ip_to_prefix($ip) {
    if (strpos($ip, '/') !== false) {
        return $ip;
    }
    return "$ip/32"; 
}

// Manuel ekleme (POST ile)
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['ip_address'])) {
    $ip_input = isset($_POST['ip_address']) ? trim($_POST['ip_address']) : '';
    $comment = isset($_POST['comment']) ? trim($_POST['comment']) : '';
    $fqdn = isset($_POST['fqdn']) ? trim($_POST['fqdn']) : '';
    $jira = isset($_POST['jira']) ? trim($_POST['jira']) : '';

    if (empty($ip_input) && empty($fqdn)) {
        $_SESSION['message'] = "Lütfen en az bir IP adresi veya FQDN girin.";
    } elseif (!empty($ip_input) && !empty($fqdn)) {
        $_SESSION['message'] = "Sadece bir IP adresi veya FQDN girin, her ikisini birden girmeyin.";
    } else {
        if (!empty($ip_input)) {
            $ip_addresses = explode(',', $ip_input);
            foreach ($ip_addresses as $ip_input) {
                $ip_input = trim($ip_input);
                if (strpos($ip_input, '/') === false) {
                    $ip_input .= '/32';
                }
                if (is_private_ip(explode('/', $ip_input)[0])) {
                    $_SESSION['message'] .= "Özel IP adresi (Private IP) eklenemez: $ip_input<br>";
                    continue;
                }
                if (!validate_ip($ip_input)) {
                    $_SESSION['message'] .= "Geçersiz IP adresi veya subnet prefix: $ip_input<br>";
                    continue;
                }
                if (is_company_ip($ip_input)) {
                    $_SESSION['message'] .= "Bu IP, şirket ortamlarına aittir ve eklenemez: $ip_input<br>";
                    continue;
                }
                $existing_ip_or_subnet = ip_exists($ip_input);
                if ($existing_ip_or_subnet) {
                    $_SESSION['message'] .= "Bu IP adresi veya subnet zaten mevcut: $ip_input, mevcut subnet: $existing_ip_or_subnet<br>";
                    continue;
                } else {
                    list($ip, $cidr) = explode('/', $ip_input);
                    if (is_private_ip($ip)) {
                        $_SESSION['message'] .= "Özel IP adresi (Private IP) eklenemez: $ip_input<br>";
                        continue;
                    } elseif (!validate_ip($ip_input)) {
                        $_SESSION['message'] .= "Geçersiz IP adresi veya subnet prefix: $ip_input<br>";
                        continue;
                    } else {
                        if (!file_exists($file_path)) {
                            // Dosya yoksa oluştur
                            file_put_contents($file_path, '');
                        }
                        $file_content = file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                        $skip = false;
                        foreach ($file_content as $item) {
                            list($existing_ip) = explode("|", $item);
                            if (strpos($existing_ip, '/') !== false) {
                                if (is_ip_in_subnet_range($ip, $existing_ip)) {
                                    $_SESSION['message'] .= "Bu IP, mevcut subnet aralığındadır ve eklenemez: $ip_input<br>";
                                    $skip = true;
                                    break;
                                }
                            }
                        }
                        if ($skip) {
                            continue;
                        }
                        $date = new DateTime('now', new DateTimeZone($config['timezone']));
                        $date_string = $date->format('Y-m-d H:i:s');
                        $new_entry = "$ip_input|$comment|$date_string|$fqdn|$jira\n";
                        file_put_contents($file_path, $new_entry, FILE_APPEND);
                        $_SESSION['message'] .= "IP adresi başarıyla eklendi: $ip_input<br>";
                        write_to_output_blacklist($ip_input, $fqdn);
                    }
                }
            }
        }
    }
}

// Excel ile toplu ekleme işlemi
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file']['tmp_name'];
    $excelData = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $sheet = $excelData->getActiveSheet();

    $successful_entries = [];
    $error_messages = [];

    foreach ($sheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);

        $rowData = [];
        foreach ($cellIterator as $cell) {
            $rowData[] = $cell->getValue();
        }

        $ip = trim($rowData[0]);
        $comment = trim($rowData[1]);
        $fqdn = trim($rowData[2]);
        $jira = trim($rowData[3]);

        if (!empty($ip)) {
            if (strpos($ip, '/') !== false) {
                $ip = explode('/', $ip)[0];
            } else {
                $ip .= '/32';
            }
            // Özel IP kontrolü
			if (is_private_ip(explode('/', $ip)[0])) {
                $error_messages[] = "Özel IP adresi (Private IP) eklenemez: $ip";
                continue; // Özel IP'yi atla
            }
            // Şirket IP bloklarına ait mi kontrol et
			if (is_company_ip($ip)) {
                $error_messages[] = "Bu IP, şirket ortamına aittir ve eklenemez: $ip";
                continue;
            }
            // IP geçerlilik kontrolü
			if (!validate_ip($ip)) {
                $error_messages[] = "Geçersiz IP adresi veya subnet prefix: $ip";
                continue; // Geçersizse bir sonraki satıra geç
            } elseif (ip_exists($ip) || subnet_exists($ip)) {
                $error_messages[] = "Bu IP adresi veya subnet zaten mevcut: $ip";
                continue; // Zaten mevcutsa bir sonraki satıra geç
            }
        }

        // FQDN doğrulama
		if (!empty($fqdn)) {
            if (!validate_fqdn($fqdn)) {
                $error_messages[] = "Geçersiz FQDN: $fqdn";
                continue; // Geçersizse bir sonraki satıra geç
            } elseif (fqdn_exists($fqdn)) {
                $error_messages[] = "Bu FQDN zaten mevcut: $fqdn";
                continue; // Zaten mevcutsa bir sonraki satıra geç
            }
        }

        // IP'yi prefix formatına çevir
		$ip_prefix = empty($ip) ? 'N/A' : convert_ip_to_prefix($ip);
        // Yeni giriş ekleme
		$date = new DateTime('now', new DateTimeZone($config['timezone']));
        $date_string = $date->format('Y-m-d H:i:s');

        // IP varsa kaydet
		if (!empty($ip)) {
            $new_entry = "$ip_prefix|$comment|$date_string|$fqdn|$jira\n";
            file_put_contents($file_path, $new_entry, FILE_APPEND);
            // output dosyasına yazma
			write_to_output_blacklist($ip, $fqdn);
        } elseif (!empty($fqdn)) {
            // FQDN eklerken IP yoksa "N/A" kullan
			$new_entry = "N/A|$comment|$date_string|$fqdn|$jira\n";
            file_put_contents($file_path, $new_entry, FILE_APPEND);
            write_to_output_blacklist('N/A', $fqdn);
        }
        $successful_entries[] = !empty($ip) ? $ip : $fqdn; // Başarıyla eklenen girişleri diziye ekle
    }

    // Bildirim oluştur
	$messages = [];
    if (!empty($successful_entries)) {
        $messages[] = "Başarıyla eklendi: " . implode(', ', $successful_entries);
    }
    if (!empty($error_messages)) {
        $messages[] = "Aşağıdaki girişler eklenemedi:<br>" . implode('<br>', $error_messages);
    }
    $_SESSION['message'] = implode('<br>', $messages);
}

function write_to_output_blacklist($ip, $fqdn) {
    global $config;
    $output_file = $config['file_paths']['output'];
    
    // Dizin yoksa oluştur
    $output_dir = dirname($output_file);
    if (!is_dir($output_dir)) {
        mkdir($output_dir, 0755, true);
    }
    
    $existing_content = file_exists($output_file) ? file_get_contents($output_file) : '';
    // Eğer IP varsa, 'N/A' değilse ve zaten mevcut değilse, ekle
	if (!empty($ip) && $ip !== 'N/A' && strpos($existing_content, trim($ip)) === false) {
        file_put_contents($output_file, trim($ip) . "\n", FILE_APPEND);
    }
	// Eğer FQDN varsa ve zaten mevcut değilse, ekle
    if (!empty($fqdn) && strpos($existing_content, trim($fqdn)) === false) {
        file_put_contents($output_file, trim($fqdn) . "\n", FILE_APPEND);
    }
}

// Kullanıcıdan arama terimini ve sayfa ayarlarını al
$search_ip = isset($_GET['search']) ? trim($_GET['search']) : '';
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : $config['pagination']['default_per_page'];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$list_filter = isset($_GET['list_filter']) ? trim($_GET['list_filter']) : 'all';
$per_page_options = $config['pagination']['options'];
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <title><?php echo $config['app']['name']; ?></title>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body>
<header style="background-color: rgba(0, 85, 136, 0.7); color: white; padding: 20px; position: relative;">
    <img src="assets/images/logo.png" alt="Şirket Logosu" style="position: absolute; top: 20px; right: 20px; height: 50px;">
    <h1 style="text-align: center; color: white;"><?php echo $config['app']['name']; ?></h1>
	<!-- Button to redirect to other management system -->
    <a href="<?php echo $config['app']['other_system_url']; ?>" 
       style="position: absolute; top: 20px; left: 100px; padding: 10px 20px; background-color:#000000; color: white; border-radius: 5px; text-decoration: none;">
       <?php echo $config['app']['other_system_name']; ?>
    </a>
</header>

<main>
    <div class="notification-area">
        <?php display_message(); ?>
    </div>
    <section>
        <div class="form-group" style="background-color: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); margin-bottom: 20px;">
            <h2>Manuel Ekleme</h2>
            <p>Bir veya daha fazla IP adresi girin (örn: 192.168.1.1/24, 255.255.255.0). Virgülle ayırmayı unutmayın.</p>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <label for="ip_address">IP Adresi:</label>
                <input type="text" name="ip_address" id="ip_address" placeholder="IP Adresi">
                <label for="comment">Yorum:</label>
                <input type="text" name="comment" id="comment" placeholder="Yorum">
                <label for="fqdn">FQDN:</label>
				<input type="text" name="fqdn" id="fqdn" placeholder="FQDN">
                <label for="jira">Jira Numarası/URL:</label>
                <input type="text" name="jira" id="jira" placeholder="Jira Numarası/URL">
                <input type="submit" value="Ekle">
            </form>
        </div>

        <div class="upload-section">
            <h3>Excel ile Toplu Ekleme</h3>
            <a href="download_excel.php" class="button">Excel Taslağını İndir</a>
            <p>Excel taslağını indirin, düzenleyin ve buraya yükleyin.</p>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                <input type="file" name="excel_file" required>
                <input type="submit" value="Yükle">
            </form>
        </div>

        <div class="search-section">
            <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <label for="search">IP Adresi Ara:</label>
                <input type="text" name="search" id="search" placeholder="IP Adresi" value="<?php echo htmlspecialchars($search_ip); ?>">
                <input type="submit" value="Ara">
            </form>
        </div>

        <div class="per-page-selection">
            <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>">
                <label for="per_page">Sayfa Başına:</label>
                <select name="per_page" id="per_page" onchange="this.form.submit()">
                    <?php foreach ($per_page_options as $option): ?>
                        <option value="<?php echo $option; ?>" <?php if ($option == $per_page) echo 'selected'; ?>>
                            <?php echo $option; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search_ip); ?>">
            </form>
        </div>

        <div class="blacklist-table">
            <?php display_blacklist($search_ip, $per_page, $page, $list_filter); ?>
        </div>
    </section>
</main>

<footer>
    <p>&copy; <?php echo date('Y'); ?> <?php echo $config['app']['company']; ?>. Tüm hakları saklıdır.</p>
</footer>
</body>
</html>