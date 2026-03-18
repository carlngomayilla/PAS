<?php

declare(strict_types=1);

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$docsDir = realpath(__DIR__.'/../docs/dossier-projet-v1');
if ($docsDir === false) {
    fwrite(STDERR, "Dossier introuvable: docs/dossier-projet-v1\n");
    exit(1);
}

$pdfDir = $docsDir.DIRECTORY_SEPARATOR.'pdf';
if (! is_dir($pdfDir) && ! mkdir($pdfDir, 0777, true) && ! is_dir($pdfDir)) {
    fwrite(STDERR, "Impossible de creer le dossier PDF: {$pdfDir}\n");
    exit(1);
}

$docs = [
    '01-cahier-des-charges.md' => '01-cahier-des-charges.pdf',
    '02-specifications-fonctionnelles.md' => '02-specifications-fonctionnelles.pdf',
    '03-specifications-techniques.md' => '03-specifications-techniques.pdf',
    '04-uml.md' => '04-uml.pdf',
    '05-modele-donnees-mcd-mld.md' => '05-modele-donnees-mcd-mld.pdf',
    '06-maquettes-ui-ux.md' => '06-maquettes-ui-ux.pdf',
];

$style = <<<CSS
<style>
    @page { margin: 18mm 15mm; }
    body {
        font-family: DejaVu Sans, sans-serif;
        color: #0f172a;
        font-size: 11px;
        line-height: 1.45;
    }
    h1 { font-size: 20px; margin: 0 0 10px; color: #0b1220; }
    h2 { font-size: 16px; margin: 16px 0 8px; color: #0b1220; }
    h3 { font-size: 13px; margin: 12px 0 6px; color: #1e293b; }
    p { margin: 0 0 8px; }
    ul, ol { margin: 0 0 10px 18px; }
    li { margin: 0 0 4px; }
    code, pre {
        font-family: DejaVu Sans Mono, monospace;
        font-size: 10px;
        background: #f8fafc;
    }
    pre {
        border: 1px solid #e2e8f0;
        border-radius: 4px;
        padding: 8px;
        white-space: pre-wrap;
    }
    table {
        width: 100%;
        border-collapse: collapse;
        margin: 8px 0 12px;
        font-size: 10px;
    }
    th, td {
        border: 1px solid #cbd5e1;
        padding: 6px;
        text-align: left;
        vertical-align: top;
    }
    th {
        background: #f1f5f9;
        font-weight: 700;
    }
    hr {
        border: 0;
        border-top: 1px solid #cbd5e1;
        margin: 12px 0;
    }
    .page-break {
        page-break-after: always;
    }
</style>
CSS;

/**
 * @param string $title
 * @param string $markdown
 * @param string $style
 * @return string
 */
function buildHtmlDocument(string $title, string $markdown, string $style): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $body = Str::markdown($markdown, [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);

    return <<<HTML
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>{$safeTitle}</title>
    {$style}
</head>
<body>
{$body}
</body>
</html>
HTML;
}

$sections = [];

foreach ($docs as $mdFile => $pdfFile) {
    $mdPath = $docsDir.DIRECTORY_SEPARATOR.$mdFile;
    if (! is_file($mdPath)) {
        fwrite(STDERR, "Fichier markdown manquant: {$mdPath}\n");
        continue;
    }

    $markdown = (string) file_get_contents($mdPath);
    $baseName = (string) pathinfo($mdFile, PATHINFO_FILENAME);
    $baseName = (string) preg_replace('/^\d+-/', '', $baseName);
    $title = (string) Str::of($baseName)
        ->replace('-', ' ')
        ->title();

    $html = buildHtmlDocument($title, $markdown, $style);
    $outPath = $pdfDir.DIRECTORY_SEPARATOR.$pdfFile;

    Pdf::loadHTML($html)
        ->setPaper('a4', 'portrait')
        ->save($outPath);

    $sectionHtml = Str::markdown($markdown, [
        'html_input' => 'strip',
        'allow_unsafe_links' => false,
    ]);

    $sections[] = '<h1>'.htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</h1>'.$sectionHtml;
    echo "Genere: {$outPath}\n";
}

if ($sections !== []) {
    $combinedBody = implode('<div class="page-break"></div>', $sections);
    $combinedHtml = <<<HTML
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Dossier Projet ANBG - Complet</title>
    {$style}
</head>
<body>
{$combinedBody}
</body>
</html>
HTML;

    $combinedPath = $pdfDir.DIRECTORY_SEPARATOR.'dossier-projet-v1-complet.pdf';
    Pdf::loadHTML($combinedHtml)
        ->setPaper('a4', 'portrait')
        ->save($combinedPath);

    echo "Genere: {$combinedPath}\n";
}
