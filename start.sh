#!/usr/bin/env sh
set -eu

cd "$(dirname "$0")"

PORT="${1:-8000}"
APP_URL="http://localhost:${PORT}"

export APP_PORT="$PORT"

say() {
    printf '%s\n' "$*"
}

open_browser() {
    url="$1"
    if command -v xdg-open >/dev/null 2>&1; then
        xdg-open "$url" >/dev/null 2>&1 &
    elif command -v open >/dev/null 2>&1; then
        open "$url" >/dev/null 2>&1 &
    fi
}

say "==================================================="
say "  ЗаботаРядом — платформа взаимной помощи"
say "  MySQL + PHP в Docker"
say "==================================================="
say ""

if ! command -v docker >/dev/null 2>&1; then
    say "[ОШИБКА] Docker не найден."
    say "Установите Docker Desktop: https://www.docker.com/products/docker-desktop/"
    exit 1
fi

if ! docker info >/dev/null 2>&1; then
    say "[ОШИБКА] Docker установлен, но демон Docker не запущен."
    say "Запустите Docker Desktop (или docker daemon) и повторите попытку."
    exit 1
fi

say "[1/2] Запускаю MySQL и приложение..."
say "[2/2] Первый запуск собирает образ и может занять несколько минут."
say ""

if [ "${START_NO_BROWSER:-0}" != "1" ]; then
    if command -v curl >/dev/null 2>&1; then
        (
            i=0
            while [ "$i" -lt 180 ]; do
                if curl -fsS "${APP_URL}/api/health" >/dev/null 2>&1; then
                    open_browser "$APP_URL"
                    break
                fi
                i=$((i + 1))
                sleep 2
            done
        ) &
    else
        (sleep 10; open_browser "$APP_URL") &
    fi
fi

exec docker compose up --build
