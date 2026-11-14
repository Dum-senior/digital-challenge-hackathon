(() => {
  'use strict';
  const root = document.getElementById('ai-intake');
  if (!root) return;
  const el = id => document.getElementById(id);
  const csrf = root.dataset.csrf;
  let current = null, meta = null;
  const error = msg => { const x = el('intakeError'); x.textContent = msg; x.hidden = !msg; };
  const btn = (target, busy) => { target.disabled = busy; };
  async function req(path, method = 'GET', body) {
    const response = await fetch(path, {
      method, credentials: 'same-origin', cache: 'no-store',
      headers: { ...(method === 'POST' ? { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf } : {}) },
      ...(method === 'POST' ? { body: JSON.stringify(body) } : {})
    });
    const json = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(json.error || ('HTTP ' + response.status));
    return json;
  }
  const add = (parent, tag, value) => { const node = document.createElement(tag); node.textContent = String(value ?? '—'); parent.appendChild(node); return node; };
  function select(id, values, currentValue) {
    const element = el(id);
    element.replaceChildren();
    const empty = document.createElement('option'); empty.value = ''; empty.textContent = 'Не выбрано'; element.appendChild(empty);
    for (const option of values) {
      const node = document.createElement('option'); node.value = option.id;
      node.textContent = option.name; element.appendChild(node);
    }
    element.value = currentValue == null ? '' : String(currentValue);
  }
  function selectedNumber(id) { const val = el(id).value; return val === '' ? null : Number(val); }
  async function renderDraft(d) {
    current = d;
    error('');
    el('intakeDraft').hidden = false;
    el('intakeDraftId').textContent = String(d.draft_id);
    el('intakeRevision').textContent = String(d.revision);
    el('intakeStatus').textContent = d.status;
    el('intakeAnswer').value = '';
    const facts = el('intakeFacts'); facts.replaceChildren();
    for (const [field, value] of Object.entries(d.extracted || {})) {
      if (typeof value === 'object' && value !== null) continue;
      const name = add(facts, 'dt', field); name.className = 'col-sm-4';
      const val = add(facts, 'dd', value); val.className = 'col-sm-8';
    }
    const emergency = el('intakeEmergency');
    emergency.hidden = !d.extracted?.is_potential_emergency;
    emergency.textContent = d.extracted?.is_potential_emergency ? 'Потенциальная авария: действуйте по утверждённому регламенту. ИИ не разрешает опасные действия.' : '';
    const questions = el('intakeQuestions'); questions.replaceChildren();
    for (const q of (d.questions || [])) add(questions, 'li', q);
    el('intakeClarify').disabled = d.status === 'created';
    if (meta?.can_confirm) {
      el('intakeReview').hidden = d.status === 'created';
      const a = d.approved || {};
      el('reviewSubject').value = a.subject || d.extracted?.subject || '';
      el('reviewDescription').value = a.description || d.raw_text || '';
      select('reviewType', meta.types || [], a.type_id);
      select('reviewPriority', meta.priorities || [], a.priority_id);
      select('reviewLocation', meta.locations || [], a.location_id);
      select('reviewGroup', meta.groups || [], a.group_id);
      select('reviewAssignee', (meta.agents || []).filter(x => !selectedNumber('reviewGroup') || Number(x.group_id) === selectedNumber('reviewGroup')).map(x => ({ id:x.id, name:x.first_name + ' ' + x.last_name })), a.assigned_to);
      const af = a.fields || d.extracted || {};
      const fieldMap = {reviewObject:'object',reviewEquipment:'equipment',reviewIncidentTime:'incident_time',
        reviewFloor:'floor', reviewRoom:'room',reviewSerial:'serial_number',reviewScope:'affected_scope'};
      for (const [input,field] of Object.entries(fieldMap)) el(input).value = af[field] || '';
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
      link.textContent = '#' + d.ticket_id + ' — открыть карточку / «Найти решение»'; created.appendChild(link);
    }
  }
  async function loadQueue() {
    const result = await req('/intake/drafts');
    const ul = el('intakeQueue'); ul.replaceChildren();
    for (const d of result.data) {
      const li = document.createElement('li'); li.className = 'list-group-item';
      const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-link';
      button.textContent = `#${d.id} · ${d.source} · ${d.status} · версия ${d.revision}`;
      button.addEventListener('click', async () => {
        try { await renderDraft(await req('/intake/drafts/' + d.id)); } catch (e) { error(e.message); }
      }); li.appendChild(button); ul.appendChild(li);
    }
    if (result.data.length === 0) add(ul, 'li', 'Черновиков пока нет');
  }
  const record = el('intakeRecord');
  const recordingStatus = el('intakeRecordingStatus');
  let recorder;
  let recordingTimer;
  record.addEventListener('click', async () => {
    if (recorder?.state === 'recording') {
      recorder.stop();
      return;
    }
    record.disabled = true;
    error('');
    let stream;
    try {
      if (!navigator.mediaDevices?.getUserMedia || !window.MediaRecorder) {
        throw new Error('Запись голоса недоступна в этом браузере.');
      }
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      recorder = new MediaRecorder(stream);
      const chunks = [];
      recorder.ondataavailable = event => { if (event.data.size) chunks.push(event.data); };
      recorder.onstop = async () => {
        clearTimeout(recordingTimer);
        stream.getTracks().forEach(track => track.stop());
        record.disabled = true;
        record.setAttribute('aria-pressed', 'false');
        record.textContent = 'Записать голос';
        recordingStatus.textContent = 'Распознаём речь…';
        try {
          const body = new FormData();
          body.append('audio', new Blob(chunks, { type: recorder.mimeType }), 'recording');
          body.append('source', 'browser');
          const response = await fetch('/intake/transcriptions', {
            method: 'POST', headers: { 'X-CSRF-Token': csrf }, body
          });
          const result = await response.json();
          if (!response.ok || !result.text) throw new Error('Не удалось распознать запись. Попробуйте снова или введите текст.');
          el('intakeText').value = result.text.slice(0, 4000);
          el('intakeSource').value = 'voice';
          recordingStatus.textContent = 'Текст готов. Проверьте его перед отправкой.';
        } catch (e) {
          recordingStatus.textContent = '';
          error(e.message);
        } finally {
          record.disabled = false;
        }
      };
      recorder.start();
      recordingTimer = setTimeout(() => { if (recorder.state === 'recording') recorder.stop(); }, 110000);
      record.setAttribute('aria-pressed', 'true');
      record.textContent = 'Остановить запись';
      recordingStatus.textContent = 'Идёт запись';
    } catch (e) {
      stream?.getTracks().forEach(track => track.stop());
      error('Не удалось начать запись. Проверьте разрешение на микрофон или введите текст.');
    } finally {
      record.disabled = false;
    }
  });
  el('intakeAnalyze').addEventListener('click', async event => {
    const button = event.currentTarget; btn(button, true); error('');
    try {
      await renderDraft(await req('/intake/analyze', 'POST', {
        text: el('intakeText').value, source: el('intakeSource').value,
        request_id: (globalThis.crypto?.randomUUID?.() || Math.random().toString(36).slice(2))
      }));
      await loadQueue();
    } catch (e) { error(e.message); } finally { btn(button, false); }
  });
  el('intakeClarify').addEventListener('click', async event => {
    if (!current) return;
    const button = event.currentTarget; btn(button, true); error('');
    try { await renderDraft(await req('/intake/drafts/' + current.draft_id + '/clarify', 'POST', {
      revision: current.revision, answer: el('intakeAnswer').value
    })); await loadQueue(); } catch (e) { error(e.message); } finally { btn(button, false); }
  });
  el('reviewGroup').addEventListener('change', () => {
    const id = selectedNumber('reviewGroup');
    select('reviewAssignee', (meta?.agents || []).filter(x => Number(x.group_id) === id).map(x => ({id:x.id,name:x.first_name+' '+x.last_name})), null);
  });
  el('reviewConfirm').addEventListener('click', async event => {
    if (!current || !el('reviewAcknowledge').checked) return error('Подтверди ручную проверку заявки.');
    const button = event.currentTarget; btn(button, true); error('');
    try {
      const output = await req('/intake/drafts/' + current.draft_id + '/confirm', 'POST', {
        revision: current.revision, approve: true,
        manual_review_reason: el('reviewReason').value,
        approved: {
          subject: el('reviewSubject').value, description: el('reviewDescription').value,
          type_id: selectedNumber('reviewType'), priority_id: selectedNumber('reviewPriority'),
          location_id: selectedNumber('reviewLocation'), group_id: selectedNumber('reviewGroup'),
          assigned_to: selectedNumber('reviewAssignee'),
          tag_ids: Array.from(el('reviewTags').selectedOptions, option => Number(option.value)),
          fields: { ...((current.approved || {}).fields || {}),
            object: el('reviewObject').value || null, equipment: el('reviewEquipment').value || null,
            incident_time: el('reviewIncidentTime').value || null, floor: el('reviewFloor').value || null,
            room: el('reviewRoom').value || null, serial_number: el('reviewSerial').value || null,
            affected_scope: el('reviewScope').value || null }
        }
      });
      await renderDraft(await req('/intake/drafts/' + current.draft_id));
      el('intakeCreated').hidden = false;
      await loadQueue();
    } catch (e) { error(e.message); } finally { btn(button, false); }
  });
  el('intakeReload').addEventListener('click', () => loadQueue().catch(e => error(e.message)));
  Promise.all([req('/intake/meta').then(x => { meta = x; }), loadQueue()]).catch(e => error(e.message));
})();
