<?php 
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

// Config dosyasını yükle
$config = require_once 'config/config.php';

// Integration Manager'ı yükle
require_once 'includes/IntegrationManager.php';
$integrationManager = new IntegrationManager($config);

// Manuel blacklist'i output'a senkronize et
sync_manual_blacklist_to_output();

// Eğer message tanımlı değilse, başlangıçta boş bir değer atayın
if (!isset($_SESSION['message'])) {
    $_SESSION['message'] = "";
}

require_once 'vendor/autoload.php';

// Config'den dosya yollarını al
$file_path = $config['file_paths']['blacklist'];

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

// Güncellenmiş Blacklist Görüntüleme Fonksiyonu
function display_blacklist($search_ip = '', $per_page = 10, $page = 1, $list_filter = 'all') {
    global $file_path, $integrationManager;
    
    // Manuel güncellenebilen liste
    $manual_items = file_exists($file_path) ? file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    // Üç listeyi birleştirip, kaynağı belirterek işaretleyelim
    $combined_items = [];
    foreach ($manual_items as $item) {
        $combined_items[] = ['data' => $item, 'editable' => true, 'source' => 'Manuel'];
    }
    
    // Aktif entegrasyonları ekle
    foreach ($integrationManager->getEnabledIntegrations() as $key => $integration) {
        $integration_items = $integrationManager->getIpList($key);
        foreach ($integration_items as $item) {
            $combined_items[] = [
                'data' => $item, 
                'editable' => false, 
                'source' => $integrationManager->getName($key)
            ];
        }
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
        $search_ip_only = $search_ip;
        
        // Eğer arama terimi CIDR formatındaysa, sadece IP kısmını çıkar
        if (strpos($search_ip, '/') !== false) {
            $search_ip_only = explode('/', $search_ip)[0];
        }
        
        foreach ($combined_items as $item) {
            // Verinin herhangi bir kısmında doğrudan metin eşleşmesi (mevcut işlevsellik)
            if (strpos($item['data'], $search_ip) !== false) {
                $filtered_items[] = $item;
                continue; // Eşleşme varsa diğer kontrolleri atla
            }
            
            // Arama teriminin subnet kontrolü için geçerli bir IP olup olmadığını kontrol et
            if (filter_var($search_ip_only, FILTER_VALIDATE_IP)) {
                // Öğe verisinden IP/subnet çıkar
                $item_ip = '';
                if ($item['source'] === 'Manuel') {
                    // Manuel liste girişleri için IP, borudan önceki ilk kısımdır
                    $entry_parts = explode("|", $item['data']);
                    if (!empty($entry_parts[0])) {
                        $item_ip = $entry_parts[0];
                    }
                } else {
                    // Global listeler için, veri doğrudan IP/subnet'tir
                    $item_ip = $item['data'];
                }
                
                // Eğer öğe bir subnet içeriyorsa ('/'), IP'nin o subnet içinde olup olmadığını kontrol et
                if (!empty($item_ip) && strpos($item_ip, '/') !== false) {
                    if (is_ip_in_subnet_range($search_ip_only, $item_ip)) {
                        $filtered_items[] = $item;
                    }
                }
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
    
    // Liste filtre seçenekleri
    echo "<div class='search-bar'>";
    echo "<form method='get' action='" . htmlspecialchars($_SERVER["PHP_SELF"]) . "'>";
    echo "<table class='search-table' cellpadding='0' cellspacing='0'><tr>";
    echo "<td style='width:100%'><input type='text' name='search' class='form-control' placeholder='IP Adresi veya FQDN ara...' value='" . htmlspecialchars($search_ip) . "'></td>";
    echo "<td><button type='submit' class='btn btn-primary'><i class='fas fa-search'></i> Ara</button></td>";
    echo "</tr></table>";
    echo "<input type='hidden' name='per_page' value='" . $per_page . "'>";
    echo "<input type='hidden' name='list_filter' value='" . $list_filter . "'>";
    echo "</form>";
    echo "</div>";
    echo "<div class='action-bar'>";
    echo "<div class='filter-section'>";
    echo "<form method='get' action='" . htmlspecialchars($_SERVER["PHP_SELF"]) . "'>";
    echo "<label for='list_filter'>Liste Filtresi:</label>";
    echo "<select name='list_filter' id='list_filter' onchange='this.form.submit()'>";
    echo "<option value='all'" . ($list_filter === 'all' ? ' selected' : '') . ">Tüm Listeler</option>";
    echo "<option value='Manuel'" . ($list_filter === 'Manuel' ? ' selected' : '') . ">Manuel Liste</option>";
    
    // Aktif entegrasyonları dinamik olarak ekle
    foreach ($integrationManager->getEnabledIntegrations() as $key => $integration) {
        $integration_name = $integrationManager->getName($key);
        $selected = ($list_filter === $integration_name) ? ' selected' : '';
        echo "<option value='$integration_name'$selected>$integration_name</option>";
    }
    
    echo "</select>";
    echo "<input type='hidden' name='search' value='" . htmlspecialchars($search_ip) . "'>";
    echo "<input type='hidden' name='per_page' value='" . $per_page . "'>";
    echo "<input type='hidden' name='page' value='1'>";
    echo "</form>";
    echo "</div>";
    
    echo "<div class='per-page-section'>";
    echo "<form method='get' action='" . htmlspecialchars($_SERVER["PHP_SELF"]) . "'>";
    echo "<label for='per_page'>Sayfa Başına:</label>";
    echo "<select name='per_page' id='per_page' onchange='this.form.submit()'>";
    $per_page_options = [10, 25, 50, 100];
    foreach ($per_page_options as $option) {
        echo "<option value='$option'" . ($option == $per_page ? ' selected' : '') . ">$option</option>";
    }
    echo "</select>";
    echo "<input type='hidden' name='search' value='" . htmlspecialchars($search_ip) . "'>";
    echo "<input type='hidden' name='page' value='$page'>";
    echo "<input type='hidden' name='list_filter' value='$list_filter'>";
    echo "</form>";
    echo "</div>";
    echo "</div>"; // action-bar end
    
    echo "<div class='table-responsive'>";
    echo "<form method='post' action='delete.php'>";
    echo "<table class='data-table'>";
    echo "<thead>";
    echo "<tr>
            <th><input type='checkbox' id='select-all' onclick='toggleAllCheckboxes()'></th>
            <th>IP Adresi</th>
            <th>Yorum</th>
            <th>FQDN</th>
            <th>Jira Numarası/URL</th>
            <th>Tarih/Saat</th>
            <th>Liste</th>
            <th>İşlem</th>
          </tr>";
    echo "</thead>";
    echo "<tbody>";
    
    if (count($displayed_items) == 0) {
        echo "<tr><td colspan='8' class='no-records'>Kayıt bulunamadı</td></tr>";
    } else {
        foreach ($displayed_items as $item) {
            if (!empty($item['data'])) {
                // Global liste öğeleri için farklı bir işleme
                if ($item['source'] !== 'Manuel') {
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
                    echo "<td><input type='checkbox' name='selected_ips[]' value='$ip' class='record-checkbox'></td>";
                } else {
                    echo "<td class='center'>-</td>";
                }
                echo "<td>" . htmlspecialchars($ip) . "</td>
                      <td>" . htmlspecialchars($comment) . "</td>
                      <td>" . htmlspecialchars($fqdn) . "</td>
                      <td>" . htmlspecialchars($jira) . "</td>
                      <td>" . htmlspecialchars($date) . "</td>
                      <td>" . htmlspecialchars($item['source']) . "</td>";
                if ($item['editable']) {
                    echo "<td><a href='edit.php?ip=$ip' class='btn btn-edit'>Düzenle</a></td>";
                } else {
                    echo "<td class='center'>Okunabilir</td>";
                }
                echo "</tr>";
            }
        }
    }
    
    echo "</tbody>";
    echo "</table>";
    
    echo "<div class='table-actions'>";
    echo "<input type='submit' name='delete' value='Seçilenleri Sil' class='btn btn-delete'>";
    echo "</div>";
    echo "</form>";
    echo "</div>"; // table-responsive end
    
    echo "<div class='record-info'>Toplam: <b>$total_items</b> kayıt</div>";
    
    // Sayfalama
    if ($total_pages > 1) {
        echo "<div class='pagination'>";
        if ($page > 1) {
            echo "<a href='?page=" . ($page - 1) . "&per_page=$per_page&search=$search_ip&list_filter=$list_filter' class='page-link'>&laquo; Önceki</a>";
        }
        
        // Sayfa numaralarını göster
        $max_pages_to_show = 5;
        $start_page = max(1, min($page - floor($max_pages_to_show / 2), $total_pages - $max_pages_to_show + 1));
        $end_page = min($start_page + $max_pages_to_show - 1, $total_pages);
        
        if ($start_page > 1) {
            echo "<a href='?page=1&per_page=$per_page&search=$search_ip&list_filter=$list_filter' class='page-link'>1</a>";
            if ($start_page > 2) {
                echo "<span class='page-ellipsis'>...</span>";
            }
        }
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            if ($i == $page) {
                echo "<span class='page-link current'>$i</span>";
            } else {
                echo "<a href='?page=$i&per_page=$per_page&search=$search_ip&list_filter=$list_filter' class='page-link'>$i</a>";
            }
        }
        
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo "<span class='page-ellipsis'>...</span>";
            }
            echo "<a href='?page=$total_pages&per_page=$per_page&search=$search_ip&list_filter=$list_filter' class='page-link'>$total_pages</a>";
        }
        
        if ($page < $total_pages) {
            echo "<a href='?page=" . ($page + 1) . "&per_page=$per_page&search=$search_ip&list_filter=$list_filter' class='page-link'>Sonraki &raquo;</a>";
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
                            // Dosya yoksa dizinleri oluştur
                            $dir = dirname($file_path);
                            if (!is_dir($dir)) {
                                mkdir($dir, 0755, true);
                            }
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
            // Output dosyasına yazma
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
    
    // Mevcut içeriği satır satır bir dizi olarak al
    $existing_content = file_exists($output_file) ? 
        file($output_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    $changes_made = false;
    
    // IP'yi ekle (N/A değilse ve zaten mevcut değilse)
    if (!empty($ip) && $ip !== 'N/A' && !in_array(trim($ip), $existing_content)) {
        $existing_content[] = trim($ip);
        $changes_made = true;
    }
    
    // FQDN'i ekle (boş değilse ve zaten mevcut değilse)
    if (!empty($fqdn) && !in_array(trim($fqdn), $existing_content)) {
        $existing_content[] = trim($fqdn);
        $changes_made = true;
    }
    
    // Değişiklik yapıldıysa dosyayı yeniden yaz
    if ($changes_made) {
        // Dizin yoksa oluştur
        $output_dir = dirname($output_file);
        if (!is_dir($output_dir)) {
            mkdir($output_dir, 0755, true);
        }
        file_put_contents($output_file, implode("\n", $existing_content) . "\n");
    }
    
    return $changes_made;
}

function sync_manual_blacklist_to_output() {
    global $file_path, $config;
    $output_file = $config['file_paths']['output'];
    
    // Manuel listeyi oku
    $manual_items = file_exists($file_path) ? file($file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    // Mevcut output içeriğini bir dizi olarak al
    $existing_content = file_exists($output_file) ? 
        file($output_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
    
    $changes_made = false;
    
    // Manuel listedeki her öğeyi işle
    foreach ($manual_items as $item) {
        $parts = explode("|", $item);
        if (count($parts) >= 1) {
            $ip = trim($parts[0]);
            $fqdn = isset($parts[3]) ? trim($parts[3]) : '';
            
            // IP'yi ekle (N/A değilse ve zaten mevcut değilse)
            if ($ip && $ip !== 'N/A' && !in_array($ip, $existing_content)) {
                $existing_content[] = $ip;
                $changes_made = true;
            }
            
            // FQDN'i ekle (boş değilse ve zaten mevcut değilse)
            if ($fqdn && !in_array($fqdn, $existing_content)) {
                $existing_content[] = $fqdn;
                $changes_made = true;
            }
        }
    }
    
    // Değişiklik yapıldıysa dosyayı yeniden yaz
    if ($changes_made) {
        // Dizin yoksa oluştur
        $output_dir = dirname($output_file);
        if (!is_dir($output_dir)) {
            mkdir($output_dir, 0755, true);
        }
        file_put_contents($output_file, implode("\n", $existing_content) . "\n");
    }
    
    return $changes_made;
}

if (isset($_POST['sync_blacklist'])) {
    if (sync_manual_blacklist_to_output()) {
        $_SESSION['message'] = "Manuel liste başarıyla output dosyası ile senkronize edildi.";
    } else {
        $_SESSION['message'] = "Senkronizasyon için değişiklik gerekmedi.";
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
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
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $config['app']['name']; ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="assets/css/styles.css">
</head>

<body>
    <header class="header">
        <div class="header-content">
            <h1 class="header-title"><?php echo $config['app']['name']; ?></h1>
            <div class="header-actions">
                <a href="whitelist.php" class="btn btn-success">
                    <i class="fas fa-shield-alt"></i> Beyaz Liste Görüntüle
                </a>
                <?php if (!empty($config['app']['other_system_url'])): ?>
                <a href="<?php echo $config['app']['other_system_url']; ?>" class="btn btn-info">
                    <i class="fas fa-external-link-alt"></i> <?php echo $config['app']['other_system_name']; ?>
                </a>
                <?php endif; ?>
            </div>
            <img src="assets/images/logo.png" alt="Şirket Logosu" class="logo">
        </div>
    </header>

    <?php if (isset($_SESSION['message']) && !empty($_SESSION['message'])): ?>
    <div class="container">
        <div class="alert">
            <?php echo $_SESSION['message']; ?>
            <button type="button" class="close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    </div>
    <?php unset($_SESSION['message']); endif; ?>

    <div class="container">
        <!-- Sol taraf - Kara Liste Tablosu -->
        <main class="main-content">
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-ban"></i> Kara Liste (Blacklist) 
                        <span class="list-info" id="current-list-info">
                            <?php echo ($list_filter === 'all' ? 'Tüm Listeler' : $list_filter); ?>
                        </span>
                    </h2>
                    
                    <!-- Senkronizasyon butonu -->
                    <form method="post" action="" class="ml-auto">
                        <button type="submit" name="sync_blacklist" class="btn btn-primary btn-sm">
                            <i class="fas fa-sync"></i> Manuel Listeyi Senkronize Et
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <?php display_blacklist($search_ip, $per_page, $page, $list_filter); ?>
                </div>
            </div>
        </main>

        <!-- Sağ taraf - Ekleme Formları -->
        <aside class="sidebar">
            <!-- Manuel Ekleme Formu -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-plus-circle"></i> Manuel Ekleme</h3>
                </div>
                <div class="card-body">
                    <p class="mb-3">Bir veya daha fazla IP adresi girin (örn: 192.168.1.1/24). Birden fazla giriş için virgül kullanın.</p>
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                        <div class="form-group">
                            <label for="ip_address">IP Adresi:</label>
                            <input type="text" name="ip_address" id="ip_address" class="form-control" placeholder="IP Adresi">
                        </div>
                        
                        <div class="form-group">
                            <label for="comment">Yorum:</label>
                            <input type="text" name="comment" id="comment" class="form-control" placeholder="Yorum">
                        </div>
                        
                        <div class="form-group">
                            <label for="fqdn">FQDN:</label>
                            <input type="text" name="fqdn" id="fqdn" class="form-control" placeholder="FQDN">
                        </div>
                        
                        <div class="form-group">
                            <label for="jira">Jira Numarası/URL:</label>
                            <input type="text" name="jira" id="jira" class="form-control" placeholder="Jira Numarası/URL">
                        </div>
                        
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Ekle
                        </button>
                    </form>
                </div>
            </div>

            <!-- Excel ile Toplu Ekleme -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-file-excel"></i> Excel ile Toplu Ekleme</h3>
                </div>
                <div class="card-body">
                    <p class="mb-3">Excel taslağını indirin, düzenleyin ve buraya yükleyin.</p>
                    <a href="download_excel.php" class="btn btn-success mb-3">
                        <i class="fas fa-download"></i> Excel Taslağını İndir
                    </a>
                    
                    <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" enctype="multipart/form-data">
                        <div class="form-group">
                            <div class="file-upload">
                                <label for="excel_file" class="file-upload-label">
                                    <i class="fas fa-upload"></i> Dosya Seç
                                </label>
                                <input type="file" name="excel_file" id="excel_file" required onchange="updateFileName(this)">
                                <span id="file-name" class="file-name">Dosya seçilmedi</span>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary mt-2">
                            <i class="fas fa-cloud-upload-alt"></i> Yükle
                        </button>
                    </form>
                </div>
            </div>
        </aside>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo date('Y'); ?> <?php echo $config['app']['company']; ?>. Tüm hakları saklıdır.</p>
    </footer>

    <script>
        // Tüm onay kutularını seçme/kaldırma
        function toggleAllCheckboxes() {
            var checkboxes = document.getElementsByClassName('record-checkbox');
            var selectAllCheckbox = document.getElementById('select-all');
            
            for (var i = 0; i < checkboxes.length; i++) {
                checkboxes[i].checked = selectAllCheckbox.checked;
            }
        }
        
        // Dosya adını gösterme
        function updateFileName(input) {
            var fileName = input.files[0] ? input.files[0].name : 'Dosya seçilmedi';
            document.getElementById('file-name').textContent = fileName;
        }
    </script>
</body>
</html>
