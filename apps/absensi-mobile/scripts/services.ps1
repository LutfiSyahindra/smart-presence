[CmdletBinding()]
param([ValidateSet('start','status','stop')] [string] $Action = 'start')

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..\..'))
$mobileRoot = Join-Path $projectRoot 'apps\absensi-mobile'
$toolingRoot = Join-Path $mobileRoot '.tooling'
$logsRoot = Join-Path $toolingRoot 'logs'
$stateFile = Join-Path $toolingRoot 'services.json'

function Get-ServiceHealth {
    param([string] $Url)
    try { return (Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 10).StatusCode -eq 200 }
    catch { return $false }
}

function Get-ReverbHealth {
    $connection = [Net.Sockets.TcpClient]::new()
    try {
        $attempt = $connection.BeginConnect('127.0.0.1', 8080, $null, $null)
        if (-not $attempt.AsyncWaitHandle.WaitOne(1000)) { return $false }
        $connection.EndConnect($attempt)
        return $true
    } catch { return $false }
    finally { $connection.Dispose() }
}

if ($Action -eq 'status') {
    [PSCustomObject]@{
        Laravel = Get-ServiceHealth 'http://127.0.0.1:8600/up'
        FaceReady = Get-ServiceHealth 'http://127.0.0.1:8001/api/v1/health/ready'
        Realtime = Get-ReverbHealth
        Logs = $logsRoot
    } | Format-List
    exit 0
}

$processRecords = @()
if (Test-Path -LiteralPath $stateFile) {
    $processRecords = [array](Get-Content -LiteralPath $stateFile -Raw | ConvertFrom-Json)
}
if ($Action -eq 'stop') {
    foreach ($record in $processRecords) {
        $serviceProcess = Get-Process -Id $record.Id -ErrorAction SilentlyContinue
        if ($serviceProcess -and $serviceProcess.StartTime.ToUniversalTime().ToString('o') -eq $record.StartTime) {
            & taskkill.exe /PID $record.Id /T /F | Out-Null
            if ($LASTEXITCODE -ne 0) { throw "Gagal menghentikan service $($record.Name)." }
            Write-Output "Service $($record.Name) dihentikan."
        }
    }
    if (Test-Path -LiteralPath $stateFile) { Remove-Item -LiteralPath $stateFile }
    exit 0
}

New-Item -ItemType Directory -Path $logsRoot -Force | Out-Null
$env:TEMP = Join-Path $toolingRoot 'tmp'
$env:TMP = $env:TEMP
New-Item -ItemType Directory -Path $env:TEMP -Force | Out-Null
$env:KERAS_HOME = Join-Path $projectRoot 'services\face-verification\.keras'
$env:MPLCONFIGDIR = Join-Path $env:TEMP 'matplotlib'
$env:TORCH_HOME = Join-Path $env:TEMP 'torch'
$env:PHP_CLI_SERVER_WORKERS = '1'

$localIp = $null
foreach ($adapter in [Net.NetworkInformation.NetworkInterface]::GetAllNetworkInterfaces()) {
    $properties = $adapter.GetIPProperties()
    if ($adapter.OperationalStatus -eq 'Up' -and @($properties.GatewayAddresses | Where-Object { $_.Address.AddressFamily -eq 'InterNetwork' -and $_.Address.ToString() -ne '0.0.0.0' }).Count -gt 0) {
        $localIp = $properties.UnicastAddresses | Where-Object { $_.Address.AddressFamily -eq 'InterNetwork' -and $_.Address.ToString() -notmatch '^(127\.|169\.254\.)' } | Select-Object -First 1 -ExpandProperty Address
        if ($localIp) { break }
    }
}
if (-not $localIp) { $localIp = '127.0.0.1' }
$apiUrl = "http://${localIp}:8600/api/mobile/v1"
$mobileEnv = Join-Path $mobileRoot '.env.development.local'
$envContent = if (Test-Path -LiteralPath $mobileEnv) { [IO.File]::ReadAllText($mobileEnv) } else { '' }
if ($envContent -match '(?m)^VITE_API_URL=.*$') {
    $envContent = [regex]::Replace($envContent, '(?m)^VITE_API_URL=.*$', "VITE_API_URL=$apiUrl")
} else { $envContent += "`nVITE_API_URL=$apiUrl`n" }
[IO.File]::WriteAllText($mobileEnv, $envContent, [Text.UTF8Encoding]::new($false))

