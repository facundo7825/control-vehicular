<#
.SYNOPSIS
  Levanta todo lo necesario para la demo local, cada proceso en su propia ventana.

.DESCRIPTION
  - Backend (php artisan serve) en 0.0.0.0:8000, accesible desde el celular por la red.
  - Reverb (tiempo real) en 0.0.0.0:8080.
  - La cola (eventos y avisos) y el scheduler (alertas y estado de choferes cada minuto).
  - La app de prueba web (solicitante) en http://localhost:5000, servida desde host_prueba\build\web.
  Con -Reiniciar, borra la base local y la vuelve a crear con los datos de demo (DemoSeeder).
  Para cortar todo, cerrá las ventanas que abre este script.

.EXAMPLE
  .\scripts\iniciar-demo.ps1 -Reiniciar
#>
param([switch]$Reiniciar)
$ErrorActionPreference = 'Stop'
$raiz = Split-Path -Parent $PSScriptRoot
$backend = Join-Path $raiz 'backend'
$web = Join-Path $raiz 'host_prueba\build\web'

if ($Reiniciar) {
  Write-Host 'Reiniciando la base local con los datos de demo...' -ForegroundColor Cyan
  Push-Location $backend
  try {
    php artisan migrate:fresh --seed --seeder=DemoSeeder --force
    if ($LASTEXITCODE -ne 0) { throw 'Falló migrate:fresh' }
    php artisan cache:clear | Out-Null
  } finally { Pop-Location }
}

if (-not (Test-Path (Join-Path $web 'index.html'))) {
  throw 'No está compilada la app web. Corré primero .\scripts\compilar-demo.ps1 -MapsKey "..."'
}

function Abrir([string]$titulo, [string]$carpeta, [string]$comando) {
  Start-Process powershell -WorkingDirectory $carpeta -ArgumentList @(
    '-NoExit', '-Command', "`$Host.UI.RawUI.WindowTitle = '$titulo'; $comando"
  ) | Out-Null
}

Abrir 'Backend :8000' $backend 'php artisan serve --host=0.0.0.0 --port=8000'
Abrir 'Reverb :8080' $backend 'php artisan reverb:start --host=0.0.0.0 --port=8080'
Abrir 'Cola' $backend 'php artisan queue:work --sleep=1 --tries=3'
Abrir 'Scheduler' $backend 'php artisan schedule:work'
Abrir 'App web (solicitante) :5000' $web 'php -S 0.0.0.0:5000'

$ip = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias 'Wi-Fi*' -ErrorAction SilentlyContinue |
       Where-Object { $_.IPAddress -notlike '169.*' } | Select-Object -First 1).IPAddress
Start-Sleep -Seconds 3
Write-Host ''
Write-Host 'Todo levantado:' -ForegroundColor Green
Write-Host "  Solicitante (esta compu):   http://localhost:5000"
Write-Host "  Panel de administración:    http://localhost:8000/admin   (admin@demo.local)"
Write-Host "  Backend para el celular:    http://${ip}:8000"
Write-Host '  Base de datos (SQLite):     backend\database\database.sqlite'
Write-Host ''
Start-Process 'http://localhost:5000'
Start-Process 'http://localhost:8000/admin'
