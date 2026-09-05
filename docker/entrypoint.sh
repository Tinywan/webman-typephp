#!/usr/bin/env bash
set -e

echo "=========================================================="
echo " [TypePHP Builder] Starting AOT Compilation Pipeline"
echo "=========================================================="

WORKSPACE_DIR="/workspace"
cd "$WORKSPACE_DIR"

# 1. 确保 project.linux.yml 存在
if [ ! -f "project.linux.yml" ]; then
    echo "[ERROR] project.linux.yml not found in workspace!"
    exit 1
fi

# 2. 解析 PHP 与 TPC 编译器可执行文件
PHP_BIN=$(command -v php85 || command -v php)
if [ -f "./vendor/bin/tpc.php" ]; then
    TPC_BIN="./vendor/bin/tpc.php"
elif command -v tpc &> /dev/null; then
    TPC_BIN="tpc"
else
    echo "[ERROR] tpc compiler not found! Please make sure swoole/typephp is installed in composer."
    exit 1
fi

# 3. 执行全静态编译
echo "[INFO] Running TPC AOT compilation in Musl full-static mode..."
$PHP_BIN $TPC_BIN project.linux.yml --full-static --compiler=clang

# 4. 组装输出 dist/ 目录
echo "[INFO] Assembling self-contained dist/ directory..."
rm -rf dist
mkdir -p dist

if [ -f "build/webman-server" ]; then
    cp -f "build/webman-server" "dist/webman-server"
    chmod +x "dist/webman-server"
elif [ -f "build/webman_server" ]; then
    cp -f "build/webman_server" "dist/webman-server"
    chmod +x "dist/webman-server"
else
    echo "[ERROR] Compiled binary not found in build/!"
    exit 1
fi

# 拷贝静态资源与业务配置
[ -d config ] && cp -r config dist/
[ -d public ] && cp -r public dist/
if [ -d app/view ]; then
    mkdir -p dist/app
    cp -r app/view dist/app/
fi

# 5. 剥离调试符号瘦身
if command -v strip &> /dev/null; then
    echo "[INFO] Stripping debug symbols to minimize binary size..."
    strip --strip-all dist/webman-server 2>/dev/null || true
fi

echo "=========================================================="
echo " [SUCCESS] Build completed! Binary: dist/webman-server"
echo " File size: $(ls -lh dist/webman-server | awk '{print $5}')"
echo "=========================================================="
