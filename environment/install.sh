#!/usr/bin/env bash
#
# BXADMIN-S environment: окружение для модуля.
#
#   install.sh [install] [--payload DIR|TAR] [--site-root DIR] [--no-cron] [--no-register]
#   install.sh start | stop | restart | status
#   install.sh reindex
#   install.sh register
#   install.sh uninstall [--purge]
#
# По умолчанию работает online: ставит системные пакеты и компоненты из сети.
# Для офлайна: --payload <tar> или --offline/--build-payload (bundle.sh).
# Поддерживаются Debian/Ubuntu и RHEL-совместимые (Alma, Rocky, CentOS, Oracle).

set -euo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
MODULE_DIR=$(cd -- "$SCRIPT_DIR/.." && pwd)
DEFAULT_SITE_ROOT=$(cd -- "$SCRIPT_DIR/../../../.." && pwd)

DEFAULT_DATA_ROOT=$(dirname "$(dirname "$DEFAULT_SITE_ROOT")")/bxadmins-data
CONFIG_FILE=${BXADMINS_ENV_CONFIG:-$DEFAULT_DATA_ROOT/environment/environment.env}
PAYLOAD_DIR=${BXADMINS_PAYLOAD_DIR:-$SCRIPT_DIR/payload}
REINDEX_SCHEDULE="17 3 */3 * *"
ONLINE_MODE=1
EMBEDDINGS_OFFLINE=0
CHROME_VERSION=${CHROME_VERSION:-156.0.7933.0}
SCRAPLING_VERSION=${SCRAPLING_VERSION:-}
CRON_MARKER="# BXADMIN-S environment"
SYSTEMD_PREFIX="bxadmins"

log()  { printf '[bxadmins-env] %s\n' "$*" >&2; }
warn() { printf '[bxadmins-env] WARN: %s\n' "$*" >&2; }
die()  { printf '[bxadmins-env] ERROR: %s\n' "$*" >&2; exit 1; }

have() { command -v "$1" >/dev/null 2>&1; }

rand_token() {
    if have openssl; then
        openssl rand -hex 24
    else
        od -An -N32 -tx1 /dev/urandom | tr -d ' \n'
    fi
}

detect_os() {
    OS_ID=""
    OS_LIKE=""
    OS_FAMILY="unknown"
    if [ -r /etc/os-release ]; then
        # shellcheck disable=SC1091
        . /etc/os-release
        OS_ID=${ID:-}
        OS_LIKE=${ID_LIKE:-}
    fi
    case " $OS_ID $OS_LIKE " in
        *debian*|*ubuntu*) OS_FAMILY=debian ;;
        *rhel*|*centos*|*fedora*|*almalinux*|*rocky*|*ol*) OS_FAMILY=rhel ;;
    esac
    ARCH=$(uname -m)
    log "ОС: ${OS_ID:-unknown} (${OS_FAMILY}), архитектура: ${ARCH}"
}

detect_site() {
    if [ -f "$CONFIG_FILE" ]; then
        # shellcheck disable=SC1090
        set -a; . "$CONFIG_FILE"; set +a
    fi

    SITE_ROOT=${BXADMINS_SITE_ROOT:-${SITE_ROOT:-$DEFAULT_SITE_ROOT}}
    SITE_ROOT=$(cd -- "$SITE_ROOT" 2>/dev/null && pwd) || die "Каталог сайта не найден: $SITE_ROOT"
    [ -d "$SITE_ROOT/bitrix/modules/main" ] || die "Это не корень Битрикса: $SITE_ROOT"

    DATA_ROOT=${DATA_ROOT:-$(dirname "$(dirname "$SITE_ROOT")")/bxadmins-data}
    ENV_HOME=${ENV_HOME:-$DATA_ROOT/environment}
    CONFIG_FILE=${BXADMINS_ENV_CONFIG:-$ENV_HOME/environment.env}
    PID_DIR=$ENV_HOME/run
    LOG_DIR=$ENV_HOME/logs

    WEB_USER=${WEB_USER:-$(stat -c '%U' "$SITE_ROOT" 2>/dev/null || echo bitrix)}
    [ -n "$WEB_USER" ] || WEB_USER=bitrix

    if [ -d "$PAYLOAD_DIR" ]; then
        :
    elif [ -d "$SCRIPT_DIR/payload/linux-$ARCH" ]; then
        PAYLOAD_DIR=$SCRIPT_DIR/payload/linux-$ARCH
    fi

    BXMCP_PORT=${BXMCP_PORT:-3100}
    EMBEDDING_PORT=${EMBEDDING_PORT:-8000}
    EMBEDDING_MODEL=${EMBEDDING_MODEL:-google/embeddinggemma-2}
    SCRAPLING_PORT=${SCRAPLING_PORT:-3101}
    BXMCP_DIR=${BXMCP_DIR:-$ENV_HOME/bxmcp}
    BXMCP_DB=${BXMCP_DB:-$BXMCP_DIR/data/index.db}
    BXMCP_DOCS=${BXMCP_DOCS:-$BXMCP_DIR/data/docs}
    BXMCP_DOCS_URL=${BXMCP_DOCS_URL:-}
    BITRIX_PATH=${BITRIX_PATH:-$SITE_ROOT/bitrix/modules}
    COMPONENTS_PATH=${COMPONENTS_PATH:-$SITE_ROOT/bitrix/components}
    NODE_BIN=${NODE_BIN:-}
    EMBEDDINGS_DIR=${EMBEDDINGS_DIR:-$ENV_HOME/embeddings}
    EMBEDDINGS_PYTHON=${EMBEDDINGS_PYTHON:-$EMBEDDINGS_DIR/venv/bin/python}
    HF_HOME=${HF_HOME:-$ENV_HOME/models/huggingface}
    SCRAPLING_DIR=${SCRAPLING_DIR:-$DATA_ROOT/scrapling}
    SCRAPLING_TOKEN=${SCRAPLING_TOKEN:-}
    SCRAPLING_BROWSERS_DIR=${SCRAPLING_BROWSERS_DIR:-$SCRAPLING_DIR/ms-playwright}
    CHROME_DIR=${CHROME_DIR:-$DATA_ROOT/chrome}
    CHROME_BIN=${CHROME_BIN:-$CHROME_DIR/chrome-headless-shell-linux64/chrome-headless-shell}
    CHROME_VERSION=${CHROME_VERSION:-156.0.7933.0}
}

check_php_extensions() {
    have php || { warn "php CLI не найден; проверьте установку PHP."; return 0; }
    local ext
    for ext in pdo_mysql curl mbstring json xml openssl zip fileinfo; do
        php -m 2>/dev/null | grep -qi "^${ext}$" || warn "PHP: не найдено расширение $ext."
    done
}

