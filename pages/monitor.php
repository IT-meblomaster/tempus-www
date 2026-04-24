<?php
declare(strict_types=1);

require_once __DIR__ . '/../inc/helpers.php';

// Endpoint AJAX - zwraca JSON z ostatnimi 12 rekordami
if (isset($_GET['ajax'])) {
    ob_start();
    header('Content-Type: application/json');
    try {
        $pg = db_pgsql($config);
        $stmt = $pg->query("
            SELECT
                zdjecie,
                data_zdarzenia,
                nazwa_kontrolera,
                nazwa_przejscia,
                pracownik,
                nazwa_dzialu
            FROM monitoring.monitoring_log
            ORDER BY data_zdarzenia DESC
            LIMIT 12
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Odwróć żeby najstarszy był na końcu
        $rows = array_reverse($rows);
        echo json_encode($rows);
        ob_clean();
    } catch (Throwable $e) {
        ob_clean();
        echo json_encode(['error' => $e->getMessage()]);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor wejść</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@400;600;700&family=Barlow:wght@400;500&display=swap');

        :root {
            --bg: #0a0c10;
            --card-bg: #12151c;
            --card-border: #1e2330;
            --accent: #00d4ff;
            --accent2: #ff6b35;
            --text-primary: #f0f4ff;
            --text-secondary: #8892a4;
            --text-muted: #4a5568;
            --time-color: #00d4ff;
            --grid-gap: 10px;
            --card-radius: 10px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html, body {
            width: 100%;
            height: 100%;
            background: var(--bg);
            color: var(--text-primary);
            font-family: 'Barlow', sans-serif;
            overflow: hidden;
        }

        body {
            display: flex;
            flex-direction: column;
            padding: 12px;
            gap: 10px;
        }

        header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 4px;
            flex-shrink: 0;
        }

        header h1 {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--text-primary);
        }

        header h1 span {
            color: var(--accent);
        }

        #clock {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 1.6rem;
            font-weight: 600;
            color: var(--accent);
            letter-spacing: 0.08em;
        }

        #status {
            font-size: 0.72rem;
            color: var(--text-muted);
            letter-spacing: 0.06em;
        }

        #status.ok::before {
            content: '● ';
            color: #22c55e;
        }

        #status.err::before {
            content: '● ';
            color: #ef4444;
        }

        #grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            grid-template-rows: repeat(2, 1fr);
            gap: var(--grid-gap);
            min-height: 0;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--card-border);
            border-radius: var(--card-radius);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: border-color 0.3s;
        }

        .card.new {
            border-color: var(--accent);
            animation: flash 1s ease-out forwards;
        }

        @keyframes flash {
            0%   { border-color: var(--accent); box-shadow: 0 0 16px var(--accent); }
            100% { border-color: var(--card-border); box-shadow: none; }
        }

        .card-photo {
            width: 100%;
            aspect-ratio: 1 / 1;
            object-fit: cover;
            background: #1a1f2e;
            display: block;
            flex-shrink: 0;
        }

        .card-photo-placeholder {
            width: 100%;
            aspect-ratio: 1 / 1;
            background: #1a1f2e;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .card-photo-placeholder svg {
            width: 40%;
            height: 40%;
            opacity: 0.15;
        }

        .card-body {
            padding: 8px 10px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            flex: 1;
            min-height: 0;
            justify-content: space-between;
        }

        .card-name {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: clamp(0.85rem, 1.4vw, 1.2rem);
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.1;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-dept {
            font-size: clamp(0.65rem, 0.95vw, 0.8rem);
            color: var(--text-secondary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-door {
            font-size: clamp(0.6rem, 0.85vw, 0.75rem);
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .card-time {
            font-family: 'Barlow Condensed', sans-serif;
            font-size: clamp(1rem, 1.8vw, 1.5rem);
            font-weight: 600;
            color: var(--time-color);
            letter-spacing: 0.05em;
            margin-top: 2px;
        }

        .card-empty {
            opacity: 0.15;
        }
    </style>
</head>
<body>

<header>
    <h1>Monitor <span>wejść</span></h1>
    <div id="clock">--:--:--</div>
    <div id="status">ładowanie…</div>
</header>

<div id="grid"></div>

<script>
    // Zegar
    function updateClock() {
        const now = new Date();
        const h = String(now.getHours()).padStart(2, '0');
        const m = String(now.getMinutes()).padStart(2, '0');
        const s = String(now.getSeconds()).padStart(2, '0');
        document.getElementById('clock').textContent = `${h}:${m}:${s}`;
    }
    setInterval(updateClock, 1000);
    updateClock();

    const PLACEHOLDER_SVG = `
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="12" cy="8" r="4" fill="white"/>
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" fill="white"/>
        </svg>`;

    let lastKeys = [];

    function rowKey(r) {
        return r.data_zdarzenia + '|' + r.pracownik;
    }

    function formatTime(dt) {
        if (!dt) return '--:--:--';
        // dt format: "YYYY-MM-DD HH:MM:SS"
        const parts = dt.split(' ');
        return parts[1] ? parts[1].substring(0, 8) : dt;
    }

    function renderGrid(rows) {
        const grid = document.getElementById('grid');
        const newKeys = rows.map(rowKey);

        // Buduj 12 kart (uzupełnij pustymi jeśli mniej rekordów)
        const cards = [];
        for (let i = 0; i < 12; i++) {
            const r = rows[i] || null;
            const isNew = r && !lastKeys.includes(rowKey(r));

            const card = document.createElement('div');
            card.className = 'card' + (r ? '' : ' card-empty') + (isNew ? ' new' : '');

            if (r) {
                const oid = parseInt(r.zdjecie || 0);
                const photoHtml = oid > 0
                    ? `<img class="card-photo" src="index.php?page=photo&oid=${oid}" alt="" onerror="this.style.display='none'">`
                    : `<div class="card-photo-placeholder">${PLACEHOLDER_SVG}</div>`;

                card.innerHTML = `
                    ${photoHtml}
                    <div class="card-body">
                        <div class="card-name">${escHtml(r.pracownik || '—')}</div>
                        <div class="card-dept">${escHtml(r.nazwa_dzialu || '—')}</div>
                        <div class="card-door">${escHtml(r.nazwa_przejscia || '—')}</div>
                        <div class="card-time">${escHtml(formatTime(r.data_zdarzenia))}</div>
                    </div>`;
            } else {
                card.innerHTML = `
                    <div class="card-photo-placeholder">${PLACEHOLDER_SVG}</div>
                    <div class="card-body">
                        <div class="card-name">—</div>
                        <div class="card-dept"></div>
                        <div class="card-door"></div>
                        <div class="card-time">--:--:--</div>
                    </div>`;
            }

            cards.push(card);
        }

        // Podmień zawartość grida bez przebudowy DOM (brak mrugania)
        grid.replaceChildren(...cards);
        lastKeys = newKeys;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    async function fetchData() {
        const status = document.getElementById('status');
        try {
            const resp = await fetch('index.php?page=monitor&ajax=1&_=' + Date.now());
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const data = await resp.json();
            if (data.error) throw new Error(data.error);
            renderGrid(data);
            status.textContent = 'aktualizacja ' + new Date().toLocaleTimeString('pl-PL');
            status.className = 'ok';
        } catch (e) {
            status.textContent = 'błąd: ' + e.message;
            status.className = 'err';
        }
    }

    fetchData();
    setInterval(fetchData, 1500);
</script>

</body>
</html>