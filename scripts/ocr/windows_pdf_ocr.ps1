param(
    [Parameter(Mandatory = $true)]
    [string] $Path,

    [int] $MaxPages = 0,

    [int] $RenderWidth = 2600
)

$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
$OutputEncoding = [System.Text.Encoding]::UTF8

if (-not (Test-Path -LiteralPath $Path)) {
    throw "PDF file not found: $Path"
}

Add-Type -AssemblyName System.Runtime.WindowsRuntime

[Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime] | Out-Null
[Windows.Data.Pdf.PdfDocument, Windows.Data.Pdf, ContentType = WindowsRuntime] | Out-Null
[Windows.Data.Pdf.PdfPageRenderOptions, Windows.Data.Pdf, ContentType = WindowsRuntime] | Out-Null
[Windows.Storage.Streams.InMemoryRandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime] | Out-Null
[Windows.Graphics.Imaging.BitmapDecoder, Windows.Graphics.Imaging, ContentType = WindowsRuntime] | Out-Null
[Windows.Graphics.Imaging.SoftwareBitmap, Windows.Graphics.Imaging, ContentType = WindowsRuntime] | Out-Null
[Windows.Graphics.Imaging.BitmapPixelFormat, Windows.Graphics.Imaging, ContentType = WindowsRuntime] | Out-Null
[Windows.Graphics.Imaging.BitmapAlphaMode, Windows.Graphics.Imaging, ContentType = WindowsRuntime] | Out-Null
[Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType = WindowsRuntime] | Out-Null
[Windows.Media.Ocr.OcrResult, Windows.Foundation, ContentType = WindowsRuntime] | Out-Null
[Windows.Globalization.Language, Windows.Globalization, ContentType = WindowsRuntime] | Out-Null

$AsTaskGeneric = [System.WindowsRuntimeSystemExtensions].GetMethods() |
    Where-Object {
        $_.Name -eq 'AsTask' -and
        $_.IsGenericMethodDefinition -and
        $_.GetParameters().Count -eq 1 -and
        $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1'
    } |
    Select-Object -First 1

$AsTaskAction = [System.WindowsRuntimeSystemExtensions].GetMethods() |
    Where-Object {
        $_.Name -eq 'AsTask' -and
        -not $_.IsGenericMethod -and
        $_.GetParameters().Count -eq 1 -and
        $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncAction'
    } |
    Select-Object -First 1

function Wait-WinRtOperation {
    param(
        [Parameter(Mandatory = $true)] $Operation,
        [Parameter(Mandatory = $true)] [type] $ResultType
    )

    $task = $script:AsTaskGeneric.MakeGenericMethod($ResultType).Invoke($null, @($Operation))
    $task.Wait() | Out-Null

    if ($task.IsFaulted) {
        throw $task.Exception.InnerException
    }

    return $task.Result
}

function Wait-WinRtAction {
    param([Parameter(Mandatory = $true)] $Action)

    $task = $script:AsTaskAction.Invoke($null, @($Action))
    $task.Wait() | Out-Null

    if ($task.IsFaulted) {
        throw $task.Exception.InnerException
    }
}

function Get-OcrLineBox {
    param([Parameter(Mandatory = $true)] $Line)

    $words = @($Line.Words)
    if ($words.Count -eq 0) {
        return $null
    }

    $minX = [double]::MaxValue
    $minY = [double]::MaxValue
    $maxX = 0
    $maxY = 0

    foreach ($word in $words) {
        $rect = $word.BoundingRect
        $minX = [Math]::Min($minX, $rect.X)
        $minY = [Math]::Min($minY, $rect.Y)
        $maxX = [Math]::Max($maxX, $rect.X + $rect.Width)
        $maxY = [Math]::Max($maxY, $rect.Y + $rect.Height)
    }

    [PSCustomObject]@{
        X = $minX
        Y = $minY
        Width = [Math]::Max(1, $maxX - $minX)
        Height = [Math]::Max(1, $maxY - $minY)
        Text = $Line.Text.Trim()
    }
}

