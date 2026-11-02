<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permission denied</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: #f9fafb;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 24px;
            position: relative;
        }
        .container {
            text-align: center;
            max-width: 520px;
        }
        h1 {
            font-size: 2.25rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: #0f172a;
            margin-bottom: 14px;
        }
        p {
            font-size: 0.95rem;
            line-height: 1.6;
            color: #64748b;
        }
        footer {
            position: absolute;
            bottom: 24px;
            width: 100%;
            text-align: center;
            font-size: 0.75rem;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    <main class="container">
        <h1>Permission denied</h1>
        <p>You don't have access to this resource. If you believe this is a mistake, check that you're signed in to the right account or contact your administrator.</p>
    </main>
    <footer>
        &copy; <?= date('Y') ?> STPL
    </footer>
</body>
</html>

