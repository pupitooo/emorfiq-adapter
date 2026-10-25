export const $ = (id) => document.getElementById(id);

export function node(tag, className = '', text = '') {
    const result = document.createElement(tag);
    if (className) result.className = className;
    result.textContent = String(text);
    return result;
}

const dateFormat = new Intl.DateTimeFormat('cs-CZ', { timeZone: 'UTC', day: '2-digit', month: '2-digit', year: 'numeric' });
const timeFormat = new Intl.DateTimeFormat('cs-CZ', { timeZone: 'UTC', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });

export function instant(value) {
    const timestamp = value ? Date.parse(value) : NaN;
    return Number.isFinite(timestamp) ? `${dateFormat.format(timestamp)} ${timeFormat.format(timestamp)}` : '—';
}

export function dateCell(value) {
    const cell = node('td', 'date-cell');
    const timestamp = value ? Date.parse(value) : NaN;
    if (Number.isFinite(timestamp)) cell.append(node('div', '', dateFormat.format(timestamp)), node('span', '', timeFormat.format(timestamp)));
    else cell.textContent = '—';
    return cell;
}

export const states = {
    in_progress: 'Probíhá', succeeded: 'Úspěšný', failed: 'Chyba', abandoned: 'Opuštěný',
};

export function statusBadge(status) {
    return node('span', `badge ${Object.hasOwn(states, status) ? status : 'off'}`, states[status] ?? status);
}

export function duration(row) {
    if (!row.finished_at) return '—';
    const milliseconds = Date.parse(row.finished_at) - Date.parse(row.started_at);
    if (!Number.isFinite(milliseconds) || milliseconds < 0) return '—';
    if (milliseconds < 1000) return `${Math.round(milliseconds)} ms`;
    if (milliseconds < 60000) return `${(milliseconds / 1000).toLocaleString('cs-CZ', { maximumFractionDigits: 2 })} s`;
    return `${Math.floor(milliseconds / 60000)} min ${Math.floor(milliseconds / 1000) % 60} s`;
}

export async function request(url, signal) {
    const response = await fetch(url, { signal, headers: { Accept: 'application/json' }, cache: 'no-store' });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error ?? 'Data se nepodařilo načíst.');
    return data;
}

export function message(container, heading, description = '', error = false) {
    container.classList.toggle('error', error);
    container.replaceChildren(node('strong', '', heading), node('p', '', description));
    container.hidden = false;
}