install_system_deps() {
    local force=${1:-0}
    local need=0 c
    if [ "$force" -eq 1 ]; then
        need=1
    fi
    for c in git curl tar gzip unzip diff ps setsid ss openssl crontab mysql mysqldump python3 fc-list make g++; do
        have "$c" || need=1
    done

    # На чистом сервере даже при наличии git может не быть библиотек Chrome.
    if have ldconfig && ! ldconfig -p 2>/dev/null | grep -q 'libnss3\.so'; then
        need=1
    fi
    [ -f /etc/ssl/certs/ca-certificates.crt ] || need=1

    if [ "$need" -eq 0 ]; then
        log "Системные утилиты уже установлены."
        return 0
    fi
    if [ "$(id -u)" -ne 0 ]; then
        warn "Не хватает системных утилит, но скрипт запущен не от root. Установите вручную или запустите с sudo."
        return 0
    fi

    case "$OS_FAMILY" in
        debian)
            command -v apt-get >/dev/null || { warn "apt-get не найден."; return 0; }
            apt_pick() {
                local candidate
                for candidate in "$@"; do
                    if apt-cache show "$candidate" >/dev/null 2>&1; then
                        echo "$candidate"
                        return
                    fi
                done
                echo "$1"
            }
            local pkgs=(git curl ca-certificates tar gzip unzip procps util-linux iproute2 diffutils openssl cron gnupg python3 python3-venv python3-pip build-essential default-mysql-client fontconfig fonts-dejavu-core fonts-liberation libnss3 libdrm2 libxcomposite1 libxdamage1 libxrandr2 libxfixes3 libxrender1 libx11-6 libxcb1 libxext6 libxi6 libxtst6)
            pkgs+=("$(apt_pick libgbm1t64 libgbm1)")
            pkgs+=("$(apt_pick libatk1.0-0t64 libatk1.0-0)")
            pkgs+=("$(apt_pick libatk-bridge2.0-0t64 libatk-bridge2.0-0)")
            pkgs+=("$(apt_pick libcups2t64 libcups2)")
            pkgs+=("$(apt_pick libasound2t64 libasound2)")
            pkgs+=("$(apt_pick libxkbcommon0t64 libxkbcommon0)")
            pkgs+=("$(apt_pick libpango-1.0-0t64 libpango-1.0-0)")
            pkgs+=("$(apt_pick libpangocairo-1.0-0t64 libpangocairo-1.0-0)")
            pkgs+=("$(apt_pick libcairo2t64 libcairo2)")
            log "Ставлю системные пакеты (apt)..."
            DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${pkgs[@]}" \
                || { apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${pkgs[@]}"; } \
                || warn "Не все пакеты установились; проверьте вручную."
            ;;
        rhel)
            command -v dnf >/dev/null || command -v yum >/dev/null || { warn "dnf/yum не найден."; return 0; }
            local pm=dnf; command -v dnf >/dev/null || pm=yum
            log "Ставлю системные пакеты ($pm)..."
            "$pm" install -y git curl ca-certificates tar gzip unzip procps-ng util-linux iproute diffutils openssl cronie gnupg2 python3 python3-pip gcc gcc-c++ make mariadb fontconfig \
                dejavu-sans-fonts liberation-fonts nss atk at-spi2-atk cups-libs libdrm mesa-libgbm alsa-lib \
                libxkbcommon libXcomposite libXdamage libXrandr libXfixes libXrender libX11 libxcb libXext libXi libXtst \
                pango cairo \
                || warn "Не все пакеты установились; проверьте вручную."
            ;;
        *)
            warn "Неизвестное семейство ОС: $OS_FAMILY. Установите git, mysql-клиент, curl, unzip, cron и библиотеки Chrome вручную."
            return 0
            ;;
    esac

    if have systemctl; then
        systemctl enable --now cron 2>/dev/null || systemctl enable --now crond 2>/dev/null || true
    fi
    for c in git curl mysqldump mysql python3 ss fc-list; do
        have "$c" || warn "После установки не найден $c."
    done
}

ensure_dirs() {
    mkdir -p "$ENV_HOME"/{logs,run,models/huggingface} \
             "$BXMCP_DIR/data/docs" "$EMBEDDINGS_DIR" "$SCRAPLING_DIR" \
             "$CHROME_DIR" "$DATA_ROOT/screenshots" "$DATA_ROOT/logs"
    if [ "$(id -u)" -eq 0 ]; then
        chown -R "$WEB_USER:$WEB_USER" "$ENV_HOME" "$SCRAPLING_DIR" "$CHROME_DIR" \
            "$DATA_ROOT/screenshots" "$DATA_ROOT/logs" 2>/dev/null || true
    fi
}

copy_tree() {
    local src=$1 dst=$2
    [ -e "$src" ] || return 0
    if [ -d "$src" ] && [ -d "$dst" ] && [ "$(cd "$src" && pwd -P)" = "$(cd "$dst" && pwd -P)" ]; then
        return 0
    fi
    mkdir -p "$dst"
    cp -a "$src/." "$dst/"
}

install_payload() {
    local payload=$1
    if [ -z "$payload" ] || [ ! -e "$payload" ]; then
        log "Payload не задан — использую уже установленные компоненты (dev-режим)."
        return 0
    fi

    if [ -f "$payload" ]; then
        case "$payload" in
            *.tar.zst) ;;
            *.tar.gz|*.tgz|*.tar) ;;
            *) die "Ожидается каталог payload или tar-архив: $payload" ;;
        esac
        local unpack=$ENV_HOME
        mkdir -p "$unpack"
        log "Распаковываю payload: $payload"
        if [ "${payload##*.}" = "zst" ]; then
            tar --zstd -xf "$payload" -C "$unpack"
        else
            tar -xf "$payload" -C "$unpack"
        fi
        if [ -d "$unpack/payload" ]; then payload=$unpack/payload; else payload=$unpack; fi
    fi
    [ -d "$payload" ] || die "Каталог payload не найден: $payload"

    if [ -d "$payload/bxmcp" ]; then
        log "BxMCP -> $BXMCP_DIR"
        copy_tree "$payload/bxmcp/dist" "$BXMCP_DIR/dist"
        copy_tree "$payload/bxmcp/node_modules" "$BXMCP_DIR/node_modules"
        copy_tree "$payload/bxmcp/data/docs" "$BXMCP_DIR/data/docs"
        [ -f "$payload/bxmcp/package.json" ] && cp -a "$payload/bxmcp/package.json" "$BXMCP_DIR/"
        [ -f "$payload/bxmcp/package-lock.json" ] && cp -a "$payload/bxmcp/package-lock.json" "$BXMCP_DIR/"
    fi

    if [ -d "$payload/runtime/node" ]; then
        log "Node runtime -> $ENV_HOME/runtime/node"
        copy_tree "$payload/runtime/node" "$ENV_HOME/runtime/node"
    fi
    if [ -d "$payload/embeddings" ]; then
        log "Embeddings -> $EMBEDDINGS_DIR"
        copy_tree "$payload/embeddings" "$EMBEDDINGS_DIR"
    fi
    if [ -d "$payload/models/embeddinggemma-2" ]; then
        log "Модель -> $ENV_HOME/models/embeddinggemma-2"
        copy_tree "$payload/models/embeddinggemma-2" "$ENV_HOME/models/embeddinggemma-2"
    fi
    if [ -d "$payload/models/huggingface" ]; then
        log "Модель -> $HF_HOME"
        copy_tree "$payload/models/huggingface" "$HF_HOME"
    fi
    if [ -d "$payload/scrapling/.venv" ]; then
        log "Scrapling -> $SCRAPLING_DIR/.venv"
        copy_tree "$payload/scrapling/.venv" "$SCRAPLING_DIR/.venv"
    fi
    if [ -d "$payload/scrapling/browsers" ]; then
        log "Scrapling browsers -> $SCRAPLING_BROWSERS_DIR"
        copy_tree "$payload/scrapling/browsers" "$SCRAPLING_BROWSERS_DIR"
    fi
    if [ -d "$payload/chrome" ]; then
        log "Chrome -> $CHROME_DIR"
        copy_tree "$payload/chrome" "$CHROME_DIR"
    fi

    if [ "$(id -u)" -eq 0 ]; then
        chown -R "$WEB_USER:$WEB_USER" "$ENV_HOME" "$SCRAPLING_DIR" "$CHROME_DIR" 2>/dev/null || true
    fi
}

