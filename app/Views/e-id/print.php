<?php
/**
 * The e-ID printout: the front, then the back, one CR80 page each (docs/29).
 *
 * @var array $card
 * @var array $school
 */
?>
<div class="card-page"><?= partial('e-id-card', ['card' => $card, 'side' => 'front', 'school' => $school]) ?></div>
<div class="card-page"><?= partial('e-id-card', ['card' => $card, 'side' => 'back', 'school' => $school]) ?></div>
