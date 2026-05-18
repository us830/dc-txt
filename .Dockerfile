FROM php:8.1-apache

# 开启 Apache 的 mod_rewrite 路由重写模块（核心：保证 /web/xxx, /raw/xxx 路由正常工作）
RUN a2enmod rewrite

# 将 index.php 复制到 Apache 的 Web 根目录
COPY index.php /var/www/html/index.php

# 创建数据存储目录，并将其所有权赋予 Apache 用户（www-data），确保有写入权限
RUN mkdir -p /var/www/html/data/files && \
    chown -R www-data:www-data /var/www/html/data && \
    chmod -R 755 /var/www/html/data

# 配置 Apache 允许 .htaccess 或目录重写规则
RUN echo '<Directory /var/www/html/>\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' >> /etc/apache2/apache2.conf

# 配置简易的伪静态（把所有不存在的请求都指向 index.php）
RUN echo 'RewriteEngine On\n\
RewriteCond %{REQUEST_FILENAME} !-f\n\
RewriteCond %{REQUEST_FILENAME} !-d\n\
RewriteRule ^(.*)$ index.php [L,QSA]' > /var/www/html/.htaccess

EXPOSE 80
