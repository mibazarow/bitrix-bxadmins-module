#!/usr/bin/env bash
#
# Собирает офлайн-payload для install.sh на подготовленном сервере.
# Индекс BxMCP НЕ копируется: он строится на целевом сайте.
#
#   sudo ./bundle.sh --out /home/bitrix/bxadmins-environment-linux-x86_64.tar.gz
#
# Источники можно переопределить:
#   BXMCP_SRC=/opt/bitrix-mcp
#   EMBEDDINGS_SRC=/opt/bitrix-mcp/python
#   SCRAPLING_SRC=/home/bitrix/bxadmins-data/scrapling/.venv
#   CHROME_SRC=/home/bitrix/bxadmins-data/chrome
#   MODEL_CACHE=/root/.cache/huggingface
#   NODE_SRC=/usr/local/bin/node

set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
OUT=""
ZSTD=0
INCLUDE_MODEL=1
INCLUDE_BXMCP=1
INCLUDE_EMBEDDINGS=1
INCLUDE_SCRAPLING=1
INCLUDE_CHROME=1

BXMCP_SRC=${BXMCP_SRC:-/opt/bitrix-mcp}
EMBEDDINGS_SRC=${EMBEDDINGS_SRC:-/opt/bitrix-mcp/python}
SCRAPLING_SRC=${SCRAPLING_SRC:-/home/bitrix/bxadmins-data/scrapling/.venv}
SCRAPLING_BROWSERS_SRC=${SCRAPLING_BROWSERS_SRC:-/home/bitrix/.cache/ms-playwright}
CHROME_SRC=${CHROME_SRC:-/home/bitrix/bxadmins-data/chrome}
MODEL_CACHE=${MODEL_CACHE:-/root/.cache/huggingface}
NODE_SRC=${NODE_SRC:-$(readlink -f "$(command -v node)" 2>/dev/null || command -v node || true)}
BXMCP_DOCS_SRC=${BXMCP_DOCS_SRC:-$SCRIPT_DIR/bxmcp/data/docs}
MODEL_SRC=${MODEL_SRC:-$SCRIPT_DIR/models/embeddinggemma-2}

log() { printf '[bundle] %s\n' "$*" >&2; }
die() { printf '[bundle] ERROR: %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
    case "$1" in
        --out) OUT=${2:?}; shift 2 ;;
        --out=*) OUT=${1#*=}; shift ;;
        --zstd) ZSTD=1; shift ;;
        --no-model) INCLUDE_MODEL=0; shift ;;
        --no-bxmcp) INCLUDE_BXMCP=0; shift ;;
        --no-embeddings) INCLUDE_EMBEDDINGS=0; shift ;;
        --no-scrapling) INCLUDE_SCRAPLING=0; shift ;;
        --no-chrome) INCLUDE_CHROME=0; shift ;;
        *) die "Неизвестный параметр: $1" ;;
    esac
done

[ -n "$OUT" ] || die "Укажите --out FILE.tar.gz"
[ -n "$NODE_SRC" ] && [ -x "$NODE_SRC" ] || die "Node не найден: укажите NODE_SRC"
[ -d "$EMBEDDINGS_SRC" ] || die "Нет каталога embeddings: $EMBEDDINGS_SRC"
[ -f "$EMBEDDINGS_SRC/embeddings-service.py" ] || die "Нет embeddings-service.py"

STAGE=$(mktemp -d "${BUNDLE_TMP:-/tmp}/bxadmins-bundle.XXXXXX")
trap 'rm -rf "$STAGE"' EXIT
PAYLOAD=$STAGE/payload
mkdir -p "$PAYLOAD"/{runtime/node/bin,models/huggingface,embeddings,scrapling,chrome}

copy_tree() {
    local src=$1 dst=$2
    [ -e "$src" ] || return 0
    mkdir -p "$dst"
    cp -a "$src/." "$dst/"
}

if [ "$INCLUDE_BXMCP" -eq 1 ]; then
    [ -d "$BXMCP_SRC/dist" ] || die "Нет BxMCP dist: $BXMCP_SRC"
    log "Копирую BxMCP (без index.db)..."
    copy_tree "$BXMCP_SRC/dist" "$PAYLOAD/bxmcp/dist"
    copy_tree "$BXMCP_SRC/node_modules" "$PAYLOAD/bxmcp/node_modules"
    [ -f "$BXMCP_SRC/package.json" ] && cp -a "$BXMCP_SRC/package.json" "$PAYLOAD/bxmcp/"
    local docs_src=$BXMCP_DOCS_SRC
    [ -d "$docs_src" ] || docs_src=$BXMCP_SRC/data/docs
    [ -d "$docs_src" ] && copy_tree "$docs_src" "$PAYLOAD/bxmcp/data/docs"
fi