resolve_binaries() {
    if [ -x "$PAYLOAD_DIR/runtime/node/bin/node" ]; then
        NODE_BIN=$PAYLOAD_DIR/runtime/node/bin/node
    elif [ -x "$ENV_HOME/runtime/node/bin/node" ]; then
        NODE_BIN=$ENV_HOME/runtime/node/bin/node
    else
        NODE_BIN=${NODE_BIN:-$(command -v node || true)}
    fi
    [ -n "$NODE_BIN" ] && [ -x "$NODE_BIN" ] || die "Node.js не найден. Положите runtime в payload или установите node."

    if [ ! -x "$EMBEDDINGS_PYTHON" ] && [ -x "$PAYLOAD_DIR/embeddings/venv/bin/python" ]; then
        EMBEDDINGS_PYTHON=$PAYLOAD_DIR/embeddings/venv/bin/python
    fi
    if [ ! -x "$EMBEDDINGS_PYTHON" ] && [ -x "/opt/bitrix-mcp/python/venv-gemma2/bin/python" ]; then
        EMBEDDINGS_PYTHON=/opt/bitrix-mcp/python/venv-gemma2/bin/python
        EMBEDDINGS_DIR=/opt/bitrix-mcp/python
    fi
    if [ ! -x "$EMBEDDINGS_PYTHON" ] && have python3; then
        EMBEDDINGS_PYTHON=$(command -v python3)
    fi
    [ -x "$EMBEDDINGS_PYTHON" ] || die "Python для сервиса эмбеддингов не найден."
    if [ -d "$ENV_HOME/models/embeddinggemma-2" ]; then
        EMBEDDING_MODEL=$ENV_HOME/models/embeddinggemma-2
        EMBEDDINGS_OFFLINE=1
    fi
    if ! "$EMBEDDINGS_PYTHON" -c 'import fastapi, uvicorn, sentence_transformers, torch' >/dev/null 2>&1; then
        warn "В Python окружении эмбеддингов не хватает пакетов. Положите готовый venv или wheelhouse в payload."
    fi

    if [ -z "${BXMCP_DIR:-}" ] || [ ! -f "$BXMCP_DIR/dist/index.js" ]; then
        if [ -f "/opt/bitrix-mcp/dist/index.js" ]; then
            BXMCP_DIR=/opt/bitrix-mcp
            BXMCP_DB=${BXMCP_DB:-$ENV_HOME/bxmcp/data/index.db}
            BXMCP_DOCS=${BXMCP_DOCS:-$BXMCP_DIR/data/docs}
        else
            die "BxMCP не найден: нужен payload/bxmcp или /opt/bitrix-mcp."
        fi
    fi

    SCRAPLING_BIN=$SCRAPLING_DIR/.venv/bin/scrapling-mcp
    if [ ! -x "$SCRAPLING_BIN" ] && [ -x "$PAYLOAD_DIR/scrapling/.venv/bin/scrapling-mcp" ]; then
        SCRAPLING_BIN=$PAYLOAD_DIR/scrapling/.venv/bin/scrapling-mcp
    fi
}

check_model() {
    if [ -d "${EMBEDDING_MODEL:-}" ]; then
        return 0
    fi
    if [ -d "$HF_HOME/hub/models--google--embeddinggemma-2" ] || [ -d "$HF_HOME/models--google--embeddinggemma-2" ]; then
        return 0
    fi
    warn "Модель EmbeddingGemma 2 не найдена в $HF_HOME."
    warn "Без модели сервис попытается скачать её из Hugging Face. Для офлайн-режима добавьте models/huggingface в payload."
}

check_ports() {
    have ss || { warn "ss не найден, пропускаю проверку портов."; return 0; }
    local p
    for p in "$EMBEDDING_PORT" "$BXMCP_PORT" "$SCRAPLING_PORT"; do
        if ss -ltn 2>/dev/null | awk '{print $4}' | grep -Eq "[:.]${p}$"; then
            warn "Порт $p уже занят. Если это не наш сервис — освободите его или задайте другой порт в config."
        fi
    done
}

write_config() {
    mkdir -p "$(dirname "$CONFIG_FILE")"
    if [ -z "$SCRAPLING_TOKEN" ] && [ -f "$CONFIG_FILE" ]; then
        # shellcheck disable=SC1090
        SCRAPLING_TOKEN=$(. "$CONFIG_FILE" >/dev/null 2>&1; printf '%s' "${SCRAPLING_TOKEN:-}")
    fi
    [ -n "$SCRAPLING_TOKEN" ] || SCRAPLING_TOKEN=$(rand_token)

    cat > "$CONFIG_FILE" <<EOF
SITE_ROOT=$SITE_ROOT
DATA_ROOT=$DATA_ROOT
ENV_HOME=$ENV_HOME
WEB_USER=$WEB_USER
BXMCP_DIR=$BXMCP_DIR
BXMCP_PORT=$BXMCP_PORT
BXMCP_DB=$BXMCP_DB
BXMCP_DOCS=$BXMCP_DOCS
BXMCP_DOCS_URL=$BXMCP_DOCS_URL
BITRIX_PATH=$BITRIX_PATH
COMPONENTS_PATH=$COMPONENTS_PATH
NODE_BIN=$NODE_BIN
EMBEDDINGS_DIR=$EMBEDDINGS_DIR
EMBEDDINGS_PYTHON=$EMBEDDINGS_PYTHON
EMBEDDING_PORT=$EMBEDDING_PORT
EMBEDDINGS_URL=http://127.0.0.1:$EMBEDDING_PORT
EMBEDDING_MODEL=${EMBEDDING_MODEL:-google/embeddinggemma-2}
EMBEDDING_DIM=${EMBEDDING_DIM:-256}
EMBEDDING_MAX_SEQ=${EMBEDDING_MAX_SEQ:-512}
EMBEDDING_THREADS=${EMBEDDING_THREADS:-2}
HF_HOME=$HF_HOME
EMBEDDINGS_OFFLINE=$EMBEDDINGS_OFFLINE
CHROME_VERSION=$CHROME_VERSION
SCRAPLING_VERSION=$SCRAPLING_VERSION
SCRAPLING_DIR=$SCRAPLING_DIR
SCRAPLING_PORT=$SCRAPLING_PORT
SCRAPLING_TOKEN=$SCRAPLING_TOKEN
SCRAPLING_BIN=$SCRAPLING_BIN
SCRAPLING_BROWSERS_DIR=$SCRAPLING_BROWSERS_DIR
PLAYWRIGHT_BROWSERS_PATH=$SCRAPLING_BROWSERS_DIR
CHROME_DIR=$CHROME_DIR
CHROME_BIN=$CHROME_BIN
EOF
    if [ -n "${HF_TOKEN:-}" ]; then
        printf 'HF_TOKEN=%s\n' "$HF_TOKEN" >> "$CONFIG_FILE"
    fi
    chmod 600 "$CONFIG_FILE" 2>/dev/null || true
    if [ "$(id -u)" -eq 0 ]; then chown "$WEB_USER:$WEB_USER" "$CONFIG_FILE" 2>/dev/null || true; fi
}

