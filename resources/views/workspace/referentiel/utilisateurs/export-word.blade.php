<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
    <meta charset="utf-8">
    <title>Référentiel - Utilisateurs</title>
    <!--[if gte mso 9]>
    <xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml>
    <![endif]-->
    <style>
        @page { size: A4 landscape; margin: 1.4cm; }
        body { font-family: 'Calibri', 'Segoe UI', Arial, sans-serif; color: #17324a; font-size: 10pt; }
        .doc-title { font-size: 18pt; font-weight: 700; color: #123b5b; margin: 0 0 2pt; }
        .doc-sub { font-size: 9.5pt; color: #667085; margin: 0 0 14pt; }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            background: #123b5b; color: #ffffff; font-size: 9pt; font-weight: 700;
            text-align: left; padding: 6pt 7pt; border: 0.5pt solid #0e2e47;
        }
        tbody td { padding: 5pt 7pt; border: 0.5pt solid #cbd5e1; font-size: 9.5pt; vertical-align: top; }
        tbody tr:nth-child(even) td { background: #eef6fc; }
        .muted { color: #94a3b8; }
    </style>
</head>
<body>
    <p class="doc-title">ANBG — Référentiel des utilisateurs</p>
    <p class="doc-sub">Liste générée le {{ $generatedAt }} — {{ count($rows) }} utilisateur(s).</p>

    <table>
        <thead>
            <tr>
                <th>Nom</th>
                <th>Adresse e-mail</th>
                <th>Rôle</th>
                <th>Direction</th>
                <th>Service / unité</th>
                <th>Matricule</th>
                <th>Fonction</th>
                <th>Téléphone</th>
                <th>Santé du compte</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['nom'] }}</td>
                    <td>{{ $row['email'] }}</td>
                    <td>{{ $row['role'] }}</td>
                    <td>{{ $row['direction'] }}</td>
                    <td>{{ $row['service'] }}</td>
                    <td>{{ $row['matricule'] }}</td>
                    <td>{{ $row['fonction'] }}</td>
                    <td>{{ $row['telephone'] }}</td>
                    <td>{{ $row['sante'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="muted">Aucun utilisateur.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
