import { $, node, request, dateCell, lockStateBadge, message } from './dom.js';
import { showRunDetail } from './history.js';

const form = $('lock-filters');
let pending = null;
let currentPage = 1;
let pageCount = 1;
let timer;

function lockRow(record) {
    const row = node('tr');
    const type = node('td');
    type.append(node('span', 'lock-type', record.lock_type === 'overlap' ? 'Proti překryvu' : 'Jeden server'));
    const state = node('td');
    state.append(lockStateBadge(record.lock_state));
    const run = node('td', 'run-id');
    if (record.run_id) {
        const button = node('button', 'run-link', `#${record.run_id}`);
        button.type = 'button';
        button.setAttribute('aria-label', `Detail běhu #${record.run_id}`);
        button.addEventListener('click', () => showRunDetail(record.run_id));
        run.append(button);
    } else run.append(node('span', 'badge off', 'Historie smazána'));
    row.append(node('td', 'schedule-id', record.schedule_id), type, state, run,
        dateCell(record.scheduled_for), dateCell(record.expires_at));
    if (record.lock_type === 'overlap') row.children[4].textContent = 'Celý plán';
    if (record.lock_type === 'occurrence') row.children[5].textContent = 'Bez expirace';
    return row;
}

function updatePagination(data) {
    currentPage = data.page;
    pageCount = data.pages;
    const start = data.total ? (data.page - 1) * data.pageSize + 1 : 0;
    const end = Math.min(data.page * data.pageSize, data.total);
    $('lock-range').textContent = `${start}–${end} z ${data.total.toLocaleString('cs-CZ')} ${data.total === 1 ? 'zámku' : 'zámků'}`;
    $('lock-page').textContent = `Strana ${data.page} / ${data.pages}`;
    $('lock-previous').disabled = currentPage <= 1;
    $('lock-next').disabled = currentPage >= pageCount;
    $('lock-total').textContent = `Nalezené zámky: ${data.total.toLocaleString('cs-CZ')}`;
}

export async function loadLocks(page = 1) {
    clearTimeout(timer);
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    const parameters = new URLSearchParams();
    for (const [key, value] of new FormData(form)) if (String(value).trim()) parameters.set(key, String(value).trim());
    parameters.set('page', page);
    parameters.set('page_size', $('lock-page-size').value);
    form.setAttribute('aria-busy', 'true');
    $('refresh-locks').disabled = true;
    $('lock-previous').disabled = true;
    $('lock-next').disabled = true;
    $('lock-total').textContent = 'Načítání…';
    try {
        const data = await request(`/api/locks?${parameters}`, controller.signal);
        $('lock-rows').replaceChildren(...data.rows.map(lockRow));
        updatePagination(data);
        $('lock-notice').hidden = data.rows.length > 0;
        if (!data.rows.length) message($('lock-notice'), 'Žádné odpovídající zámky',
            'Zkuste upravit filtry. Běhy bez zapnutých ochran zámky nevytvářejí.');
    } catch (error) {
        if (error.name === 'AbortError') return;
        $('lock-rows').replaceChildren();
        $('lock-total').textContent = 'Zámky nejsou dostupné';
        $('lock-range').textContent = '—';
        $('lock-page').textContent = '—';
        message($('lock-notice'), 'Zámky nelze načíst', error.message, true);
    } finally {
        if (pending === controller) {
            form.setAttribute('aria-busy', 'false');
            $('refresh-locks').disabled = false;
        }
    }
}

form.addEventListener('submit', (event) => { event.preventDefault(); loadLocks(); });
form.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => loadLocks(), 400); });
form.addEventListener('change', () => loadLocks());
form.addEventListener('reset', () => { setTimeout(() => loadLocks(), 0); });
$('refresh-locks').addEventListener('click', () => loadLocks(currentPage));
$('lock-previous').addEventListener('click', () => loadLocks(currentPage - 1));
$('lock-next').addEventListener('click', () => loadLocks(currentPage + 1));
