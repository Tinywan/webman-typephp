FROM alpine:edge

LABEL maintainer="Tinywan <756684177@qq.com>"
LABEL description="Linux x86_64 TypePHP builder for Webman portable-dir artifacts (Alpine Edge + PHP 8.5)"

ARG TYPEPHP_VERSION=v0.7.0

# 1. 安装构建工具链与 PHP 8.5 官方环境
RUN apk add --no-cache bash clang lld build-base binutils git curl \
    php85 php85-phar php85-openssl php85-tokenizer php85-ctype \
    php85-mbstring php85-dom php85-xml php85-simplexml php85-pcntl php85-posix php85-curl php85-xmlwriter \
    && ln -sf /usr/bin/php85 /usr/bin/php \
    && ln -sf /usr/bin/php85 /usr/local/bin/php \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && git clone --depth 1 --branch "${TYPEPHP_VERSION}" https://github.com/swoole/typephp.git /opt/typephp \
    && cd /opt/typephp && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader --ignore-platform-reqs

# 2. 预置构建入口调度脚本
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /workspace
ENTRYPOINT ["/entrypoint.sh"]


