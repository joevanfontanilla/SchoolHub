<?php
/**
 * Weekly meeting rows for the class forms. Blank rows are ignored on save.
 *
 * @var array $meetings existing meetings (day_of_week, start_time, end_time, room_id)
 * @var array $rooms    active rooms
 */
use App\Services\ClassScheduleService;

$submitted = old('meetings', null);
$rows = is_array($submitted) ? array_values($submitted) : array_map(static fn ($m) => [
    'day_of_week' => $m['day_of_week'],
    'start_time'  => substr((string) $m['start_time'], 0, 5),
    'end_time'    => substr((string) $m['end_time'], 0, 5),
    'room_id'     => $m['room_id'],
], $meetings);
while (count($rows) < max(3, count($meetings) + 1)) {
    $rows[] = [];
}
$overall = error_for('meetings');
?>
<fieldset>
    <legend class="field-label">Weekly meetings</legend>
    <p class="-mt-1 mb-3 text-xs text-brand-gray">Between 6:00 AM and 9:00 PM. Leave a row blank to skip it. A subject meeting on two days is one class with two rows.</p>
    <?php if ($overall): ?>
        <p class="field-error mb-3" role="alert"><span><?= e($overall) ?></span></p>
    <?php endif; ?>
    <div class="space-y-3">
        <?php foreach ($rows as $i => $row): ?>
            <?php $rowError = error_for("meetings.{$i}"); ?>
            <div class="rounded-lg border <?= $rowError ? 'border-danger-line bg-danger-bg/40' : 'border-slate-200' ?> p-3">
                <div class="grid gap-3 sm:grid-cols-4">
                    <div>
                        <label for="meeting-<?= $i ?>-day" class="field-label">Day <span class="sr-only">(meeting <?= $i + 1 ?>)</span></label>
                        <select id="meeting-<?= $i ?>-day" name="meetings[<?= $i ?>][day_of_week]" class="field-input">
                            <option value="">—</option>
                            <?php foreach (ClassScheduleService::DAYS as $day => $name): ?>
                                <option value="<?= $day ?>" <?= (string) ($row['day_of_week'] ?? '') === (string) $day ? 'selected' : '' ?>><?= e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="meeting-<?= $i ?>-start" class="field-label">Starts <span class="sr-only">(meeting <?= $i + 1 ?>)</span></label>
                        <input id="meeting-<?= $i ?>-start" type="time" name="meetings[<?= $i ?>][start_time]" value="<?= e($row['start_time'] ?? '') ?>" min="06:00" max="21:00" step="300" class="field-input">
                    </div>
                    <div>
                        <label for="meeting-<?= $i ?>-end" class="field-label">Ends <span class="sr-only">(meeting <?= $i + 1 ?>)</span></label>
                        <input id="meeting-<?= $i ?>-end" type="time" name="meetings[<?= $i ?>][end_time]" value="<?= e($row['end_time'] ?? '') ?>" min="06:00" max="21:00" step="300" class="field-input">
                    </div>
                    <div>
                        <label for="meeting-<?= $i ?>-room" class="field-label">Room <span class="sr-only">(meeting <?= $i + 1 ?>)</span></label>
                        <select id="meeting-<?= $i ?>-room" name="meetings[<?= $i ?>][room_id]" class="field-input">
                            <option value="">No room yet</option>
                            <?php foreach ($rooms as $room): ?>
                                <option value="<?= e($room['id']) ?>" <?= (string) ($row['room_id'] ?? '') === (string) $room['id'] ? 'selected' : '' ?>><?= e($room['code'] . ' · ' . $room['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php if ($rowError): ?>
                    <p class="field-error mt-2"><span><?= e($rowError) ?></span></p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</fieldset>
