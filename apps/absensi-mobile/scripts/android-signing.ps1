[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'android-environment.ps1')

$signingDirectory = Join-Path $toolingRoot 'signing'
$propertiesFile = Join-Path $signingDirectory 'release-signing.properties'
$keystoreFile = Join-Path $signingDirectory 'arsy-presensi-release.p12'

if (Test-Path -LiteralPath $propertiesFile) {
    if (-not (Test-Path -LiteralPath $keystoreFile)) { throw 'Keystore release hilang. Pulihkan backup signing; kunci tidak diganti otomatis.' }
    Write-Output 'Menggunakan kunci signing release yang sudah ada.'
    return
}
if (Test-Path -LiteralPath $keystoreFile) { throw 'Keystore sudah ada tanpa file properties. Pulihkan backup properties; keystore tidak ditimpa.' }

New-Item -ItemType Directory -Path $signingDirectory -Force | Out-Null
$randomBytes = New-Object byte[] 32
$random = [Security.Cryptography.RandomNumberGenerator]::Create()
try { $random.GetBytes($randomBytes) } finally { $random.Dispose() }
$password = [BitConverter]::ToString($randomBytes).Replace('-', '').ToLowerInvariant()
$env:RSARSY_SIGNING_PASSWORD = $password
try {
    & (Join-Path $env:JAVA_HOME 'bin\keytool.exe') -genkeypair -noprompt -storetype PKCS12 `
        -keystore $keystoreFile -alias arsy-presensi -keyalg RSA -keysize 3072 -validity 10000 `
        -storepass:env RSARSY_SIGNING_PASSWORD -keypass:env RSARSY_SIGNING_PASSWORD `
        -dname 'CN=RSARSY Presence, O=RS ARSY Lamongan, C=ID'
    if ($LASTEXITCODE -ne 0) { throw 'Pembuatan keystore release gagal.' }

    $properties = "storeFile=arsy-presensi-release.p12`nstorePassword=$password`nkeyAlias=arsy-presensi`nkeyPassword=$password`n"
    [IO.File]::WriteAllText($propertiesFile, $properties, (New-Object Text.UTF8Encoding($false)))
    Write-Output 'Kunci signing release dibuat di .tooling/signing. Simpan backup seluruh folder ini untuk update APK berikutnya.'
} finally {
    Remove-Item Env:\RSARSY_SIGNING_PASSWORD -ErrorAction SilentlyContinue
    $password = $null
}
