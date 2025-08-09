if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_FILES['excel_file'])) {
    $file = $_FILES['excel_file']['tmp_name'];
    $excelData = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
    $sheet = $excelData->getActiveSheet();

    $successful_entries = []; // Başarıyla eklenen girişleri tutacak dizi
    $error_messages = []; // Hata mesajlarını tutacak dizi

    foreach ($sheet->getRowIterator() as $row) {
        $cellIterator = $row->getCellIterator();
        $cellIterator->setIterateOnlyExistingCells(false);

        $rowData = [];
        foreach ($cellIterator as $cell) {
            $rowData[] = $cell->getValue();
        }

        // Excel'den alınan veriler
        $ip = trim($rowData[0]);
        $comment = trim($rowData[1]);
        $fqdn = trim($rowData[2]);
        $jira = trim($rowData[3]);

        // IP doğrulama ve özel IP kontrolü
        if (!empty($ip)) {
            // CIDR formatı kontrolü (IP/Prefix formatı)
            if (strpos($ip, '/') !== false) {
                // Eğer CIDR formatı varsa sadece IP kısmını al
                $ip = explode('/', $ip)[0];
            }

            // Özel IP kontrolü
            if (is_private_ip($ip)) {
                $error_messages[] = "Özel IP adresi (Private IP) eklenemez: $ip";
                continue; // Özel IP ise bir sonraki satıra geç
            }

            // Geçersiz IP adresi kontrolü
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
        $date = new DateTime('now', new DateTimeZone('Europe/Istanbul'));
        $date_string = $date->format('Y-m-d H:i:s');

        // IP varsa kaydet
        if (!empty($ip)) {
            $new_entry = "$ip_prefix|$comment|$date_string|$fqdn|$jira\n";
            file_put_contents($file_path, $new_entry, FILE_APPEND);
            // paytenblacklist.txt dosyasına yazma
            write_to_payten_blacklist($ip, $fqdn);
        } elseif (!empty($fqdn)) {
            // FQDN eklerken IP yoksa "N/A" kullan
            $new_entry = "N/A|$comment|$date_string|$fqdn|$jira\n";
            file_put_contents($file_path, $new_entry, FILE_APPEND);
            write_to_payten_blacklist('N/A', $fqdn);
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
