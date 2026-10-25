import { $, node, instant, message } from './dom.js';

const records = [];
let stream = null;
let active = false;
let paused = false;

function streamStatus(text, state = '') {
    $('stream-state').textContent = text;
    $('stream-dot').className = `status-dot ${state}`;
}

function recordRow(record) {
    const row = node('tr');
    row.append(node('td', 'date-cell', instant(record.datetime)));
    const level = node('td');
    const levelStyle = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'].includes(record.level_name) ? 'failed'
        : record.level_name === 'WARNING' ? 'abandoned' : 'off';
    level.append(node('span', `badge ${levelStyle}`, record.level_name));
    row.append(level, node('td', 'log-channel', record.channel ?? '—'));
    const text = node('td', '', record.message);
    const metadata = {};
    if (record.context && Object.keys(record.context).length) metadata.context = record.context;
    if (record.extra && Object.keys(record.extra).length) metadata.extra = record.extra;
    if (Object.keys(metadata).length) {
        const details = node('details');
        details.append(node('summary', '', 'Kontext a metadata'), node('pre', 'context-json', JSON.stringify(metadata, null, 2)));
        text.append(details);
    }
    row.append(text);
    return row;
}

function visibleRecords() {
    const query = $('log-search').value.trim().toLocaleLowerCase('cs-CZ');
    const level = $('log-level').value;
    return records.filter(({ record }) => (!level || record.level_name === level)
        && (!query || JSON.stringify(record).toLocaleLowerCase('cs-CZ').includes(query)));
}

function renderLogs() {
    const scroll = $('log-scroll');
    const atBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 50;
    const visible = visibleRecords();
    const existing = new Map([...$('log-rows').children].map((row) => [row.dataset.id, row]));
    const body = $('log-rows');
    const retained = new Set(visible.map((entry) => entry.id));
    for (const row of [...body.children]) if (!retained.has(row.dataset.id)) row.remove();
    visible.forEach(({ id, record }, index) => {
        const row = existing.get(id) ?? recordRow(record);
        row.dataset.id = id;
        if (body.children[index] !== row) body.insertBefore(row, body.children[index] ?? null);
    });
    $('log-empty').hidden = visible.length > 0;
    if (!visible.length) message($('log-empty'), records.length ? 'Žádné odpovídající zprávy' : 'Zatím žádné zprávy',
        records.length ? 'Zkuste změnit hledání nebo úroveň logu.' : 'Nové záznamy se zobrazí automaticky.');
    $('log-count').textContent = `${visible.length} z ${records.length} přijatých záznamů`;
    if (atBottom) scroll.scrollTop = scroll.scrollHeight;
}

function connectStream() {
    if (stream || !active || paused) return;
    streamStatus('Připojování…', 'waiting');
    stream = new EventSource('/api/logs/stream');
    stream.addEventListener('ready', () => streamStatus('Živý stream připojen'));
    stream.addEventListener('log', (event) => {
        let record;
        try { record = JSON.parse(event.data); } catch { return; }
        if (!record || typeof record.message !== 'string' || !Number.isFinite(Date.parse(record.datetime))) return;
        const id = `${event.lastEventId}:${record.datetime}:${record.message}`;
        if (records.some((entry) => entry.id === id)) return;
        records.push({ id, record });
        if (records.length > 200) records.shift();
        renderLogs();
    });
    stream.onerror = () => streamStatus('Obnovování připojení…', 'waiting');
}

export function activateLogs(selected) {
    active = selected;
    if (selected && !paused) connectStream();
    else {
        stream?.close();
        stream = null;
        streamStatus(paused ? 'Příjem pozastaven' : 'Stream neaktivní', 'muted');
    }
}

$('log-search').addEventListener('input', renderLogs);
$('log-level').addEventListener('change', renderLogs);
$('toggle-stream').addEventListener('click', () => {
    paused = !paused;
    $('toggle-stream').textContent = paused ? 'Pokračovat' : 'Pozastavit';
    $('toggle-stream').setAttribute('aria-pressed', String(paused));
    activateLogs(active);
});
$('clear-logs').addEventListener('click', () => { records.length = 0; renderLogs(); });
window.addEventListener('pagehide', () => { stream?.close(); stream = null; });
window.addEventListener('pageshow', connectStream);
