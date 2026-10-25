import { $ } from './dom.js';
import { loadConfiguration } from './configuration.js';
import { loadHistory } from './history.js';
import { loadLocks } from './locks.js';
import { activateLogs } from './logs.js';

const tabs = [...document.querySelectorAll('[role="tab"]')];
const sections = ['configuration', 'history', 'locks', 'logs'];
let selectedSection = null;

function selectTab(section) {
    if (!sections.includes(section)) section = 'configuration';
    if (section === selectedSection) return;
    selectedSection = section;
    for (const tab of tabs) {
        const active = tab.id === `tab-${section}`;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        $(tab.getAttribute('aria-controls')).hidden = !active;
    }
    activateLogs(section === 'logs');
    if (section === 'configuration') loadConfiguration();
    if (section === 'history') loadHistory();
    if (section === 'locks') loadLocks();
    document.title = `${{ configuration: 'Konfigurace', history: 'Historie tasků', locks: 'Zámky', logs: 'Logy' }[section]} · emorfiq`;
}

function navigate(section) {
    if (location.hash === `#${section}`) selectTab(section);
    else location.hash = section;
}

for (const [index, tab] of tabs.entries()) {
    tab.addEventListener('click', () => navigate(sections[index]));
    tab.addEventListener('keydown', (event) => {
        const next = { ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length,
            Home: 0, End: tabs.length - 1 }[event.key];
        if (next !== undefined) {
            event.preventDefault();
            navigate(sections[next]);
            tabs[next].focus();
        }
    });
}
window.addEventListener('hashchange', () => selectTab(location.hash.slice(1)));
selectTab(location.hash.slice(1));
