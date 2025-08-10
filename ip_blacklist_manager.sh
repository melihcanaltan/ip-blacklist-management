#!/bin/bash

#################################################################
# IP BLACKLIST YÖNETİM SİSTEMİ
# 
# Bu script çeşitli güvenlik listelerini indirir, filtreler,
# birleştirir ve whitelist ile çakışmaları kontrol eder.
#
# Versiyon: 2.1 (Fixed for local directory usage)
# Geliştirici: Generic Security Tools
# Güncelleme: 2024
#################################################################

#################################################################
# YAPILANDIRMA AYARLARI
# 
# ⚠️  Bu bölümü kendinize göre düzenleyin!
# 
# Nasıl Özelleştirirsiniz:
# 1. Dosya yollarını değiştirin
# 2. URL'leri ekleyin/çıkarın
# 3. Mail ayarlarını yapın
# 4. Whitelist konfigürasyonunu ayarlayın
#################################################################

# =============================================================
# DOSYA YOLLARI VE DİZİN AYARLARI
# =============================================================
# Script'in bulunduğu dizini otomatik tespit et
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Data dizinini script'in yanında oluştur
BASE_DIR="$SCRIPT_DIR/data"

# Bireysel blacklist dosyaları - Her kaynak için ayrı dosya
CINSSCORE_FILE="$BASE_DIR/ci-badguys.txt"          # CINSScore kötü IP'ler
FIREHOL_FILE="$BASE_DIR/firehol_level1.txt"        # FireHOL Level 1 tehditler
THREATSTOP_FILE="$BASE_DIR/threatstop.txt"         # ThreatStop listesi
WHITELIST_FILE="$BASE_DIR/whitelist.txt"           # Beyaz liste (güvenli IP'ler)

# Sistem dosyaları
CONFLICT_LOG="$BASE_DIR/conflict_log.txt"          # Çakışma raporları
COMBINED_FILE="$BASE_DIR/output/combined_blacklist.txt"   # ⭐ ANA ÇIKTI: Tüm listelerin birleşimi
LOG_FILE="$BASE_DIR/ip_blocklist.log"              # İşlem logları

# Geçici dosyalar - Script çalışırken kullanılır
TEMP_DIR="/tmp/blacklist_$$"                       # Her çalıştırmada unique
CINSSCORE_TEMP="$TEMP_DIR/ci-badguys.temp"
FIREHOL_TEMP="$TEMP_DIR/firehol_level1.temp"
THREATSTOP_TEMP="$TEMP_DIR/threatstop.temp"

# =============================================================
# GÜVENLİK LİSTESİ URL'LERİ
# 
# 🔧 NASIL EKLERSİNİZ:
# 1. Yeni URL değişkeni tanımlayın: NEW_LIST_URL="http://..."
# 2. Yeni dosya yolu tanımlayın: NEW_LIST_FILE="$BASE_DIR/new_list.txt"
# 3. download_file fonksiyonuna çağrı ekleyin
# 4. filter_ips fonksiyonuna çağrı ekleyin
# 5. Birleştirme işleminde dahil edin
# 
# 🚫 NASIL ÇIKARIRSINIZ:
# URL'yi boş string yapın: CINSSCORE_URL=""
# =============================================================

# Aktif güvenlik listeleri
CINSSCORE_URL="https://cinsscore.com/list/ci-badguys.txt"
FIREHOL_URL="https://raw.githubusercontent.com/firehol/blocklist-ipsets/master/firehol_level1.netset"

# Kendi listeleriniz - Boş bırakılan listeler atlanır
THREATSTOP_URL=""                                   # Kendi internal URL'nizi ekleyin

# 📋 YENİ LİSTE EKLEMEK İÇİN ÖRNEK:
# SPAMHAUS_URL="https://www.spamhaus.org/drop/drop.txt"
# EMERGING_THREATS_URL="https://rules.emergingthreats.net/fwrules/emerging-Block-IPs.txt"

