<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

// Eger message tanimli degilse, baslangiçta bos bir deger atayin
if (!isset($_SESSION['message'])) {
    $_SESSION['message'] = "";
}

// Bildirimleri göster
function display_message() {
    if (isset($_SESSION['message']) && !empty($_SESSION['message'])) {
        echo "<div class='alert alert-info'>
                {$_SESSION['message']}
                <button type='button' class='close' onclick='this.parentElement.style.display=\"none\"'>&times;</button>
              </div>";
        unset($_SESSION['message']);
    }
}

function display_whitelist($search_ip = '', $per_page = 10, $page = 1) {
    $whitelist_path = "/var/www/blacklist/payten/whitelist.txt";
    
    // Whitelist dosyasini oku
    $whitelist_items = [];
    if (file_exists($whitelist_path)) {
        $lines = file($whitelist_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Yorum satirlarini atla ve IP formatinda olan satirlari al
            if (substr(trim($line), 0, 1) !== '#' && (filter_var(explode('/', trim($line))[0], FILTER_VALIDATE_IP) || 
                (strpos(trim($line), '/') !== false && validate_cidr(trim($line))))) {
                $whitelist_items[] = trim($line);
            }
        }
    }
    
    // Arama yapiliyorsa filtrele
    if ($search_ip) {
        $filtered_items = [];
        foreach ($whitelist_items as $item) {
            // Direkt eslesme veya subnet kontrolü
            if (strpos($item, $search_ip) !== false || ip_in_subnet($search_ip, $item)) {
                $filtered_items[] = $item;
            }
        }
    } else {
        $filtered_items = $whitelist_items;
    }
    
    $total_items = count($filtered_items);
    $total_pages = ceil($total_items / $per_page);
    if ($total_pages < 1) $total_pages = 1;
    $page = max(1, min($page, $total_pages));
    $start_index = ($page - 1) * $per_page;
    $displayed_items = array_slice($filtered_items, $start_index, $per_page);
    
    // Sayfa basina gösterim seçenegi
    echo "<div class='action-bar'>";
    echo "<div class='per-page-section'>";
    echo "<form method='get' action='" . htmlspecialchars($_SERVER["PHP_SELF"]) . "'>";
    echo "<label for='per_page'>Sayfa Ba&#351;&#305;na:</label>";
    echo "<select name='per_page' id='per_page' class='form-control' onchange='this.form.submit()'>";
    $per_page_options = [10, 25, 50, 100];
    foreach ($per_page_options as $option) {
        echo "<option value='$option'" . ($option == $per_page ? ' selected' : '') . ">$option</option>";
    }
    echo "</select>";
    echo "<input type='hidden' name='search' value='" . htmlspecialchars($search_ip) . "'>";
    echo "<input type='hidden' name='page' value='$page'>";
    echo "</form>";
    echo "</div>";
    echo "</div>";
    
    echo "<div class='table-responsive'>";
    echo "<table class='data-table'>";
    echo "<thead>";
    echo "<tr>
            <th>IP Adresi/Subnet</th>
            <th>Liste</th>
          </tr>";
    echo "</thead>";
    echo "<tbody>";
    
    if (count($displayed_items) == 0) {
        echo "<tr><td colspan='2' class='no-records'>Kay&#305;t bulunamad&#305;</td></tr>";
    } else {
        foreach ($displayed_items as $item) {
            if (!empty($item)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($item) . "</td>";
                echo "<td>Whitelist</td>";
                echo "</tr>";
            }
        }
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
    
    echo "<div class='record-info'>Toplam: $total_items kay&#305;t</div>";
    
    // Sayfalama
    if ($total_pages > 1) {
        echo "<div class='pagination'>";
        if ($page > 1) {
            echo "<a href='?page=" . ($page - 1) . "&per_page=$per_page&search=$search_ip' class='page-link'>&laquo; &Ouml;nceki</a>";
        }
        
        // Sayfa numaralarini göster
        $max_pages_to_show = 5;
        $start_page = max(1, min($page - floor($max_pages_to_show / 2), $total_pages - $max_pages_to_show + 1));
        $end_page = min($start_page + $max_pages_to_show - 1, $total_pages);
        
        if ($start_page > 1) {
            echo "<a href='?page=1&per_page=$per_page&search=$search_ip' class='page-link'>1</a>";
            if ($start_page > 2) {
                echo "<span class='page-ellipsis'>...</span>";
            }
        }
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            if ($i == $page) {
                echo "<span class='page-link current'>$i</span>";
            } else {
                echo "<a href='?page=$i&per_page=$per_page&search=$search_ip' class='page-link'>$i</a>";
            }
        }
        
        if ($end_page < $total_pages) {
            if ($end_page < $total_pages - 1) {
                echo "<span class='page-ellipsis'>...</span>";
            }
            echo "<a href='?page=$total_pages&per_page=$per_page&search=$search_ip' class='page-link'>$total_pages</a>";
        }
        
        if ($page < $total_pages) {
            echo "<a href='?page=" . ($page + 1) . "&per_page=$per_page&search=$search_ip' class='page-link'>Sonraki &raquo;</a>";
        }
        echo "</div>";
    }
}

