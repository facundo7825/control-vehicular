<#
.SYNOPSIS
  Compila host_prueba para la demo local: la versión web (solicitante en la compu) y el APK (chofer en el celular).

.DESCRIPTION
  - Toma la IP de la compu en la red (o la de -Ip) para que el celular y el navegador lleguen al backend.
  - Lee la clave de Reverb de backend\.env.
  - La clave de Google Maps (-MapsKey) se escribe solo en archivos ignorados por git:
      host_prueba\android\secretos.properties (Android) y build\web\index.html (web, salida de compilación).
  - Si hay un celular conectado por adb (USB o depuración inalámbrica), instala el APK.

.EXAMPLE
  .\scripts\compilar-demo.ps1 -MapsKey "AIza..."
#>
param(
  [Parameter(Mandatory = $true)][string]$MapsKey,
  [string]$Ip = ''
)
$ErrorActionPreference = 'Stop'
$raiz = Split-Path -Parent $PSScriptRoot
$host_ = Join-Path $raiz 'host_prueba'

if (-not $Ip) {
  $Ip = (Get-NetIPAddress -AddressFamily IPv4 -InterfaceAlias 'Wi-Fi*' -ErrorAction SilentlyContinue |
         Where-Object { $_.IPAddress -notlike '169.*' } | Select-Object -First 1).IPAddress
  if (-not $Ip) { throw 'No encontré la IP de la compu en el Wi-Fi. Pasala con -Ip 192.168.x.x' }
}
$reverbKey = ((Get-Content (Join-Path $raiz 'backend\.env')) | Where-Object { $_ -like 'REVERB_APP_KEY=*' }) -replace '^REVERB_APP_KEY=', '' -replace '"', ''
if (-not $reverbKey) { throw 'Falta REVERB_APP_KEY en backend\.env' }

Write-Host "IP de la compu: $Ip" -ForegroundColor Cyan
$defines = @(
  "--dart-define=API_URL=http://${Ip}:8000",
  "--dart-define=REVERB_HOST=$Ip",
  '--dart-define=REVERB_PORT=8080',
  '--dart-define=REVERB_SCHEME=http',
  "--dart-define=REVERB_APP_KEY=$reverbKey",
  "--dart-define=MAPS_API_KEY=$MapsKey"
)

Push-Location $host_
try {
  # Android: la clave va al manifiesto por un placeholder leído de este archivo (ignorado por git).
  Set-Content -Path 'android\secretos.properties' -Value "MAPS_API_KEY=$MapsKey" -Encoding ascii

  Write-Host 'Compilando la versión web (solicitante)...' -ForegroundColor Cyan
  flutter build web @defines
  if ($LASTEXITCODE -ne 0) { throw 'Falló flutter build web' }
  # Web: el mapa necesita el script de la Maps JavaScript API antes de Flutter.
  $index = 'build\web\index.html'
  $script = "<script src=`"https://maps.googleapis.com/maps/api/js?key=$MapsKey`"></script>"
  (Get-Content $index -Raw) -replace '(<script src="flutter_bootstrap.js")', "$script`r`n  `$1" | Set-Content $index -Encoding utf8

  Write-Host 'Compilando el APK (chofer)...' -ForegroundColor Cyan
  flutter build apk --debug @defines
  if ($LASTEXITCODE -ne 0) { throw 'Falló flutter build apk' }

  $adb = Join-Path $env:LOCALAPPDATA 'Android\Sdk\platform-tools\adb.exe'
  $dispositivo = (& $adb devices | Select-String '\tdevice$' | Select-Object -First 1)
  if ($dispositivo) {
    $id = ($dispositivo.ToString() -split "`t")[0]
    Write-Host "Instalando en el celular ($id)..." -ForegroundColor Cyan
    & $adb -s $id install -r 'build\app\outputs\flutter-apk\app-debug.apk'
  } else {
    Write-Host 'No hay celular conectado por adb: el APK quedó en host_prueba\build\app\outputs\flutter-apk\app-debug.apk' -ForegroundColor Yellow
  }
} finally {
  Pop-Location
}
Write-Host 'Listo. Ahora corré .\scripts\iniciar-demo.ps1' -ForegroundColor Green
