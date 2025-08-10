<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: text/html; charset=utf-8');

// Configuration - Edit these settings for your environment
$config = [
    // System Configuration
    'app_name' => 'IP Whitelist Management System',
    'company_name' => 'Generic Security Tools',
    'logo_path' => '/images/logo.png', // Change to your logo path
    'copyright_year' => date('Y'),
    
    // File Paths
    'whitelist_path' => __DIR__ . '/data/whitelist.txt', // Adjust path as needed
    'blacklist_manager_url' => 'index.php', // URL to blacklist manager
    
    // Display Settings
    'default_per_page' => 10,
    'max_per_page' => 100,
    'per_page_options' => [10, 25, 50, 100],
    
    // Theme Colors (CSS Custom Properties)
    'theme' => [
        'primary_color' => '#005588',
        'primary_light' => '#2579b0',
        'secondary_color' => '#333333',
        'success_color' => '#28a745',
        'danger_color' => '#dc3545',
        'warning_color' => '#ffc107',
        'info_color' => '#17a2b8',
        'light_color' => '#f8f9fa',
        'dark_color' => '#343a40'
    ]
];

// Language Configuration
$lang = [
    'app_title' => 'Whitelist Management Interface',
    'whitelist_title' => 'Whitelist (Allowed IPs)',
    'search_placeholder' => 'Search IP address...',
    'search_button' => 'Search',
    'back_to_blacklist' => 'Back to Blacklist Manager',
    'per_page_label' => 'Per Page:',
    'ip_address_column' => 'IP Address/Subnet',
    'list_type_column' => 'List Type',
    'list_type_whitelist' => 'Whitelist',
    'no_records' => 'No records found',
    'total_records' => 'Total: %d records',
    'previous_page' => '« Previous',
    'next_page' => 'Next »',
    'all_rights_reserved' => 'All rights reserved.'
];

// Initialize session message if not set
if (!isset($_SESSION['message'])) {
    $_SESSION['message'] = "";
}

/**
 * Display session messages and clear them
 */
function display_message() {
    if (isset($_SESSION['message']) && !empty($_SESSION['message'])) {
        echo "<div class='alert alert-info'>
                {$_SESSION['message']}
                <button type='button' class='close' onclick='this.parentElement.style.display=\"none\"'>&times;</button>
              </div>";
        unset($_SESSION['message']);
    }
}

/**
 * Display whitelist with pagination and search functionality
 */
