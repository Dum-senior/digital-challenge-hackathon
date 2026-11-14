(() => {
  'use strict';
  const root = document.getElementById('ai-intake');
  if (!root) return;
  const el = id => document.getElementById(id);
  const csrf = root.dataset.csrf;
  const failures = {
    400: 'Проверьте данные и попробуйте снова.',
    401: 'Войдите снова, чтобы продолжить.',
    403: 'Для этого действия нужны дополнительные права.',
    404: 'Черновик не найден.',
    409: 'Черновик изменился. Откройте его снова.',
    413: 'Файл должен быть не больше 20 МиБ и не длиннее 120 секунд.',
    415: 'Формат аудио не поддерживается.',
    422: 'Проверьте заполненные поля.',
    429: 'Слишком много запросов. Повторите через минуту.',
    503: 'Сервис временно недоступен. Повторите попытку.'
  };
  let current = null, meta = null;
  const statuses = new Map(Object.entries({
    needs_clarification: 'Нужно уточнение', awaiting_review: 'На проверке',
    failed: 'Разбор недоступен. Заполните заявку вручную.', created: 'Заявка создана'
  }));
  const labels = new Map(Object.entries({
    subject: 'Тема', description: 'Описание', object: 'Объект', equipment: 'Оборудование',
    incident_time: 'Время события', floor: 'Этаж', room: 'Кабинет',
    serial_number: 'Серийный номер', affected_scope: 'Масштаб отказа',
    symptoms: 'Симптомы', ip_addresses: 'IP-адреса', error_codes: 'Коды ошибок',
    Low: 'Низкий', Medium: 'Средний', Normal: 'Обычный', High: 'Высокий', Critical: 'Критический'
  }));
  const error = msg => {
    const notice = el('intakeError');
    notice.textContent = msg;
    notice.hidden = !msg;
    if (msg) notice.scrollIntoView({ block: 'nearest' });
  };
  async function req(path, method = 'GET', body) {
    const response = await fetch(path, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { Accept: 'application/json', ...(method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}) },
      ...(method === 'POST' ? { body: JSON.stringify(body) } : {})
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok || response.redirected) throw new Error(failures[response.redirected ? 401 : response.status] || 'Не удалось выполнить запрос.');
    return json;
  }
  const add = (parent, tag, value) => { const node = document.createElement(tag); node.textContent = String(value ?? '—'); parent.appendChild(node); return node; };
  function select(id, values, currentValue) {
    const element = el(id);
    element.replaceChildren();
    const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'Не выбрано'; element.appendChild(empty);
    for (const option of values) {
      const node = document.createElement('option'); node.value = option.id;
      node.textContent = labels.get(option.name) || option.name; element.appendChild(node);
    }
    element.value = currentValue == null ? '' : String(currentValue);
  }
  function selectedNumber(id) { const val = el(id).value; return val === '' ? null : Number(val); }
  function renderDraft(d) {
    current = d;
    error('');
    el('intakeDraft').hidden = false;
    el('intakeDraftId').textContent = String(d.draft_id);
    el('intakeStatus').textContent = statuses.get(d.status) || 'Черновик';
    el('intakeAnswer').value = '';
    el('intakeOriginal').textContent = d.raw_text || '';
    const recommendation = el('intakeRecommendation');
    recommendation.textContent = meta?.can_confirm ? d.recommendation?.reason || '' : '';
    recommendation.hidden = !recommendation.textContent;
    for (const button of el('intakeQueue').querySelectorAll('button[data-id]')) {
      button.setAttribute('aria-current', String(Number(button.dataset.id) === d.draft_id));
    }
    const facts = el('intakeFacts'); facts.replaceChildren();
    const info = d.approved?.fields || d.extracted || {};
    const location = d.extracted?.location;
    for (const [label, value] of Object.entries({
      'Название': info.object, 'Адрес': location?.address, 'Счётчик': info.meter_number,
      'Начало': info.started_at ?? info.incident_time, 'Кабинет': info.room, 'Важное': info.useful_notes
    })) {
      if (!value) continue;
      add(facts, 'dt', label);
      add(facts, 'dd', value);
    }
    el('intakeFactsTitle').hidden = !facts.children.length;
    el('intakeSolution').hidden = true;
    const priority = el('intakePriorityReason');
    priority.textContent = d.extracted?.severity_reason || '';
    const emergency = el('intakeEmergency');
    emergency.hidden = !d.extracted?.is_potential_emergency;
    emergency.textContent = d.extracted?.is_potential_emergency ? 'Потенциальная авария: действуйте по утверждённому регламенту. ИИ не разрешает опасные действия.' : '';
    const questions = el('intakeQuestions'); questions.replaceChildren();
    for (const q of (d.questions || [])) add(questions, 'li', q);
    el('intakeClarification').hidden = d.status === 'created' || !(d.questions || []).length;
    if (meta?.can_confirm) {
      el('intakeReview').hidden = d.status === 'created';
      const a = d.approved || {};
      el('reviewSubject').value = a.subject || d.extracted?.subject || '';
      el('reviewDescription').value = a.description || d.raw_text || '';
      el('reviewMeter').value = a.fields?.meter_number || '';
      el('reviewNotes').value = a.fields?.useful_notes || '';
      el('reviewReason').value = '';
      select('reviewType', meta.types || [], a.type_id);
      select('reviewPriority', meta.priorities || [], a.priority_id);
      select('reviewLocation', meta.locations || [], a.location_id);
      select('reviewGroup', meta.groups || [], a.group_id);
      select('reviewAssignee', (meta.agents || []).filter(x => !selectedNumber('reviewGroup') || Number(x.group_id) === selectedNumber('reviewGroup')).map(x => ({ id:x.id, name:x.first_name + ' ' + x.last_name })), a.assigned_to);
      const af = a.fields || d.extracted || {};
      el('reviewObject').value = af.object || '';
      el('reviewRoom').value = af.room || '';
      el('reviewIncidentTime').value = af.started_at || af.incident_time || '';
      const tagBox = el('reviewTags'); tagBox.replaceChildren();
      for (const tag of (meta.tags || [])) {
        const option = document.createElement('option');
        option.value = tag.id; option.textContent = tag.name;
        option.selected = (a.tag_ids || []).includes(Number(tag.id));
        tagBox.appendChild(option);
      }
      el('reviewAcknowledge').checked = false;
    } else el('intakeReview').hidden = true;
    const created = el('intakeCreated'); created.hidden = d.status !== 'created';
    if (d.status === 'created') {
      created.replaceChildren();
      add(created, 'strong', 'Заявка создана: ');
      const link = document.createElement('a'); link.href = (meta?.ticket_url_prefix || '/portal/tickets/') + encodeURIComponent(d.ticket_id);
      link.textContent = 'Открыть заявку №' + d.ticket_id; created.appendChild(link);
    }
  }
  async function loadQueue() {
    const result = await req('/intake/drafts');
    const ul = el('intakeQueue'); ul.replaceChildren();
    const count = el('intakeCount');
    count.textContent = String(result.data.length);
    count.hidden = false;
    for (const d of result.data) {
      const li = document.createElement('li');
      const button = document.createElement('button'); button.type = 'button';
      button.dataset.id = d.id;
      button.setAttribute('aria-current', String(Number(d.id) === current?.draft_id));
      button.textContent = `№${d.id} · ${statuses.get(d.status) || 'Черновик'}`;
      button.addEventListener('click', async () => {
        await run(async () => {
          renderDraft(await req('/intake/drafts/' + d.id));
          el('intakeDraft').scrollIntoView({ block: 'start' });
        });
      }); li.appendChild(button); ul.appendChild(li);
    }
    if (result.data.length === 0) add(ul, 'li', 'Черновиков пока нет');
  }
  const record = el('intakeRecord');
  const upload = el('intakeUpload');
  const analyze = el('intakeAnalyze');
  const recordingStatus = el('intakeRecordingStatus');
  const textInput = el('intakeText');
  const sourceInput = el('intakeSource');
  let recorder;
  let busy = false;
  let pendingRequest;

  function setBusy(value) {
    busy = value;
    record.disabled = busy && recorder?.state !== 'recording';
    upload.disabled = analyze.disabled = textInput.readOnly = busy;
    el('intakeResolve').disabled = el('intakeClarify').disabled = el('reviewConfirm').disabled = el('intakeReload').disabled = busy;
  }

  async function run(action) {
    if (busy) return;
    error('');
    setBusy(true);
    try { await action(); }
    catch (e) { error(e instanceof TypeError ? 'Нет связи с сервером.' : e.message); }
    finally { setBusy(false); }
  }

  async function transcribe(file, source) {
    if (!file.size) throw new Error('Аудиозапись пуста.');
    if (file.size > 20 * 1024 * 1024) throw new Error(failures[413]);
    recordingStatus.textContent = 'Распознаём речь…';
    try {
      const body = new FormData();
      body.append('audio', file);
      body.append('source', source);
      const response = await fetch('/intake/transcriptions', {
        method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-Token': csrf }, body
      });
      const result = await response.json().catch(() => ({}));
      if (!response.ok || !result.text) {
        throw new Error(response.redirected ? failures[401] :
          response.status === 400 ? 'Речь не распознана или запись повреждена.' :
          failures[response.status] || 'Не удалось распознать запись.');
      }
      textInput.value = result.text.slice(0, 4000);
      sourceInput.value = 'voice';
      recordingStatus.textContent = 'Текст готов. Проверьте его перед отправкой.';
    } catch (e) {
      recordingStatus.textContent = '';
      throw e;
    }
  }

  upload.addEventListener('change', async () => {
    const file = upload.files[0];
    upload.value = '';
    if (file) await run(() => transcribe(file, 'upload'));
  });

  record.addEventListener('click', async () => {
    if (recorder?.state === 'recording') {
      recorder.stop();
      return;
    }
    if (busy) return;
    setBusy(true);
    error('');
    let stream;
    try {
      if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
        throw new Error('Запись недоступна. Используйте HTTPS или загрузите аудиофайл.');
      }
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      recorder = new MediaRecorder(stream);
      const chunks = [];
      let timer;
      let failed = false;
      recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data); };
      recorder.onerror = () => { failed = true; };
      recorder.onstop = async () => {
        clearTimeout(timer);
        stream.getTracks().forEach(track => track.stop());
        const mime = recorder.mimeType;
        recorder = null;
        setBusy(true);
        record.setAttribute('aria-pressed', 'false');
        record.textContent = 'Записать голос';
        try {
          if (failed) throw new Error('Запись прервалась. Попробуйте ещё раз.');
          const extension = mime.includes('mp4') ? 'm4a' : mime.includes('ogg') ? 'ogg' : 'webm';
          await transcribe(new File(chunks, 'recording.' + extension, { type: mime }), 'browser');
        } catch (e) {
          recordingStatus.textContent = '';
          error(e instanceof TypeError ? 'Нет связи с сервером.' : e.message);
        } finally { setBusy(false); }
      };
      recorder.start();
      timer = setTimeout(() => { if (recorder?.state === 'recording') recorder.stop(); }, 119000);
      setBusy(true);
      record.setAttribute('aria-pressed', 'true');
      record.textContent = 'Остановить запись';
      recordingStatus.textContent = 'Идёт запись…';
    } catch (e) {
      stream?.getTracks().forEach(track => track.stop());
      recorder = null;
      error(e.name === 'Error' ? e.message : 'Проверьте разрешение на микрофон или загрузите аудиофайл.');
      setBusy(false);
    }
  });

  analyze.addEventListener('click', () => run(async () => {
    const text = textInput.value.trim();
    const source = sourceInput.value;
    if (text.length < 3) throw new Error('Введите описание обращения.');
    if (!pendingRequest || pendingRequest.text !== text || pendingRequest.source !== source) {
      pendingRequest = { text, source, request_id: crypto.randomUUID?.() || Math.random().toString(36).slice(2) };
    }
    renderDraft(await req('/intake/analyze', 'POST', pendingRequest));
    pendingRequest = null;
    textInput.value = '';
    sourceInput.value = 'text';
    recordingStatus.textContent = '';
    el('intakeDraft').scrollIntoView({ block: 'start' });
    await loadQueue();
  }));

  el('intakeClarify').addEventListener('click', () => run(async () => {
    if (!current) return;
    const answer = el('intakeAnswer').value.trim();
    if (!answer) throw new Error('Введите ответ на уточнение.');
    renderDraft(await req('/intake/drafts/' + current.draft_id + '/clarify', 'POST', {
      revision: current.revision, answer
    }));
    await loadQueue();
  }));

  el('reviewGroup').addEventListener('change', () => {
    const id = selectedNumber('reviewGroup');
    select('reviewAssignee', (meta?.agents || []).filter(x => Number(x.group_id) === id).map(x => ({id:x.id,name:x.first_name+' '+x.last_name})), null);
  });
  el('reviewConfirm').addEventListener('click', () => run(async () => {
    if (!current || !el('reviewAcknowledge').checked) throw new Error('Отметьте, что данные проверены.');
    const reason = el('reviewReason').value.trim();
    const priority = selectedNumber('reviewPriority');
    const emergency = current.extracted?.is_potential_emergency;
    if (priority === null) throw new Error('Выберите приоритет заявки.');
    if ((current.status !== 'awaiting_review' || (emergency && current.recommendation?.priority_id != null
      && Number(current.recommendation.priority_id) !== priority)) && reason.length < 20) {
      throw new Error('Добавьте обоснование ручного решения, минимум 20 символов.');
    }
    if (!el('reviewSubject').value.trim() || !el('reviewDescription').value.trim()) {
      throw new Error('Заполните тему и описание заявки.');
    }
    const output = await req('/intake/drafts/' + current.draft_id + '/confirm', 'POST', {
        revision: current.revision, approve: true,
        manual_review_reason: reason,
        approved: {
          subject: el('reviewSubject').value, description: el('reviewDescription').value,
          type_id: selectedNumber('reviewType'), priority_id: selectedNumber('reviewPriority'),
          location_id: selectedNumber('reviewLocation'), group_id: selectedNumber('reviewGroup'),
          assigned_to: selectedNumber('reviewAssignee'),
          tag_ids: Array.from(el('reviewTags').selectedOptions, option => Number(option.value)),
          fields: { ...((current.approved || {}).fields || {}),
            object: el('reviewObject').value || null, meter_number: el('reviewMeter').value || null,
            started_at: el('reviewIncidentTime').value || null, incident_time: null,
            room: el('reviewRoom').value || null, useful_notes: el('reviewNotes').value || null }
        }
      });
    renderDraft({ ...current, ...output });
    await loadQueue();
  }));
  el('intakeResolve').addEventListener('click', () => run(async () => {
    if (!current) return;
    const solution = el('intakeSolution');
    solution.hidden = true;
    const description = meta?.can_confirm && current.status !== 'created' ? el('reviewDescription').value : current.raw_text;
    const result = await req('/intake/resolve', 'POST', {draft_id: current.draft_id, text: description});
    if (result.status !== 'suggested' || !result.steps?.length || !result.sources?.length) return;
    for (const [id, values] of [['intakeSteps', result.steps], ['intakeSources', result.sources.map(source => source.section)]]) {
      const list = el(id);
      list.replaceChildren();
      for (const value of values) add(list, 'li', value);
    }
    solution.hidden = false;
  }));
  el('intakeReload').addEventListener('click', () => run(refresh));
  async function refresh() {
    meta = await req('/intake/meta');
    await loadQueue();
  }
  void run(async () => {
    try { await refresh(); }
    finally {
      const queue = el('intakeQueue');
      if (el('intakeCount').hidden) {
        queue.replaceChildren();
        add(queue, 'li', 'Не удалось загрузить. Нажмите «Обновить».');
      }
    }
  });
})();
