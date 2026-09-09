FROM tinywan/typephp-linux-x64:v0.8.0@sha256:f18cac640edf52126acc1ad781f220f9fe547f7c8db925dcc38d4854f9436f90

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
COPY AssignOpTrait.php /opt/typephp/vendor/swoole/typephp/src/Parser/AssignOpTrait.php
RUN chmod 0755 /entrypoint.sh

WORKDIR /workspace
ENTRYPOINT ["/entrypoint.sh"]
