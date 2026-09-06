FROM tinywan/typephp-linux-x64:v0.7.0@sha256:92a5afbae1da2d3d7d942d8c9754091e00a1c2a527a4ac758a863e132af3453a

LABEL org.opencontainers.image.title="TypePHP Webman Builder"
LABEL org.opencontainers.image.description="Linux x86_64 glibc portable-dir builder"

COPY entrypoint.sh /entrypoint.sh
COPY SourcePipelineTrait.php /opt/typephp/vendor/swoole/typephp/src/Build/SourcePipelineTrait.php
COPY Translator.php /opt/typephp/vendor/swoole/typephp/src/Translator.php
COPY CompilerDiagnosticTrait.php /opt/typephp/vendor/swoole/typephp/src/Diagnostics/CompilerDiagnosticTrait.php
COPY gen_stub.php /opt/typephp/vendor/swoole/typephp/src/gen_stub.php
COPY SwitchTrait.php /opt/typephp/vendor/swoole/typephp/src/Parser/SwitchTrait.php
COPY CompilerBase.php /opt/typephp/vendor/swoole/typephp/src/CompilerBase.php
COPY ArrayExpressionTrait.php /opt/typephp/vendor/swoole/typephp/src/Parser/ArrayExpressionTrait.php
COPY FuncCallOptimizer.php /opt/typephp/vendor/swoole/typephp/src/Optimizer/FuncCallOptimizer.php
RUN chmod 0755 /entrypoint.sh

WORKDIR /workspace
ENTRYPOINT ["/entrypoint.sh"]
