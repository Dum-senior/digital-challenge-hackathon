<?php
$layout = 'app';
$pageTitle = 'Заявки инженера';
$sidebarItems = staffSidebar('tickets');
?>
<h1 class="h3 mb-3">Заявки инженера</h1>
<?php if (empty($recentTickets)): ?>
<p>Открытых заявок пока нет.</p>
<?php else: ?>
<div class="list-group">
<?php foreach ($recentTickets as $ticket): ?>
    <a class="list-group-item list-group-item-action p-3" href="/agent/tickets/<?= (int) $ticket['id'] ?>">
        <strong>№<?= (int) $ticket['id'] ?> <?= e($ticket['subject']) ?></strong>
        <span class="d-block"><?= ticketStatusBadgeHtml($ticket['status']) ?></span>
    </a>
<?php endforeach; ?>
</div>
<?php endif; ?>
