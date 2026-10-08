[CmdletBinding()]
param(
    [ValidateSet('setup','start','status')] [string] $Action = 'start',
    [switch] $Headless,
    [switch] $Restart,
    [switch] $Software,
    [ValidateSet('auto','host','software','swiftshader','swangle')] [string] $Graphics = 'host'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'android-environment.ps1')
$avdName = 'RSARSY_API_36'
$avdDirectory = Join-Path $env:ANDROID_AVD_HOME ($avdName + '.avd')
$imageDirectory = Join-Path $sdkRoot 'system-images\android-36\google_apis\x86_64'
$emulator = Join-Path $sdkRoot 'emulator\emulator.exe'
if (-not (Test-Path -LiteralPath $emulator)) { throw 'Android Emulator belum terpasang pada SDK yang dipilih.' }

if ($Action -eq 'status') {
    & (Join-Path $sdkRoot 'platform-tools\adb.exe') devices -l
    & $emulator -accel-check
    exit $LASTEXITCODE
}
if (-not (Test-Path -LiteralPath (Join-Path $imageDirectory 'system.img'))) {
    if (-not $sdkManager) { throw 'Command Line Tools belum tersedia untuk memasang system image API 36.' }
    & $sdkManager "--sdk_root=$sdkRoot" --install 'system-images;android-36;google_apis;x86_64'
    if ($LASTEXITCODE -ne 0) { throw 'Pemasangan system image API 36 gagal.' }
}
if (-not (Test-Path -LiteralPath (Join-Path $avdDirectory 'config.ini'))) {
    $avdManager = Join-Path (Split-Path -Parent $sdkManager) 'avdmanager.bat'
    'no' | & $avdManager create avd -n $avdName -k 'system-images;android-36;google_apis;x86_64' -d 'pixel_7' -p $avdDirectory
    if ($LASTEXITCODE -ne 0) { throw 'Pembuatan emulator API 36 gagal.' }
}
if ($Action -eq 'setup') {
    Write-Output "Emulator siap: $avdName ($avdDirectory)"
    exit 0
}
$adb = Join-Path $sdkRoot 'platform-tools\adb.exe'
$runningSerial = $null
foreach ($deviceLine in (& $adb devices)) {
    if ($deviceLine -match '^(emulator-\d+)\s+(device|offline)\b') {
        $serial = $Matches[1]
        # Read the Android property first: emulator-console output can be empty
        # when launched from a redirected Windows terminal.
        $runningAvd = (& $adb -s $serial shell getprop ro.boot.qemu.avd_name 2>$null | Out-String).Trim()
        if ($runningAvd -ne $avdName) {
            $consoleName = & $adb -s $serial emu avd name 2>&1
            $runningAvd = ($consoleName | Where-Object { $_.ToString().Trim() -eq $avdName } | Select-Object -First 1)
        }
        if ($runningAvd -and $runningAvd.ToString().Trim() -eq $avdName) {
            $runningSerial = $serial
            break
        }
    }
}
if ($runningSerial) {
    if (-not $Restart) {
        Write-Output "Emulator $avdName sudah berjalan ($runningSerial)."
        Write-Output 'Jika jendelanya tidak terlihat, jalankan npm run android:emulator -- -Restart untuk membukanya kembali.'
        exit 0
    }
    & $adb -s $runningSerial emu kill
    if ($LASTEXITCODE -ne 0) { throw 'Gagal menutup emulator proyek untuk membuka kembali jendelanya.' }
    $stopDeadline = [DateTime]::UtcNow.AddSeconds(20)
    do {
        Start-Sleep -Milliseconds 500
        $stillOnline = @(& $adb devices | Where-Object { $_ -match ('^' + [regex]::Escape($runningSerial) + '\s') }).Count -gt 0
    } while ($stillOnline -and [DateTime]::UtcNow -lt $stopDeadline)
    if ($stillOnline) { throw 'Emulator masih menutup. Tunggu sebentar lalu jalankan kembali perintah -Restart.' }
    Start-Sleep -Seconds 2
}
if ($Restart -and -not $Headless) {
    $windowSettings = Join-Path $avdDirectory 'emulator-user.ini'
    if (Test-Path -LiteralPath $windowSettings) {
        $windowText = [IO.File]::ReadAllText($windowSettings)
        foreach ($setting in @{ 'window.x'='80'; 'window.y'='40'; 'window.scale'='-1.000000' }.GetEnumerator()) {
            $pattern = '(?m)^' + [regex]::Escape($setting.Key) + '\s*=.*$'
            if ($windowText -match $pattern) { $windowText = [regex]::Replace($windowText, $pattern, ($setting.Key + ' = ' + $setting.Value)) }
            else { $windowText += "`n$($setting.Key) = $($setting.Value)`n" }
        }
        [IO.File]::WriteAllText($windowSettings, $windowText, [Text.UTF8Encoding]::new($false))
    }
}
$acceleration = & $emulator -accel-check 2>&1
if ($LASTEXITCODE -ne 0 -and -not $Software) {
    $guidance = 'Jalankan npm run android:hypervisor, setujui Administrator, lalu pilih Restart pada Windows.'
    $reportPath = Join-Path $toolingRoot 'hypervisor-status.json'
    if (Test-Path -LiteralPath $reportPath) {
        $hypervisorStatus = Get-Content -LiteralPath $reportPath | ConvertFrom-Json
        $bootTime = (Get-CimInstance Win32_OperatingSystem -ErrorAction SilentlyContinue).LastBootUpTime
        if ($hypervisorStatus.RestartNeeded -and $hypervisorStatus.CheckedAt -and $bootTime -and
            ([DateTimeOffset]::Parse($hypervisorStatus.CheckedAt).LocalDateTime -gt $bootTime)) {
            $guidance = 'Pengaturan hypervisor sudah diperbarui setelah boot terakhir. Simpan pekerjaan dan pilih Restart pada Windows, lalu jalankan kembali service dan emulator.'
        }
    }
    throw ("Akselerasi emulator belum tersedia. $guidance`nHasil emulator -accel-check:`n" + ($acceleration -join "`n"))
}
# Pending crash reports can show a consent dialog before Android even boots.
# Keep project launches independent of crash reporting and never submit reports.
$arguments = @('-avd',$avdName,'-no-snapshot','-memory','2048','-cores','2','-gpu',$Graphics,'-feature','-Vulkan','-no-boot-anim','-no-audio','-crash-report-mode','disabled')
if ($Headless) { $arguments += '-no-window' }
if ($Software) { $arguments += @('-accel','off') }
$logsRoot = Join-Path $toolingRoot 'logs'
New-Item -ItemType Directory -Path $logsRoot -Force | Out-Null
$windowStyle = if ($Headless) { 'Hidden' } else { 'Normal' }
$emulatorProcess = Start-Process -FilePath $emulator -ArgumentList $arguments -WindowStyle $windowStyle -RedirectStandardOutput (Join-Path $logsRoot 'emulator.stdout.log') -RedirectStandardError (Join-Path $logsRoot 'emulator.stderr.log') -PassThru
Start-Sleep -Seconds 5
$emulatorProcess.Refresh()
if ($emulatorProcess.HasExited) { throw "Emulator berhenti saat mulai (exit $($emulatorProcess.ExitCode)). Periksa $logsRoot\emulator.stderr.log." }
Write-Output "Emulator $avdName dijalankan (grafis: $Graphics). Tunggu boot Android selesai. Log: $logsRoot"
