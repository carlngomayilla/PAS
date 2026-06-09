param(
    [Parameter(Mandatory = $true)]
    [string] $SshHost,

    [string] $SshUser = "user",
    [int] $SshPort = 22,
    [int] $LocalPort = 5433,
    [string] $RemoteDbHost = "127.0.0.1",
    [int] $RemoteDbPort = 5432
)

$ErrorActionPreference = "Stop"

$target = "$SshUser@$SshHost"
$forward = "${LocalPort}:${RemoteDbHost}:${RemoteDbPort}"

Write-Host "Ouverture du tunnel PostgreSQL local : 127.0.0.1:$LocalPort -> $target port $SshPort -> ${RemoteDbHost}:$RemoteDbPort"
Write-Host "Laisse cette fenetre ouverte pendant que tu utilises l'application locale."
Write-Host "Pour fermer le tunnel : Ctrl+C"

ssh -p $SshPort -N -L $forward $target