service_alive() {
    curl -fsS --max-time 2 "$1" >/dev/null 2>&1
}

start_pid() {
    local name=$1; shift
    local pid_file=$PID_DIR/$name.pid
    if [ -f "$pid_file" ] && kill -0 "$(cat "$pid_file")" 2>/dev/null; then
        return 0
    fi
    nohup "$@" >> "$LOG_DIR/$name.log" 2>&1 &
    echo $! > "$pid_file"
}

start_embeddings() {
    service_alive "http://127.0.0.1:$EMBEDDING_PORT/health" && return 0
    (
        cd "$EMBEDDINGS_DIR"
        start_pid embeddings \
            env HF_HOME="$HF_HOME" TRANSFORMERS_OFFLINE="$EMBEDDINGS_OFFLINE" HF_TOKEN="${HF_TOKEN:-}" \
            EMBEDDING_MODEL="${EMBEDDING_MODEL:-google/embeddinggemma-2}" \
            EMBEDDING_DIM="${EMBEDDING_DIM:-256}" EMBEDDING_MAX_SEQ="${EMBEDDING_MAX_SEQ:-512}" \
            EMBEDDING_THREADS="${EMBEDDING_THREADS:-2}" EMBEDDING_TEXT_ONLY=1 \
            OMP_NUM_THREADS="${EMBEDDING_THREADS:-2}" TOKENIZERS_PARALLELISM=false \
            "$EMBEDDINGS_PYTHON" -m uvicorn embeddings-service:app --host 127.0.0.1 --port "$EMBEDDING_PORT"
    )
}

start_bxmcp() {
    service_alive "http://127.0.0.1:$BXMCP_PORT/health" && return 0
    mkdir -p "$(dirname "$BXMCP_DB")"
    (
        cd "$BXMCP_DIR"
        start_pid bxmcp \
            env NODE_ENV=production BITRIX_PATH="$BITRIX_PATH" COMPONENTS_PATH="$COMPONENTS_PATH" \
            DB_PATH="$BXMCP_DB" DOCS_PATH="$BXMCP_DOCS" PORT="$BXMCP_PORT" \
            EMBEDDINGS_URL="http://127.0.0.1:$EMBEDDING_PORT" \
            "$NODE_BIN" dist/index.js
    )
}

start_scrapling() {
    [ -x "$SCRAPLING_BIN" ] || { warn "Scrapling не установлен — пропускаю."; return 0; }
    pgrep -f "scrapling-mcp.*--port $SCRAPLING_PORT" >/dev/null 2>&1 && return 0
    (
        cd "$SCRAPLING_DIR"
        start_pid scrapling \
            env SCRAPLING_MCP_AUTH_TOKEN="$SCRAPLING_TOKEN" \
            PLAYWRIGHT_BROWSERS_PATH="$SCRAPLING_BROWSERS_DIR" \
            "$SCRAPLING_BIN" --http --host 127.0.0.1 --port "$SCRAPLING_PORT"
    )
}

start_all() {
    [ -f "$CONFIG_FILE" ] || die "Нет конфигурации: $CONFIG_FILE. Сначала install."
    # shellcheck disable=SC1090
    set -a; . "$CONFIG_FILE"; set +a
    SCRAPLING_BIN=${SCRAPLING_BIN:-$SCRAPLING_DIR/.venv/bin/scrapling-mcp}
    PID_DIR=${PID_DIR:-$ENV_HOME/run}
    LOG_DIR=${LOG_DIR:-$ENV_HOME/logs}
    if [ "$(id -u)" -eq 0 ] && have systemctl && [ -f /etc/systemd/system/${SYSTEMD_PREFIX}-bxmcp.service ]; then
        if systemctl start ${SYSTEMD_PREFIX}-embeddings.service ${SYSTEMD_PREFIX}-bxmcp.service 2>/dev/null; then
            [ -f /etc/systemd/system/${SYSTEMD_PREFIX}-scrapling.service ] && systemctl start ${SYSTEMD_PREFIX}-scrapling.service 2>/dev/null || true
            log "Сервисы запущены через systemd."
            return 0
        fi
        warn "systemd не смог запустить юниты, перехожу на setsid/nohup."
    fi
    mkdir -p "$PID_DIR" "$LOG_DIR"
    start_embeddings
    start_bxmcp
    start_scrapling
    log "Сервисы запущены."
}

stop_pid() {
    local name=$1 pid_file=$PID_DIR/$1.pid
    if [ -f "$pid_file" ]; then
        local pid
        pid=$(cat "$pid_file" 2>/dev/null || true)
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill "$pid" 2>/dev/null || true
            sleep 1
            kill -9 "$pid" 2>/dev/null || true
        fi
        rm -f "$pid_file"
    fi
}

stop_all() {
    if [ -f "$CONFIG_FILE" ]; then
        # shellcheck disable=SC1090
        set -a; . "$CONFIG_FILE"; set +a
    fi
    ENV_HOME=${ENV_HOME:-$DEFAULT_DATA_ROOT/environment}
    PID_DIR=${PID_DIR:-$ENV_HOME/run}
    if [ "$(id -u)" -eq 0 ] && have systemctl && [ -f /etc/systemd/system/${SYSTEMD_PREFIX}-bxmcp.service ]; then
        systemctl stop ${SYSTEMD_PREFIX}-scrapling.service ${SYSTEMD_PREFIX}-bxmcp.service ${SYSTEMD_PREFIX}-embeddings.service 2>/dev/null || true
    fi
    stop_pid scrapling
    stop_pid bxmcp
    stop_pid embeddings
    log "Сервисы остановлены."
}

wait_http() {
    local url=$1 name=$2 i
    for i in $(seq 1 60); do
        if service_alive "$url"; then
            log "$name готов."
            return 0
        fi
        sleep 2
    done
    warn "$name не ответил за 120 секунд: $url"
    return 1
}

service_reachable() {
    curl -sS --max-time 2 -o /dev/null "$1" >/dev/null 2>&1
}

wait_reachable() {
    local url=$1 name=$2 i
    for i in $(seq 1 30); do
        if service_reachable "$url"; then
            log "$name готов."
            return 0
        fi
        sleep 2
    done
    warn "$name не ответил за 60 секунд: $url"
    return 1
}

