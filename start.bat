@echo off
setlocal EnableExtensions
chcp 65001 >nul 2>&1
title ЗаботаРядом — запуск (MySQL)
cd /d "%~dp0"

set "PORT=%~1"
if not defined PORT set "PORT=8000"
set "APP_URL=http://localhost:%PORT%"
set "APP_PORT=%PORT%"
set "EXIT_CODE=0"

echo ===================================================
echo   ЗаботаРядом — платформа взаимной помощи
echo   MySQL + PHP в Docker
echo ===================================================
echo.

where docker >nul 2>&1
if errorlevel 1 goto :no_docker

docker info >nul 2>&1
if errorlevel 1 goto :docker_not_running

echo [1/2] Запускаю MySQL и приложение...
echo [2/2] Первый запуск собирает образ и может занять несколько минут.
echo.
if defined START_NO_BROWSER goto :compose_up
start "" /b powershell -NoProfile -WindowStyle Hidden -Command "for($i=0;$i -lt 180;$i++){try{$r=Invoke-WebRequest -UseBasicParsing -Uri '%APP_URL%/api/health' -TimeoutSec 3; if($r.StatusCode -eq 200){Start-Process '%APP_URL%'; break}}catch{}; Start-Sleep -Seconds 2}"

:compose_up
docker compose up --build
set "EXIT_CODE=%ERRORLEVEL%"
goto :done

:docker_not_running
echo [ОШИБКА] Docker установлен, но Docker Desktop не запущен.
echo Запустите Docker Desktop и повторите попытку.
set "EXIT_CODE=1"
goto :done

:no_docker
echo [ОШИБКА] Docker не найден.
echo Установите Docker Desktop: https://www.docker.com/products/docker-desktop/
set "EXIT_CODE=1"
goto :done

:done
echo.
if not "%EXIT_CODE%"=="0" echo Завершено с кодом %EXIT_CODE%.
echo Окно можно закрыть.
pause
exit /b %EXIT_CODE%
