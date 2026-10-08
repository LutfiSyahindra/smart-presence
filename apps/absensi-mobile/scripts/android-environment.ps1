[CmdletBinding()]
param([switch] $EmitBatch)

$ErrorActionPreference = 'Stop'
$mobileRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$androidRoot = Join-Path $mobileRoot 'android'
$toolingRoot = Join-Path $mobileRoot '.tooling'
$androidUserHome = Join-Path $toolingRoot 'android-user-home'
$temporaryRoot = Join-Path $toolingRoot 'tmp'
$gradleUserHome = Join-Path $mobileRoot '.gradle-user-home'
$npmCache = Join-Path $mobileRoot '.npm-cache'

function Get-JavaProperty {
    param([string] $File, [string] $Name)
    if (-not (Test-Path -LiteralPath $File)) { return $null }
    foreach ($line in Get-Content -LiteralPath $File) {
        if ($line -match ('^\s*' + [regex]::Escape($Name) + '\s*=(.*)$')) {
            return $Matches[1].Trim().Replace('\\', '\').Replace('\:', ':').Replace('\ ', ' ')
        }
    }
    return $null
}

function Test-Jdk21 {
    param([string] $Directory)
    if (-not $Directory -or -not (Test-Path -LiteralPath (Join-Path $Directory 'bin\java.exe'))) { return $false }
    $releaseFile = Join-Path $Directory 'release'
    return (Test-Path -LiteralPath $releaseFile) -and
        [bool](Select-String -LiteralPath $releaseFile -Pattern '^JAVA_VERSION="21[\."]' -Quiet)
}

$jdkCandidates = @(
    $env:MOBILE_JAVA_HOME,
    (Get-JavaProperty (Join-Path $androidRoot '.gradle\config.properties') 'java.home')
)
foreach ($parentDirectory in @((Join-Path $toolingRoot 'jdk'), (Join-Path $env:USERPROFILE '.jdks'))) {
    if (Test-Path -LiteralPath $parentDirectory) {
        $jdkCandidates += @(Get-ChildItem -LiteralPath $parentDirectory -Directory | Sort-Object Name -Descending | Select-Object -ExpandProperty FullName)
    }
}
$jdkCandidates += $env:JAVA_HOME
if ($env:MOBILE_JAVA_HOME -and -not (Test-Jdk21 $env:MOBILE_JAVA_HOME)) {
    throw 'MOBILE_JAVA_HOME harus menunjuk ke JDK 21.'
}
$jdkRoot = $jdkCandidates | Where-Object { Test-Jdk21 $_ } | Select-Object -First 1
if (-not $jdkRoot) { throw 'JDK 21 belum ditemukan. Pilih JDK 21 pada Gradle JDK di Android Studio atau set MOBILE_JAVA_HOME khusus terminal ini.' }

function Test-AndroidSdk {
    param([string] $Directory)
    return $Directory -and (Test-Path -LiteralPath (Join-Path $Directory 'platforms\android-36\android.jar'))
}

$sdkCandidates = @(
    $env:MOBILE_ANDROID_HOME,
    (Get-JavaProperty (Join-Path $androidRoot 'local.properties') 'sdk.dir'),
    (Join-Path $toolingRoot 'android-sdk'),
    $env:ANDROID_HOME,
    (Join-Path $env:LOCALAPPDATA 'Android\Sdk')
)
if ($env:MOBILE_ANDROID_HOME -and -not (Test-AndroidSdk $env:MOBILE_ANDROID_HOME)) {
    throw 'MOBILE_ANDROID_HOME harus menunjuk ke SDK yang memiliki Android API 36.'
}
$sdkRoot = $sdkCandidates | Where-Object { Test-AndroidSdk $_ } | Select-Object -First 1
if (-not $sdkRoot) { throw 'SDK Platform API 36 belum ditemukan. Pasang melalui SDK Manager di Android Studio.' }
foreach ($component in @('platform-tools\adb.exe', 'build-tools\35.0.0\aapt2.exe')) {
    if (-not (Test-Path -LiteralPath (Join-Path $sdkRoot $component))) {
        throw "Komponen SDK belum tersedia: $component. Pasang melalui SDK Manager."
    }
}

# Command Line Tools may be kept in the repository while the installed SDK is
# managed by Android Studio. sdkmanager always receives the selected SDK root.
$sdkManager = @(
    (Join-Path $sdkRoot 'cmdline-tools\latest\bin\sdkmanager.bat'),
    (Join-Path $toolingRoot 'android-sdk\cmdline-tools\latest\bin\sdkmanager.bat')
) | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1

foreach ($directory in @($androidUserHome, $temporaryRoot, $gradleUserHome, $npmCache, (Join-Path $androidUserHome 'avd'))) {
    New-Item -ItemType Directory -Path $directory -Force | Out-Null
}
$env:JAVA_HOME = $jdkRoot
$env:ANDROID_HOME = $sdkRoot
$env:ANDROID_SDK_ROOT = $sdkRoot
$env:ANDROID_USER_HOME = $androidUserHome
$env:ANDROID_AVD_HOME = Join-Path $androidUserHome 'avd'
$env:GRADLE_USER_HOME = $gradleUserHome
$env:NPM_CONFIG_CACHE = $npmCache
$env:TEMP = $temporaryRoot
$env:TMP = $temporaryRoot

if (-not $env:CAPACITOR_ANDROID_STUDIO_PATH) {
    $studioRoot = (Get-ItemProperty -LiteralPath 'HKLM:\SOFTWARE\Android Studio' -Name Path -ErrorAction SilentlyContinue).Path
    if ($studioRoot) {
        $studioExecutable = Join-Path $studioRoot 'bin\studio64.exe'
        if (Test-Path -LiteralPath $studioExecutable) { $env:CAPACITOR_ANDROID_STUDIO_PATH = $studioExecutable }
    }
}

if ($EmitBatch) {
    foreach ($name in @('JAVA_HOME','ANDROID_HOME','ANDROID_SDK_ROOT','ANDROID_USER_HOME','ANDROID_AVD_HOME','GRADLE_USER_HOME','TEMP','TMP')) {
        Write-Output ($name + '=' + [Environment]::GetEnvironmentVariable($name, 'Process'))
    }
    Write-Output 'RSARSY_ANDROID_ENV_READY=true'
}