register_mcp() {
    have php || { warn "php не найден — пропускаю регистрацию MCP."; return 0; }
    (
        cd "$SITE_ROOT"
        BXADMINS_SITE_ROOT="$SITE_ROOT" php "$SCRIPT_DIR/register-mcp.php" \
            --name="BxMCP" --url="http://127.0.0.1:$BXMCP_PORT/" \
            || warn "BxMCP не зарегистрирован автоматически. После установки модуля выполните install.sh register."
        if [ -x "$SCRAPLING_BIN" ]; then
            BXADMINS_SITE_ROOT="$SITE_ROOT" php "$SCRIPT_DIR/register-mcp.php" \
                --name="Scrapling" --url="http://127.0.0.1:$SCRAPLING_PORT/mcp" \
                --token="$SCRAPLING_TOKEN" \
                || warn "Scrapling не зарегистрирован автоматически. После установки модуля выполните install.sh register."
        fi
    )
}

reindex() {
    [ -f "$CONFIG_FILE" ] || die "Нет конфигурации: $CONFIG_FILE."
    # shellcheck disable=SC1090
    set -a; . "$CONFIG_FILE"; set +a
    SCRAPLING_BIN=${SCRAPLING_BIN:-$SCRAPLING_DIR/.venv/bin/scrapling-mcp}
    PID_DIR=${PID_DIR:-$ENV_HOME/run}
    LOG_DIR=${LOG_DIR:-$ENV_HOME/logs}
    service_alive "http://127.0.0.1:$BXMCP_PORT/health" || start_bxmcp
    wait_http "http://127.0.0.1:$BXMCP_PORT/health" "BxMCP" || exit 1

    log "Индексирую модули и компоненты..."
    curl -fsS --max-time 30 -X POST "http://127.0.0.1:$BXMCP_PORT/admin/reindex" >/dev/null || {
        warn "Не удалось запустить reindex модулей."
        return 1
    }

    # BxMCP не ставит docs-reindex в очередь: пока идёт модульный проход,
    # он отвечает busy. Ждём и повторяем, максимум 4 часа.
    local i
    for i in $(seq 1 240); do
        local out
        out=$(curl -fsS --max-time 30 -X POST "http://127.0.0.1:$BXMCP_PORT/admin/reindex-docs" 2>/dev/null || true)
        case "$out" in
            *'"busy"'*) sleep 60 ;;
            *) [ -n "$out" ] && { log "Индексация документации запущена."; return 0; } ;;
        esac
        sleep 60
    done
    warn "Документация не переиндексирована: BxMCP всё ещё занят."
    return 1
}

install_bitrix_cron() {
    [ -f /etc/cron.d/bitrix-cron ] && return 0
    if [ "$(id -u)" -ne 0 ]; then
        warn "Нет /etc/cron.d/bitrix-cron, а скрипт запущен не от root. Настройте cron Битрикса вручную (setup.md, §4.4)."
        return 0
    fi
    local php_bin
    php_bin=$(command -v php || true)
    [ -n "$php_bin" ] || { warn "php CLI не найден — cron Битрикса не установлен."; return 0; }
    cat > /etc/cron.d/bitrix-cron <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * $WEB_USER $php_bin -f $SITE_ROOT/bitrix/modules/main/tools/cron_events.php >/dev/null 2>&1
EOF
    chmod 644 /etc/cron.d/bitrix-cron
    BXADMINS_SITE_ROOT="$SITE_ROOT" "$php_bin" -r '
        $_SERVER["DOCUMENT_ROOT"] = getenv("BXADMINS_SITE_ROOT");
        define("NO_KEEP_STATISTIC", true);
        define("NOT_CHECK_PERMISSIONS", true);
        require $_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php";
        \Bitrix\Main\Config\Option::set("main", "agents_use_crontab", "Y");
    ' >/dev/null 2>&1 || warn "Не удалось включить agents_use_crontab=Y."
    log "Cron Битрикса установлен: /etc/cron.d/bitrix-cron"
}

install_cron() {
    local runner=$ENV_HOME/bin/install.sh
    [ -x "$runner" ] || runner=$SCRIPT_DIR/install.sh
    local cmd="cd \"$SITE_ROOT\" && BXADMINS_ENV_CONFIG=\"$CONFIG_FILE\" bash \"$runner\" reindex >> \"$LOG_DIR/reindex.log\" 2>&1"
    if [ "$(id -u)" -eq 0 ] && [ -d /etc/cron.d ]; then
        cat > /etc/cron.d/bxadmins-environment <<EOF
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
$REINDEX_SCHEDULE $WEB_USER $cmd
EOF
        chmod 644 /etc/cron.d/bxadmins-environment
        log "Cron установлен: /etc/cron.d/bxadmins-environment"
        return 0
    fi

    have crontab || { warn "crontab не найден — cron не установлен."; return 0; }
    local current
    current=$(crontab -l 2>/dev/null | grep -v "$CRON_MARKER" | grep -v "install.sh\" reindex" || true)
    {
        printf '%s\n' "$current"
        printf '%s\n' "$CRON_MARKER"
        printf '%s\n' "$REINDEX_SCHEDULE $cmd"
    } | crontab -
    log "Cron установлен в crontab пользователя $(id -un)."
}

install_systemd() {
    [ "$(id -u)" -eq 0 ] && have systemctl && [ -d /run/systemd/system ] || return 1

    sed -e "s|@WEB_USER@|$WEB_USER|g" -e "s|@CONFIG_FILE@|$CONFIG_FILE|g" \
        -e "s|@EMBEDDINGS_DIR@|$EMBEDDINGS_DIR|g" -e "s|@EMBEDDINGS_PYTHON@|$EMBEDDINGS_PYTHON|g" \
        -e "s|@EMBEDDING_PORT@|$EMBEDDING_PORT|g" -e "s|@EMBEDDINGS_OFFLINE@|$EMBEDDINGS_OFFLINE|g" \
        "$SCRIPT_DIR/systemd/bxadmins-embeddings.service.in" > /etc/systemd/system/${SYSTEMD_PREFIX}-embeddings.service
    sed -e "s|@WEB_USER@|$WEB_USER|g" -e "s|@CONFIG_FILE@|$CONFIG_FILE|g" \
        -e "s|@BXMCP_DIR@|$BXMCP_DIR|g" -e "s|@NODE_BIN@|$NODE_BIN|g" \
        -e "s|@BXMCP_PORT@|$BXMCP_PORT|g" \
        "$SCRIPT_DIR/systemd/bxadmins-bxmcp.service.in" > /etc/systemd/system/${SYSTEMD_PREFIX}-bxmcp.service
    if [ -x "$SCRAPLING_BIN" ]; then
        sed -e "s|@WEB_USER@|$WEB_USER|g" -e "s|@CONFIG_FILE@|$CONFIG_FILE|g" \
            -e "s|@SCRAPLING_DIR@|$SCRAPLING_DIR|g" -e "s|@SCRAPLING_BIN@|$SCRAPLING_BIN|g" \
            -e "s|@SCRAPLING_PORT@|$SCRAPLING_PORT|g" \
            "$SCRIPT_DIR/systemd/bxadmins-scrapling.service.in" > /etc/systemd/system/${SYSTEMD_PREFIX}-scrapling.service
    fi

    systemctl daemon-reload || { warn "systemctl daemon-reload не удался."; return 1; }
    systemctl enable --now ${SYSTEMD_PREFIX}-embeddings.service ${SYSTEMD_PREFIX}-bxmcp.service \
        || {
            warn "Не удалось запустить systemd-юниты; удаляю их и ухожу в fallback."
            systemctl disable ${SYSTEMD_PREFIX}-embeddings.service ${SYSTEMD_PREFIX}-bxmcp.service 2>/dev/null || true
            rm -f /etc/systemd/system/${SYSTEMD_PREFIX}-embeddings.service /etc/systemd/system/${SYSTEMD_PREFIX}-bxmcp.service /etc/systemd/system/${SYSTEMD_PREFIX}-scrapling.service
            systemctl daemon-reload 2>/dev/null || true
            return 1
        }
    if [ -x "$SCRAPLING_BIN" ]; then
        systemctl enable --now ${SYSTEMD_PREFIX}-scrapling.service \
            || warn "Не удалось запустить Scrapling unit."
    fi
    log "systemd-юниты установлены и запущены."
    return 0
}

