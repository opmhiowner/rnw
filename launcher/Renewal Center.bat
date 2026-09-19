@echo off
rem Renewal Center - opens the three windows on their monitors.
powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%~dp0launch-renewal.ps1"
