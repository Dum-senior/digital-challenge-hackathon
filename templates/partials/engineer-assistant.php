<?php if (empty($isConfidential)): ?>
<section class="card border-0 shadow-sm mb-4" id="engineerAssistant"
         data-url="/agent/tickets/<?= (int) $ticket['id'] ?>/engineer-assist"
         data-token="<?= e(csrfToken()) ?>" aria-labelledby="engineerAssistantTitle">
    <div class="card-header bg-white d-flex align-items-center justify-content-between gap-2">
        <h5 class="mb-0" id="engineerAssistantTitle">Помощник инженера</h5>
        <button type="button" class="btn btn-primary btn-sm" id="engineerAssistantRun">Найти решение</button>
    </div>
    <div class="card-body">
        <p class="small text-muted">Инструкции и отмеченные решения прошлых заявок. Рекомендации проверяет инженер: ссылка на источник не гарантирует правильность совета.</p>
        <p id="engineerAssistantStatus" role="status" aria-live="polite">Нажмите «Найти решение», чтобы проверить базу знаний и архив.</p>
        <div id="engineerAssistantResult"></div>
    </div>
</section>
<script src="/assets/js/engineer-assistant.js" defer></script>
<?php endif; ?>
