FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    apache2 \
    curl \
    iproute2 \
    iptables \
    libapache2-mod-php \
    openssl \
    php \
    php-curl \
    php-mbstring \
    php-sqlite3 \
    php-xml \
    procps \
    qrencode \
    sqlite3 \
    sudo \
    wireguard \
    wireguard-tools \
 && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite

COPY web /opt/vpnweb
COPY scripts/admin-password.sh /opt/vpnweb/bin/vpnweb-admin-passwd
COPY docker/wireguard/vpnctl.sh /opt/vpnweb/bin/vpnctl
COPY docker/wireguard/entrypoint.sh /usr/local/bin/vpnweb-entrypoint

RUN chmod 750 /opt/vpnweb/bin/vpnctl /opt/vpnweb/bin/vpnweb-admin-passwd /usr/local/bin/vpnweb-entrypoint \
 && chown root:root /opt/vpnweb/bin/vpnctl /opt/vpnweb/bin/vpnweb-admin-passwd /usr/local/bin/vpnweb-entrypoint \
 && mkdir -p /opt/vpnweb/var/qr /etc/wireguard/clients \
 && chown -R www-data:www-data /opt/vpnweb/var

EXPOSE 8080/tcp 51820/udp

ENTRYPOINT ["/usr/local/bin/vpnweb-entrypoint"]