# =============================================================
# WHİTELİST AYARLARI (Opsiyonel)
# 
# 🔧 YAPILANDIRMA:
# Bu ayarlar uzak sunucudan whitelist indirmek için kullanılır.
# SSH key-based authentication önerilir (güvenli)
# 
# ⚠️  GÜVENLİK UYARISI:
# Şifre kullanmayın! SSH key kullanın veya environment variable:
# export WHITELIST_PASS="your_password"
# =============================================================

WHITELIST_HOST=""                    # Sunucu IP/hostname - örnek: "192.168.1.100"
WHITELIST_USER=""                    # SSH kullanıcı adı - örnek: "admin"
WHITELIST_REMOTE_PATH=""             # Uzak dosya yolu - örnek: "/home/admin/whitelist.txt"

# Şifre için environment variable kullanın (WHITELIST_PASS)
# Script şifreleri hardcode etmez - güvenlik için!

# =============================================================
# E-POSTA BİLDİRİM AYARLARI
# 
# 🔧 MAİL YAPILANDIRMASI:
# Çakışma tespit edildiğinde otomatik mail gönderir
# sendmail servisinizin yapılandırılmış olması gerekir
# =============================================================

MAIL_TO=""                          # Alıcı e-posta - örnek: "security@company.com"
MAIL_FROM="Blacklist-Manager"       # Gönderen adı
MAIL_SUBJECT="ALERT: IP List Conflict Detected"  # Mail konusu

#################################################################
# SİSTEM FONKSİYONLARI
# 
# ⚠️  Bu bölümü değiştirmeyiniz!
# Bu fonksiyonlar script'in çalışması için gereklidir.
#################################################################

# =============================================================
# HATA YÖNETİMİ VE TEMİZLİK
# =============================================================

# Script hata durumunda veya kesintiye uğradığında temizlik yapar
cleanup() {
    echo "Cleaning up temporary files..."
    rm -rf "$TEMP_DIR"
    exit 1
}

# Sinyal yakalama - CTRL+C, TERM, ERR
trap cleanup ERR INT TERM

# =============================================================
# LOG VE MESAJ FONKSİYONLARI
# =============================================================

# Zaman damgalı log mesajı yazar
log_message() {
    # Log dizinini oluştur (eğer yoksa)
    mkdir -p "$(dirname "$LOG_FILE")"
    echo "$(date '+%Y-%m-%d %H:%M:%S') - $1" >> "$LOG_FILE"
    echo "$1"  # Aynı zamanda ekrana da yaz
}

# Hata mesajı ve çıkış
error_exit() {
    log_message "ERROR: $1"
    cleanup
}

# =============================================================
# IP FİLTRELEME FONKSİYONU
# 
# 🔧 NASIL ÇALIŞIR:
# Bu fonksiyon indirilen IP listelerinden istenmeyen IP'leri temizler:
# - Yorum satırları (#)
# - Boş satırlar
# - Özel ağ aralıkları (RFC 1918)
# - Geçersiz IP'ler
# 
# 🔧 FİLTRE KURALLARI EKLEME/ÇIKARMA:
# grep -v "^pattern" satırları ekleyin/çıkarın
# =============================================================

filter_ips() {
    local input_file="$1"
    local output_file="$2"
    
    if [ ! -f "$input_file" ]; then
        log_message "WARNING: Input file $input_file not found, creating empty output"
        touch "$output_file"
        return 1
    fi
    
    log_message "Filtering IPs from $(basename $input_file)..."
    
    # IP filtreleme kuralları - düzeltilmiş versiyon
    cat "$input_file" | \
        grep -v "^#" | \
        grep -v "^$" | \
        grep -v "^10\." | \
        grep -v "^172\.\(1[6-9]\|2[0-9]\|3[0-1]\)\." | \
        grep -v "^192\.168\." | \
        grep -v "^0\." | \
        grep -v "0\.0\.0\.0" | \
        grep -v "^127\." | \
        grep -v "^169\.254\." | \
        grep -v "^224\." | \
        grep -v "^240\." | \
        grep -v "^255\." > "$output_file"
    
    # 🔧 YENİ FİLTRE KURALI EKLEMEK İÇİN:
    # grep -v "^YOUR_PATTERN" | \ satırını ekleyin
    
    local filtered_count=$(cat "$output_file" | wc -l)
    log_message "Filtered $(basename $input_file): $filtered_count IPs remain"
}

