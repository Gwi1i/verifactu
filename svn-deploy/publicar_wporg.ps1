# Publica el plugin en el directorio de WordPress.org por SVN.
#
# Requisitos previos (una sola vez):
#   1. Activar la verificación en dos pasos en https://profiles.wordpress.org/gwii/profile/edit/group/3/
#      (WordPress.org la exige a las cuentas con acceso de commit).
#   2. Generar la contraseña de SVN en esa misma página ("Account & Security").
#   3. Tener el cliente de línea de comandos de SVN. Con TortoiseSVN:
#        winget install --id TortoiseSVN.TortoiseSVN --custom "ADDLOCAL=ALL"
#      y aceptar el aviso de permisos de Windows.
#
# Uso (desde PowerShell, en la carpeta E:\Verifactu\svn-deploy):
#   .\publicar_wporg.ps1
# SVN pedirá la contraseña de SVN la primera vez y la recordará.

$ErrorActionPreference = 'Stop'

$slug    = 'gwii-invoice-hash-for-woocommerce'
$version = '1.1.2'
$repo    = "https://plugins.svn.wordpress.org/$slug"
$user    = 'gwii'
$origen  = $PSScriptRoot
$wc      = Join-Path $env:TEMP "wporg-$slug"

$svn = Get-Command svn -ErrorAction SilentlyContinue
if (-not $svn) {
    $candidato = 'C:\Program Files\TortoiseSVN\bin\svn.exe'
    if (Test-Path $candidato) { $env:PATH = "C:\Program Files\TortoiseSVN\bin;$env:PATH" }
    else { throw 'No se encuentra svn.exe. Instala TortoiseSVN con las herramientas de línea de comandos.' }
}

Write-Host "1/5 Descargando el repositorio en $wc"
if (Test-Path $wc) { Remove-Item -Recurse -Force $wc }
svn checkout $repo $wc --username $user | Out-Null

Write-Host '2/5 Copiando trunk y assets'
Get-ChildItem (Join-Path $wc 'trunk')  | Remove-Item -Recurse -Force
Get-ChildItem (Join-Path $wc 'assets') | Remove-Item -Recurse -Force
Copy-Item (Join-Path $origen 'trunk\*')  (Join-Path $wc 'trunk')  -Recurse -Force
Copy-Item (Join-Path $origen 'assets\*') (Join-Path $wc 'assets') -Recurse -Force

Set-Location $wc
svn add --force trunk assets | Out-Null
# Tipos MIME correctos para que las imágenes se sirvan bien.
Get-ChildItem assets -Filter *.png | ForEach-Object { svn propset svn:mime-type image/png $_.FullName | Out-Null }

Write-Host '3/5 Estado antes del commit:'
svn status

Write-Host "4/5 Enviando trunk y assets (version $version)"
svn commit -m "Version $version: trunk y assets" --username $user

Write-Host "5/5 Creando la etiqueta tags/$version"
svn copy "$repo/trunk" "$repo/tags/$version" -m "Etiqueta $version" --username $user

Write-Host ''
Write-Host "Listo. En unos minutos: https://wordpress.org/plugins/$slug/"
