<?php

/**
 * Settings of the module (Settings → Product settings → Module settings → OneTrace): a tab per site and the state
 * of the queue. Saving resumes the sending paused by a rejected key.
 *
 * @var CMain $APPLICATION
 * @var CUser $USER
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Onetrace\Connector\CatalogSync;
use Onetrace\Connector\Config;
use Onetrace\Connector\Connection;
use Onetrace\Connector\Queue;
use Onetrace\Connector\Status;

$module = 'onetrace.connector';

if ($APPLICATION->GetGroupRight($module) < 'W' || !Loader::includeModule($module)) {
    return;
}

Loc::loadMessages(__FILE__);
$sites = Config::sites();
$fields = [
    'url' => 'text', 'write_key' => 'text', 'secret_key' => 'password',
    'part_tracker' => 'checkbox', 'part_orders' => 'checkbox', 'part_cart' => 'checkbox', 'part_catalog' => 'checkbox', 'part_newsletter' => 'checkbox',
    'consent_topic' => 'text', 'consent_property' => 'text', 'push' => 'checkbox',
];
$notes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if (isset($_POST['save'])) {
        foreach (array_keys($sites) as $siteId) {
            foreach ($fields as $name => $type) {
                $value = $_POST['site'][$siteId][$name] ?? '';
                Config::set($name, $type === 'checkbox' ? ($value === 'Y' ? 'Y' : 'N') : trim((string) $value), $siteId);
            }
        }

        Queue::resume();
        Connection::reset();
        $notes[] = ['ok' => true, 'text' => Loc::getMessage('ONETRACE_OPTIONS_SAVED')];
    } elseif (isset($_POST['check'], $sites[(string) $_POST['check']])) {
        $notes = Status::check((string) $_POST['check']);
    } elseif (isset($_POST['sync'])) {
        $notes[] = ['ok' => true, 'text' => Loc::getMessage('ONETRACE_OPTIONS_SYNCED', ['#COUNT#' => CatalogSync::queueAll()])];
    } elseif (isset($_POST['flush'])) {
        $result = Queue::flush();
        $notes[] = ['ok' => true, 'text' => Loc::getMessage('ONETRACE_OPTIONS_FLUSHED', ['#SENT#' => $result['sent'], '#RETRIED#' => $result['retried'], '#DROPPED#' => $result['dropped']])];
    }
}

foreach ($notes as $note) {
    CAdminMessage::ShowMessage(['MESSAGE' => $note['text'], 'TYPE' => $note['ok'] ? 'OK' : 'ERROR', 'HTML' => false]);
}

$tabs = [];

foreach ($sites as $siteId => $language) {
    $tabs[] = ['DIV' => 'site_' . $siteId, 'TAB' => Loc::getMessage('ONETRACE_OPTIONS_SITE', ['#SITE#' => $siteId]), 'TITLE' => Loc::getMessage('ONETRACE_OPTIONS_SITE_TITLE', ['#SITE#' => $siteId])];
}

$tabs[] = ['DIV' => 'queue', 'TAB' => Loc::getMessage('ONETRACE_OPTIONS_QUEUE'), 'TITLE' => Loc::getMessage('ONETRACE_OPTIONS_QUEUE_TITLE')];
$control = new CAdminTabControl('onetrace_options', $tabs);
$stats = Queue::stats();
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module) ?>&amp;lang=<?= LANGUAGE_ID ?>">
    <?= bitrix_sessid_post() ?>
    <?php $control->Begin(); ?>
    <?php foreach ($sites as $siteId => $language) { ?>
        <?php $control->BeginNextTab(); ?>
        <?php foreach ($fields as $name => $type) { ?>
            <?php $value = Config::get($name, $siteId, str_starts_with($name, 'part_') ? 'Y' : ''); ?>
            <tr>
                <td width="40%"><label for="<?= $siteId . '_' . $name ?>"><?= Loc::getMessage('ONETRACE_OPTIONS_' . strtoupper($name)) ?></label></td>
                <td width="60%">
                    <?php if ($type === 'checkbox') { ?>
                        <input type="checkbox" id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>]" value="Y"<?= $value === 'Y' ? ' checked' : '' ?>>
                    <?php } else { ?>
                        <input type="<?= $type ?>" id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>]" value="<?= htmlspecialcharsbx($value) ?>" size="60" autocomplete="off">
                    <?php } ?>
                    <?php if (Loc::getMessage('ONETRACE_OPTIONS_' . strtoupper($name) . '_HINT')) { ?>
                        <br><small><?= Loc::getMessage('ONETRACE_OPTIONS_' . strtoupper($name) . '_HINT') ?></small>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        <tr>
            <td></td>
            <td><button type="submit" name="check" value="<?= $siteId ?>" class="adm-btn"><?= Loc::getMessage('ONETRACE_OPTIONS_CHECK') ?></button></td>
        </tr>
    <?php } ?>
    <?php $control->BeginNextTab(); ?>
    <tr>
        <td width="40%"><?= Loc::getMessage('ONETRACE_OPTIONS_WAITING') ?></td>
        <td width="60%"><?= (int) $stats['waiting'] ?> (<?= Loc::getMessage('ONETRACE_OPTIONS_RETRYING', ['#COUNT#' => (int) $stats['retrying']]) ?>)</td>
    </tr>
    <tr>
        <td><?= Loc::getMessage('ONETRACE_OPTIONS_LAST_ERROR') ?></td>
        <td><?= htmlspecialcharsbx((string) (Queue::lastError() ?? '—')) ?></td>
    </tr>
    <tr>
        <td></td>
        <td>
            <button type="submit" name="flush" value="Y" class="adm-btn"><?= Loc::getMessage('ONETRACE_OPTIONS_FLUSH') ?></button>
            <button type="submit" name="sync" value="Y" class="adm-btn"><?= Loc::getMessage('ONETRACE_OPTIONS_SYNC') ?></button>
        </td>
    </tr>
    <?php $control->Buttons(); ?>
    <input type="submit" name="save" value="<?= Loc::getMessage('ONETRACE_OPTIONS_SAVE') ?>" class="adm-btn-save">
    <?php $control->End(); ?>
</form>
