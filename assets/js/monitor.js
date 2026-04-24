(function () {
    const grid = document.getElementById('monitorGrid');
    const clock = document.getElementById('monitorClock');
    const status = document.getElementById('monitorStatus');

    if (!grid || !clock || !status) {
        return;
    }

    const REFRESH_MS = 1500;
    const CARD_COUNT = 12;

    const PLACEHOLDER_SVG = `
        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <circle cx="12" cy="8" r="4" fill="currentColor"/>
            <path d="M4 20c0-4 3.6-7 8-7s8 3 8 7" fill="currentColor"/>
        </svg>`;

    let lastKeys = [];
    let loading = false;

    function updateClock() {
        const now = new Date();
        clock.textContent = now.toLocaleTimeString('pl-PL', {
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    }

    function escHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function rowKey(row) {
        return [
            row.data_zdarzenia || '',
            row.pracownik || '',
            row.nazwa_przejscia || ''
        ].join('|');
    }

    function formatTime(value) {
        if (!value) {
            return '--:--:--';
        }

        const text = String(value);
        const parts = text.split(' ');

        if (parts.length > 1) {
            return parts[1].substring(0, 8);
        }

        const isoParts = text.split('T');
        if (isoParts.length > 1) {
            return isoParts[1].substring(0, 8);
        }

        return text.substring(0, 8);
    }

    function buildPhotoHtml(row) {
        const oid = parseInt(row.zdjecie || '0', 10);

        if (oid > 0) {
            return `
                <img
                    class="monitor-card-photo"
                    src="index.php?page=photo&oid=${encodeURIComponent(String(oid))}"
                    alt=""
                    loading="lazy"
                    onerror="this.replaceWith(this.nextElementSibling);"
                >
                <div class="monitor-card-photo-placeholder" style="display:none;">${PLACEHOLDER_SVG}</div>
            `;
        }

        return `<div class="monitor-card-photo-placeholder">${PLACEHOLDER_SVG}</div>`;
    }

    function buildCard(row, isNew) {
        const card = document.createElement('div');
        card.className = 'monitor-card' + (row ? '' : ' monitor-card-empty') + (isNew ? ' monitor-card-new' : '');

        if (!row) {
            card.innerHTML = `
                <div class="monitor-card-photo-placeholder">${PLACEHOLDER_SVG}</div>
                <div class="monitor-card-body">
                    <div class="monitor-card-name">—</div>
                    <div class="monitor-card-dept">—</div>
                    <div class="monitor-card-door">—</div>
                    <div class="monitor-card-time">--:--:--</div>
                </div>
            `;
            return card;
        }

        card.innerHTML = `
            ${buildPhotoHtml(row)}
            <div class="monitor-card-body">
                <div class="monitor-card-name">${escHtml(row.pracownik || '—')}</div>
                <div class="monitor-card-dept">${escHtml(row.nazwa_dzialu || '—')}</div>
                <div class="monitor-card-door">${escHtml(row.nazwa_przejscia || '—')}</div>
                <div class="monitor-card-time">${escHtml(formatTime(row.data_zdarzenia))}</div>
            </div>
        `;

        return card;
    }

    function renderGrid(rows) {
        const cleanRows = Array.isArray(rows) ? rows.slice(0, CARD_COUNT) : [];
        const newKeys = cleanRows.map(rowKey);
        const cards = [];

        for (let i = 0; i < CARD_COUNT; i++) {
            const row = cleanRows[i] || null;
            const key = row ? rowKey(row) : '';
            const isNew = row && !lastKeys.includes(key);

            cards.push(buildCard(row, isNew));
        }

        grid.replaceChildren(...cards);
        lastKeys = newKeys;
    }

    async function fetchData() {
        if (loading) {
            return;
        }

        loading = true;

        try {
            const response = await fetch('index.php?page=monitor_data&_=' + Date.now(), {
                cache: 'no-store',
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            const data = await response.json();

            if (data && data.error) {
                throw new Error(data.error);
            }

            renderGrid(data);

            status.textContent = 'aktualizacja ' + new Date().toLocaleTimeString('pl-PL');
            status.className = 'monitor-status monitor-status-ok';
        } catch (error) {
            status.textContent = 'błąd: ' + error.message;
            status.className = 'monitor-status monitor-status-error';
        } finally {
            loading = false;
        }
    }

    updateClock();
    setInterval(updateClock, 1000);

    fetchData();
    setInterval(fetchData, REFRESH_MS);
})();