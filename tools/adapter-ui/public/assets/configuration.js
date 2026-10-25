import { $, node, instant, request } from './dom.js';

let source = '';
let loaded = false;
let pending = null;

function definition(label, value, code = false) {
    const wrapper = node('div');
    const content = node('dd');
    content.append(node(code ? 'code' : 'span', '', value));
    wrapper.append(node('dt', '', label), content);
    return wrapper;
}

function schedule(task) {
    const item = node('section', 'schedule-item');
    const title = node('div', 'schedule-title');
    const identity = node('div');
    identity.append(node('h4', '', task.id), node('div', 'task-class', task.task));
    title.append(node('span', 'task-icon', '{}'), identity);
    const grid = node('dl', 'schedule-grid');
    grid.append(definition('Cron výraz', task.cron, true), definition('Časové pásmo', task.timezone));
    const options = node('div', 'schedule-options');
    options.append(node('span', `badge ${task.withoutOverlapping ? 'on' : 'off'}`, `Proti překryvu: ${task.withoutOverlapping ? 'zapnuto' : 'vypnuto'}`));
    options.append(node('span', `badge ${task.onOneServer ? 'on' : 'off'}`, `Jeden server: ${task.onOneServer ? 'ano' : 'ne'}`));
    options.append(node('p', 'option-note', task.withoutOverlapping
        ? `Zámek proti překryvu se uvolní po dokončení nebo zotavením po ${task.overlapExpiresAfterMinutes} min.`
        : 'Běhy se mohou překrývat. Historie se ukládá i bez této ochrany.'));
    if (task.onOneServer) options.append(node('p', 'option-note',
        'Každý naplánovaný výskyt získá jen jeden proces scheduleru. Rezervace zůstává i po dokončení.'));
    item.append(title, grid, options);
    return item;
}

function highlightJson(json) {
    const lines = [];
    for (const text of json.trimEnd().split('\n')) {
        const line = node('span', 'code-line');
        const expression = /("(?:\\.|[^"\\])*"\s*:)|("(?:\\.|[^"\\])*")|\b(true|false|null|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)\b/g;
        let offset = 0;
        for (const match of text.matchAll(expression)) {
            line.append(document.createTextNode(text.slice(offset, match.index)));
            line.append(node('span', match[1] ? 'json-key' : match[2] ? 'json-string' : 'json-value', match[0]));
            offset = match.index + match[0].length;
        }
        line.append(document.createTextNode(text.slice(offset)));
        lines.push(line);
    }
    $('configuration-json').replaceChildren(...lines);
}

export async function loadConfiguration(force = false) {
    if (loaded && !force) return;
    pending?.abort();
    const controller = new AbortController();
    pending = controller;
    $('refresh-configuration').disabled = true;
    $('configuration-notice').hidden = false;
    $('configuration-notice').classList.remove('error');
    $('configuration-notice').textContent = 'Načítání konfigurace…';
    try {
        const data = await request('/api/configuration', controller.signal);
        source = data.source;
        $('configuration-content').hidden = false;
        $('configuration-notice').hidden = true;
        $('schedule-count').textContent = data.tasks.length;
        $('metric-tasks').textContent = data.tasks.length;
        $('metric-overlap').textContent = data.tasks.filter((task) => task.withoutOverlapping).length;
        $('metric-single').textContent = data.tasks.filter((task) => task.onOneServer).length;
        $('schedule-list').replaceChildren(...data.tasks.map(schedule));
        $('configuration-empty').hidden = data.tasks.length > 0;
        $('configuration-version').textContent = `verze ${data.version}`;
        $('configuration-modified').textContent = `Změněno ${instant(data.modifiedAt)} UTC`;
        highlightJson(source);
        loaded = true;
    } catch (error) {
        if (error.name === 'AbortError') return;
        loaded = false;
        $('configuration-content').hidden = true;
        $('schedule-count').textContent = '—';
        $('configuration-notice').classList.add('error');
        $('configuration-notice').textContent = `Konfiguraci nelze načíst. ${error.message}`;
    } finally {
        if (pending === controller) $('refresh-configuration').disabled = false;
    }
}

$('refresh-configuration').addEventListener('click', () => loadConfiguration(true));
$('copy-configuration').addEventListener('click', async () => {
    try {
        await navigator.clipboard.writeText(source);
        $('copy-configuration').textContent = 'Zkopírováno';
    } catch {
        $('copy-configuration').textContent = 'Kopírování není dostupné';
    }
    setTimeout(() => { $('copy-configuration').textContent = 'Kopírovat'; }, 2000);
});