function Write-LayoutLines {
    param([Parameter(Mandatory = $true)] $OcrResult)

    $lines = @()
    foreach ($line in $OcrResult.Lines) {
        $box = Get-OcrLineBox -Line $line
        if ($null -ne $box -and $box.Text -ne '') {
            $lines += $box
        }
    }

    $pending = @()
    $currentY = $null

    foreach ($line in ($lines | Sort-Object Y, X)) {
        if ($null -eq $currentY -or [Math]::Abs($line.Y - $currentY) -le 36) {
            $pending += $line
            if ($null -eq $currentY) {
                $currentY = $line.Y
            }

            continue
        }

        Write-LayoutGroup -Group $pending
        $pending = @($line)
        $currentY = $line.Y
    }

    if ($pending.Count -gt 0) {
        Write-LayoutGroup -Group $pending
    }
}

function Write-OcrBoxes {
    param(
        [Parameter(Mandatory = $true)] $OcrResult,
        [Parameter(Mandatory = $true)] [int] $PageNumber
    )

    foreach ($line in $OcrResult.Lines) {
        $box = Get-OcrLineBox -Line $line
        if ($null -eq $box -or $box.Text -eq '') {
            continue
        }

        $safeText = $box.Text.Replace('|', '/')
        "@@OCR_BOX|$PageNumber|$([int] $box.X)|$([int] $box.Y)|$safeText"
    }
}

function Write-LayoutGroup {
    param([array] $Group)

    $lineText = ''
    $lastRight = 0
    $ordered = $Group | Sort-Object X

    foreach ($item in $ordered) {
        $gap = if ($lineText -eq '') { 1 } else { [Math]::Max(2, [int] [Math]::Round(($item.X - $lastRight) / 18)) }
        $gap = [Math]::Min($gap, 28)
        $lineText += (' ' * $gap) + $item.Text
        $lastRight = $item.X + $item.Width
    }

    $lineText.TrimEnd()
}

$engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromUserProfileLanguages()
if ($null -eq $engine) {
    $engine = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage([Windows.Globalization.Language]::new('fr-FR'))
}

if ($null -eq $engine) {
    throw 'Windows OCR engine is unavailable for the current user languages.'
}

$file = Wait-WinRtOperation ([Windows.Storage.StorageFile]::GetFileFromPathAsync((Resolve-Path -LiteralPath $Path).Path)) ([Windows.Storage.StorageFile])
$pdf = Wait-WinRtOperation ([Windows.Data.Pdf.PdfDocument]::LoadFromFileAsync($file)) ([Windows.Data.Pdf.PdfDocument])
$pageCount = [int] $pdf.PageCount
if ($MaxPages -gt 0) {
    $pageCount = [Math]::Min($pageCount, $MaxPages)
}

for ($pageIndex = 0; $pageIndex -lt $pageCount; $pageIndex++) {
    "=== OCR PAGE $($pageIndex + 1) ==="

    $page = $pdf.GetPage([uint32] $pageIndex)
    $stream = [Windows.Storage.Streams.InMemoryRandomAccessStream]::new()
    $options = [Windows.Data.Pdf.PdfPageRenderOptions]::new()
    if ($RenderWidth -gt 0) {
        $options.DestinationWidth = [uint32] $RenderWidth
    }

    Wait-WinRtAction ($page.RenderToStreamAsync($stream, $options))
    $stream.Seek(0) | Out-Null

    $decoder = Wait-WinRtOperation ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($stream)) ([Windows.Graphics.Imaging.BitmapDecoder])
    $bitmap = Wait-WinRtOperation ($decoder.GetSoftwareBitmapAsync()) ([Windows.Graphics.Imaging.SoftwareBitmap])
    $bitmap = [Windows.Graphics.Imaging.SoftwareBitmap]::Convert($bitmap, [Windows.Graphics.Imaging.BitmapPixelFormat]::Bgra8, [Windows.Graphics.Imaging.BitmapAlphaMode]::Premultiplied)
    $result = Wait-WinRtOperation ($engine.RecognizeAsync($bitmap)) ([Windows.Media.Ocr.OcrResult])

    Write-LayoutLines -OcrResult $result
    Write-OcrBoxes -OcrResult $result -PageNumber ($pageIndex + 1)

    $page.Dispose()
    $stream.Dispose()
    ''
}
