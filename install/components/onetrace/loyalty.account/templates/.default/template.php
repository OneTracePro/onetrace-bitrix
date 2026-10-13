<?php

/**
 * @var array $arResult
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
    die();
}

$operation = static function (string $type): string {
    return (string) (GetMessage('ONETRACE_LOYALTY_OPERATION_' . strtoupper($type)) ?: $type);
};
$date = static function (string $iso): string {
    $time = strtotime($iso);

    return $time ? FormatDate('j F Y', $time) : '';
};
?>
<div class="onetrace-loyalty-account">
    <?php if ($arResult['STATE'] === 'guest') { ?>
        <p><?= GetMessage('ONETRACE_LOYALTY_SIGN_IN') ?></p>
    <?php } elseif ($arResult['STATE'] === 'error') { ?>
        <p><?= GetMessage('ONETRACE_LOYALTY_UNAVAILABLE') ?></p>
    <?php } elseif ($arResult['STATE'] === 'join') { ?>
        <p><?= GetMessage('ONETRACE_LOYALTY_JOIN_TEXT') ?></p>
        <form method="post">
            <?= bitrix_sessid_post() ?>
            <input type="hidden" name="onetrace_loyalty_join" value="Y">
            <button type="submit" class="btn btn-primary"><?= GetMessage('ONETRACE_LOYALTY_JOIN') ?></button>
        </form>
    <?php } else { ?>
        <?php $account = $arResult['ACCOUNT']; ?>
        <?php if ($arResult['JOINED']) { ?>
            <p><?= GetMessage('ONETRACE_LOYALTY_WELCOME') ?></p>
        <?php } ?>
        <table class="table">
            <tr><th><?= GetMessage('ONETRACE_LOYALTY_AVAILABLE') ?></th><td><?= (int) $account['balance'] ?></td></tr>
            <tr><th><?= GetMessage('ONETRACE_LOYALTY_PENDING') ?></th><td><?= (int) $account['pending'] ?></td></tr>
            <?php if (!empty($account['tier'])) { ?>
                <tr><th><?= GetMessage('ONETRACE_LOYALTY_TIER') ?></th><td><?= htmlspecialcharsbx((string) $account['tier']) ?></td></tr>
            <?php } ?>
            <?php if ((int) ($account['expiring'] ?? 0) > 0 && !empty($account['expires_at'])) { ?>
                <tr><th><?= GetMessage('ONETRACE_LOYALTY_EXPIRING') ?></th><td><?= GetMessage('ONETRACE_LOYALTY_EXPIRING_VALUE', ['#POINTS#' => (int) $account['expiring'], '#DATE#' => htmlspecialcharsbx($date((string) $account['expires_at']))]) ?></td></tr>
            <?php } ?>
        </table>
        <?php if (!empty($account['referral_url'])) { ?>
            <h4><?= GetMessage('ONETRACE_LOYALTY_INVITE') ?></h4>
            <p><?= GetMessage('ONETRACE_LOYALTY_INVITE_TEXT') ?></p>
            <p>
                <input type="text" class="form-control" readonly value="<?= htmlspecialcharsbx((string) $account['referral_url']) ?>" style="max-width:28em;display:inline-block" onclick="this.select()">
                <button type="button" class="btn btn-default" data-done="<?= htmlspecialcharsbx((string) GetMessage('ONETRACE_LOYALTY_COPIED')) ?>" onclick="navigator.clipboard&&navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent=this.dataset.done"><?= GetMessage('ONETRACE_LOYALTY_COPY') ?></button>
            </p>
            <?php $referrals = (array) ($account['referrals'] ?? []); ?>
            <?php if ((int) ($referrals['invited'] ?? 0) > 0) { ?>
                <p><small><?= GetMessage('ONETRACE_LOYALTY_FRIENDS', ['#INVITED#' => (int) $referrals['invited'], '#REWARDED#' => (int) ($referrals['rewarded'] ?? 0), '#POINTS#' => (int) ($referrals['points'] ?? 0)]) ?></small></p>
            <?php } ?>
        <?php } ?>
        <?php if ($arResult['PROMO_CODES'] !== []) { ?>
            <h4><?= GetMessage('ONETRACE_LOYALTY_PROMO_CODES') ?></h4>
            <ul>
                <?php foreach ($arResult['PROMO_CODES'] as $promoCode) { ?>
                    <li><code><?= htmlspecialcharsbx((string) $promoCode['code']) ?></code><?php if (!empty($promoCode['expires_at'])) { ?> <small><?= GetMessage('ONETRACE_LOYALTY_VALID_UNTIL', ['#DATE#' => htmlspecialcharsbx($date((string) $promoCode['expires_at']))]) ?></small><?php } ?></li>
                <?php } ?>
            </ul>
        <?php } ?>
        <?php if ($arResult['OPERATIONS'] !== []) { ?>
            <h4><?= GetMessage('ONETRACE_LOYALTY_HISTORY') ?></h4>
            <table class="table">
                <thead><tr><th><?= GetMessage('ONETRACE_LOYALTY_DATE') ?></th><th><?= GetMessage('ONETRACE_LOYALTY_OPERATION') ?></th><th><?= GetMessage('ONETRACE_LOYALTY_POINTS') ?></th></tr></thead>
                <tbody>
                    <?php foreach ($arResult['OPERATIONS'] as $item) { ?>
                        <?php
                        $points = (int) ($item['points'] ?? 0);
                        $reference = (string) ($item['reference'] ?? '');
                        $referral = in_array($item['referral'] ?? null, ['friend', 'referrer'], true) ? (string) GetMessage('ONETRACE_LOYALTY_REFERRAL_' . strtoupper((string) $item['referral'])) : '';
                        $details = array_filter([(string) ($item['reason'] ?? ''), (string) ($item['rule'] ?? ''), $referral, preg_match('/^[a-z0-9]+:/', $reference) === 1 ? '' : $reference]);
                        ?>
                        <tr>
                            <td><?= htmlspecialcharsbx($date((string) ($item['created_at'] ?? ''))) ?></td>
                            <td><?= htmlspecialcharsbx($operation((string) ($item['type'] ?? ''))) ?><?php if ($details !== []) { ?><br><small><?= htmlspecialcharsbx(implode(' · ', $details)) ?></small><?php } ?></td>
                            <td><?= ($points > 0 ? '+' : '') . $points ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    <?php } ?>
</div>
