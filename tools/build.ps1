# Kullanım:  powershell -File tools\build.ps1
# Çıktı: dist\funbreak-public.zip (belge kökü: muhasebe.funbreak.com.tr) ve dist\funbreak-app.zip (web kökü DIŞINA yüklenir)
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$dist = Join-Path $root 'dist'
if (Test-Path $dist) { Remove-Item $dist -Recurse -Force }
New-Item -ItemType Directory -Force "$dist\public", "$dist\app" | Out-Null
Copy-Item "$root\public\*" "$dist\public" -Recurse -Force -Exclude 'app_path.txt'
Copy-Item "$root\public\.htaccess","$root\public\.user.ini" "$dist\public" -Force
Copy-Item "$root\public\assets\.htaccess" "$dist\public\assets" -Force
foreach ($d in 'src','migrations','bin') { Copy-Item "$root\app\$d" "$dist\app\$d" -Recurse -Force }
Copy-Item "$root\app\.htaccess" "$dist\app" -Force
New-Item -ItemType Directory -Force "$dist\app\storage\uploads","$dist\app\storage\logs","$dist\app\storage\backups","$dist\app\storage\tmp" | Out-Null
Copy-Item "$root\app\storage\.htaccess" "$dist\app\storage" -Force
# tek kullanımlık kurulum anahtarı
$bytes = New-Object byte[] 16; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
$token = ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
Set-Content -NoNewline -Path "$dist\app\storage\setup.token" -Value $token
Compress-Archive -Path "$dist\public\*" -DestinationPath "$dist\funbreak-public.zip" -Force
Compress-Archive -Path "$dist\app\*" -DestinationPath "$dist\funbreak-app.zip" -Force
Write-Host "Hazır: $dist\funbreak-public.zip ve $dist\funbreak-app.zip"
Write-Host "Kurulum anahtarı (app/storage/setup.token içinde de var): $token"
