FROM tinywan/typephp-linux-x64:v0.9.0@sha256:7155c079aa8fef76f6460278535a7ce6b4a77c5b4734aadf31612e039f9eec75

LABEL org.opencontainers.image.title="TypePHP Webman Builder"
LABEL org.opencontainers.image.description="Linux x86_64 glibc portable-dir builder"

COPY entrypoint.sh /entrypoint.sh
RUN chmod 0755 /entrypoint.sh

WORKDIR /workspace
ENTRYPOINT ["/entrypoint.sh"]
