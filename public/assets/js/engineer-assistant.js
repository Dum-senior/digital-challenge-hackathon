'use strict';

(() => {
    const card = document.getElementById('engineerAssistant');
    if (!card) return;
    const button = document.getElementById('engineerAssistantRun');
    const status = document.getElementById('engineerAssistantStatus');
    const result = document.getElementById('engineerAssistantResult');
    const element = (tag, text) => {
        const node = document.createElement(tag);
        node.textContent = text;
        return node;
    };
    const link = source => {
        const node = element('a', source.title);
        const url = new URL(source.url, window.location.origin);
        if (url.origin === window.location.origin && /^\/(agent|admin)\//.test(url.pathname)) {
            node.href = url.href;
        }
        return node;
    };
    button.addEventListener('click', async () => {
        button.disabled = true;
        result.replaceChildren();
        card.setAttribute('aria-busy', 'true');
        status.textContent = 'Ищем инструкции и решения…';
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000);
        try {
            const response = await fetch(card.dataset.url, {
                method: 'POST', credentials: 'same-origin',
                headers: {Accept: 'application/json'},
                body: new URLSearchParams({_token: card.dataset.token}), signal: controller.signal
            });
            if (!response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Обновите страницу и проверьте вход в систему.');
            }
            const data = await response.json();
            if (!response.ok) throw new Error(data.error || 'Не удалось выполнить поиск.');
            const messages = {
                answered: 'Предлагаемые шаги — проверьте их по источникам перед выполнением.',
                sources_only: 'Найдены источники. Локальная модель отключена, недоступна или не смогла подтвердить решение.',
                no_sources: 'Подтверждённое решение не найдено. Уточните симптомы или добавьте инструкцию в базу знаний.'
            };
            status.textContent = messages[data.status] || 'Поиск временно недоступен.';
            const sources = new Map(data.sources.map(source => [source.id, source]));
            if (data.safety) {
                result.append(element('h6', 'Безопасность — действуйте сразу'));
                if (!data.steps.length) result.append(element('p', 'Прекратите использование и сообщите ответственному. Действуйте по правилам безопасности объекта.'));
            }
            if (data.steps.length) {
                const list = document.createElement('ol');
                for (const step of data.steps) {
                    const item = element('li', step.text);
                    for (const id of step.source_ids) {
                        const source = sources.get(id);
                        if (source) item.append(document.createTextNode(' — '), link(source));
                    }
                    list.append(item);
                }
                result.append(list);
            }
            for (const source of data.sources) {
                const article = document.createElement('article');
                article.className = 'border-top pt-3 mt-3';
                article.append(link(source), element('p', source.excerpt));
                result.append(article);
            }
        } catch (error) {
            result.replaceChildren();
            status.textContent = error.name === 'AbortError'
                ? 'Поиск занял слишком много времени. Повторите позже.' : error.message;
        } finally {
            clearTimeout(timer);
            card.removeAttribute('aria-busy');
            button.disabled = false;
        }
    });
})();
