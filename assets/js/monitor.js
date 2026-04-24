(function () {
    const grid = document.getElementById('monitorGrid');
    const clock = document.getElementById('monitorClock');
    const status = document.getElementById('monitorStatus');
    const doorsTable = document.getElementById('monitorDoorsTable');

    if (!grid || !clock || !status) {
        return;
    }

    const REFRESH_MS = 1500;
    const CARD_COUNT = 12;
    const STORAGE_KEY = 'monitorActiveDoors';

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
            row.nazwa_przejscia || '',
            row.zdjecie || '',
            row.id_kontrolera || '',
            row.id_drzwi || ''
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

    function doorKeyFromCell(cell) {
        return `${cell.dataset.idKontrolera}:${cell.dataset.idDrzwi}`;
    }

    function getDoorCells() {
        if (!doorsTable) {
            return [];
        }

        return Array.from(doorsTable.querySelectorAll('.monitor-door-cell'));
    }

    function getAllDoorKeys() {
        return getDoorCells().map(doorKeyFromCell);
    }

    function loadActiveDoorKeys() {
        const allKeys = getAllDoorKeys();

        if (allKeys.length === 0) {
            return [];
        }

        const saved = localStorage.getItem(STORAGE_KEY);

        if (!saved) {
            return allKeys;
        }

        try {
            const parsed = JSON.parse(saved);

            if (!Array.isArray(parsed)) {
                return allKeys;
            }

            const active = parsed.filter(function (key) {
                return allKeys.includes(key);
            });

            return active;
        } catch (e) {
            return allKeys;
        }
    }

    function saveActiveDoorKeys(keys) {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(keys));
    }

    function setCellState(cell, active) {
        cell.classList.toggle('is-active', active);
        cell.classList.toggle('is-inactive', !active);
    }

    function getActiveDoorKeys() {
        return getDoorCells()
            .filter(function (cell) {
                return cell.classList.contains('is-active');
            })
            .map(doorKeyFromCell);
    }

    function initDoorSelection() {
        const cells = getDoorCells();

        if (cells.length === 0) {
            return;
        }

        const activeKeys = loadActiveDoorKeys();

        cells.forEach(function (cell) {
            const key = doorKeyFromCell(cell);
            setCellState(cell, activeKeys.includes(key));

            cell.addEventListener('click', function () {
                const isActive = cell.classList.contains('is-active');
                setCellState(cell, !isActive);
                saveActiveDoorKeys(getActiveDoorKeys());

                lastKeys = [];
                fetchData();
            });
        });

        saveActiveDoorKeys(getActiveDoorKeys());
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
        card.dataset.key = row ? rowKey(row) : '';

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

    function arraysEqual(a, b) {
        if (a.length !== b.length) {
            return false;
        }

        for (let i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) {
                return false;
            }
        }

        return true;
    }

    function renderGrid(rows) {
        const cleanRows = Array.isArray(rows) ? rows.slice(0, CARD_COUNT) : [];
        const newKeys = cleanRows.map(rowKey);

        while (newKeys.length < CARD_COUNT) {
            newKeys.push('');
        }

        if (arraysEqual(newKeys, lastKeys)) {
            return;
        }

        const currentCards = Array.from(grid.children);
        const cards = [];

        for (let i = 0; i < CARD_COUNT; i++) {
            const row = cleanRows[i] || null;
            const key = row ? rowKey(row) : '';
            const oldCard = currentCards[i] || null;

            if (oldCard && oldCard.dataset.key === key) {
                cards.push(oldCard);
                continue;
            }

            const isNew = row && !lastKeys.includes(key);
            cards.push(buildCard(row, isNew));
        }

        grid.replaceChildren(...cards);
        lastKeys = newKeys;
    }

    function getFetchUrl() {
        const activeKeys = getActiveDoorKeys();

        if (activeKeys.length === 0) {
            return 'index.php?page=monitor_data&doors=__none__&_=' + Date.now();
        }

        return 'index.php?page=monitor_data&doors=' + encodeURIComponent(activeKeys.join(',')) + '&_=' + Date.now();
    }

    async function fetchData() {
        if (loading) {
            return;
        }

        loading = true;

        try {
            const response = await fetch(getFetchUrl(), {
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

            status.textContent = '';
            status.className = 'monitor-status';
        } catch (error) {
            status.textContent = 'błąd: ' + error.message;
            status.className = 'monitor-status monitor-status-error';
        } finally {
            loading = false;
        }
    }

    updateClock();
    setInterval(updateClock, 1000);

    initDoorSelection();

    fetchData();
    setInterval(fetchData, REFRESH_MS);
})();