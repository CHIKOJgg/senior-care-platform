#!/bin/sh
set -e

echo "==================================================="
echo "  Запуск «ЗаботаРядом» (MySQL + PHP)"
echo "==================================================="
echo
echo "Запускаю development-стек: MySQL 8 + PHP 8.2."
echo "После старта откройте http://localhost:8000"
echo "Остановка: Ctrl+C, затем 'docker compose down'."
echo
exec docker compose up --build
