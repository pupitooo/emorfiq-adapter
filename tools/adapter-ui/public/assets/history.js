import { $, node, request, dateCell, duration, statusBadge, instant, message } from './dom.js';

let pending = null;
let currentPage = 1;
let pageCount = 1;
let timer;
const form = $('history-filters');

function detailField(label, value) {
    const field = node('div');
    field.append(node('dt', '', label), node('dd', '', value ?? '—'));
    return field;
}

function showDetail(row) {
    $('run-detail-title').textContent = `Běh #${row.id}`;
    const grid = node('dl', 'detail-grid');
    grid.append(detailField('ID plánu', row.schedule_id), detailField('Stav', ''));
    grid.lastChild.lastChild.replaceChildren(statusBadge(row.status));
    grid.append(detailField('Naplánováno · UTC', instant(row.scheduled_for)), detailField('Začátek · UTC', instant(row.started_at)));
    grid.append(detailField('Dokončení · UTC', instant(row.finished_at)), detailField('Trvání', duration(row)));
    grid.append(detailField('Expirace ochrany · UTC', instant(row.lease_expires_at)), detailField('Detekce opuštění · UTC', instant(row.abandoned_at)));
    grid.append(detailField('Obnovující proces', row.abandoned_by), detailField('Třída tasku', row.task_class));
    const snapshot = node('section', 'detail-section');
    snapshot.append(node('h3', '', 'Nastavení uložené s během'), node('pre', '', JSON.stringify({
        task: row.task_class, cron: row.cron_expression, timezone: row.schedule_timezone,
        withoutOverlapping: row.without_overlapping,
        overlapExpiresAfterMinutes: row.overlap_expires_after_minutes, onOneServer: row.on_one_server,
    }, null, 2)));
    $('run-detail-content').replaceChildren(grid, snapshot);
    if (row.error_message) {
        const error = node('section', 'detail-section error');
        error.append(node('h3', '', 'Chybová zpráva'), node('pre', '', row.error_message));
        $('run-detail-content').append(error);
    }
    $('run-detail').showModal();
}

function historyRow(record) {
    const row = node('tr');
    const identity = node('td', 'run-id');
    const button = node('button', 'run-link', `#${record.id}`);
    button.type = 'button';
    button.setAttribute('aria-label', `Detail běhu #${record.id}`);
    button.addEventListener('click', () => showDetail(record));
    identity.append(button);
    row.append(identity, node('td', 'schedule-id', record.schedule_id));
    const task = node('td', 'history-task', record.task_class.split('\\').at(-1));
    task.title = record.task_class;
    const status = node('td');
    status.append(statusBadge(record.status));
    row.append(task, status, dateCell(record.scheduled_for), dateCell(record.started_at), dateCell(record.finished_at));
    row.append(node('td', 'mono date-cell', duration(record)));
    return row;
}

function updatePagination(data) {
    currentPage = data.page;
    pageCount = data.pages;
    const start = data.total ? (data.page - 1) * data.pageSize + 1 : 0;
    const end = Math.min(data.page * data.pageSize, data.total);
    $('history-range').textContent = `${start}–${end} z ${data.total.toLocaleString('cs-CZ')} běhů`;
    $('history-page').textContent = `Strana ${data.page} / ${data.pages}`;
    $('history-previous').disabled = currentPage <= 1;
    $('history-next').disabled = currentPage >= pageCount;
    $('history-total').textContent = `${data.total.toLocaleString('cs-CZ')} nalezených běhů`;
}

export async function loadHistory(page = 1) {
    clearTimeout(timer);
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    const parameters = new URLSearchParams();
    for (const [key, value] of new FormData(form)) if (String(value).trim()) parameters.set(key, String(value).trim());
    parameters.set('page', page);
    parameters.set('page_size', $('history-page-size').value);
    form.setAttribute('aria-busy', 'true');
    $('refresh-history').disabled = true;
    $('history-previous').disabled = true;
    $('history-next').disabled = true;
    $('history-total').textContent = 'Načítání…';
    try {
        const data = await request(`/api/runs?${parameters}`, controller.signal);
        $('history-rows').replaceChildren(...data.rows.map(historyRow));
        updatePagination(data);
        $('history-notice').hidden = data.rows.length > 0;
        if (!data.rows.length) message($('history-notice'), 'Žádné odpovídající běhy',
            'Zkuste upravit filtry. Nové záznamy se ukládají při rezervaci tasků s některou z ochran spouštění.');
    } catch (error) {
        if (error.name === 'AbortError') return;
        $('history-rows').replaceChildren();
        $('history-total').textContent = 'Historie není dostupná';
        $('history-range').textContent = '—';
        $('history-page').textContent = '—';
        message($('history-notice'), 'Historii nelze načíst', error.message, true);
    } finally {
        if (pending === controller) {
            form.setAttribute('aria-busy', 'false');
            $('refresh-history').disabled = false;
        }
    }
}

form.addEventListener('submit', (event) => { event.preventDefault(); loadHistory(); });
form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadHistory(), 400); });
form.addEventListener('change', () => loadHistory());
form.addEventListener('reset', () => { setTimeout(() => loadHistory(), 0); });
$('refresh-history').addEventListener('click', () => loadHistory(currentPage));
$('history-previous').addEventListener('click', () => loadHistory(currentPage - 1));
$('history-next').addEventListener('click', () => loadHistory(currentPage + 1));