status() {
    [ -f "$CONFIG_FILE" ] || die "Нет конфигурации: $CONFIG_FILE."
    # shellcheck disable=SC1090
    set -a; . "$CONFIG_FILE"; set +a
    SCRAPLING_BIN=${SCRAPLING_BIN:-$SCRAPLING_DIR/.venv/bin/scrapling-mcp}
    printf 'environment: %s\n' "$ENV_HOME"
    printf 'site:        %s\n' "$SITE_ROOT"
    if service_alive "http://127.0.0.1:$EMBEDDING_PORT/health"; then echo "embeddings: ok ($EMBEDDING_PORT)"; else echo "embeddings: DOWN ($EMBEDDING_PORT)"; fi
    if service_alive "http://127.0.0.1:$BXMCP_PORT/health"; then echo "bxmcp:      ok ($BXMCP_PORT)"; else echo "bxmcp:      DOWN ($BXMCP_PORT)"; fi
    if [ -x "$SCRAPLING_BIN" ]; then
        if pgrep -f "scrapling-mcp.*--port $SCRAPLING_PORT" >/dev/null 2>&1; then echo "scrapling:  ok ($SCRAPLING_PORT)"; else echo "scrapling:  DOWN ($SCRAPLING_PORT)"; fi
    else
        echo "scrapling:  not installed"
    fi
    [ -x "$CHROME_BIN" ] && echo "chrome:     $CHROME_BIN" || echo "chrome:     not found"
    [ -f "$BXMCP_DB" ] && echo "bxmcp index: $(du -h "$BXMCP_DB" | cut -f1)" || echo "bxmcp index: not built"
}

node_ok() {
    [ -n "${1:-}" ] && [ -x "$1" ] || return 1
    "$1" -e 'const [m]=process.versions.node.split(".").map(Number); process.exit(m>=22?0:1)' >/dev/null 2>&1
}

install_node_online() {
    local candidate=${NODE_BIN:-}
    [ -n "$candidate" ] || candidate=$(command -v node || true)
    if node_ok "$candidate"; then
        NODE_BIN=$candidate
        have npm || die "Node найден, но npm отсутствует. Установите полный пакет nodejs/npm."
        log "Node.js уже установлен: $($NODE_BIN -v)"
        return 0
    fi

    log "Устанавливаю Node.js 22..."
    case "$OS_FAMILY" in
        debian)
            curl -fsSL https://deb.nodesource.com/setup_22.x | bash - || die "Не удалось подключить NodeSource (deb)."
            DEBIAN_FRONTEND=noninteractive apt-get install -y nodejs || die "Не удалось установить nodejs."
            ;;
        rhel)
            curl -fsSL https://rpm.nodesource.com/setup_22.x | bash - || die "Не удалось подключить NodeSource (rpm)."
            local pm=dnf
            command -v dnf >/dev/null || pm=yum
            "$pm" install -y nodejs || die "Не удалось установить nodejs."
            ;;
        *)
            die "Не знаю, как ставить Node.js для $OS_FAMILY. Используйте payload."
            ;;
    esac
    NODE_BIN=$(command -v node || true)
    node_ok "$NODE_BIN" || die "Node.js 22 не установился."
    have npm || die "npm не установился вместе с Node."
    log "Node.js установлен: $($NODE_BIN -v)"
}

