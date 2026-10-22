<?php

/**
 * @var array $arResult
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}
?>
<div class="onetrace-preferences">
    <?php if ($arResult['STATE'] === 'guest') { ?>
        <p><?= GetMessage('ONETRACE_PREFERENCES_SIGN_IN') ?></p>
    <?php } elseif ($arResult['STATE'] === 'error') { ?>
        <p><?= GetMessage('ONETRACE_PREFERENCES_UNAVAILABLE') ?></p>
    <?php } else { ?>
        <?php if ($arResult['DONE'] !== null) { ?>
            <p class="<?= $arResult['DONE'] === 'error' ? 'errortext' : 'notetext' ?>"><?= GetMessage('ONETRACE_PREFERENCES_DONE_' . strtoupper($arResult['DONE'])) ?></p>
        <?php } ?>
        <?php if ($arResult['CHANNELS'] === [] || $arResult['TOPICS'] === []) { ?>
            <p><?= GetMessage('ONETRACE_PREFERENCES_NOTHING') ?></p>
        <?php } else { ?>
            <form method="post">
                <?= bitrix_sessid_post() ?>
                <input type="hidden" name="onetrace_preferences" value="Y">
                <p><?= GetMessage('ONETRACE_PREFERENCES_INTRO') ?></p>
                <table class="table">
                    <thead>
                        <tr>
                            <th></th>
                            <?php foreach ($arResult['CHANNELS'] as $channel) { ?>
                                <th style="text-align:center"><?= htmlspecialcharsbx($channel['NAME']) ?></th>
                            <?php } ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($arResult['TOPICS'] as $topic) { ?>
                            <tr>
                                <td><?= htmlspecialcharsbx($topic['NAME']) ?><?php if ($topic['DESCRIPTION'] !== '') { ?><br><small><?= htmlspecialcharsbx($topic['DESCRIPTION']) ?></small><?php } ?></td>
                                <?php foreach ($arResult['CHANNELS'] as $channel) { ?>
                                    <?php $subscribed = $topic['CHANNELS'][$channel['KEY']] ?? null; ?>
                                    <td style="text-align:center">
                                        <?php if ($subscribed === null) { ?>
                                            —
                                        <?php } else { ?>
                                            <input type="checkbox" name="topics[<?= htmlspecialcharsbx($topic['KEY']) ?>][<?= htmlspecialcharsbx($channel['KEY']) ?>]" value="Y"
                                                   aria-label="<?= htmlspecialcharsbx($topic['NAME'] . ' · ' . $channel['NAME']) ?>"
                                                   <?= $subscribed ? 'checked' : '' ?> <?= $channel['BLOCKED'] ? 'disabled' : '' ?>>
                                        <?php } ?>
                                    </td>
                                <?php } ?>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <?php if (in_array(true, array_column($arResult['CHANNELS'], 'BLOCKED'), true)) { ?>
                    <p><small><?= GetMessage('ONETRACE_PREFERENCES_BLOCKED') ?></small></p>
                <?php } ?>
                <p>
                    <label for="onetrace-frequency"><?= GetMessage('ONETRACE_PREFERENCES_FREQUENCY') ?></label>
                    <select name="frequency" id="onetrace-frequency" class="form-control">
                        <?php foreach (['default', 'weekly', 'monthly'] as $value) { ?>
                            <option value="<?= $value ?>" <?= $arResult['FREQUENCY'] === $value ? 'selected' : '' ?>><?= GetMessage('ONETRACE_PREFERENCES_FREQUENCY_' . strtoupper($value)) ?></option>
                        <?php } ?>
                    </select>
                </p>
                <p>
                    <label for="onetrace-pause"><?= GetMessage('ONETRACE_PREFERENCES_PAUSE') ?></label>
                    <select name="pause" id="onetrace-pause" class="form-control">
                        <?php if ($arResult['PAUSED_UNTIL'] !== null) { ?>
                            <option value="keep"><?= GetMessage('ONETRACE_PREFERENCES_PAUSED_UNTIL', ['#DATE#' => htmlspecialcharsbx(FormatDate('j F Y', (int) strtotime($arResult['PAUSED_UNTIL'])))]) ?></option>
                            <option value="none"><?= GetMessage('ONETRACE_PREFERENCES_RESUME') ?></option>
                        <?php } else { ?>
                            <option value="none"><?= GetMessage('ONETRACE_PREFERENCES_NO_PAUSE') ?></option>
                        <?php } ?>
                        <?php foreach ($arResult['PAUSE_DAYS'] as $days) { ?>
                            <option value="<?= (int) $days ?>"><?= GetMessage('ONETRACE_PREFERENCES_PAUSE_DAYS', ['#DAYS#' => (int) $days]) ?></option>
                        <?php } ?>
                    </select>
                </p>
                <p>
                    <button type="submit" class="btn btn-primary" name="onetrace_action" value="save"><?= GetMessage('ONETRACE_PREFERENCES_SAVE') ?></button>
                    <button type="submit" class="btn btn-default" name="onetrace_action" value="all"><?= GetMessage('ONETRACE_PREFERENCES_ALL') ?></button>
                </p>
            </form>
        <?php } ?>
    <?php } ?>
</div>