log "Копирую Node runtime..."
cp -L "$NODE_SRC" "$PAYLOAD/runtime/node/bin/node"

if [ "$INCLUDE_EMBEDDINGS" -eq 1 ]; then
    [ -f "$EMBEDDINGS_SRC/embeddings-service.py" ] || die "Нет embeddings-service.py"
    log "Копирую embeddings..."
    cp -a "$EMBEDDINGS_SRC/embeddings-service.py" "$PAYLOAD/embeddings/"
    [ -f "$EMBEDDINGS_SRC/requirements.txt" ] && cp -a "$EMBEDDINGS_SRC/requirements.txt" "$PAYLOAD/embeddings/"
    if [ -d "$EMBEDDINGS_SRC/venv-gemma2" ]; then
        copy_tree "$EMBEDDINGS_SRC/venv-gemma2" "$PAYLOAD/embeddings/venv"
    elif [ -d "$EMBEDDINGS_SRC/venv" ]; then
        copy_tree "$EMBEDDINGS_SRC/venv" "$PAYLOAD/embeddings/venv"
    else
        die "Не найден venv эмбеддингов: $EMBEDDINGS_SRC/venv-gemma2"
    fi
fi

if [ "$INCLUDE_MODEL" -eq 1 ]; then
    if [ -d "$MODEL_SRC" ]; then
        log "Копирую встроенную модель EmbeddingGemma 2..."
        copy_tree "$MODEL_SRC" "$PAYLOAD/models/embeddinggemma-2"
    elif [ -d "$MODEL_CACHE/hub/models--google--embeddinggemma-2" ]; then
        log "Копирую модель EmbeddingGemma 2..."
        copy_tree "$MODEL_CACHE/hub/models--google--embeddinggemma-2" \
            "$PAYLOAD/models/huggingface/hub/models--google--embeddinggemma-2"
    elif [ -d "$MODEL_CACHE/models--google--embeddinggemma-2" ]; then
        log "Копирую модель EmbeddingGemma 2..."
        copy_tree "$MODEL_CACHE/models--google--embeddinggemma-2" \
            "$PAYLOAD/models/huggingface/models--google--embeddinggemma-2"
    else
        die "Модель не найдена в $MODEL_CACHE. Запустите bundle.sh от root или укажите MODEL_CACHE."
    fi
fi

if [ "$INCLUDE_SCRAPLING" -eq 1 ]; then
    if [ -d "$SCRAPLING_SRC" ]; then
        log "Копирую Scrapling venv..."
        copy_tree "$SCRAPLING_SRC" "$PAYLOAD/scrapling/.venv"
    else
        log "Scrapling не найден ($SCRAPLING_SRC) — пропускаю."
    fi
    if [ -d "$SCRAPLING_BROWSERS_SRC" ]; then
        log "Копирую Scrapling browsers..."
        copy_tree "$SCRAPLING_BROWSERS_SRC" "$PAYLOAD/scrapling/browsers"
    else
        log "Браузеры Scrapling не найдены ($SCRAPLING_BROWSERS_SRC) — пропускаю."
    fi
fi

if [ "$INCLUDE_CHROME" -eq 1 ]; then
    if [ -d "$CHROME_SRC" ]; then
        log "Копирую Chrome..."
        copy_tree "$CHROME_SRC" "$PAYLOAD/chrome"
    else
        log "Chrome не найден ($CHROME_SRC) — пропускаю."
    fi
fi

{
    echo "created=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "arch=$(uname -m)"
    echo "kernel=$(uname -r)"
    echo "os=$(. /etc/os-release 2>/dev/null && echo "${PRETTY_NAME:-$ID}")"
    echo "glibc=$(ldd --version 2>/dev/null | head -1 || true)"
    echo "node=$("$NODE_SRC" -v 2>/dev/null || true)"
    echo "python=$("$EMBEDDINGS_SRC/venv-gemma2/bin/python" --version 2>/dev/null || "$EMBEDDINGS_SRC/venv/bin/python" --version 2>/dev/null || true)"
    echo "bxmcp=$(cat "$BXMCP_SRC/package.json" 2>/dev/null | tr -d '\n' | head -c 300 || true)"
    echo "--- sizes ---"
    du -sh "$PAYLOAD"/* 2>/dev/null || true
} > "$PAYLOAD/manifest.txt"

log "Собираю архив..."
mkdir -p "$(dirname "$OUT")"
if [ "$ZSTD" -eq 1 ]; then
    command -v zstd >/dev/null 2>&1 || die "zstd не найден; уберите --zstd или установите zstd."
    tar --zstd -cf "$OUT" -C "$PAYLOAD" .
else
    tar -czf "$OUT" -C "$PAYLOAD" .
fi
log "Готово: $OUT ($(du -h "$OUT" | cut -f1))"