// IP'yi CIDR formatinda dogrulama
function validate_cidr($cidr) {
    if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d+$/', $cidr)) {
        list($ip, $prefix) = explode('/', $cidr);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $prefix = (int)$prefix;
            // IPv4 için prefix 0-32 arasinda olmali
            if ($prefix >= 0 && $prefix <= 32) {
                return true;
            }
        }
    }
    return false;
}

// Bir IP'nin subnet içinde olup olmadigini kontrol eder
function ip_in_subnet($ip, $subnet) {
    // Eger subnet degilse direkt karsilastir
    if (strpos($subnet, '/') === false) {
        return $ip === $subnet;
    }
    
    // CIDR notasyonu için kontrol yapalim
    list($subnet_ip, $subnet_bits) = explode('/', $subnet);
    
    // IP adreslerini 32-bit tamsayilara dönüstür
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet_ip);
    
    if ($ip_long === false || $subnet_long === false) {
        return false; // Geçersiz IP adresi
    }
    
    // Subnet maskesini hesapla
    $mask = -1 << (32 - (int)$subnet_bits);
    
    // IP'nin subnet içinde olup olmadigini kontrol et
    return ($ip_long & $mask) === ($subnet_long & $mask);
}

// Kullanicidan arama terimini ve sayfa ayarlarini al
$search_ip = isset($_GET['search']) ? trim($_GET['search']) : '';
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
?>