install_bxmcp_online() {
    mkdir -p "$BXMCP_DIR"
    if [ ! -f "$BXMCP_DIR/dist/index.js" ]; then
        log "Раскладываю BxMCP..."
        copy_tree "$SCRIPT_DIR/bxmcp" "$BXMCP_DIR"
    fi
    [ -f "$BXMCP_DIR/package.json" ] || cp -a "$SCRIPT_DIR/bxmcp/package.json" "$BXMCP_DIR/"
    [ -f "$BXMCP_DIR/package-lock.json" ] || cp -a "$SCRIPT_DIR/bxmcp/package-lock.json" "$BXMCP_DIR/"
    [ -f "$BXMCP_DIR/package-lock.json" ] || die "В BxMCP нет package-lock.json."
    if [ ! -d "$BXMCP_DIR/node_modules" ]; then
        log "Ставлю зависимости BxMCP (npm ci)..."
        ( cd "$BXMCP_DIR" && npm ci --omit=dev ) || die "npm ci для BxMCP не удался."
    fi
    mkdir -p "$BXMCP_DIR/data/docs"
    if [ -d "$SCRIPT_DIR/bxmcp/data/docs" ]; then
        log "Копирую документацию BxMCP..."
        copy_tree "$SCRIPT_DIR/bxmcp/data/docs" "$BXMCP_DIR/data/docs"
    fi
    if [ -n "${BXMCP_DOCS_URL:-}" ]; then
        log "Обновляю документацию BxMCP из BXMCP_DOCS_URL..."
        curl -fL --retry 3 "$BXMCP_DOCS_URL" | tar -xz -C "$BXMCP_DIR/data/docs" \
            || warn "Документация BxMCP не скачалась, остаётся встроенная копия."
    elif [ -z "$(find "$BXMCP_DIR/data/docs" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        warn "Документация BxMCP не найдена; поиск по докам будет пустым."
    fi
    log "BxMCP готов."
}

install_embeddings_online() {
    mkdir -p "$EMBEDDINGS_DIR" "$HF_HOME"
    if [ -d "$SCRIPT_DIR/models/embeddinggemma-2" ]; then
        if [ ! -d "$ENV_HOME/models/embeddinggemma-2" ]; then
            log "Раскладываю встроенную модель EmbeddingGemma 2..."
            copy_tree "$SCRIPT_DIR/models/embeddinggemma-2" "$ENV_HOME/models/embeddinggemma-2"
        fi
        EMBEDDING_MODEL=$ENV_HOME/models/embeddinggemma-2
        EMBEDDINGS_OFFLINE=1
    fi
    cp -a "$SCRIPT_DIR/embeddings/embeddings-service.py" "$EMBEDDINGS_DIR/" 2>/dev/null || true
    cp -a "$SCRIPT_DIR/embeddings/requirements.txt" "$EMBEDDINGS_DIR/" 2>/dev/null || true
    [ -f "$EMBEDDINGS_DIR/embeddings-service.py" ] || die "Нет embeddings-service.py в environment/embeddings."

    if [ ! -x "$EMBEDDINGS_DIR/venv/bin/python" ]; then
        log "Создаю venv эмбеддингов..."
        python3 -m venv "$EMBEDDINGS_DIR/venv" || die "Не удалось создать venv. Установите python3-venv."
        "$EMBEDDINGS_DIR/venv/bin/pip" install --upgrade pip || warn "pip upgrade не удался."
    fi
    EMBEDDINGS_PYTHON=$EMBEDDINGS_DIR/venv/bin/python

    if ! "$EMBEDDINGS_PYTHON" -c 'import fastapi, uvicorn, sentence_transformers, torch' >/dev/null 2>&1; then
        local torch_ver torch_spec
        torch_ver=$(grep -E '^torch==' "$EMBEDDINGS_DIR/requirements.txt" | head -1 | cut -d= -f3 || true)
        torch_spec=${torch_ver:+torch==$torch_ver}
        log "Ставлю torch (CPU) и зависимости эмбеддингов..."
        if [ -n "$torch_spec" ]; then
            "$EMBEDDINGS_DIR/venv/bin/pip" install --index-url https://download.pytorch.org/whl/cpu "$torch_spec" \
                || warn "CPU torch не установился, пробую обычный индекс."
        fi
        local req_no_torch=$EMBEDDINGS_DIR/requirements-no-torch.txt
        grep -v '^torch==' "$EMBEDDINGS_DIR/requirements.txt" > "$req_no_torch" || true
        "$EMBEDDINGS_DIR/venv/bin/pip" install -r "$req_no_torch" \
            || die "pip install для embeddings не удался."
        rm -f "$req_no_torch"
    fi

    if [ -d "${EMBEDDING_MODEL:-}" ]; then
        log "Модель уже лежит локально: $EMBEDDING_MODEL"
    elif [ "${SKIP_MODEL_DOWNLOAD:-0}" != 1 ]; then
        log "Проверяю/скачиваю модель EmbeddingGemma 2..."
        HF_HOME="$HF_HOME" HF_TOKEN="${HF_TOKEN:-}" "$EMBEDDINGS_PYTHON" -c \
            'from sentence_transformers import SentenceTransformer; SentenceTransformer("google/embeddinggemma-2")' \
            || warn "Модель не скачалась. Проверьте HF_TOKEN/лицензию; сервис попробует позже."
    fi
    log "Embeddings готовы."
}

install_scrapling_online() {
    mkdir -p "$SCRAPLING_DIR" "$SCRAPLING_BROWSERS_DIR"
    if [ ! -x "$SCRAPLING_DIR/.venv/bin/python" ]; then
        log "Создаю venv Scrapling..."
        python3 -m venv "$SCRAPLING_DIR/.venv" || { warn "Не удалось создать venv Scrapling."; return 0; }
        "$SCRAPLING_DIR/.venv/bin/pip" install --upgrade pip >/dev/null 2>&1 || true
    fi
    if [ ! -x "$SCRAPLING_DIR/.venv/bin/scrapling-mcp" ]; then
        local spec="scrapling[ai]"
        [ -n "${SCRAPLING_VERSION:-}" ] && spec="${spec}==${SCRAPLING_VERSION}"
        log "Ставлю Scrapling ($spec)..."
        "$SCRAPLING_DIR/.venv/bin/pip" install "$spec" || { warn "Scrapling не установился."; return 0; }
    fi
    if [ -z "$(find "$SCRAPLING_BROWSERS_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null)" ]; then
        log "Ставлю браузеры Scrapling..."
        PLAYWRIGHT_BROWSERS_PATH="$SCRAPLING_BROWSERS_DIR" \
            "$SCRAPLING_DIR/.venv/bin/scrapling" install || warn "Браузеры Scrapling не установились."
    fi
    SCRAPLING_BIN=$SCRAPLING_DIR/.venv/bin/scrapling-mcp
    log "Scrapling готов."
}

install_chrome_online() {
    [ -x "$CHROME_BIN" ] && return 0
    if [ "$ARCH" != "x86_64" ] && [ "$ARCH" != "amd64" ]; then
        warn "Chrome for Testing не поддерживает $ARCH в этом установщике; используйте системный Chromium."
        return 0
    fi
    mkdir -p "$CHROME_DIR"
    local url="https://storage.googleapis.com/chrome-for-testing-public/${CHROME_VERSION}/linux64/chrome-headless-shell-linux64.zip"
    local zip=$CHROME_DIR/chrome-headless-shell.zip
    log "Скачиваю Chrome $CHROME_VERSION..."
    if curl -fL --retry 3 "$url" -o "$zip"; then
        unzip -q -o "$zip" -d "$CHROME_DIR" || warn "Не удалось распаковать Chrome."
        rm -f "$zip"
        [ -x "$CHROME_BIN" ] && log "Chrome готов." || warn "Chrome не найден после распаковки."
    else
        warn "Chrome не скачался; браузерные инструменты будут недоступны."
    fi
}

install_online_components() {
    install_node_online
    install_bxmcp_online
    install_embeddings_online
    install_scrapling_online
    install_chrome_online
    if [ "$(id -u)" -eq 0 ]; then
        chown -R "$WEB_USER:$WEB_USER" "$ENV_HOME" "$SCRAPLING_DIR" "$SCRAPLING_BROWSERS_DIR" "$CHROME_DIR" 2>/dev/null || true
    fi
}

payload_has_components() {
    local p=${1:-}
    [ -n "$p" ] || return 1
    if [ -f "$p" ]; then
        case "$p" in
            *.tar.gz|*.tgz|*.tar|*.tar.zst) return 0 ;;
        esac
        return 1
    fi
    [ -d "$p/bxmcp" ] || [ -d "$p/runtime/node" ] || [ -d "$p/embeddings" ] \
        || [ -d "$p/scrapling" ] || [ -d "$p/chrome" ]
}

sources_available() {
    [ -f /opt/bitrix-mcp/dist/index.js ] \
        && [ -d /opt/bitrix-mcp/python/venv-gemma2 ] \
        && [ -d /home/bitrix/bxadmins-data/chrome ]
}

