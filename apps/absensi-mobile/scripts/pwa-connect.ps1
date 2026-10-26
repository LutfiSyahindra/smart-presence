[CmdletBinding()]
param([string] $Target, [switch] $Vite)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'android-environment.ps1')
$adb = Join-Path $sdkRoot 'platform-tools\adb.exe'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $mobileRoot '..\..'))
$hotFile = Join-Path $projectRoot 'public\hot'
if (Test-Path -LiteralPath $hotFile) {
    $hotUrl = $null
    if ([Uri]::TryCreate([IO.File]::ReadAllText($hotFile).Trim(), [UriKind]::Absolute, [ref]$hotUrl) -and $hotUrl.IsLoopback -and $hotUrl.Port -eq 5173) {
        $Vite = $true
    }
}
$devices = @(& $adb devices | Select-Object -Skip 1 | Where-Object { $_ -match '^\S+\s+device(?:\s|$)' } | ForEach-Object { ($_ -split '\s+')[0] })
if ($LASTEXITCODE -ne 0) { throw 'Gagal membaca perangkat Android.' }
if ($Target) {
    if ($Target -notin $devices) { throw "Perangkat $Target belum online di adb." }
    $devices = @($Target)
}
foreach ($serial in $devices) {
    & $adb -s $serial reverse 'tcp:8600' 'tcp:8600'
    if ($LASTEXITCODE -ne 0) { throw "Gagal menghubungkan Laravel ke $serial." }
    & $adb -s $serial reverse 'tcp:8080' 'tcp:8080'
    if ($LASTEXITCODE -ne 0) { throw "Gagal menghubungkan WebSocket realtime ke $serial." }
    if ($Vite) {
        & $adb -s $serial reverse 'tcp:5173' 'tcp:5173'
        if ($LASTEXITCODE -ne 0) { throw "Gagal menghubungkan Vite ke $serial." }
    }
    Write-Output "Vue/Laravel tersambung ke ${serial}: http://localhost:8600"
}
if ($devices.Count -eq 0) {
    Write-Output 'Belum ada Android online. Setelah emulator/HP menyala, jalankan npm run android:connect.'
}
