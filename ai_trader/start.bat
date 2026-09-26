@echo off
rem Локальный запуск AI Trader на Windows (для проверки). Нужен Python 3.10+ с python.org
cd /d "%~dp0"
if not exist venv (
  python -m venv venv || (echo Установите Python 3.10+ с python.org и отметьте "Add to PATH" & pause & exit /b 1)
)
venv\Scripts\python -m pip install --upgrade pip -q
venv\Scripts\python -m pip install -r requirements.txt -q
if not exist logs mkdir logs
echo Откройте http://localhost:8000/setup  (код - в файле SETUP_CODE.txt)
venv\Scripts\python run.py
pause