$php = (Get-Command php -ErrorAction Stop).Source
& $php (Join-Path $projectRoot 'scripts\realtime-setup.php')
if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi realtime belum berhasil disiapkan.' }
& $php artisan config:clear --no-interaction
if ($LASTEXITCODE -ne 0) { throw 'Cache konfigurasi belum berhasil diperbarui.' }
if (-not (Get-ReverbHealth)) {
    $reverb = Start-Process -FilePath $php -ArgumentList @('artisan','reverb:start','--host=127.0.0.1','--port=8080','--no-interaction') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logsRoot 'reverb.stdout.log') -RedirectStandardError (Join-Path $logsRoot 'reverb.stderr.log') -PassThru
    $processRecords += [PSCustomObject]@{Name='Reverb';Id=$reverb.Id;StartTime=$reverb.StartTime.ToUniversalTime().ToString('o')}
}
if (-not (Get-ServiceHealth 'http://127.0.0.1:8600/up')) {
    $laravel = Start-Process -FilePath $php -ArgumentList @('artisan','serve','--host=0.0.0.0','--port=8600','--no-reload') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logsRoot 'laravel.stdout.log') -RedirectStandardError (Join-Path $logsRoot 'laravel.stderr.log') -PassThru
    $processRecords += [PSCustomObject]@{Name='Laravel';Id=$laravel.Id;StartTime=$laravel.StartTime.ToUniversalTime().ToString('o')}
}
if (-not (Get-ServiceHealth 'http://127.0.0.1:8001/api/v1/health/live')) {
    $faceRoot = Join-Path $projectRoot 'services\face-verification'
    $python = Join-Path $faceRoot '.venv\Scripts\python.exe'
    if (-not (Test-Path -LiteralPath $python)) { throw 'Virtual environment service wajah belum tersedia. Ikuti services/face-verification/README.md.' }
    $face = Start-Process -FilePath $python -ArgumentList @('-m','uvicorn','app.main:app','--host','127.0.0.1','--port','8001','--workers','1') -WorkingDirectory $faceRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logsRoot 'face.stdout.log') -RedirectStandardError (Join-Path $logsRoot 'face.stderr.log') -PassThru
    $processRecords += [PSCustomObject]@{Name='Face';Id=$face.Id;StartTime=$face.StartTime.ToUniversalTime().ToString('o')}
}
$existingQueue = $processRecords | Where-Object {
    $tracked = Get-Process -Id $_.Id -ErrorAction SilentlyContinue
    $_.Name -eq 'Queue' -and $tracked -and $tracked.StartTime.ToUniversalTime().ToString('o') -eq $_.StartTime
}
if (-not $existingQueue) {
    $queue = Start-Process -FilePath $php -ArgumentList @('artisan','queue:work','--queue=attendance,notifications,default','--sleep=1','--tries=3','--timeout=60','--no-interaction') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logsRoot 'queue.stdout.log') -RedirectStandardError (Join-Path $logsRoot 'queue.stderr.log') -PassThru
    $processRecords += [PSCustomObject]@{Name='Queue';Id=$queue.Id;StartTime=$queue.StartTime.ToUniversalTime().ToString('o')}
}
$existingScheduler = $processRecords | Where-Object {
    $tracked = Get-Process -Id $_.Id -ErrorAction SilentlyContinue
    $_.Name -eq 'Scheduler' -and $tracked -and $tracked.StartTime.ToUniversalTime().ToString('o') -eq $_.StartTime
}
if (-not $existingScheduler) {
    $scheduler = Start-Process -FilePath $php -ArgumentList @('artisan','schedule:work','--no-interaction') -WorkingDirectory $projectRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logsRoot 'scheduler.stdout.log') -RedirectStandardError (Join-Path $logsRoot 'scheduler.stderr.log') -PassThru
    $processRecords += [PSCustomObject]@{Name='Scheduler';Id=$scheduler.Id;StartTime=$scheduler.StartTime.ToUniversalTime().ToString('o')}
}
ConvertTo-Json -InputObject @($processRecords) | Set-Content -LiteralPath $stateFile -Encoding UTF8
Write-Output 'Vue/Laravel development: http://localhost:8600/login'
Write-Output "API prototipe mobile: $apiUrl"
Write-Output "Log service: $logsRoot"
& (Join-Path $PSScriptRoot 'pwa-connect.ps1')
Write-Output 'Jalankan npm run services:status untuk memeriksa kesiapan. Android memakai frontend Vue/Laravel melalui adb reverse.'
