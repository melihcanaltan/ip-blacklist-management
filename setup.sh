#!/bin/bash

echo "🚀 Blacklist Management Kurulum Betiği"

# Apache kurulumu
read -p "Apache2 kurulu mu? (E/h): " install_apache
if [[ "$install_apache" == "E" || "$install_apache" == "e" ]]; then
    echo "🔧 Apache2 kuruluyor..."
    sudo apt update
    sudo apt install apache2 -y
fi

# Port seçimi
read -p "Kullanmak istediğiniz port numarası? (Varsayılan: 80): " custom_port
custom_port=${custom_port:-80}

# Proje dizini
PROJECT_DIR="blacklist-management"
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
