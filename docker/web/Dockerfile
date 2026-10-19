# Apache in front of PHP-FPM.
#
# WHY APACHE AND NOT NGINX: U-25 makes .htaccess the protection for a project
# that sits whole inside the web root. Serving the test environment any other
# way would mean the one deployment model the project actually supports is the
# one never exercised. `bin/check-deployment.php` can be pointed at this
# container and get a real answer.
#
# `docs/deployment/nginx.conf.example` is the alternative for installations that
# cannot use .htaccess; it is not what runs here.

FROM httpd:2.4-bookworm

RUN set -eux; \
    sed -ri \
        -e 's|^#(LoadModule rewrite_module .*)$|\1|' \
        -e 's|^#(LoadModule proxy_module .*)$|\1|' \
        -e 's|^#(LoadModule proxy_fcgi_module .*)$|\1|' \
        -e 's|^#(LoadModule deflate_module .*)$|\1|' \
        -e 's|^#(LoadModule expires_module .*)$|\1|' \
        -e 's|^#(LoadModule headers_module .*)$|\1|' \
        /usr/local/apache2/conf/httpd.conf; \
    echo 'Include conf/mailadmin.conf' >> /usr/local/apache2/conf/httpd.conf

COPY mailadmin.conf /usr/local/apache2/conf/mailadmin.conf
