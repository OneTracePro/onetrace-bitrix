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
use Onetrace\Connector\Events;
use Onetrace\Connector\Loyalty;
use Onetrace\Connector\PromoSync;
use Onetrace\Connector\Queue;
use Onetrace\Connector\Search\Widget;
use Onetrace\Connector\Status;
use Onetrace\Connector\StoreEmails;
use Onetrace\Connector\StoreMcp;

$module = 'onetrace.connector';

if ($APPLICATION->GetGroupRight($module) < 'W' || !Loader::includeModule($module)) {
    return;
}

Loc::loadMessages(__FILE__);
$sites = Config::sites();
$fields = [
    'url' => 'text', 'write_key' => 'text', 'secret_key' => 'password',
    'part_tracker' => 'checkbox', 'part_orders' => 'checkbox', 'delivered_statuses' => 'multiselect', 'part_cart' => 'checkbox', 'part_catalog' => 'checkbox', 'part_newsletter' => 'checkbox',
    'part_notify' => 'checkbox', 'part_search' => 'checkbox', 'part_search_widget' => 'checkbox', 'consent_topic' => 'text', 'consent_property' => 'text', 'push' => 'checkbox',
    'loyalty' => 'select', 'promo_pools' => 'textarea',
];
/** Choices of the select fields: the bonus points are off, a payment of the order or a discount on its lines. */
$choices = ['loyalty' => ['N', Loyalty::MODE_PAYMENT, Loyalty::MODE_DISCOUNT]];
/** @var array<string, string> $orderStatuses order statuses of the store: id => name */
$orderStatuses = Loader::includeModule('sale') ? array_map('strval', \Bitrix\Sale\OrderStatus::getAllStatusesNames(LANGUAGE_ID)) : [];
$notes = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_bitrix_sessid()) {
    if (isset($_POST['save'])) {
        foreach (array_keys($sites) as $siteId) {
            foreach ($fields as $name => $type) {
                $value = $_POST['site'][$siteId][$name] ?? '';

                // The secret key is never printed back: an empty field keeps the stored one.
                if ($type === 'password' && trim((string) $value) === '') {
                    continue;
                }

                if ($type === 'select') {
                    $value = in_array($value, $choices[$name], true) ? $value : $choices[$name][0];
                }

                // Nothing chosen — no order_delivered.
                if ($type === 'multiselect') {
                    $value = implode(',', array_intersect(array_map('strval', array_keys($orderStatuses)), array_map('strval', (array) $value)));
                }

                Config::set($name, $type === 'checkbox' ? ($value === 'Y' ? 'Y' : 'N') : trim((string) $value), $siteId);
            }
        }

        // Store MCP for the AI chat bot: the switch, the tools the bot may use and the return policy of each site.
        foreach (array_keys($sites) as $siteId) {
            $mcp = (array) ($_POST['site'][$siteId] ?? []);
            Config::set('store_mcp', ($mcp['store_mcp'] ?? '') === 'Y' ? 'Y' : 'N', $siteId);
            Config::set('store_mcp_tools', implode(',', array_intersect(array_merge(StoreMcp::READ_TOOLS, StoreMcp::ACTIONS), array_map('strval', (array) ($mcp['store_mcp_tools'] ?? [])))), $siteId);
            Config::set('store_mcp_return_text', trim((string) ($mcp['store_mcp_return_text'] ?? '')), $siteId);
            Config::set('store_mcp_return_url', trim((string) ($mcp['store_mcp_return_url'] ?? '')), $siteId);
        }

        // Event handlers of this version (an upgrade from an older module): registering is idempotent.
        Events::register();

        // Bonus points: the table and the agent of this version (an upgrade from an older module), and the pay
        // system of the payment mode.
        $modes = array_map(static fn (string $siteId): string => Config::get('loyalty', $siteId, 'N'), array_keys($sites));

        if (array_filter($modes, static fn (string $mode): bool => $mode !== 'N') !== []) {
            try {
                Loyalty::install();

                if (!CAgent::GetList([], ['NAME' => '\\Onetrace\\Connector\\Agent::loyalty();'])->Fetch()) {
                    CAgent::AddAgent('\\Onetrace\\Connector\\Agent::loyalty();', $module, 'N', 60);
                }

                if (!CAgent::GetList([], ['NAME' => '\\Onetrace\\Connector\\Agent::referrals();'])->Fetch()) {
                    CAgent::AddAgent('\\Onetrace\\Connector\\Agent::referrals();', $module, 'N', 60);
                }

                if (in_array(Loyalty::MODE_PAYMENT, $modes, true) && Loader::includeModule('sale')) {
                    Loyalty::paySystemId();
                }
            } catch (\Throwable $error) {
                $notes[] = ['ok' => false, 'text' => $error->getMessage()];
            }
        }

        // Promo codes: the agent of this version (an upgrade from an older module).
        if (array_filter(array_keys($sites), static fn (string $siteId): bool => PromoSync::pools($siteId) !== []) !== []
            && !CAgent::GetList([], ['NAME' => '\\Onetrace\\Connector\\Agent::promo();'])->Fetch()) {
            CAgent::AddAgent('\\Onetrace\\Connector\\Agent::promo();', $module, 'N', 300);
        }

        // Components of this version (an upgrade from an older module: /bitrix/components gets them on install only).
        CopyDirFiles(__DIR__ . '/install/components', \Bitrix\Main\Application::getDocumentRoot() . '/bitrix/components', true, true);
        CopyDirFiles(__DIR__ . '/install/services', \Bitrix\Main\Application::getDocumentRoot() . '/bitrix/services/' . $module, true, true);
        Queue::resume();
        Connection::reset();
        // The store MCP server connected to the bot of each project (or disconnected).
        StoreMcp::connect();
        // Store emails: the settings of the new key (or of the changed project) are read again.
        StoreEmails::forget();
        // The search widget: the state of the project is read again.
        Widget::forget();
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
            <?php $value = $type === 'multiselect' ? implode(',', Config::deliveredStatuses($siteId)) : Config::get($name, $siteId, str_starts_with($name, 'part_') && !str_starts_with($name, 'part_search') ? 'Y' : ''); ?>
            <tr>
                <td width="40%"><label for="<?= $siteId . '_' . $name ?>"><?= Loc::getMessage('ONETRACE_OPTIONS_' . strtoupper($name)) ?></label></td>
                <td width="60%">
                    <?php if ($type === 'checkbox') { ?>
                        <input type="checkbox" id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>]" value="Y"<?= $value === 'Y' ? ' checked' : '' ?>>
                    <?php } elseif ($type === 'select') { ?>
                        <select id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>]">
                            <?php foreach ($choices[$name] as $choice) { ?>
                                <option value="<?= htmlspecialcharsbx($choice) ?>"<?= ($value ?: 'N') === $choice ? ' selected' : '' ?>><?= Loc::getMessage('ONETRACE_OPTIONS_' . strtoupper($name) . '_' . strtoupper($choice)) ?></option>
                            <?php } ?>
                        </select>
                    <?php } elseif ($type === 'multiselect') { ?>
                        <select id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>][]" multiple size="<?= min(8, max(3, count($orderStatuses))) ?>">
                            <?php foreach ($orderStatuses as $statusId => $statusName) { ?>
                                <option value="<?= htmlspecialcharsbx((string) $statusId) ?>"<?= in_array((string) $statusId, explode(',', $value), true) ? ' selected' : '' ?>><?= htmlspecialcharsbx('[' . $statusId . '] ' . $statusName) ?></option>
                            <?php } ?>
                        </select>
                    <?php } elseif ($type === 'textarea') { ?>
                        <textarea id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>]" rows="3" cols="60" placeholder="welcome = 12"><?= htmlspecialcharsbx($value) ?></textarea>
                    <?php } elseif ($type === 'password') { ?>
                        <input type="password" id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>]" value="" size="60" autocomplete="new-password" placeholder="<?= $value !== '' ? Loc::getMessage('ONETRACE_OPTIONS_SECRET_SAVED') : 'cdp_sk_…' ?>">
                    <?php } else { ?>
                        <input type="<?= $type ?>" id="<?= $siteId . '_' . $name ?>" name="site[<?= $siteId ?>][<?= $name ?>]" value="<?= htmlspecialcharsbx($value) ?>" size="60" autocomplete="off">
                    <?php } ?>
                    <?php if (Loc::getMessage('ONETRACE_OPTIONS_' . strtoupper($name) . '_HINT')) { ?>
                        <br><small><?= Loc::getMessage('ONETRACE_OPTIONS_' . strtoupper($name) . '_HINT') ?></small>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        <tr class="heading"><td colspan="2"><?= Loc::getMessage('ONETRACE_OPTIONS_EMAILS') ?></td></tr>
        <?php $emails = Config::server($siteId) ? StoreEmails::settings($siteId) : null; ?>
        <tr>
            <td width="40%"><?= Loc::getMessage('ONETRACE_OPTIONS_EMAILS_RELAY') ?></td>
            <td width="60%">
                <?php if (!Config::server($siteId)) { ?>
                    <?= Loc::getMessage('ONETRACE_OPTIONS_EMAILS_NOT_CONNECTED') ?>
                <?php } elseif ($emails === null) { ?>
                    <?= StoreEmails::settingsError($siteId) === 'permission' ? Loc::getMessage('ONETRACE_OPTIONS_EMAILS_NO_PERMISSION') : Loc::getMessage('ONETRACE_OPTIONS_EMAILS_UNAVAILABLE', ['#ERROR#' => htmlspecialcharsbx(StoreEmails::settingsError($siteId))]) ?>
                <?php } else { ?>
                    <?= Loc::getMessage($emails['relay'] ? 'ONETRACE_OPTIONS_EMAILS_ON' : 'ONETRACE_OPTIONS_EMAILS_OFF') ?>
                <?php } ?>
            </td>
        </tr>
        <?php if ($emails !== null) { ?>
            <tr>
                <td><?= Loc::getMessage('ONETRACE_OPTIONS_EMAILS_TEMPLATES') ?></td>
                <td>
                    <?php foreach (\OneTrace\Resource\Emails::ORDER_KINDS as $emailKind) { ?>
                        <?= Loc::getMessage('ONETRACE_OPTIONS_EMAILS_KIND_' . strtoupper($emailKind)) ?>: <?= Loc::getMessage(!empty($emails['templates'][$emailKind]) ? 'ONETRACE_OPTIONS_EMAILS_TEMPLATE' : 'ONETRACE_OPTIONS_EMAILS_STORE') ?><br>
                    <?php } ?>
                </td>
            </tr>
        <?php } ?>
        <tr>
            <td></td>
            <td><small><?= Loc::getMessage('ONETRACE_OPTIONS_EMAILS_HINT') ?></small></td>
        </tr>
        <tr class="heading"><td colspan="2"><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_HEADING') ?></td></tr>
        <?php $mcpTools = Config::get('store_mcp_tools', $siteId, implode(',', StoreMcp::READ_TOOLS)); ?>
        <tr>
            <td width="40%"><label for="<?= $siteId ?>_store_mcp"><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP') ?></label></td>
            <td width="60%">
                <input type="checkbox" id="<?= $siteId ?>_store_mcp" name="site[<?= $siteId ?>][store_mcp]" value="Y"<?= Config::get('store_mcp', $siteId, 'N') === 'Y' ? ' checked' : '' ?>>
                <br><small><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_HINT') ?></small>
            </td>
        </tr>
        <tr>
            <td class="adm-detail-valign-top"><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_TOOLS') ?></td>
            <td>
                <?php foreach (array_merge(StoreMcp::READ_TOOLS, StoreMcp::ACTIONS) as $tool) { ?>
                    <label><input type="checkbox" name="site[<?= $siteId ?>][store_mcp_tools][]" value="<?= $tool ?>"<?= in_array($tool, explode(',', $mcpTools), true) ? ' checked' : '' ?>> <?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_TOOL_' . strtoupper($tool)) ?></label><br>
                <?php } ?>
                <small><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_TOOLS_HINT') ?></small>
            </td>
        </tr>
        <tr>
            <td class="adm-detail-valign-top"><label for="<?= $siteId ?>_store_mcp_return_text"><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_RETURN_TEXT') ?></label></td>
            <td><textarea id="<?= $siteId ?>_store_mcp_return_text" name="site[<?= $siteId ?>][store_mcp_return_text]" rows="4" cols="60"><?= htmlspecialcharsbx(Config::get('store_mcp_return_text', $siteId)) ?></textarea></td>
        </tr>
        <tr>
            <td><label for="<?= $siteId ?>_store_mcp_return_url"><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_RETURN_URL') ?></label></td>
            <td><input type="text" id="<?= $siteId ?>_store_mcp_return_url" name="site[<?= $siteId ?>][store_mcp_return_url]" value="<?= htmlspecialcharsbx(Config::get('store_mcp_return_url', $siteId)) ?>" size="60" placeholder="/about/returns/"></td>
        </tr>
        <?php $mcpStatus = StoreMcp::status($siteId); ?>
        <tr>
            <td><?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_STATE') ?></td>
            <td>
                <?php if ($mcpStatus === null) { ?>
                    <?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_OFF') ?>
                <?php } elseif ($mcpStatus['status'] !== 'ok') { ?>
                    <?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_ERROR', ['#ERROR#' => htmlspecialcharsbx($mcpStatus['error'] !== '' ? $mcpStatus['error'] : $mcpStatus['status'])]) ?>
                <?php } else { ?>
                    <?= Loc::getMessage('ONETRACE_OPTIONS_STORE_MCP_OK', ['#URL#' => htmlspecialcharsbx($mcpStatus['url']), '#TOOLS#' => htmlspecialcharsbx(implode(', ', $mcpStatus['tools']))]) ?>
                <?php } ?>
            </td>
        </tr>
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