# =============================================================
# DOSYA İNDİRME FONKSİYONU
# 
# 🔧 NASIL ÇALIŞIR:
# URL'den dosya indirir, hata kontrolü yapar, retry mechanism içerir
# =============================================================

download_file() {
    local url="$1"
    local output_file="$2"
    local description="$3"
    
    # URL boşsa atla
    if [ -z "$url" ]; then
        log_message "INFO: $description URL not configured, skipping"
        touch "$output_file"  # Boş dosya oluştur
        return 0
    fi
    
    log_message "Downloading: $description from $url"
    
    # curl ile indirme - timeout ve retry ile
    if curl -s --max-time 60 --retry 3 --retry-delay 5 "$url" -o "$output_file"; then
        if [ -s "$output_file" ]; then
            log_message "SUCCESS: $description downloaded successfully"
            return 0
        else
            log_message "WARNING: $description downloaded but file is empty"
            return 1
        fi
    else
        log_message "ERROR: Failed to download $description"
        touch "$output_file"  # Boş dosya oluştur
        return 1
    fi
}

# =============================================================
# WHİTELİST İNDİRME FONKSİYONU
# 
# 🔧 NASIL ÇALIŞIR:
# Uzak sunucudan SSH ile whitelist dosyası indirir
# SSH key authentication veya environment variable ile şifre
# =============================================================

download_whitelist() {
    # Gerekli parametreler kontrol et
    if [ -z "$WHITELIST_HOST" ] || [ -z "$WHITELIST_USER" ] || [ -z "$WHITELIST_REMOTE_PATH" ]; then
        log_message "INFO: Whitelist settings not configured, creating empty file"
        echo "# No whitelist configured" > "$WHITELIST_FILE"
        return 0
    fi
    
    log_message "Downloading whitelist from $WHITELIST_HOST..."
    
    # Environment variable'dan şifre kontrol et
    if [ -n "$WHITELIST_PASS" ]; then
        # ⚠️ Şifre kullanımı güvenli değil, key-based auth önerilir
        log_message "Using password authentication (not recommended)"
        sshpass -p "$WHITELIST_PASS" scp -o StrictHostKeyChecking=no -o ConnectTimeout=30 \
            "$WHITELIST_USER@$WHITELIST_HOST:$WHITELIST_REMOTE_PATH" "$WHITELIST_FILE"
    else
        # SSH key-based authentication (güvenli yöntem)
        log_message "Using key-based authentication"
        scp -o StrictHostKeyChecking=no -o ConnectTimeout=30 \
            "$WHITELIST_USER@$WHITELIST_HOST:$WHITELIST_REMOTE_PATH" "$WHITELIST_FILE"
    fi
    
    # İndirme sonucunu kontrol et
    if [ $? -eq 0 ] && [ -s "$WHITELIST_FILE" ]; then
        log_message "SUCCESS: Whitelist downloaded successfully"
        return 0
    else
        log_message "WARNING: Whitelist could not be downloaded, creating empty file"
        echo "# Whitelist download failed at $(date)" > "$WHITELIST_FILE"
        return 1
    fi
}

# =============================================================
# ÇAKIŞMA KONTROL FONKSİYONU
# 
# 🔧 NASIL ÇALIŞIR:
# Whitelist'teki IP'lerin blacklistlerde olup olmadığını kontrol eder
# Çakışma varsa detaylı rapor oluşturur ve mail gönderir
# =============================================================

