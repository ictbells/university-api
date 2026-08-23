<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        @page { margin: 2cm 1.8cm; }
        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.5;
            color: #1e293b;
        }
        h1 { font-size: 20pt; color: #0c4a6e; margin: 0 0 8pt; }
        h2 { font-size: 14pt; color: #0c4a6e; margin: 18pt 0 8pt; border-bottom: 1px solid #cbd5e1; padding-bottom: 4pt; }
        h3 { font-size: 12pt; color: #334155; margin: 14pt 0 6pt; }
        p { margin: 0 0 8pt; }
        ul, ol { margin: 0 0 10pt 18pt; padding: 0; }
        li { margin-bottom: 4pt; }
        table { width: 100%; border-collapse: collapse; margin: 10pt 0 14pt; font-size: 10pt; }
        th, td { border: 1px solid #cbd5e1; padding: 6pt 8pt; text-align: left; vertical-align: top; }
        th { background: #f1f5f9; font-weight: bold; }
        code { font-family: DejaVu Sans Mono, monospace; font-size: 9pt; background: #f1f5f9; padding: 1pt 3pt; }
        pre { background: #f8fafc; border: 1px solid #e2e8f0; padding: 8pt; overflow-wrap: break-word; white-space: pre-wrap; }
        hr { border: none; border-top: 1px solid #cbd5e1; margin: 16pt 0; }
        .meta { font-size: 9pt; color: #64748b; margin-bottom: 16pt; }
        strong { color: #0f172a; }
    </style>
</head>
<body>
    <div class="meta">
        {{ $title }} · Version {{ $version }} · Updated {{ $updated_at }}
    </div>
    {!! $content !!}
</body>
</html>
