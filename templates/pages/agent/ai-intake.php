<?php
/** Safe DOM UI for /intake; no API keys and no innerHTML with LLM-originated strings. */
?>
<div class="container py-4 dispatcher-intake" id="ai-intake" data-csrf="<?= e(csrfToken()) ?>">
  <header class="dispatcher-intake-header">
    <h1>Приём обращений</h1>
    <p>Опишите проблему. ИИ подготовит черновик, а диспетчер проверит данные перед созданием заявки.</p>
  </header>
  <div class="dispatcher-intake-grid">
  <section class="card mb-3"><div class="card-body">
    <label class="form-label" for="intakeText">Описание проблемы на русском</label>
    <textarea id="intakeText" class="form-control" maxlength="4000" rows="4" placeholder="Например: На НТП с девяти утра нет данных со счётчика"></textarea>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
      <button type="button" id="intakeRecord" class="btn btn-outline-primary" aria-pressed="false">Записать голос</button>
      <span id="intakeRecordingStatus" role="status"></span>
    </div>
    <div class="row mt-2 g-2"><div class="col-auto"><label for="intakeSource" class="form-label">Канал</label>
      <select id="intakeSource" class="form-select"><option value="text">Текст</option><option value="voice">Голос (исправленная транскрипция)</option><option value="phone">Телефон (проверенная транскрипция)</option></select>
    </div><div class="col-auto d-flex align-items-end"><button type="button" id="intakeAnalyze" class="btn btn-primary">Разобрать обращение</button></div></div>
  </div></section>
  <section class="card mb-3"><div class="card-body"><details><summary class="fw-semibold mb-2">Очередь черновиков</summary>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="intakeReload">Обновить</button>
    <ul id="intakeQueue" class="list-group mt-2" aria-live="polite"></ul>
  </details></div></section>
  </div>
  <div id="intakeError" class="alert alert-warning" role="alert" hidden></div>
  <section id="intakeDraft" class="card" hidden><div class="card-body">
    <h2 class="h5">Черновик №<span id="intakeDraftId"></span> · версия <span id="intakeRevision"></span></h2>
    <p id="intakeStatus" class="fw-bold"></p>
    <p id="intakeEmergency" class="alert alert-danger" hidden></p>
    <dl id="intakeFacts" class="row small"></dl>
    <h3 class="h6">Уточняющие вопросы</h3><ul id="intakeQuestions"></ul>
    <label for="intakeAnswer" class="form-label">Ответ на уточнение</label>
    <textarea id="intakeAnswer" class="form-control" maxlength="2000" rows="2"></textarea>
    <button type="button" id="intakeClarify" class="btn btn-outline-primary mt-2">Сохранить ответ и повторно проверить</button>
    <div id="intakeReview" class="mt-4" hidden>
      <h3 class="h5">Проверка диспетчером</h3>
      <p class="text-muted">Поля и приоритет можно изменить. Подтверждение создаёт реальную заявку.</p>
      <div class="mb-2"><label for="reviewSubject" class="form-label">Тема</label><input id="reviewSubject" maxlength="255" class="form-control"></div>
      <div class="mb-2"><label for="reviewDescription" class="form-label">Описание</label><textarea id="reviewDescription" class="form-control" rows="3"></textarea></div>
      <div class="row g-2">
        <div class="col-md-4"><label for="reviewType" class="form-label">Тип</label><select id="reviewType" class="form-select"></select></div>
        <div class="col-md-4"><label for="reviewPriority" class="form-label">Приоритет</label><select id="reviewPriority" class="form-select"></select></div>
        <div class="col-md-4"><label for="reviewLocation" class="form-label">Место</label><select id="reviewLocation" class="form-select"></select></div>
        <div class="col-md-6"><label for="reviewGroup" class="form-label">Группа</label><select id="reviewGroup" class="form-select"></select></div>
        <div class="col-md-6"><label for="reviewAssignee" class="form-label">Инженер</label><select id="reviewAssignee" class="form-select"></select></div>
      </div>
      <details class="mt-3"><summary class="fw-semibold mb-2">Дополнительные поля</summary>
      <div class="row g-2">
        <div class="col-md-4"><label for="reviewObject" class="form-label">Объект</label><input id="reviewObject" class="form-control" maxlength="200"></div>
        <div class="col-md-4"><label for="reviewEquipment" class="form-label">Оборудование</label><input id="reviewEquipment" class="form-control" maxlength="200"></div>
        <div class="col-md-4"><label for="reviewIncidentTime" class="form-label">Время события (ЧЧ:ММ)</label><input id="reviewIncidentTime" class="form-control" placeholder="09:00" maxlength="5"></div>
        <div class="col-md-4"><label for="reviewFloor" class="form-label">Этаж</label><input id="reviewFloor" class="form-control" maxlength="100"></div>
        <div class="col-md-4"><label for="reviewRoom" class="form-label">Кабинет</label><input id="reviewRoom" class="form-control" maxlength="100"></div>
        <div class="col-md-4"><label for="reviewSerial" class="form-label">Серийный номер</label><input id="reviewSerial" class="form-control" maxlength="120"></div>
        <div class="col-12"><label for="reviewScope" class="form-label">Масштаб отказа</label><input id="reviewScope" class="form-control" maxlength="500"></div>
        <div class="col-12"><label for="reviewTags" class="form-label">Теги (из существующей базы, Ctrl для нескольких)</label><select id="reviewTags" class="form-select" multiple size="3"></select></div>
      </div>
      <div class="mt-2"><label for="reviewReason" class="form-label">Причина ручного подтверждения/изменения аварийного приоритета</label><textarea id="reviewReason" class="form-control" maxlength="500" rows="2"></textarea></div>
      </details>
      <div class="form-check my-2"><input id="reviewAcknowledge" class="form-check-input" type="checkbox"><label for="reviewAcknowledge" class="form-check-label">Я проверил данные, права назначения и потенциальные риски</label></div>
      <button type="button" id="reviewConfirm" class="btn btn-success">Подтвердить и создать заявку</button>
    </div>
    <p id="intakeCreated" class="mt-3" hidden></p>
  </div></section>
</div>
<script src="/assets/js/intake-dispatcher.js" defer></script>