function display_whitelist($config, $lang, $search_ip = '', $per_page = 10, $page = 1) {
    $whitelist_path = $config['whitelist_path'];
    
    // Read whitelist file
    $whitelist_items = [];
    if (file_exists($whitelist_path)) {
        $lines = file($whitelist_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comment lines and get lines that are in IP format
            if (substr(trim($line), 0, 1) !== '#' && (filter_var(explode('/', trim($line))[0], FILTER_VALIDATE_IP) || 
                (strpos(trim($line), '/') !== false && validate_cidr(trim($line))))) {
                $whitelist_items[] = trim($line);
            }
        }
    }
    
    // Filter if search is performed
    if ($search_ip) {
        $filtered_items = [];
        foreach ($whitelist_items as $item) {
            // Direct match or subnet check
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
    
    // Per page selection
    echo "<div class='action-bar'>";
    echo "<div class='per-page-section'>";
    echo "<form method='get' action='" . htmlspecialchars($_SERVER["PHP_SELF"]) . "'>";
    echo "<label for='per_page'>" . $lang['per_page_label'] . "</label>";
    echo "<select name='per_page' id='per_page' class='form-control' onchange='this.form.submit()'>";
    foreach ($config['per_page_options'] as $option) {
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
            <th>" . $lang['ip_address_column'] . "</th>
            <th>" . $lang['list_type_column'] . "</th>
          </tr>";
    echo "</thead>";
    echo "<tbody>";
    
    if (count($displayed_items) == 0) {
        echo "<tr><td colspan='2' class='no-records'>" . $lang['no_records'] . "</td></tr>";
    } else {
        foreach ($displayed_items as $item) {
            if (!empty($item)) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($item) . "</td>";
                echo "<td>" . $lang['list_type_whitelist'] . "</td>";
                echo "</tr>";
            }
        }
    }
    
    echo "</tbody>";
    echo "</table>";
    echo "</div>";
    
    echo "<div class='record-info'>" . sprintf($lang['total_records'], $total_items) . "</div>";
    
    // Pagination
    if ($total_pages > 1) {
        echo "<div class='pagination'>";
        if ($page > 1) {
            echo "<a href='?page=" . ($page - 1) . "&per_page=$per_page&search=$search_ip' class='page-link'>" . $lang['previous_page'] . "</a>";
        }
        
        // Show page numbers
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
            echo "<a href='?page=" . ($page + 1) . "&per_page=$per_page&search=$search_ip' class='page-link'>" . $lang['next_page'] . "</a>";
        }
        echo "</div>";
    }
}

/**
 * Validate CIDR format IP addresses
 */
function validate_cidr($cidr) {
    if (preg_match('/^(\d{1,3}\.){3}\d{1,3}\/\d+$/', $cidr)) {
        list($ip, $prefix) = explode('/', $cidr);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $prefix = (int)$prefix;
            // For IPv4, prefix should be between 0-32
            if ($prefix >= 0 && $prefix <= 32) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Check if an IP is within a subnet
 */
function ip_in_subnet($ip, $subnet) {
    // If not a subnet, compare directly
    if (strpos($subnet, '/') === false) {
        return $ip === $subnet;
    }
    
    // Check for CIDR notation
    list($subnet_ip, $subnet_bits) = explode('/', $subnet);
    
    // Convert IP addresses to 32-bit integers
    $ip_long = ip2long($ip);
    $subnet_long = ip2long($subnet_ip);
    
    if ($ip_long === false || $subnet_long === false) {
        return false; // Invalid IP address
    }
    
    // Calculate subnet mask
    $mask = -1 << (32 - (int)$subnet_bits);
    
    // Check if IP is within subnet
    return ($ip_long & $mask) === ($subnet_long & $mask);
}

/**
 * Generate CSS custom properties from theme configuration
 */
function generate_css_variables($theme) {
    $css = ":root {\n";
    foreach ($theme as $key => $value) {
        $css_var_name = '--' . str_replace('_', '-', $key);
        $css .= "    $css_var_name: $value;\n";
    }
    $css .= "}";
    return $css;
}

// Get user input for search terms and page settings
$search_ip = isset($_GET['search']) ? trim($_GET['search']) : '';
$per_page = isset($_GET['per_page']) ? min((int)$_GET['per_page'], $config['max_per_page']) : $config['default_per_page'];
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($config['app_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        <?php echo generate_css_variables($config['theme']); ?>
        
        --shadow-color: rgba(0, 0, 0, 0.1);
        --border-color: #dee2e6;

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
            max-width: 150px;
            object-fit: contain;
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
            background-color: var(--light-color);
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
            color: #fff;
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
            background-color: var(--light-color);
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
            background-color: var(--light-color);
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
            <h1 class="header-title"><?php echo htmlspecialchars($config['app_name']); ?></h1>
            <div class="header-actions">
                <a href="<?php echo htmlspecialchars($config['blacklist_manager_url']); ?>" class="btn btn-primary">
                    <i class="fas fa-arrow-left"></i> <?php echo htmlspecialchars($lang['back_to_blacklist']); ?>
                </a>
            </div>
            <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . $config['logo_path'])): ?>
                <img src="<?php echo htmlspecialchars($config['logo_path']); ?>" alt="<?php echo htmlspecialchars($config['company_name']); ?> Logo" class="logo">
            <?php endif; ?>
        </div>
    </header>

    <?php display_message(); ?>

    <div class="container">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><?php echo htmlspecialchars($lang['whitelist_title']); ?></h2>
                <div class="search-section">
                    <form method="get" action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" class="d-flex">
                        <input type="text" name="search" class="form-control" placeholder="<?php echo htmlspecialchars($lang['search_placeholder']); ?>" value="<?php echo htmlspecialchars($search_ip); ?>">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-search"></i> <?php echo htmlspecialchars($lang['search_button']); ?>
                        </button>
                    </form>
                </div>
            </div>
            <div class="card-body">
                <?php display_whitelist($config, $lang, $search_ip, $per_page, $page); ?>
            </div>
        </div>
    </div>

    <footer class="footer">
        <p>&copy; <?php echo $config['copyright_year'] . ' ' . htmlspecialchars($config['company_name']); ?>. <?php echo htmlspecialchars($lang['all_rights_reserved']); ?></p>
    </footer>
</body>
</html>