prepare_payload() {
    local payload=$1 build=$2 dev=$3 answer
    PREPARED_PAYLOAD=""
    if payload_has_components "$payload"; then
        ONLINE_MODE=0
        EMBEDDINGS_OFFLINE=1
        return 0
    fi

    if [ "$ONLINE_MODE" -eq 1 ] && [ "$build" -eq 0 ] && [ "$dev" -eq 0 ]; then
        log "Payload не задан — ставлю компоненты из сети (online-режим)."
        return 0
    fi

    if [ "$build" -eq 1 ] || sources_available; then
        if [ "$build" -eq 1 ]; then
            answer=y
        elif [ -t 0 ]; then
            printf 'Payload не найден. Собрать bundle из текущего сервера? [y/N] ' >&2
            read -r answer || true
        else
            answer=n
        fi

        if [ "$answer" = "y" ] || [ "$answer" = "Y" ]; then
            mkdir -p "$ENV_HOME"
            log "Собираю payload через bundle.sh..."
            bash "$SCRIPT_DIR/bundle.sh" --out "$ENV_HOME/payload.tar.gz" || die "bundle.sh завершился с ошибкой."
            PREPARED_PAYLOAD=$ENV_HOME/payload.tar.gz
            ONLINE_MODE=0
            EMBEDDINGS_OFFLINE=1
            return 0
        fi
    fi

    if [ "$dev" -eq 1 ]; then
        warn "Payload не найден — продолжаю в dev-режиме на уже установленных компонентах."
        ONLINE_MODE=0
        return 0
    fi

    die "Payload не найден. Соберите его на подготовленном сервере: ./bundle.sh --out bxadmins-environment.tar.gz, затем повторите: install.sh install --payload <файл>. Для dev-режима добавьте --dev."
}

install_self() {
    mkdir -p "$ENV_HOME/bin"
    cp -a "$SCRIPT_DIR/install.sh" "$ENV_HOME/bin/install.sh"
    chmod 755 "$ENV_HOME/bin/install.sh"
}

cmd_install() {
    local payload=${PAYLOAD_DIR:-}
    local no_cron=0 no_register=0
    local wait_index=0 build_payload=0 dev_mode=0
    NO_SYSTEM_DEPS=0
    while [ $# -gt 0 ]; do
        case "$1" in
            --payload) payload=${2:?}; ONLINE_MODE=0; shift 2 ;;
            --payload=*) payload=${1#*=}; ONLINE_MODE=0; shift ;;
            --site-root) BXADMINS_SITE_ROOT=${2:?}; shift 2 ;;
            --site-root=*) BXADMINS_SITE_ROOT=${1#*=}; shift ;;
            --no-cron) no_cron=1; shift ;;
            --no-register) no_register=1; shift ;;
            --no-system-deps) NO_SYSTEM_DEPS=1; shift ;;
            --wait-index) wait_index=1; shift ;;
            --build-payload) build_payload=1; ONLINE_MODE=0; shift ;;
            --dev) dev_mode=1; ONLINE_MODE=0; shift ;;
            --offline) ONLINE_MODE=0; shift ;;
            --online) ONLINE_MODE=1; shift ;;
            *) die "Неизвестный параметр: $1" ;;
        esac
    done

    [ "$(id -u)" -eq 0 ] || die "install нужно запускать от root: sudo bash \"$SCRIPT_DIR/install.sh\" install ..."

    detect_os
    detect_site
    ensure_dirs
    prepare_payload "$payload" "$build_payload" "$dev_mode"
    payload=${PREPARED_PAYLOAD:-$payload}
    install_self
    if [ "${NO_SYSTEM_DEPS:-0}" -eq 0 ]; then
        install_system_deps
    fi
    check_php_extensions
    if [ "${ONLINE_MODE:-1}" -eq 1 ] && [ "$dev_mode" -eq 0 ]; then
        install_online_components
    else
        install_payload "$payload"
        if [ -n "${PREPARED_PAYLOAD:-}" ] && [ "$PREPARED_PAYLOAD" = "$ENV_HOME/payload.tar.gz" ]; then
            rm -f "$PREPARED_PAYLOAD"
        fi
    fi
    resolve_binaries
    if [ -x "$CHROME_BIN" ] && have ldd && ldd "$CHROME_BIN" 2>/dev/null | grep -q 'not found'; then
        install_system_deps 1
    fi
    check_model
    check_ports
    write_config

    if ! install_systemd; then
        start_all
    fi
    wait_http "http://127.0.0.1:$EMBEDDING_PORT/health" "embeddings" || true
    wait_http "http://127.0.0.1:$BXMCP_PORT/health" "BxMCP" || true
    if [ -x "$SCRAPLING_BIN" ]; then
        wait_reachable "http://127.0.0.1:$SCRAPLING_PORT/mcp" "Scrapling" || true
    fi

    [ "$no_register" -eq 1 ] || register_mcp
    [ "$no_cron" -eq 1 ] || install_cron
    [ "$no_cron" -eq 1 ] || install_bitrix_cron

    if [ "$wait_index" -eq 1 ]; then
        reindex || true
    else
        nohup env BXADMINS_ENV_CONFIG="$CONFIG_FILE" bash "$ENV_HOME/bin/install.sh" reindex >> "$LOG_DIR/reindex.log" 2>&1 &
        log "Первичная индексация запущена в фоне: $LOG_DIR/reindex.log"
    fi

    log "Готово. Конфиг: $CONFIG_FILE"
    status
}

cmd_uninstall() {
    local purge=0
    [ "${1:-}" = "--purge" ] && purge=1
    if [ -f "$CONFIG_FILE" ]; then
        # shellcheck disable=SC1090
        set -a; . "$CONFIG_FILE"; set +a
    fi
    ENV_HOME=${ENV_HOME:-$DEFAULT_DATA_ROOT/environment}
    PID_DIR=${PID_DIR:-$ENV_HOME/run}
    if [ "$(id -u)" -eq 0 ] && have systemctl; then
        systemctl disable --now ${SYSTEMD_PREFIX}-embeddings.service ${SYSTEMD_PREFIX}-bxmcp.service ${SYSTEMD_PREFIX}-scrapling.service 2>/dev/null || true
        rm -f /etc/systemd/system/${SYSTEMD_PREFIX}-*.service
        systemctl daemon-reload || true
    fi
    stop_all || true
    rm -f /etc/cron.d/bxadmins-environment 2>/dev/null || true
    if have crontab; then
        crontab -l 2>/dev/null | grep -v "$CRON_MARKER" | grep -v "install.sh\" reindex" | crontab - || true
    fi
    if [ "$purge" -eq 1 ]; then
        rm -rf "$ENV_HOME"
        log "Удалён $ENV_HOME (payload и данные чата не тронуты)."
    else
        log "Сервисы и cron удалены. Данные остались в $ENV_HOME."
    fi
}

if [ $# -eq 0 ]; then
    set -- install
fi

case "${1:-}" in
    install) shift; cmd_install "$@" ;;
    -h|--help) cat <<EOF
Использование: $0 [install|start|stop|restart|status|reindex|register|uninstall]

Без аргументов запускает install.
  install [--payload DIR|TAR] [--site-root DIR] [--no-cron] [--no-register]
          [--offline|--online] [--build-payload] [--dev] [--wait-index]
  uninstall [--purge]
EOF
        exit 0 ;;
    start) start_all ;;
    stop) stop_all ;;
    restart) stop_all; start_all ;;
    status) status ;;
    reindex) reindex ;;
    register)
        detect_site
        # shellcheck disable=SC1090
        set -a; . "$CONFIG_FILE"; set +a
        resolve_binaries
        register_mcp
        ;;
    uninstall) shift; cmd_uninstall "$@" ;;
    *)
        cat <<EOF
Использование: $0 {install|start|stop|restart|status|reindex|register|uninstall}

  install [--payload DIR|TAR] [--site-root DIR] [--no-cron] [--no-register]
  uninstall [--purge]
EOF
        exit 1
        ;;
esac
