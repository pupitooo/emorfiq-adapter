import { $, node, request, dateCell, duration, statusBadge, instant, message, protectionBadges, runLockBadges } from './dom.js';

let pending = null;
let currentPage = 1;
let pageCount = 1;
let timer;
let detailPending = null;
const form = $('history-filters');

function detailField(label, value) {
    const field = node('div');
    field.append(node('dt', '', label), node('dd', '', value ?? '—'));
    return field;
}

function lockDetail(row) {
    const section = node('section', 'detail-section');
    section.append(node('h3', '', 'Zámky tohoto běhu nyní'));
    const cards = node('div', 'lock-cards');
    const overlap = node('div', 'lock-card');
    overlap.append(node('h4', '', 'Proti překryvu'));
    const overlapState = !row.without_overlapping ? ['off', 'Ochrana vypnutá', 'Běhy tohoto plánu se mohou překrývat.']
        : row.overlap_locked ? ['on', 'Zámek držen', 'Další chráněný běh tohoto plánu se přeskočí.']
        : row.overlap_expired ? ['abandoned', 'Po expiraci', 'Čeká na zotavení. Expirace sama běžící task neukončí.']
        : ['off', 'Zámek není uložený', 'Tomuto běhu už nepatří zámek proti překryvu.'];
    overlap.append(node('span', `badge ${overlapState[0]}`, overlapState[1]), node('p', '', overlapState[2]));
    const occurrence = node('div', 'lock-card');
    occurrence.append(node('h4', '', 'Jeden server · onOneServer'));
    const occurrenceState = !row.on_one_server ? ['off', 'Ochrana vypnutá', 'Stejný výskyt může spustit více procesů scheduleru.']
        : row.occurrence_reserved ? ['in_progress', 'Výskyt rezervován', 'Rezervace brání opakování tohoto výskytu i po dokončení běhu.']
        : ['off', 'Rezervace není uložená', 'Tomuto běhu aktuálně nepatří rezervace výskytu.'];
    occurrence.append(node('span', `badge ${occurrenceState[0]}`, occurrenceState[1]), node('p', '', occurrenceState[2]));
    cards.append(overlap, occurrence);
    section.append(cards, node('p', 'detail-note', 'Stav zámků odpovídá okamžiku načtení detailu. Nastavení při spuštění je uložené zvlášť.'));
    return section;
}

function renderDetail(row) {
    $('run-detail-title').textContent = `Běh #${row.id}`;
    const grid = node('dl', 'detail-grid');
    grid.append(detailField('ID plánu', row.schedule_id), detailField('Stav', ''));
    grid.lastChild.lastChild.replaceChildren(statusBadge(row.status));
    grid.append(detailField('Naplánováno · UTC', instant(row.scheduled_for)), detailField('Začátek · UTC', instant(row.started_at)));
    grid.append(detailField('Dokončení · UTC', instant(row.finished_at)), detailField('Trvání', duration(row)));
    grid.append(detailField('Původní expirace proti překryvu · UTC', instant(row.lease_expires_at)), detailField('Detekce opuštění · UTC', instant(row.abandoned_at)));
    grid.append(detailField('Obnovující proces', row.abandoned_by), detailField('Třída tasku', row.task_class));
    const snapshot = node('section', 'detail-section');
    snapshot.append(node('h3', '', 'Nastavení uložené s během'), node('pre', '', JSON.stringify({
        task: row.task_class, cron: row.cron_expression, timezone: row.schedule_timezone,
        withoutOverlapping: row.without_overlapping,
        overlapExpiresAfterMinutes: row.overlap_expires_after_minutes, onOneServer: row.on_one_server,
    }, null, 2)));
    $('run-detail-content').replaceChildren(grid, lockDetail(row), snapshot);
    if (row.error_message) {
        const error = node('section', 'detail-section error');
        error.append(node('h3', '', 'Chybová zpráva'), node('pre', '', row.error_message));
        $('run-detail-content').append(error);
    }
}

export async function showRunDetail(id) {
    detailPending?.abort();
    const controller = new AbortController();
    detailPending = controller;
    $('run-detail-title').textContent = `Běh #${id}`;
    $('run-detail-content').replaceChildren(node('p', 'detail-note', 'Načítání aktuálního stavu běhu a jeho zámků…'));
    $('run-detail-content').setAttribute('aria-busy', 'true');
    if (!$('run-detail').open) $('run-detail').showModal();
    try {
        const data = await request(`/api/runs?${new URLSearchParams({ id, page_size: '25' })}`, controller.signal);
        if (!data.rows.length) {
            const notice = node('div', 'notice');
            message(notice, 'Běh už není v historii', 'Jeho zámky mohou zůstat uložené i po smazání historie.');
            $('run-detail-content').replaceChildren(notice);
        } else renderDetail(data.rows[0]);
    } catch (error) {
        if (error.name === 'AbortError') return;
        const notice = node('div', 'notice');
        message(notice, 'Detail nelze načíst', error.message, true);
        $('run-detail-content').replaceChildren(notice);
    } finally {
        if (detailPending === controller) $('run-detail-content').setAttribute('aria-busy', 'false');
    }
}

$('run-detail').addEventListener('close', () => detailPending?.abort());

function historyRow(record) {
    const row = node('tr');
    const identity = node('td', 'run-id');
    const button = node('button', 'run-link', `#${record.id}`);
    button.type = 'button';
    button.setAttribute('aria-label', `Detail běhu #${record.id}`);
    button.addEventListener('click', () => showRunDetail(record.id));
    identity.append(button);
    row.append(identity, node('td', 'schedule-id', record.schedule_id));
    const task = node('td', 'history-task', record.task_class.split('\\').at(-1));
    task.title = record.task_class;
    const status = node('td');
    status.append(statusBadge(record.status));
    const protections = node('td');
    protections.append(protectionBadges(record));
    const locks = node('td');
    locks.append(runLockBadges(record));
    row.append(task, status, protections, locks, dateCell(record.scheduled_for), dateCell(record.started_at), dateCell(record.finished_at));
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
            'Zkuste upravit filtry. Nové záznamy se ukládají před spuštěním tasků bez ohledu na nastavení ochran.');
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
