<?php
$layout = 'dispatcher';
?>
<main id="ai-intake" data-csrf="<?= e(csrfToken()) ?>">
    <header class="page-heading">
        <h1>Рабочий стол</h1>
    </header>
    <div class="intake-grid">
        <section class="panel">
            <h2>Новое обращение</h2>
            <label for="intakeText">Описание проблемы</label>
            <textarea id="intakeText" maxlength="4000" rows="4" placeholder="Что случилось, где и когда?"></textarea>
            <input type="hidden" id="intakeSource" value="text">
            <div class="actions">
                <button type="button" id="intakeRecord" aria-pressed="false">Записать голос</button>
                <label class="button file-label">
                    Загрузить аудио
                    <input type="file" id="intakeUpload" accept="audio/*,.m4a,.mp4,.webm,.ogg">
                </label>
            </div>
            <p id="intakeRecordingStatus" class="message" role="status"></p>
            <button type="button" id="intakeAnalyze" class="primary">Подготовить черновик</button>
        </section>
        <section class="panel">
            <header class="section-heading">
                <h2>Черновики <span id="intakeCount" class="badge" hidden></span></h2>
                <button type="button" id="intakeReload">Обновить</button>
            </header>
            <ul id="intakeQueue" class="queue" aria-live="polite"><li>Загружаем черновики…</li></ul>
        </section>
    </div>
  <div id="intakeError" class="alert alert-warning" role="alert" hidden></div>
  <section id="intakeDraft" class="panel" hidden>
    <h2>Черновик №<span id="intakeDraftId"></span></h2>
    <p id="intakeStatus" class="badge"></p>
    <p id="intakeEmergency" class="alert alert-danger" hidden></p>
    <p id="intakeOriginal" class="message"></p>
    <p id="intakeRecommendation" class="message" hidden></p>
    <dl id="intakeFacts"></dl>
    <div id="intakeClarification" hidden>
    <h3>Уточняющие вопросы</h3><ul id="intakeQuestions"></ul>
    <label for="intakeAnswer">Ответ на уточнение</label>
    <textarea id="intakeAnswer" maxlength="2000" rows="2"></textarea>
    <button type="button" id="intakeClarify" class="mt-2">Ответить</button>
    </div>
    <div id="intakeReview" class="mt-4" hidden>
      <h3>Проверка диспетчером</h3>
      <p class="text-muted">Проверьте сведения и выберите исполнителя.</p>
      <div class="mb-2"><label for="reviewSubject">Тема</label><input id="reviewSubject" maxlength="255"></div>
      <div class="mb-2"><label for="reviewDescription">Описание</label><textarea id="reviewDescription" rows="3" maxlength="6000"></textarea></div>
      <div class="row">
        <div><label for="reviewType">Тип</label><select id="reviewType"></select></div>
        <div><label for="reviewPriority">Приоритет</label><select id="reviewPriority"></select></div>
        <div><label for="reviewLocation">Место</label><select id="reviewLocation"></select></div>
        <div><label for="reviewGroup">Группа</label><select id="reviewGroup"></select></div>
        <div><label for="reviewAssignee">Инженер</label><select id="reviewAssignee"></select></div>
      </div>
      <details class="mt-3"><summary class="fw-semibold mb-2">Дополнительные поля</summary>
      <div class="row">
        <div><label for="reviewObject">Объект</label><input id="reviewObject" maxlength="200"></div>
        <div><label for="reviewEquipment">Оборудование</label><input id="reviewEquipment" maxlength="200"></div>
        <div><label for="reviewIncidentTime">Время события (ЧЧ:ММ)</label><input id="reviewIncidentTime" placeholder="09:00" maxlength="5"></div>
        <div><label for="reviewFloor">Этаж</label><input id="reviewFloor" maxlength="100"></div>
        <div><label for="reviewRoom">Кабинет</label><input id="reviewRoom" maxlength="100"></div>
        <div><label for="reviewSerial">Серийный номер</label><input id="reviewSerial" maxlength="120"></div>
        <div><label for="reviewIps">IP-адреса через запятую</label><input id="reviewIps" maxlength="500"></div>
        <div><label for="reviewErrors">Коды ошибок через запятую</label><input id="reviewErrors" maxlength="500"></div>
        <div class="col-12"><label for="reviewScope">Масштаб отказа</label><input id="reviewScope" maxlength="500"></div>
        <div class="col-12"><label for="reviewTags">Теги</label><select id="reviewTags" multiple size="3"></select></div>
      </div>
      </details>
      <div class="mt-2"><label for="reviewReason">Обоснование ручного решения</label><textarea id="reviewReason" maxlength="500" rows="2"></textarea></div>
      <div class="form-check my-2"><input id="reviewAcknowledge" type="checkbox"><label for="reviewAcknowledge">Я проверил данные заявки</label></div>
      <button type="button" id="reviewConfirm" class="primary">Подтвердить и создать заявку</button>
    </div>
    <p id="intakeCreated" class="mt-3" hidden></p>
  </section>

    <section id="tickets" class="panel">
        <header class="section-heading">
            <h2>Последние заявки</h2>
            <?php if (Auth::isAdmin()): ?>
                <a href="/admin/reports/sla">Отчёт по срокам</a>
            <?php endif; ?>
        </header>
        <?php if (!$tickets): ?>
            <p class="muted">Заявок пока нет.</p>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th scope="col">Заявка</th><th scope="col">Статус</th><th scope="col">Инженер</th><th scope="col">Срок</th></tr></thead>
                    <tbody>
                        <?php foreach ($tickets as $ticket): ?>
                            <tr>
                                <td><a href="<?= e($ticketPrefix . $ticket['id']) ?>"><span class="muted">№<?= (int) $ticket['id'] ?></span> <?= e($ticket['subject']) ?></a></td>
                                <td><?= e($statusLabels[$ticket['status']] ?? 'Другой статус') ?></td>
                                <td><?= e(trim($ticket['assignee'] ?? '') ?: 'Не назначен') ?></td>
                                <td><?= e(['breached'=>'Просрочена', 'warning'=>'Срок истекает'][$ticket['sla_state']] ?? ($ticket['due_date'] ? date('d.m.Y H:i', strtotime($ticket['due_date'])) : '—')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
