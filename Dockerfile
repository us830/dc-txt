FROM php:8.2-apache

# 启用 mod_rewrite
RUN a2enmod rewrite

# 复制主程序
COPY index.php /var/www/html/index.php

# 创建 .htaccess 实现路由重写（也可直接在虚拟主机配置中写规则）
RUN echo "RewriteEngine On\n\
RewriteCond %{REQUEST_FILENAME} !-f\n\
RewriteCond %{REQUEST_FILENAME} !-d\n\
RewriteRule ^ index.php [QSA,L]" > /var/www/html/.htaccess

# 修改 Apache 站点配置，允许 .htaccess 覆盖
RUN sed -i '/<Directory \/var\/www\/html>/s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# 确保数据目录可写
RUN mkdir -p /var/www/html/data && chmod 777 /var/www/html/data

# （可选）关闭目录列表
RUN echo "Options -Indexes" >> /var/www/html/.htaccess

EXPOSE 80
