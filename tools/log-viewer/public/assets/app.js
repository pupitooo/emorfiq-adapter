const $ = (id) => document.getElementById(id);
const records = [];
let lastPing = null;
let connected = false;
const clock = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
const detailedClock = new Intl.DateTimeFormat('en-GB', { hour: '2-digit', minute: '2-digit', second: '2-digit', fractionalSecondDigits: 3 });
$('timezone').textContent = Intl.DateTimeFormat().resolvedOptions().timeZone;

function updateStatus() {
    const healthy = lastPing !== null && Date.now() - lastPing < 5000;
    $('adapter-state').textContent = healthy ? 'Running normally' : lastPing === null ? 'Waiting for ping' : 'No response';
    $('adapter-dot').className = `status-dot${healthy ? '' : ' waiting'}`;
    $('stream-state').textContent = connected ? 'Live stream connected' : 'Reconnecting';
    $('stream-dot').className = `status-dot${connected ? '' : ' waiting'}`;
    if (lastPing !== null) $('last-ping').textContent = clock.format(lastPing);
}

const stream = new EventSource('/api/logs/stream');
stream.addEventListener('ready', () => { connected = true; updateStatus(); });
stream.addEventListener('log', (event) => {
    if (records.includes(event.lastEventId)) return;
    let record;
    try { record = JSON.parse(event.data); } catch { return; }
    if (!record || typeof record.message !== 'string') return;
    const timestamp = Date.parse(record.datetime);
    if (!Number.isFinite(timestamp)) return;

    const scroll = $('log-scroll');
    const atBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 45;
    const row = document.createElement('tr');
    const values = [detailedClock.format(timestamp), record.level_name, record.context?.task ?? '—', record.message];
    for (const [index, value] of values.entries()) {
        const cell = document.createElement('td');
        if (index === 1) {
            const badge = document.createElement('span');
            badge.className = 'level-badge';
            badge.textContent = value;
            cell.append(badge);
        } else {
            cell.textContent = value;
        }
        row.append(cell);
    }
    $('log-rows').append(row);
    records.push(event.lastEventId);
    if (records.length > 200) {
        records.shift();
        $('log-rows').firstElementChild.remove();
    }
    $('empty-state').hidden = true;
    $('record-count').textContent = `${records.length} entries`;
    if (atBottom) scroll.scrollTop = scroll.scrollHeight;
    if (record.message === 'ping') lastPing = Math.max(lastPing ?? 0, timestamp);
    updateStatus();
});
stream.onerror = () => { connected = false; updateStatus(); };
window.addEventListener('pagehide', () => stream.close());
setInterval(updateStatus, 1000);

// Additional tabs use roles and aria-controls without changing this handler.
const tabs = [...document.querySelectorAll('[role="tab"]')];
function selectTab(selected) {
    for (const tab of tabs) {
        const active = tab === selected;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        document.getElementById(tab.getAttribute('aria-controls')).hidden = !active;
    }
}
for (const [index, tab] of tabs.entries()) {
    tab.addEventListener('click', () => selectTab(tab));
    tab.addEventListener('keydown', (event) => {
        const next = {
            ArrowRight: tabs[(index + 1) % tabs.length],
            ArrowLeft: tabs[(index - 1 + tabs.length) % tabs.length],
            Home: tabs[0],
            End: tabs.at(-1),
        }[event.key];
        if (next) { event.preventDefault(); selectTab(next); next.focus(); }
    });
}
