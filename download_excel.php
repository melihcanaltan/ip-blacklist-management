<?php
require_once 'vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Yeni bir spreadsheet oluştur
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Başlıkları ayarla
$sheet->setCellValue('A1', 'IP Adresi');
$sheet->setCellValue('B1', 'Yorum');
$sheet->setCellValue('C1', 'FQDN');
$sheet->setCellValue('D1', 'Jira Numarası/URL');

// Örnek veriler ekle
$sheet->setCellValue('A2', '203.0.113.10/32');
$sheet->setCellValue('B2', 'Şüpheli aktivite');
$sheet->setCellValue('C2', 'suspicious.example.com');
$sheet->setCellValue('D2', 'TICKET-123');

$sheet->setCellValue('A3', '198.51.100.0/24');
$sheet->setCellValue('B3', 'Spam kaynağı');
$sheet->setCellValue('C3', '');
$sheet->setCellValue('D3', 'TICKET-124');

$sheet->setCellValue('A4', '');
$sheet->setCellValue('B4', 'Sadece domain engeli');
$sheet->setCellValue('C4', 'malware.example.org');
$sheet->setCellValue('D4', 'TICKET-125');

// Sütun genişliklerini ayarla
$sheet->getColumnDimension('A')->setWidth(20);
$sheet->getColumnDimension('B')->setWidth(25);
$sheet->getColumnDimension('C')->setWidth(25);
$sheet->getColumnDimension('D')->setWidth(20);

// Başlık satırını kalın yap
$sheet->getStyle('A1:D1')->getFont()->setBold(true);

// Dosya adını ayarla
$filename = 'blacklist_template_' . date('Y-m-d') . '.xlsx';

// HTTP başlıklarını ayarla
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Excel dosyasını çıktı olarak ver
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit();
?>