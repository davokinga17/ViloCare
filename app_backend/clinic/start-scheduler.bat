@echo off
REM ViLoCare clinic scheduler launcher.
REM Runs `php artisan schedule:work` (sync every minute, SMS queue every 5 min, due reminders at 08:00)
REM and restarts it if it ever exits. Output goes to storage\logs\scheduler.log.
REM
REM Uses PHP from PATH, or set VILOCARE_PHP to a full php.exe path.

setlocal
cd /d "%~dp0.."

if "%VILOCARE_PHP%"=="" (set "PHP=php") else (set "PHP=%VILOCARE_PHP%")

if not exist storage\logs mkdir storage\logs

:loop
echo [%date% %time%] Starting scheduler>> storage\logs\scheduler.log
"%PHP%" artisan schedule:work >> storage\logs\scheduler.log 2>&1
echo [%date% %time%] Scheduler exited with code %errorlevel%, restarting in 10s>> storage\logs\scheduler.log
timeout /t 10 /nobreak >nul
goto loop
