<?php
/**
 * Pastille d'état unifiée : la même couleur signifie la même chose partout.
 * @var string $key clé de traduction @var string $status
 */
$color = [
    'registered' => 'waiting_part', 'draft' => 'waiting_part',
    'assigned' => 'maintenance', 'in_progress' => 'maintenance',
    'answered' => 'available', 'sent' => 'available',
    'closed' => 'immobilized', 'cancelled' => 'immobilized',
][$status] ?? 'neutral';
?><span class="badge badge--<?= e($color) ?>"><?= e(t($key)) ?></span>
