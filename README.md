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
- **Otomatik Liste Yönetimi**: ip_blacklist_manager.sh ile otomatik güvenlik listesi toplama

## 📋 Gereksinimler

### PHP Gereksinimleri
- PHP 7.4 veya üzeri
- Composer
- Web Server (Apache/Nginx)

### PHP Extensions
- php-zip (Excel işlemleri için)
- php-xml (Excel işlemleri için)
- php-gd (opsiyonel)

### Otomatik Liste Yönetimi için
- curl (HTTP/HTTPS indirme)
- bash 4.0+ (ip_blacklist_manager.sh için)
- sendmail (opsiyonel, e-posta bildirimleri için)

## 🔧 Kurulum

### 1. Projeyi İndirin

```bash
# 1. Repository clone et
git clone https://github.com/melihcanaltan/ip-blacklist-management.git
cd ip-blacklist-management

# 3. İzinleri ayarla
sudo chown -R www-data:www-data /path/to/ip-blacklist-management
sudo chmod -R 755 /path/to/ip-blacklist-management
```

### 2. Bağımlılıkları Yükleyin

```bash
composer install
```

### ⚙️ Otomatik Kurulum (Apache + Port + Sendmail)

Projeyle birlikte gelen setup.sh betiği ile aşağıdaki işlemler otomatik yapılabilir:

- Apache kurulumu
- İstenilen portta yayına alma
- Sendmail kurulumu (PHP mail desteği için)
- VirtualHost yapılandırması

#### Kullanım

```bash
chmod +x setup.sh
./setup.sh
```

#### Kurulum Süreci

Kurulum sırasında size aşağıdaki sorular sorulacaktır:

- Apache kurulumu yapılmasını ister misiniz?
- Hangi port kullanılacak?
- Sendmail kurulumu yapılacak mı?

#### Erişim

Kurulum tamamlandıktan sonra aşağıdaki adres üzerinden projenize erişebilirsiniz:

```
http://localhost:PORT
```

**Örnek:** 8080 portu seçildiğinde:
```
http://localhost:8080
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

## 🤖 Otomatik Güvenlik Listesi Yönetimi (ip_blacklist_manager.sh)

Sistem, birden fazla güvenlik kaynağından tehdit verilerini otomatik olarak toplar, filtreler ve merkezi bir blacklist oluşturan bash script'i ile birlikte gelir.

### Script Özellikleri

- **Çoklu Kaynak Desteği**: CINSScore, FireHOL, ThreatStop ve özel listeler
- **Akıllı IP Filtreleme**: RFC 1918 özel ağlar, geçersiz IP'ler ve sistem aralıklarını otomatik filtreler
- **Whitelist Çakışma Kontrolü**: Güvenli IP'lerin yanlışlıkla engellenmesini önler
- **Otomatik E-posta Uyarıları**: Kritik çakışmalarda anlık bildirim
- **SSH Tabanlı Whitelist Senkronizasyonu**: Uzak sunuculardan güvenli liste indirme

### Script Konfigürasyonu

`ip_blacklist_manager.sh` dosyasını düzenleyin:

```bash
# Çalışma dizini
BASE_DIR="/opt/blacklist"  # Web uygulamasının data klasörü ile senkron olsun

# Mail bildirimleri
MAIL_TO="security@yourcompany.com"
MAIL_FROM="Blacklist-Manager"

# Whitelist ayarları (opsiyonel)
WHITELIST_HOST="your-server.com"
WHITELIST_USER="admin"
WHITELIST_REMOTE_PATH="/path/to/whitelist.txt"
```

### Otomatik Çalıştırma

```bash
# Script'i çalıştırılabilir yapın
chmod +x ip_blacklist_manager.sh

# Günlük otomatik çalıştırma için cron job ekleyin
crontab -e

# Her gün saat 02:00'da çalıştır
0 2 * * * /path/to/ip_blacklist_manager.sh >/dev/null 2>&1
```

### Script Çıktıları

Script aşağıdaki dosyaları oluşturur:

```
/opt/blacklist/
├── combined_blacklist.txt    # 🎯 ANA ÇIKTI: Birleştirilmiş blacklist
├── ci-badguys.txt           # CINSScore tehdit listesi
├── firehol_level1.txt       # FireHOL Level 1 listesi
├── threatstop.txt           # ThreatStop özel listesi
├── whitelist.txt            # Güvenli IP listesi
├── conflict_log.txt         # Çakışma raporları
└── ip_blocklist.log        # İşlem logları
```

Bu dosyalar web arayüzü tarafından otomatik olarak okunur ve görüntülenir.

## 📁 Dizin Yapısı

```
blacklist-management/
├── README.md
├── composer.json
├── .gitignore
├── ip_blacklist_manager.sh   # Otomatik liste yönetim script'i
├── setup.sh                 # Otomatik kurulum script'i
├── index.php                # Ana uygulama dosyası
├── delete.php              # Silme işlemleri
├── edit.php                # Düzenleme sayfası
├── download_excel.php      # Excel template indirme
├──upload_excel.php          # Excel yükleme
├── whitelist.php             # Whitelist ekleme
├── config/
│   └── config.php         # Ana konfigürasyon
├── assets/
│   ├── css/
│   │   └── styles.css     # Stil dosyaları
│   └── images/
│       └── logo.png       # Logo dosyası
├── data/
│   ├── blacklist.txt      # Manuel blacklist
│   ├── ci-badguys.txt    # CI-Badguys listesi (otomatik)
│   ├── firehol_level1.txt # Firehol listesi (otomatik)
│   ├── whitelist.txt     # Whitelist
│   ├── conflict_log.txt  # Çakışma raporları (otomatik)
│   ├── ip_blocklist.log  # İşlem logları (otomatik)
│   └── output/
│       └── combined_blacklist.txt # Birleşik çıktı (otomatik)
└── vendor/               # Composer bağımlılıkları
```

## 🔐 Güvenlik Notları

### Önemli Güvenlik Ayarları

- **Private IP Koruması**: Sistem otomatik olarak private IP'lerin eklenmesini engeller
- **Şirket IP Koruması**: config.php'de tanımlanan şirket IP'leri korunur
- **Whitelist Desteği**: data/whitelist.txt dosyasındaki IP'ler korunur
- **Otomatik Çakışma Kontrolü**: ip_blacklist_manager.sh çakışmaları tespit eder ve bildirim gönderir

### Güvenlik Tavsiyeleri

- Gerçek ortamda config/config.php dosyasını web erişiminden koruyun
- Veri dosyalarını (data/ klasörü) web erişiminden koruyun
- HTTPS kullanın
- Güçlü authentication ekleyin
- ip_blacklist_manager.sh için SSH key-based authentication kullanın

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

1. Tabloda "Düzenle" linkine tıklayın
2. Yorum, FQDN ve Jira bilgilerini güncelleyin
3. IP adresi değiştirilemez (güvenlik)

### IP Silme

1. Tabloda istediğiniz IP'leri seçin
2. "Sil" butonuna tıklayın
3. Sadece manuel listedeki IP'ler silinebilir

### Otomatik Liste Yönetimi

- ip_blacklist_manager.sh script'i cron job ile otomatik çalışır
- Web arayüzünde otomatik listeler görüntülenir (ci-badguys, firehol)
- Çakışma raporları ve loglar web arayüzünden erişilebilir

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
- ip_blacklist_manager.sh otomatik script entegrasyonu
