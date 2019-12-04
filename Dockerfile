FROM php:latest

RUN mkdir -p /opt/mywifi
RUN mkdir -p /opt/config

COPY content/* /opt/mywifi/
COPY config/* /opt/config/
COPY entrypoint.sh /

RUN apt-get update
RUN apt-get install -y \
    libldap2-dev \
    libpq-dev

RUN docker-php-ext-install ldap
RUN docker-php-ext-configure pgsql -with-pgsql=/usr/local/pgsql
RUN docker-php-ext-install pdo pdo_pgsql pgsql

RUN apt-get clean all

EXPOSE 8080

CMD ["bash", "/entrypoint.sh"]