<!DOCTYPE html>
<html lang="tr">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payten Whitelist Y&ouml;netim Aray&uuml;z&uuml;</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #005588;
            --primary-light: #2579b0;
            --secondary-color: #333333;
            --success-color: #28a745;
            --danger-color: #dc3545;
            --warning-color: #ffc107;
            --info-color: #17a2b8;
            --light-color: #f8f9fa;
            --dark-color: #343a40;
            --border-color: #dee2e6;
            --shadow-color: rgba(0, 0, 0, 0.1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 14px;
            line-height: 1.6;
            color: #333;
            background-color: #f5f5f5;
        }

        /* Header Styles */
        .header {
            background: linear-gradient(135deg, var(--primary-color), #003c6c);
            color: white;
            padding: 1rem;
            box-shadow: 0 2px 5px var(--shadow-color);
            position: relative;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            max-width: 1400px;
            margin: 0 auto;
        }

        .header-title {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        .logo {
            height: 40px;
        }

        /* Main Layout */
        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 0 15px;
        }

        /* Card Styles */
        .card {
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px var(--shadow-color);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .card-header {
            padding: 15px 20px;
            background-color: #f8f9fa;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin: 0;
        }

        .card-body {
            padding: 20px;
        }

        /* Form Styles */
        .form-control {
            width: 100%;
            padding: 8px 12px;
            font-size: 14px;
            line-height: 1.5;
            color: #495057;
            background-color: #fff;
            border: 1px solid #ced4da;
            border-radius: 4px;
            transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
        }

        .form-control:focus {
            border-color: var(--primary-light);
            outline: 0;
            box-shadow: 0 0 0 0.2rem rgba(0, 85, 136, 0.25);
        }

        /* Button Styles */
        .btn {
            display: inline-block;
            font-weight: 500;
            text-align: center;
            white-space: nowrap;
            vertical-align: middle;
            user-select: none;
            border: 1px solid transparent;
            padding: 8px 16px;
            font-size: 14px;
            line-height: 1.5;
            border-radius: 4px;
            transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-primary {
            color: #fff;
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .btn-primary:hover {
            background-color: var(--primary-light);
            border-color: var(--primary-light);
        }

        /* Table Styles */
        .table-responsive {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th, 
        .data-table td {
            padding: 12px 15px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }

        .data-table thead th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: var(--secondary-color);
            border-bottom: 2px solid var(--border-color);
            white-space: nowrap;
        }

        .data-table tbody tr:hover {
            background-color: rgba(0, 85, 136, 0.05);
        }

        .no-records {
            text-align: center;
            padding: 30px;
            color: #6c757d;
            font-style: italic;
        }

        /* Table Action Bar */
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .search-section {
            display: flex;
            gap: 10px;
            flex-grow: 1;
        }

        .search-section .form-control {
            max-width: 300px;
        }

        .per-page-section {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .per-page-section .form-control {
            width: auto;
        }

        /* Pagination */
        .pagination {
            display: flex;
            justify-content: center;
            padding: 20px 0;
            gap: 5px;
        }

        .page-link {
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            color: var(--primary-color);
            background-color: #fff;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .page-link:hover {
            background-color: #f8f9fa;
        }

        .page-link.current {
            background-color: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }

        .page-ellipsis {
            padding: 8px 12px;
            color: #6c757d;
        }

        /* Alert Styles */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
            position: relative;
        }

        .alert-info {
            color: #0c5460;
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }

        .alert .close {
            position: absolute;
            top: 15px;
            right: 15px;
            font-size: 1.25rem;
            font-weight: 700;
            line-height: 1;
            color: inherit;
            text-shadow: 0 1px 0 #fff;
            opacity: 0.5;
            background: none;
            border: none;
            cursor: pointer;
        }

        /* Record Info */
        .record-info {
            margin: 15px 0;
            color: #6c757d;
            font-style: italic;
        }

        /* Footer */
        .footer {
            padding: 15px;
            text-align: center;
            background-color: var(--dark-color);
            color: white;
            margin-top: 30px;
            font-size: 0.9rem;
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                text-align: center;
                gap: 10px;
            }
            
            .header-actions {
                justify-content: center;
            }
            
            .action-bar {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        /* Helper Classes */
        .d-flex {
            display: flex;
        }

        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
            color: var(--secondary-color);
        }
    </style>
</head>
<body>
    <header class="header">
        <div class="header-content">
            <h1 class="header-title">Payten Whitelist Y&ouml;netim Aray&uuml;z&uuml;</h1>
            <div class="header-actions">
                <a href="paytenblacklist.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> Blacklist Y&ouml;netim Aray&uuml;z&uuml;ne D&ouml;n
                </a>
            </div>
            <img src="/images/payten.png" alt="Payten Logo" class="logo">
        </div>
    </header>

    <?php display_message(); ?>

    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Beyaz Liste (Whitelist)</h2>
                <div class="search-section">
                    <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="d-flex">
                        <input type="text" name="search" class="form-control" placeholder="IP Adresi ara..." value="<?php echo htmlspecialchars($search_ip); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> Ara
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <?php display_whitelist($search_ip, $per_page, $page); ?>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; 2024 Payten. T&uuml;m haklar&#305; sakl&#305;d&#305;r.</p>
    </footer>
</body>
</html>