<?php
/**
 * Summary card row shared by every role dashboard (docs/21).
 *
 * @var array<int,array{label:string,value:string,hint:string,tone:string}> $cards
 */
?>
<div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <?php foreach ($cards as $card): ?>
        <?= component('stat-card', $card) ?>
    <?php endforeach; ?>
</div>