check_conflicts() {
    local timestamp=$(date '+%Y-%m-%d_%H:%M:%S')
    local conflict_temp="/tmp/conflicts_$timestamp.txt"
    local conflict_found=false
    
    log_message "Starting conflict check..."
    
    # Rapor başlığı oluştur
    echo "IP List Conflict Report - $timestamp" > "$conflict_temp"
    echo "================================================" >> "$conflict_temp"
    echo "" >> "$conflict_temp"
    
    # Whitelist dosyası kontrol et
    if [ ! -f "$WHITELIST_FILE" ] || [ ! -s "$WHITELIST_FILE" ]; then
        echo "Whitelist file not found or empty, skipping conflict check." >> "$conflict_temp"
        log_message "INFO: No whitelist available for conflict checking"
        cat "$conflict_temp" >> "$CONFLICT_LOG"
        rm "$conflict_temp"
        return 1
    fi
    
    log_message "Checking whitelist against blacklists..."
    
    # Whitelist'teki her satırı kontrol et
    local line_count=0
    local total_lines=$(grep -v "^#" "$WHITELIST_FILE" | grep -v "^$" | wc -l)
    
    while IFS= read -r whitelist_ip; do
        # Boş satırları ve yorum satırlarını atla
        if [ -z "$whitelist_ip" ] || [[ "$whitelist_ip" == \#* ]]; then
            continue
        fi
        
        line_count=$((line_count + 1))
        
        # IP'yi temizle (CIDR notasyonu ve açıklamalar için)
        clean_ip=$(echo "$whitelist_ip" | awk '{print $1}' | cut -d'/' -f1)
        
        # IP format kontrol et (basit kontrol)
        if [[ ! $clean_ip =~ ^[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}$ ]]; then
            continue  # Geçersiz IP formatı
        fi
        
        # Her blacklist dosyasında bu IP'yi ara
        for list_info in "Cinsscore:$CINSSCORE_FILE" "Threatstop:$THREATSTOP_FILE" "Firehol:$FIREHOL_FILE"; do
            list_name=$(echo $list_info | cut -d':' -f1)
            list_file=$(echo $list_info | cut -d':' -f2)
            
            # Dosya varsa ve IP bulunursa çakışma kaydet
            if [ -f "$list_file" ] && grep -q "^$clean_ip$" "$list_file"; then
                echo "⚠️  CONFLICT: $clean_ip found in both WHITELIST and $list_name blacklist!" >> "$conflict_temp"
                log_message "CONFLICT DETECTED: $clean_ip in $list_name"
                conflict_found=true
            fi
        done
        
        # İlerleme göstergesi (her 100 IP'de bir)
        if [ $((line_count % 100)) -eq 0 ]; then
            log_message "Progress: $line_count/$total_lines IPs checked"
        fi
        
    done < <(grep -v "^#" "$WHITELIST_FILE" | grep -v "^$")
    
    # Sonuç raporunu tamamla
    echo "" >> "$conflict_temp"
    if [ "$conflict_found" = true ]; then
        echo "🚨 SUMMARY: Conflicts detected! These IPs are in both whitelist and blacklist." >> "$conflict_temp"
        echo "   This may pose a security risk and should be reviewed immediately." >> "$conflict_temp"
        echo "" >> "$conflict_temp"
        echo "📋 RECOMMENDED ACTIONS:" >> "$conflict_temp"
        echo "   1. Review conflicting IPs for legitimacy" >> "$conflict_temp"
        echo "   2. Remove from whitelist if malicious" >> "$conflict_temp"
        echo "   3. Add to permanent whitelist if legitimate" >> "$conflict_temp"
    else
        echo "✅ SUMMARY: No conflicts detected between whitelist and blacklists." >> "$conflict_temp"
        echo "   All whitelist IPs are clean." >> "$conflict_temp"
    fi
    
    echo "" >> "$conflict_temp"
    echo "📊 STATISTICS:" >> "$conflict_temp"
    echo "   - Whitelist IPs checked: $total_lines" >> "$conflict_temp"
    echo "   - Conflicts found: $([ "$conflict_found" = true ] && echo "YES" || echo "NO")" >> "$conflict_temp"
    echo "   - Report generated: $timestamp" >> "$conflict_temp"
    echo "" >> "$conflict_temp"
    echo "This report was generated automatically by IP Blacklist Management System." >> "$conflict_temp"
    
    # Conflict log'a ekle
    cat "$conflict_temp" >> "$CONFLICT_LOG"
    
    # Mail gönder
    if [ "$conflict_found" = true ] && [ -n "$MAIL_TO" ]; then
        send_alert_email "$conflict_temp"
    fi
    
    # Cleanup
    rm "$conflict_temp"
    
    log_message "Conflict check completed. Conflicts found: $([ "$conflict_found" = true ] && echo "YES" || echo "NO")"
    return $([ "$conflict_found" = true ] && echo 0 || echo 1)
}

# =============================================================
# E-POSTA BİLDİRİM FONKSİYONU
# =============================================================

send_alert_email() {
    local conflict_file="$1"
    
    if [ -z "$MAIL_TO" ]; then
        log_message "WARNING: No email configured, skipping notification"
        return 1
    fi
    
    log_message "Sending alert email to $MAIL_TO..."
    
    # Mail oluştur ve gönder
    {
        echo "To: $MAIL_TO"
        echo "From: $MAIL_FROM"
        echo "Subject: $MAIL_SUBJECT"
        echo "Content-Type: text/plain; charset=UTF-8"
        echo ""
        echo "Dear Security Team,"
        echo ""
        cat "$conflict_file"
        echo ""
        echo "Best regards,"
        echo "Network Security Monitoring System"
        echo ""
        echo "---"
        echo "This is an automated message from IP Blacklist Management System"
        echo "Server: $(hostname)"
        echo "Time: $(date)"
    } | sendmail -t
    
    if [ $? -eq 0 ]; then
        log_message "SUCCESS: Alert email sent successfully"
    else
        log_message "ERROR: Failed to send alert email"
    fi
}

# =============================================================
# İSTATİSTİK VE RAPOR FONKSİYONU
# =============================================================

log_statistics() {
    log_message "Generating statistics..."
    
    # Dosya boyutlarını hesapla
    local cinsscore_count=$([ -f "$CINSSCORE_FILE" ] && cat "$CINSSCORE_FILE" | wc -l || echo 0)
    local firehol_count=$([ -f "$FIREHOL_FILE" ] && cat "$FIREHOL_FILE" | wc -l || echo 0)
    local threatstop_count=$([ -f "$THREATSTOP_FILE" ] && cat "$THREATSTOP_FILE" | wc -l || echo 0)
    local whitelist_count=$([ -f "$WHITELIST_FILE" ] && grep -v "^#" "$WHITELIST_FILE" | grep -v "^$" | wc -l || echo 0)
    local combined_count=$([ -f "$COMBINED_FILE" ] && cat "$COMBINED_FILE" | wc -l || echo 0)
    
    # Detaylı istatistik logu
    log_message "=== PROCESSING STATISTICS ==="
    log_message "Cinsscore IPs: $cinsscore_count"
    log_message "Firehol IPs: $firehol_count" 
    log_message "Threatstop IPs: $threatstop_count"
    log_message "Whitelist IPs: $whitelist_count"
    log_message "Combined Blacklist IPs: $combined_count"
    log_message "============================="
    
    # Dosya boyutları
    if [ -f "$COMBINED_FILE" ]; then
        local file_size=$(du -h "$COMBINED_FILE" | cut -f1)
        log_message "Combined blacklist file size: $file_size"
    fi
}

#################################################################
# ANA PROGRAM
# 
# 🔧 İŞLEM AKIŞI:
# 1. Sistem kontrolleri
# 2. Dizin oluşturma  
# 3. Güvenlik listelerini indirme
# 4. Whitelist'i indirme
# 5. IP filtreleme
# 6. Listeleri birleştirme
# 7. Çakışma kontrolü
# 8. İstatistik ve cleanup
#################################################################

main() {
    echo ""
    echo "🚀 IP BLACKLIST MANAGEMENT SYSTEM v2.1"
    echo "========================================"
    echo ""
    
    log_message "=== IP Blacklist Management System Started ==="
    
    # Sistem kontrolleri
    log_message "Performing system checks..."
    
    # Gerekli komutları kontrol et
    for cmd in curl grep sort uniq; do
        if ! command -v $cmd >/dev/null 2>&1; then
            error_exit "Required command not found: $cmd"
        fi
    done
    
    # Gerekli dizinleri oluştur
    log_message "Creating directories..."
    mkdir -p "$BASE_DIR" "$TEMP_DIR" "$(dirname "$COMBINED_FILE")" || error_exit "Failed to create directories"
    
    # İzinleri kontrol et
    if [ ! -w "$BASE_DIR" ]; then
        error_exit "No write permission to $BASE_DIR"
    fi
    
    echo "📁 Working directory: $BASE_DIR"
    echo ""
    
    # =============================================================
    # 1. GÜVENLİK LİSTELERİNİ İNDİR
    # =============================================================
    
    echo "📥 Downloading security lists..."
    echo "--------------------------------"
    
    # Her liste için indirme işlemi
    download_file "$CINSSCORE_URL" "$CINSSCORE_TEMP" "CINSScore Bad Guys List"
    download_file "$FIREHOL_URL" "$FIREHOL_TEMP" "FireHOL Level 1 List"
    download_file "$THREATSTOP_URL" "$THREATSTOP_TEMP" "ThreatStop List"
    
    # 🔧 YENİ LİSTE EKLEMEK İÇİN BURAYA EKLEYİN:
    # download_file "$YOUR_NEW_URL" "$YOUR_TEMP_FILE" "Your New List Description"
    
    echo ""
    
    # =============================================================
    # 2. WHİTELİST'İ İNDİR
    # =============================================================
    
    echo "📥 Downloading whitelist..."
    echo "--------------------------"
    download_whitelist
    echo ""
    
    # =============================================================
    # 3. IP'LERİ FİLTRELE
    # =============================================================
    
    echo "🔍 Filtering IP addresses..."
    echo "-----------------------------"
    
    # Her dosyayı filtrele
    filter_ips "$CINSSCORE_TEMP" "$CINSSCORE_FILE"
    filter_ips "$FIREHOL_TEMP" "$FIREHOL_FILE" 
    filter_ips "$THREATSTOP_TEMP" "$THREATSTOP_FILE"
    
    # 🔧 YENİ LİSTE EKLEMEK İÇİN BURAYA EKLEYİN:
    # filter_ips "$YOUR_TEMP_FILE" "$YOUR_OUTPUT_FILE"
    
    echo ""
    
    # =============================================================
    # 4. LİSTELERİ BİRLEŞTİR
    # =============================================================
    
    echo "🔗 Combining blacklists..."
    echo "-------------------------"
    
    # Tüm filtrelenmiş dosyaları birleştir ve tekrarları kaldır
    cat "$CINSSCORE_FILE" "$FIREHOL_FILE" "$THREATSTOP_FILE" 2>/dev/null | \
        sort -u > "$COMBINED_FILE"
    
    # 🔧 YENİ LİSTE EKLEMEK İÇİN:
    # Yukarıdaki cat komutuna "$YOUR_OUTPUT_FILE" ekleyin
    
    log_message "Combined blacklist created: $(cat "$COMBINED_FILE" | wc -l) unique IPs"
    echo ""
    
    # =============================================================
    # 5. ÇAKIŞMA KONTROLÜ
    # =============================================================
    
    echo "⚠️  Checking for whitelist conflicts..."
    echo "-------------------------------------"
    check_conflicts
    echo ""
    
    # =============================================================
    # 6. İSTATİSTİK VE RAPOR
    # =============================================================
    
    echo "📊 Generating statistics..."
    echo "-------------------------"
    log_statistics
    echo ""
    
    # =============================================================
    # 7. TEMİZLİK VE BİTİŞ
    # =============================================================
    
    echo "🧹 Cleaning up..."
    rm -rf "$TEMP_DIR"
    
    # Son dosya izinlerini ayarla
    if [ -f "$COMBINED_FILE" ]; then
        chmod 644 "$COMBINED_FILE"
        echo "✅ Main output file: $COMBINED_FILE"
        echo "   File size: $(du -h "$COMBINED_FILE" | cut -f1)"
        echo "   Total IPs: $(cat "$COMBINED_FILE" | wc -l)"
    fi
    
    echo ""
    echo "🌟 IP Blacklist Management System completed successfully!"
    echo "   Check logs: $LOG_FILE"
    echo "   Main output: $COMBINED_FILE"
    echo "   Data directory: $BASE_DIR"
    echo ""
    
    log_message "=== IP Blacklist Management System Completed Successfully ==="
}

#################################################################
# PROGRAM BAŞLATMA
# 
# Script buradan başlar
#################################################################

# Ana fonksiyonu çalıştır
main "$@"

# Başarı ile çıkış
exit 0

#################################################################
# SCRIPT SONU
# 
# 📋 KULLANIM ÖRNEKLERİ:
# 
# Manuel çalıştırma:
#   ./ip_blacklist_manager.sh
# 
# Cron job için:
#   0 2 * * * /path/to/ip_blacklist_manager.sh >/dev/null 2>&1
# 
# Debug modunda:
#   bash -x ip_blacklist_manager.sh
# 
# 🔧 ÖZELLEŞTİRME REHBERİ:
# 
# 1. YENİ GÜVENLİK LİSTESİ EKLEMEK:
#    - URL tanımla (başta)
#    - download_file çağrısı ekle (main fonksiyonunda)
#    - filter_ips çağrısı ekle
#    - Birleştirme işleminde dahil et
# 
# 2. MAİL AYARLARI:
#    - MAIL_TO değişkenini doldur
#    - sendmail servisini yapılandır
#    - Test için: echo "test" | sendmail your@email.com
# 
# 3. WHİTELİST AYARLARI:
#    - SSH key oluştur: ssh-keygen -t rsa
#    - Public key kopyala: ssh-copy-id user@server
#    - WHITELIST_* değişkenlerini doldur
# 
# 4. FİLTRE KURALLARI EKLEMEK:
#    - filter_ips fonksiyonuna grep -v satırı ekle
#    - Yeni IP aralığı bloklamak için pattern ekle
# 
# 5. ÇIKTI DİZİNİ DEĞİŞTİRME:
#    - BASE_DIR değişkenini düzenle
#    - Dizin izinlerini kontrol et
#    - mkdir -p komutu ile oluştur
#
# 📚 DAHA FAZLA BİLGİ:
# - GitHub repository'de README.md dosyasını okuyun
# - Log dosyalarını düzenli kontrol edin
# - Güvenlik listelerini güncel tutun
# - Backup stratejinizi planlayın
#
# 🔐 GÜVENLİK NOTLARI:
# - Script'i düzenli olarak cron job ile çalıştırın
# - Log dosyalarını monitör edin
# - Çakışma raporlarını inceleyin
# - Whitelist'i düzenli güncelleyin
#
#################################################################
