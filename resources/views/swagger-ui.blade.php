<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Secretlab KV Store API Docs</title>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
    <style>
        :root {
            color-scheme: light;
            --ink: #101828;
            --muted: #475467;
            --bg: #f7f4ef;
            --panel: #ffffff;
            --accent: #0f766e;
        }

        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            background: radial-gradient(circle at top, rgba(15, 118, 110, 0.10), transparent 36%), var(--bg);
            color: var(--ink);
        }

        .hero {
            padding: 32px 24px 12px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.16em;
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
        }

        h1 {
            margin: 8px 0 10px;
            font-size: clamp(2rem, 4vw, 3.5rem);
            line-height: 1.05;
        }

        p {
            margin: 0;
            max-width: 68ch;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.6;
        }

        .meta {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }

        .chip {
            background: rgba(255,255,255,0.9);
            border: 1px solid rgba(16,24,40,0.08);
            border-radius: 999px;
            padding: 8px 12px;
            font-size: 13px;
            color: var(--ink);
        }

        #swagger-ui {
            max-width: 1200px;
            margin: 0 auto;
            padding: 16px 24px 48px;
        }
    </style>
</head>
<body>
    <section class="hero">
        <div class="eyebrow">OpenAPI 3.0</div>
        <h1>Secretlab KV Store API Docs</h1>
        <p>
            Interactive Swagger UI for the version-controlled key-value store.
            Use the API spec to try writes, read latest values, and inspect historical lookups.
        </p>
        <div class="meta">
            <div class="chip">Spec: {{ $specUrl }}</div>
            <div class="chip">Base path: /api</div>
            <div class="chip">Content: JSON</div>
        </div>
    </section>

    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.addEventListener('load', function () {
            window.ui = SwaggerUIBundle({
                url: @json($specUrl),
                dom_id: '#swagger-ui',
                deepLinking: true,
                docExpansion: 'list',
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset,
                ],
                layout: 'BaseLayout',
            });
        });
    </script>
</body>
</html>
