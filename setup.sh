#!/bin/bash

echo "🚀 Blacklist Management Kurulum Betiği"

# Apache kurulumu
read -p "Apache2 kurulu mu? (E/h): " install_apache
if [[ "$install_apache" == "E" || "$install_apache" == "e" ]]; then
    echo "🔧 Apache2 kuruluyor..."
    sudo apt update
    sudo apt install apache2 -y
fi

# PHP kurulumu
read -p "PHP 7.4+ kurulu mu? Kurulmasını ister misiniz? (E/h): " install_php
if [[ "$install_php" == "E" || "$install_php" == "e" ]]; then
    echo "🔧 PHP ve gerekli eklentiler kuruluyor..."
    sudo apt update
    sudo apt install php php-cli php-zip php-xml php-gd php-curl php-mbstring unzip curl -y
    echo "📦 Composer kuruluyor..."
    
    EXPECTED_SIGNATURE=$(curl -s https://composer.github.io/installer.sig)
    php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
    ACTUAL_SIGNATURE=$(php -r "echo hash_file('sha384', 'composer-setup.php');")

    if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]
    then
        >&2 echo "❌ Hata: Composer yükleyici doğrulama başarısız."
        rm composer-setup.php
        exit 1
    fi

    php composer-setup.php --quiet
    sudo mv composer.phar /usr/local/bin/composer
    rm composer-setup.php

    echo "✅ PHP ve Composer kurulumu tamamlandı."
else
    echo "⚠️ PHP kurulumu atlandı. Sistemde PHP 7.4+ ve gerekli eklentilerin kurulu olduğundan emin olun."
fi

# Port seçimi
read -p "Kullanmak istediğiniz port numarası? (Varsayılan: 80): " custom_port
custom_port=${custom_port:-80}

# Proje dizini
PROJECT_DIR="ip-blacklist-management"
TARGET_DIR="/var/www/html/$PROJECT_DIR"

echo "📁 Proje dizini oluşturuluyor: $TARGET_DIR"
sudo mkdir -p "$TARGET_DIR"
sudo cp -r . "$TARGET_DIR"

# Apache VirtualHost yapılandırması
VHOST_FILE="/etc/apache2/sites-available/$PROJECT_DIR.conf"

echo "🛠️ Apache VirtualHost dosyası yazılıyor..."

sudo bash -c "cat > $VHOST_FILE" <<EOL
<VirtualHost *:$custom_port>
    DocumentRoot "$TARGET_DIR"
    <Directory "$TARGET_DIR">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/$PROJECT_DIR-error.log
    CustomLog \${APACHE_LOG_DIR}/$PROJECT_DIR-access.log combined
</VirtualHost>
EOL

# Apache port ayarı
PORTS_FILE="/etc/apache2/ports.conf"
if ! grep -q "Listen $custom_port" "$PORTS_FILE"; then
    echo "🔧 Apache ports.conf dosyasına port ekleniyor: $custom_port"
    echo "Listen $custom_port" | sudo tee -a "$PORTS_FILE"
fi

# VirtualHost etkinleştir
sudo a2ensite "$PROJECT_DIR.conf"
sudo systemctl reload apache2
sudo systemctl restart apache2

# Sendmail kurulumu
read -p "Mail gönderimi için Sendmail kurulumu yapılsın mı? (E/h): " install_sendmail
if [[ "$install_sendmail" == "E" || "$install_sendmail" == "e" ]]; then
    echo "📨 Sendmail kuruluyor..."
    sudo apt install sendmail -y
    echo "🔄 Sendmail konfigürasyonu başlatılıyor..."
    sudo sendmailconfig <<< "Y"
    echo "✅ Sendmail kurulumu tamamlandı."
    echo "ℹ️ Not: Mail gönderimi çalışmazsa sunucu SMTP portlarının açık olduğundan emin olun."
fi

echo "✅ Kurulum tamamlandı."
echo "🌐 Uygulamayı şu adresten açabilirsiniz: http://localhost:$custom_port"
