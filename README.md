# IP Blacklist Management System

PHP tabanlı, web arayüzlü IP blacklist yönetim sistemi. Manuel IP ekleme/silme, Excel ile toplu import, çoklu liste desteği ve gelişmiş filtreleme özellikleri sunar.

## 🚀 Özellikler

- **Manuel IP Yönetimi**: Tekil veya çoklu IP ekleme/silme
- **Excel Import**: Toplu IP ekleme için Excel desteği
- **CIDR Desteği**: Subnet blokları yönetimi
- **Çoklu Liste Desteği**: Manuel, CI-Badguys, Firehol listeleri
- **Gelişmiş Arama**: IP bazlı arama ve filtreleme
- **Sayfalama**: Büyük listeler için sayfa desteği
- **FQDN Desteği**: Domain adı yönetimi
- **Jira Entegrasyonu**: Ticket takibi için Jira link desteği
- **Whitelist Kontrolü**: Şirket IP'lerinin korunması

## 📋 Gereksinimler

- PHP 7.4 veya üzeri
- Composer
- Web Server (Apache/Nginx)
- PHP Extensions:
  - `php-zip` (Excel işlemleri için)
  - `php-xml` (Excel işlemleri için)
  - `php-gd` (opsiyonel)

## 🔧 Kurulum

### 1. Projeyi İndirin
```bash
git clone https://github.com/yourusername/blacklist-management.git
cd blacklist-management
```

### 2. Bağımlılıkları Yükleyin
```bash
composer install
```

### 3. Dizinleri Oluşturun
```bash
mkdir -p data/output
mkdir -p assets/images
chmod 755 data
chmod 755 data/output
```

### 4. Konfigürasyon
`config/config.php` dosyasını düzenleyin:
```php
return [
    'app' => [
        'name' => 'Your Company Blacklist Management',
        'company' => 'Your Company Name',
        // ... diğer ayarlar
    ],
    'company_blocks' => [
        // Kendi şirket IP bloklarınızı buraya ekleyin
        "192.168.1.0/24",
        "10.0.0.0/8",
    ],
    // ...
];
```

### 5. Veri Dosyalarını Hazırlayın
```bash
# Boş veri dosyaları oluşturun
touch data/blacklist.txt
touch data/ci-badguys.txt
touch data/firehol_level1.txt
touch data/whitelist.txt
```

## 📁 Dizin Yapısı

```
blacklist-management/
├── README.md
├── composer.json
├── .gitignore
├── index.php                 # Ana uygulama dosyası
├── delete.php               # Silme işlemleri
├── edit.php                 # Düzenleme sayfası
├── download_excel.php       # Excel template indirme
├── config/
│   └── config.php          # Ana konfigürasyon
├── assets/
│   ├── css/
│   │   └── styles.css      # Stil dosyaları
│   └── images/
│       └── logo.png        # Logo dosyası
├── data/
│   ├── blacklist.txt       # Manuel blacklist
│   ├── ci-badguys.txt     # CI-Badguys listesi
│   ├── firehol_level1.txt # Firehol listesi
│   ├── whitelist.txt      # Whitelist
│   └── output/
│       └── blacklist_output.txt # Çıktı dosyası
└── vendor/                 # Composer bağımlılıkları
```

## 🔐 Güvenlik Notları

### Önemli Güvenlik Ayarları:

1. **Private IP Koruması**: Sistem otomatik olarak private IP'lerin eklenmesini engeller
2. **Şirket IP Koruması**: `config.php`'de tanımlanan şirket IP'leri korunur
3. **Whitelist Desteği**: `data/whitelist.txt` dosyasındaki IP'ler korunur

### Güvenlik Tavsiyeleri:

- Gerçek ortamda `config/config.php` dosyasını web erişiminden koruyun
- Veri dosyalarını (`data/` klasörü) web erişiminden koruyun
- HTTPS kullanın
- Güçlü authentication ekleyin

## 📖 Kullanım

### Manuel IP Ekleme
1. Ana sayfada "Manuel Ekleme" bölümünü kullanın
2. IP adresi (CIDR formatı desteklenir): `192.168.1.1/32` veya `10.0.0.0/24`
3. Yorum, FQDN ve Jira bilgilerini ekleyin
4. "Ekle" butonuna tıklayın

### Excel ile Toplu Ekleme
1. "Excel Taslağını İndir" ile template'i indirin
2. Template'i doldurun (örnek veriler mevcuttur)
3. Dosyayı yükleyip "Yükle" butonuna tıklayın

### IP Arama ve Filtreleme
- Arama kutusunu kullanarak belirli IP'leri bulun
- Liste filtresi ile farklı kaynaklardan listeleri görüntüleyin
- Sayfa başına gösterilecek kayıt sayısını ayarlayın

### IP Düzenleme
- Tabloda "Düzenle" linkine tıklayın
- Yorum, FQDN ve Jira bilgilerini güncelleyin
- IP adresi değiştirilemez (güvenlik)

### IP Silme
- Tabloda istediğiniz IP'leri seçin
- "Sil" butonuna tıklayın
- Sadece manuel listedeki IP'ler silinebilir

## 🔄 Veri Formatları

### Blacklist Dosya Formatı
```
IP|Yorum|Tarih|FQDN|Jira
192.168.1.1/32|Şüpheli aktivite|2024-01-01 12:00:00|malware.com|TICKET-123
10.0.0.0/24|Spam kaynağı|2024-01-02 14:30:00||TICKET-124
```

### Excel Template Formatı
| IP Adresi | Yorum | FQDN | Jira Numarası/URL |
|-----------|-------|------|-------------------|
| 203.0.113.10/32 | Şüpheli aktivite | suspicious.com | TICKET-123 |

## 🤝 Katkıda Bulunma

1. Fork yapın
2. Feature branch oluşturun (`git checkout -b feature/yeni-ozellik`)
3. Commit yapın (`git commit -am 'Yeni özellik eklendi'`)
4. Push yapın (`git push origin feature/yeni-ozellik`)
5. Pull Request oluşturun

## 📝 Değişiklik Geçmişi

### v1.0.0
- İlk sürüm
- Manuel IP ekleme/silme
- Excel import
- Çoklu liste desteği
- Arama ve filtreleme

## 📄 Lisans

Bu proje MIT lisansı altında lisanslanmıştır. Detaylar için `LICENSE` dosyasına bakın.

## 🐛 Sorun Bildirimi

Sorunları [GitHub Issues](https://github.com/yourusername/blacklist-management/issues) üzerinden bildirebilirsiniz.

## 👥 İletişim

- **Proje Sahibi**: Your Name
- **E-posta**: your.email@company.com
- **GitHub**: [@yourusername](https://github.com/yourusername)

---

⚠️ **Önemli Not**: Bu sistem production ortamında kullanılmadan önce güvenlik testlerinden geçirilmelidir. Gerçek IP adreslerini GitHub'a yüklememeyiniz.
