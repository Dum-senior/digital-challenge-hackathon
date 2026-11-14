<?php
$layout = 'app';
$pageTitle = 'Обращения';
$sidebarItems = Auth::isAdmin() ? adminSidebar('intake') : (Auth::isStaff() ? staffSidebar('intake') : portalSidebar('intake'));

?>
<div class="container py-4 dispatcher-intake" id="ai-intake" data-csrf="<?= e(csrfToken()) ?>">
  <header class="dispatcher-intake-header">
    <h1>Новое обращение</h1>
    <p>Опишите проблему — система поможет классифицировать заявку.</p>
  </header>
  <div class="dispatcher-intake-grid">
  <section class="card mb-3"><div class="card-body">
    <label class="form-label" for="intakeText">01 — Расскажите, что произошло</label>
    <textarea id="intakeText" class="form-control" maxlength="4000" rows="4" placeholder="Например: Не печатает принтер в кабинете 205, из него идёт дым..."></textarea>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
      <button type="button" id="intakeRecord" class="btn btn-outline-primary" aria-pressed="false">Записать голос</button>
      <span id="intakeRecordingStatus" role="status"></span>
    </div>
    <select id="intakeSource" hidden aria-label="Канал обращения"><option value="text">Текст</option><option value="voice">Голос</option></select>
    <button type="button" id="intakeAnalyze" class="btn btn-primary mt-3">Анализировать</button>
    <div class="intake-examples mt-3 d-flex flex-wrap gap-2">
      <button type="button" class="btn btn-sm btn-outline-secondary" data-example="В принтере закончилась краска">Нет краски</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-example="Не работают 100 принтеров">100 принтеров</button>
      <button type="button" class="btn btn-sm btn-outline-secondary" data-example="Принтер не печатает и дымится">Принтер дымится</button>
    </div>
  </div></section>
  <section class="card mb-3"><div class="card-body"><details><summary class="fw-semibold mb-2">Очередь черновиков</summary>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="intakeReload">Обновить</button>
    <ul id="intakeQueue" class="list-group mt-2" aria-live="polite"></ul>
  </details></div></section>
  </div>
  <div id="intakeError" class="alert alert-warning" role="alert" hidden></div>
  <div id="intakeEmpty" class="card p-4 mb-3 text-secondary">Здесь появится результат анализа</div>
  <section id="intakeDraft" class="card" hidden><div class="card-body">
    <h2 class="h5">Результат анализа <small class="text-secondary">№<span id="intakeDraftId"></span></small></h2>
    <span id="intakePriority" class="intake-priority" hidden></span>
    <p id="intakeStatus" class="fw-bold"></p>
    <p id="intakeEmergency" class="alert alert-danger" hidden></p>
    <?php $supportPhone = trim(env('INTAKE_SUPPORT_PHONE', '')); ?>
    <?php if ($supportPhone !== ''): ?>
        <p>Телефон поддержки: <a href="tel:<?= e(preg_replace('/[^+0-9]/', '', $supportPhone)) ?>"><?= e($supportPhone) ?></a></p>
    <?php endif; ?>
    <p id="intakeOriginal" class="text-break"></p>
    <dl id="intakeFacts" class="row"></dl>
    <div id="intakeClarification" hidden>
    <h3 class="h6">Уточняющие вопросы</h3><ul id="intakeQuestions"></ul>
    <label for="intakeAnswer" class="form-label">Ответ на уточнение</label>
    <textarea id="intakeAnswer" class="form-control" maxlength="2000" rows="2"></textarea>
    <button type="button" id="intakeClarify" class="btn btn-outline-primary mt-2">Ответить</button>
    </div>
    <div id="intakeReview" class="mt-4" hidden>
      <div id="intakeEditable" hidden>
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
      </div>
      <button type="button" id="intakeEdit" class="btn btn-outline-primary" aria-expanded="false" aria-controls="intakeEditable">Редактировать</button>
      <button type="button" id="reviewConfirm" class="btn btn-success">Подтвердить и создать заявку</button>
    </div>
    <p id="intakeCreated" class="mt-3" hidden></p>
  </div></section>
</div>
<script src="/assets/js/intake-dispatcher.js" defer></script>
