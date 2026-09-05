FROM alpine:3.21

LABEL maintainer="Tinywan <756684177@qq.com>"
LABEL description="Linux x86_64 TypePHP builder for Webman portable-dir artifacts"

ARG TYPEPHP_VERSION=v0.7.0

# 1. 安装构建工具链与 PHP 8.4/8.5 依赖环境
RUN apk add --no-cache bash clang lld build-base binutils git composer \
    php84 php84-phar php84-openssl php84-tokenizer php84-ctype \
    php84-mbstring php84-dom php84-xml php84-simplexml php84-pcntl php84-posix \
    && ln -sf /usr/bin/php84 /usr/local/bin/php \
    && git clone --depth 1 --branch "${TYPEPHP_VERSION}" https://github.com/swoole/typephp.git /opt/typephp \
    && cd /opt/typephp && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# 2. 预置构建入口调度脚本
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /workspace
ENTRYPOINT ["/entrypoint.sh"]
