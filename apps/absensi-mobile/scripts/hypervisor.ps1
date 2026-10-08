[CmdletBinding()]
param([ValidateSet('enable','status')] [string] $Action = 'enable')

$ErrorActionPreference = 'Stop'
$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = [Security.Principal.WindowsPrincipal]::new($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    # Windows asks for UAC consent; the script never restarts the computer.
    $elevatedProcess = Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile','-ExecutionPolicy','Bypass','-File',('"' + $PSCommandPath + '"'),'-Action',$Action) -Verb RunAs -WindowStyle Hidden -Wait -PassThru
    if ($elevatedProcess.ExitCode -ne 0) { throw 'Pemeriksaan/pengaktifan hypervisor gagal. Periksa .tooling/hypervisor-status.json dan izin Administrator.' }
} else {
    $toolingRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\.tooling'))
    New-Item -ItemType Directory -Path $toolingRoot -Force | Out-Null
    $reportPath = Join-Path $toolingRoot 'hypervisor-status.json'
    $report = [ordered]@{
        CheckedAt = (Get-Date).ToString('o')
        Action = $Action
        Features = @()
        Enabled = $false
        PreviousBootLaunchType = $null
        BootLaunchType = $null
        HypervisorPresent = $false
        RestartNeeded = $false
        Error = $null
    }
    try {
        $bcdEdit = Join-Path $env:SystemRoot 'System32\bcdedit.exe'
        $bootOutput = & $bcdEdit /enum '{current}' 2>&1
        if ($LASTEXITCODE -ne 0) { throw ('Pembacaan konfigurasi boot gagal: ' + ($bootOutput -join ' ')) }
        $bootMatch = [regex]::Match(($bootOutput -join "`n"), '(?im)^\s*hypervisorlaunchtype\s+(\S+)')
        $report.PreviousBootLaunchType = if ($bootMatch.Success) { $bootMatch.Groups[1].Value } else { 'Default' }
        $report.BootLaunchType = $report.PreviousBootLaunchType
        foreach ($featureName in @('HypervisorPlatform','VirtualMachinePlatform')) {
            $feature = Get-WindowsOptionalFeature -Online -FeatureName $featureName
            if ($Action -eq 'enable' -and $feature.State -ne 'Enabled') {
                $result = Enable-WindowsOptionalFeature -Online -FeatureName $featureName -All -NoRestart
                $report.RestartNeeded = $report.RestartNeeded -or $result.RestartNeeded
                $feature = Get-WindowsOptionalFeature -Online -FeatureName $featureName
            }
            $report.Features += [pscustomobject]@{Name=$featureName;State=$feature.State.ToString()}
        }
        $report.Enabled = @($report.Features | Where-Object { $_.State -ne 'Enabled' }).Count -eq 0
        if ($Action -eq 'enable' -and $report.BootLaunchType -ne 'Auto') {
            $bootOutput = & $bcdEdit /set '{current}' hypervisorlaunchtype Auto 2>&1
            if ($LASTEXITCODE -ne 0) { throw ('Pengaktifan hypervisor saat boot gagal: ' + ($bootOutput -join ' ')) }
            $report.BootLaunchType = 'Auto'
            $report.RestartNeeded = $true
        }
        $report.HypervisorPresent = [bool](Get-CimInstance Win32_ComputerSystem).HypervisorPresent
        $report.RestartNeeded = $report.RestartNeeded -or (Test-Path 'HKLM:\SOFTWARE\Microsoft\Windows\CurrentVersion\Component Based Servicing\RebootPending')
    } catch {
        $report.Error = $_.Exception.Message
        [pscustomobject]$report | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $reportPath -Encoding UTF8
        throw
    }
    [pscustomobject]$report | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $reportPath -Encoding UTF8
}

$reportPath = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\.tooling\hypervisor-status.json'))
$status = Get-Content -LiteralPath $reportPath | ConvertFrom-Json
$status.Features | Format-Table Name, State -AutoSize
Write-Output ('Hypervisor saat boot: ' + $status.BootLaunchType)
Write-Output ('Hypervisor berjalan sekarang: ' + $status.HypervisorPresent)
if ($status.RestartNeeded) { Write-Output 'Simpan pekerjaan, lalu pilih Restart pada Windows. Setelah itu jalankan kembali service dan emulator.' }
