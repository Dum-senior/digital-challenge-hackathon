<?php
/** Safe DOM UI for /intake; no API keys and no innerHTML with LLM-originated strings. */
?>
<main class="container py-4" id="ai-intake" data-csrf="<?= e(csrfToken()) ?>">
  <h1 class="h3">ИИ-диспетчерская · обработка обращения</h1>
  <p class="text-muted">ИИ формирует черновик. Заявка, назначение, SLA и уведомления появятся только после подтверждения уполномоченным диспетчером.</p>
  <section class="card mb-3"><div class="card-body">
    <label class="form-label" for="intakeText">Описание проблемы на русском</label>
    <textarea id="intakeText" class="form-control" maxlength="4000" rows="4" placeholder="Например: На НТП с девяти утра нет данных со счётчика"></textarea>
    <div class="row mt-2 g-2"><div class="col-auto"><label for="intakeSource" class="form-label">Канал</label>
      <select id="intakeSource" class="form-select"><option value="text">Текст</option><option value="voice">Голос (исправленная транскрипция)</option><option value="phone">Телефон (проверенная транскрипция)</option></select>
    </div><div class="col-auto d-flex align-items-end"><button type="button" id="intakeAnalyze" class="btn btn-primary">Разобрать обращение</button></div></div>
  </div></section>
  <section class="mb-3"><h2 class="h5">Мои черновики / очередь диспетчера</h2>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="intakeReload">Обновить</button>
    <ul id="intakeQueue" class="list-group mt-2" aria-live="polite"></ul>
  </section>
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
      <h4 class="h6 mt-3">Подтверждённые технические поля</h4>
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
      <div class="form-check my-2"><input id="reviewAcknowledge" class="form-check-input" type="checkbox"><label for="reviewAcknowledge" class="form-check-label">Я проверил данные, права назначения и потенциальные риски</label></div>
      <button type="button" id="reviewConfirm" class="btn btn-success">Подтвердить и создать заявку</button>
    </div>
    <p id="intakeCreated" class="mt-3" hidden></p>
  </div></section>
</main>
<script src="/assets/js/intake-dispatcher.js" defer></script>
