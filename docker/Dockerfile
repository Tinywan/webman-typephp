FROM tinywan/typephp-linux-x64:v0.7.0@sha256:92a5afbae1da2d3d7d942d8c9754091e00a1c2a527a4ac758a863e132af3453a

LABEL org.opencontainers.image.title="TypePHP Webman Builder"
LABEL org.opencontainers.image.description="Linux x86_64 glibc portable-dir builder"

COPY entrypoint.sh /entrypoint.sh
RUN chmod 0755 /entrypoint.sh

WORKDIR /workspace
ENTRYPOINT ["/entrypoint.sh"]
