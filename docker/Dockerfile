FROM alpine:edge

LABEL maintainer="Tinywan <756684177@qq.com>"
LABEL description="TypePHP AOT Static Compiler & Packager Environment for Webman"

# 1. 安装构建工具链与 PHP 8.5 依赖
RUN apk add --no-cache bash clang lld build-base binutils \
    pcre2-dev php85 php85-phar php85-openssl php85-tokenizer \
    php85-ctype php85-mbstring php85-dom php85-xml \
    php85-simplexml php85-pcntl php85-posix \
    && ln -sf /usr/bin/php85 /usr/bin/php

# 2. 预置构建入口调度脚本
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /workspace
ENTRYPOINT ["/entrypoint.sh"]
