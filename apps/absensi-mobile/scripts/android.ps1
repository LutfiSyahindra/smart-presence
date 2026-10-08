[CmdletBinding()]
param(
    [ValidateSet('apk', 'run', 'open', 'sync', 'sync-all', 'doctor', 'dev')]
    [string] $Action = 'apk',

    [switch] $Development,

    [string] $Target
)

$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'android-environment.ps1')

$projectRoot = [IO.Path]::GetFullPath((Join-Path $mobileRoot '..\..'))

function Invoke-CheckedCommand {
    param(
        [Parameter(Mandatory)]
        [string] $Command,

        [Parameter(ValueFromRemainingArguments)]
        [string[]] $Arguments
    )

    & $Command @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Perintah gagal dengan exit code ${LASTEXITCODE}: $Command $($Arguments -join ' ')"
    }
}

function Invoke-Capacitor {
    param(
        [Parameter(ValueFromRemainingArguments)]
        [string[]] $Arguments
    )

    $capacitorEntryPoint = Join-Path $mobileRoot 'node_modules\@capacitor\cli\bin\capacitor'
    Invoke-CheckedCommand $mobileNode $capacitorEntryPoint @Arguments
}

# Reuse the isolated Node 22 runtime, without changing the computer's Node.
$mobileNode = $null
$nodeCacheRoot = Join-Path $npmCache '_npx'
if (Test-Path -LiteralPath $nodeCacheRoot) {
    foreach ($cacheDirectory in Get-ChildItem -LiteralPath $nodeCacheRoot -Directory) {
        $nodePackageFile = Join-Path $cacheDirectory.FullName 'node_modules\node\package.json'
        if (Test-Path -LiteralPath $nodePackageFile) {
            $nodeVersion = [version](Get-Content -LiteralPath $nodePackageFile -Raw | ConvertFrom-Json).version
            $nodeExecutable = Join-Path $cacheDirectory.FullName 'node_modules\node\bin\node.exe'
            if ($nodeVersion.Major -eq 22 -and $nodeVersion.Minor -ge 12 -and (Test-Path -LiteralPath $nodeExecutable)) {
                $mobileNode = $nodeExecutable
                break
            }
        }
    }
}
if (-not $mobileNode) {
    $nodeLocation = & npm.cmd exec --yes --package=node@22 -- node -p 'process.execPath'
    if ($LASTEXITCODE -ne 0) { throw 'Node 22 khusus proyek gagal disiapkan.' }
    $mobileNode = [string](@($nodeLocation)[-1])
}
$npmExecutable = (Get-Command npm.cmd -ErrorAction Stop).Source
$npmCli = Join-Path (Split-Path -Parent $npmExecutable) 'node_modules\npm\bin\npm-cli.js'
if (-not (Test-Path -LiteralPath $npmCli)) { throw "npm CLI tidak ditemukan: $npmCli" }
$env:PATH = (Split-Path -Parent $mobileNode) + ';' + (Join-Path $sdkRoot 'platform-tools') + ';' + $env:PATH

Push-Location $mobileRoot
try {
    if ($Action -eq 'doctor') {
        Write-Output "Mobile root       : $mobileRoot"
        Write-Output "Java home         : $env:JAVA_HOME"
        Write-Output "Android SDK       : $env:ANDROID_HOME"
        Write-Output "Node mobile       : $mobileNode"
        Write-Output "SDK Platform      : Android API 36"
        Write-Output "Build Tools       : 35.0.0"
        Write-Output "Android user home : $env:ANDROID_USER_HOME"
        Write-Output "Gradle user home  : $env:GRADLE_USER_HOME"
        Write-Output "npm cache         : $env:NPM_CONFIG_CACHE"
        Write-Output "Temporary files   : $env:TEMP"
        Invoke-CheckedCommand (Join-Path $env:JAVA_HOME 'bin\java.exe') '-version'
        Invoke-CheckedCommand $mobileNode '--version'
        if ($sdkManager) {
            Write-Output "Command Line Tools: $sdkManager"
            Invoke-CheckedCommand $sdkManager "--sdk_root=$sdkRoot" '--version'
        }
        Invoke-CheckedCommand (Join-Path $androidRoot 'gradlew.bat') '--version'
        exit 0
    }

    if ($Action -eq 'dev') {
        # HMR assets and WebSocket use device localhost through adb reverse.
        $env:VITE_HMR_HOST = 'localhost'
        & (Join-Path $PSScriptRoot 'services.ps1') -Action start
        & (Join-Path $PSScriptRoot 'pwa-connect.ps1') -Vite
        Invoke-CheckedCommand $mobileNode $npmCli '--prefix' $projectRoot 'run' 'dev' '--' '--port' '5173' '--strictPort'
        exit 0
    }

    # Build the actual Laravel/Vue frontend, shared with the browser. The old
    # mobile SPA in src/ is retained as a prototype, but is never packaged here.
    Invoke-CheckedCommand $mobileNode $npmCli '--prefix' $projectRoot 'run' 'build'

    if ($Action -eq 'sync-all') { Invoke-Capacitor 'sync' }
    else { Invoke-Capacitor 'sync' 'android' }

    switch ($Action) {
        'sync' {
            break
        }
        'sync-all' {
            break
        }
        'open' {
            Invoke-Capacitor 'open' 'android'
        }
        'run' {
            $adb = Join-Path $sdkRoot 'platform-tools\adb.exe'
            $devices = @(& $adb devices | Select-Object -Skip 1 | Where-Object { $_ -match '^\S+\s+device(?:\s|$)' } | ForEach-Object { ($_ -split '\s+')[0] })
            if ($Target) {
                if ($Target -notin $devices) { throw "Perangkat $Target belum online di adb." }
            } elseif ($devices.Count -eq 1) {
                $Target = $devices[0]
            } else {
                throw 'Nyalakan satu emulator/HP, atau pilih dengan npm run android:run -- -Target SERIAL.'
            }
            $origin = if ($env:CAPACITOR_PWA_URL) { [Uri]$env:CAPACITOR_PWA_URL } else { [Uri]'http://localhost:8600' }
            if ($origin.IsLoopback) {
                & (Join-Path $PSScriptRoot 'pwa-connect.ps1') -Target $Target
                try { $ready = (Invoke-WebRequest 'http://127.0.0.1:8600/up' -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200 }
                catch { $ready = $false }
                if (-not $ready) { throw 'Laravel belum aktif. Jalankan npm run services:start terlebih dahulu.' }
            }
            Push-Location $androidRoot
            try {
                Invoke-CheckedCommand (Join-Path $androidRoot 'gradlew.bat') '--gradle-user-home' $gradleUserHome 'assembleDebug'
            } finally { Pop-Location }
            $apk = Join-Path $androidRoot 'app\build\outputs\apk\debug\app-debug.apk'
            Invoke-CheckedCommand $adb '-s' $Target 'install' '-r' $apk
            Invoke-CheckedCommand $adb '-s' $Target 'shell' 'am' 'force-stop' 'id.rsarsy.presensi'
            Invoke-CheckedCommand $adb '-s' $Target 'shell' 'am' 'start' '-n' 'id.rsarsy.presensi/.MainActivity'
        }
        'apk' {
            Push-Location $androidRoot
            try {
                Invoke-CheckedCommand (Join-Path $androidRoot 'gradlew.bat') '--gradle-user-home' $gradleUserHome 'assembleDebug'
                Write-Output 'APK development memakai Vue/Laravel yang sama dengan browser. Untuk emulator/USB, jalankan npm run android:connect.'
            } finally {
                Pop-Location
            }
        }
    }
} finally {
    Pop-Location
}
