# Activa los volcados de memoria de Windows (WER LocalDumps) para KaraFunPlayer.exe.
# Si KaraFun se cierra por un fallo, Windows deja un volcado completo en C:\KaraFunDumps (máx. 5) para analizar la causa.
# Requiere administrador. Para quitarlo: Remove-Item 'HKLM:\SOFTWARE\Microsoft\Windows\Windows Error Reporting\LocalDumps\KaraFunPlayer.exe'
$ErrorActionPreference = 'Stop'
$k = 'HKLM:\SOFTWARE\Microsoft\Windows\Windows Error Reporting\LocalDumps\KaraFunPlayer.exe'
New-Item -ItemType Directory -Force 'C:\KaraFunDumps' | Out-Null
New-Item $k -Force | Out-Null
New-ItemProperty $k -Name DumpFolder -PropertyType ExpandString -Value 'C:\KaraFunDumps' -Force | Out-Null
New-ItemProperty $k -Name DumpType -PropertyType DWord -Value 2 -Force | Out-Null   # 2 = volcado completo
New-ItemProperty $k -Name DumpCount -PropertyType DWord -Value 5 -Force | Out-Null
Get-ItemProperty $k | Select-Object DumpFolder, DumpType, DumpCount | Format-List
'Volcados de KaraFun activados.'
