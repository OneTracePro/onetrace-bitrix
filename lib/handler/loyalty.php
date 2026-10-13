<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Page\Asset;
use Bitrix\Main\Page\AssetLocation;
use Bitrix\Sale\Internals\SiteCurrencyTable;
use Bitrix\Sale\Order;
use Onetrace\Connector\Config;
use Onetrace\Connector\Loyalty as Points;
use Onetrace\Connector\Queue;

Loc::loadMessages(__FILE__);

/**
 * Bonus points in the checkout of sale.order.ajax: the block above the order total (a script of the module, any
 * template of the component), its service /bitrix/services/onetrace.connector/loyalty.php, the points applied to the
 * order on every refresh of the component and the confirmation when the order is saved.
 */
final class Loyalty
{
    /** OnSaleComponentOrderCreated: the component built the order — the points go into it. */
    public static function onOrderCreated($order = null, &$userResult = null, $request = null, &$params = null, &$result = null): void
    {
        if (!$order instanceof Order) {
            return;
        }

        try {
            Points::applyToOrder($order);
        } catch (\Throwable $error) {
            Queue::log('loyalty: ' . $error->getMessage());
        }
    }

    /**
     * OnSaleComponentOrderResultPrepared: the points payment in the order total (the rows "Paid" and "Left to pay" of
     * the template, as for the inner account) and, on the checkout page, the script of the points block (not on AJAX
     * refreshes).
     */
    public static function onResultPrepared($order = null, &$userResult = null, $request = null, &$params = null, &$result = null): void
    {
        $siteId = (string) (defined('SITE_ID') ? SITE_ID : '');

        if ($siteId === '' || Points::mode($siteId) === null) {
            return;
        }

        if ($order instanceof Order && is_array($result)) {
            self::totals($order, $result);
        }

        if (Application::getInstance()->getContext()->getRequest()->isAjaxRequest()) {
            return;
        }

        Asset::getInstance()->addString(self::script(), false, AssetLocation::AFTER_JS);
    }

    /** OnSaleOrderSaved: a new order confirms the points of the session. */
    public static function onOrderSaved(Event $event): void
    {
        $order = $event->getParameter('ENTITY');

        if (!$order instanceof Order || !$event->getParameter('IS_NEW')) {
            return;
        }

        try {
            Points::placed($order);
        } catch (\Throwable $error) {
            Queue::log('loyalty order ' . $order->getId() . ': ' . $error->getMessage());
        }
    }

    /**
     * The service of the block: GET — the state, POST (sessid, mode = apply | remove, points) — holds or removes.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function endpoint(string $siteId): array
    {
        $request = Application::getInstance()->getContext()->getRequest();

        if (Points::mode($siteId) === null) {
            return ['status' => 200, 'body' => ['loyalty' => null]];
        }

        if ($request->isPost()) {
            if (!check_bitrix_sessid()) {
                return ['status' => 403, 'body' => ['message' => (string) Loc::getMessage('ONETRACE_LOYALTY_RELOAD')]];
            }

            try {
                $request->getPost('mode') === 'remove' ? Points::remove($siteId) : Points::apply($siteId, max(0, (int) $request->getPost('points')));
            } catch (\Throwable $error) {
                return ['status' => 422, 'body' => ['message' => $error->getMessage()]];
            }
        }

        return ['status' => 200, 'body' => ['loyalty' => self::state($siteId)]];
    }

    /**
     * What the block shows, with the texts in the site language; null — nothing to show.
     *
     * @return array<string, mixed>|null
     */
    public static function state(string $siteId): ?array
    {
        $amount = Points::basketAmount($siteId);

        if ($amount <= 0) {
            return null;
        }

        $title = (string) Loc::getMessage('ONETRACE_LOYALTY_TITLE');

        if (Points::profile() === null) {
            return ['title' => $title, 'link' => ['url' => '/personal/', 'text' => (string) Loc::getMessage('ONETRACE_LOYALTY_SIGN_IN')]];
        }

        $offer = Points::offer($siteId, $amount);

        if ($offer === null) {
            return null;
        }

        if (empty($offer['member'])) {
            return ['title' => $title, 'link' => ['url' => '/personal/', 'text' => (string) Loc::getMessage('ONETRACE_LOYALTY_JOIN_LINK')]];
        }

        $hold = Points::hold($siteId);
        $earn = (int) ($offer['earn_points'] ?? 0);

        if ($hold !== null) {
            $paidAtMax = $amount - (float) ($offer['max_discount'] ?? 0);
            $earn = $paidAtMax > 0 ? (int) floor($earn * ($amount - min($hold['discount'], $amount)) / $paidAtMax) : $earn;
        }

        $earnText = $earn > 0 ? (string) Loc::getMessage('ONETRACE_LOYALTY_EARN', ['#POINTS#' => $earn]) : null;

        if ($hold !== null) {
            return [
                'title' => $title,
                'applied' => (string) Loc::getMessage('ONETRACE_LOYALTY_APPLIED', ['#POINTS#' => $hold['points'], '#SUM#' => self::money($hold['discount'], $siteId)]),
                'remove' => (string) Loc::getMessage('ONETRACE_LOYALTY_REMOVE'),
                'earn' => $earnText,
            ];
        }

        $balance = (int) ($offer['balance'] ?? 0);
        $max = (int) ($offer['max_points'] ?? 0);

        return [
            'title' => $title,
            'balance' => (string) Loc::getMessage('ONETRACE_LOYALTY_BALANCE', ['#POINTS#' => $balance, '#SUM#' => self::money($balance * (float) ($offer['point_value'] ?? 1), $siteId)]),
            'max' => $max,
            'hint' => $max > 0 ? (string) Loc::getMessage('ONETRACE_LOYALTY_MAX', ['#POINTS#' => $max, '#SUM#' => self::money((float) ($offer['max_discount'] ?? 0), $siteId)]) : null,
            'field' => (string) Loc::getMessage('ONETRACE_LOYALTY_FIELD'),
            'apply' => (string) Loc::getMessage('ONETRACE_LOYALTY_APPLY'),
            'earn' => $earnText,
        ];
    }

    /**
     * The points payment as a paid sum of the total: added to the payment from the inner account when there is one.
     *
     * @param array<string, mixed> $result
     */
    private static function totals(Order $order, array &$result): void
    {
        $points = Points::paidWithPoints($order);

        if ($points <= 0 || !isset($result['JS_DATA']['TOTAL']) || !is_array($result['JS_DATA']['TOTAL'])) {
            return;
        }

        $currency = (string) $order->getCurrency();
        $price = (float) $order->getPrice();
        $left = isset($result['ORDER_TOTAL_LEFT_TO_PAY']) && is_numeric($result['ORDER_TOTAL_LEFT_TO_PAY']) ? (float) $result['ORDER_TOTAL_LEFT_TO_PAY'] : $price;
        $left = max(0.0, $left - $points);
        $total = [
            'PAYED_FROM_ACCOUNT_FORMATED' => self::format($price - $left, $currency),
            'ORDER_TOTAL_LEFT_TO_PAY' => $left,
            'ORDER_TOTAL_LEFT_TO_PAY_FORMATED' => self::format($left, $currency),
        ];

        $result['JS_DATA']['TOTAL'] = array_replace($result['JS_DATA']['TOTAL'], $total);
    }

    private static function format(float $amount, string $currency): string
    {
        return class_exists(\CCurrencyLang::class) ? (string) \CCurrencyLang::CurrencyFormat($amount, $currency, true) : number_format($amount, 2) . ' ' . $currency;
    }

    private static function money(float $amount, string $siteId): string
    {
        $currency = (string) SiteCurrencyTable::getSiteCurrency($siteId);

        return trim(html_entity_decode(strip_tags(self::format($amount, $currency)), ENT_QUOTES, 'UTF-8'));
    }

    /**
     * The block above the order total of sale.order.ajax (#bx-soa-total of the standard template, otherwise at the
     * end of the form); after applying the component refreshes the order.
     */
    private static function script(): string
    {
        $settings = ['url' => '/bitrix/services/' . Config::MODULE . '/loyalty.php', 'error' => (string) Loc::getMessage('ONETRACE_LOYALTY_ERROR')];

        return '<script>(function(s){'
            . 'function t(v){var d=document.createElement("div");d.textContent=v==null?"":String(v);return d.innerHTML}'
            . 'function place(){var b=document.getElementById("onetrace-loyalty");if(b)return b;b=document.createElement("div");b.id="onetrace-loyalty";b.className="onetrace-loyalty bx-soa-section";b.style.cssText="padding:15px;margin:15px 0;border:1px solid #e0e0e0;border-radius:4px";'
            . 'var a=document.getElementById("bx-soa-total")||document.getElementById("bx-soa-order-form")||document.querySelector("form[name=ORDER_FORM]");if(!a)return null;if(a.id==="bx-soa-total"){a.parentNode.insertBefore(b,a)}else{a.appendChild(b)}return b}'
            . 'function draw(st,err){var b=place();if(!b)return;if(!st){b.style.display="none";return}b.style.display="";var h="<strong>"+t(st.title)+"</strong><div>";'
            . 'if(st.link){h+="<a href=\""+t(st.link.url)+"\">"+t(st.link.text)+"</a>"}else if(st.applied){h+=t(st.applied)+" <button type=\"button\" class=\"btn btn-default btn-sm\" data-ot=\"remove\">"+t(st.remove)+"</button>"}'
            . 'else{h+=t(st.balance);if(st.max>0){h+="</div><div style=\"display:flex;gap:8px;margin-top:8px\"><input type=\"number\" min=\"1\" step=\"1\" max=\""+Number(st.max)+"\" value=\""+Number(st.max)+"\" aria-label=\""+t(st.field)+"\" class=\"form-control\" style=\"width:8em\" data-ot=\"points\"><button type=\"button\" class=\"btn btn-primary btn-sm\" data-ot=\"apply\">"+t(st.apply)+"</button></div><div><small>"+t(st.hint)+"</small>"}}'
            . 'h+="</div>";if(st.earn)h+="<div><small>"+t(st.earn)+"</small></div>";if(err)h+="<div style=\"color:#c00\" role=\"alert\">"+t(err)+"</div>";b.innerHTML=h;b._state=st}'
            . 'function load(){fetch(s.url,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json()}).then(function(d){draw(d.loyalty)})}'
            . 'function send(data){var f=new FormData();f.append("sessid",BX.bitrix_sessid());for(var k in data)f.append(k,data[k]);var b=place();'
            . 'fetch(s.url,{method:"POST",body:f,credentials:"same-origin"}).then(function(r){return r.json().then(function(d){if(!r.ok)throw new Error(d.message||s.error);return d})})'
            . '.then(function(d){draw(d.loyalty);if(window.BX&&BX.Sale&&BX.Sale.OrderAjaxComponent)BX.Sale.OrderAjaxComponent.sendRequest()}).catch(function(e){draw(b&&b._state,e.message||s.error)})}'
            . 'document.addEventListener("click",function(e){var x=e.target.closest("[data-ot]");if(!x)return;if(x.getAttribute("data-ot")==="apply"){var i=document.querySelector("#onetrace-loyalty [data-ot=points]");send({mode:"apply",points:i?i.value:0})}else if(x.getAttribute("data-ot")==="remove"){send({mode:"remove"})}});'
            . 'if(window.BX&&BX.addCustomEvent)BX.addCustomEvent("onAjaxSuccess",function(){if(!document.getElementById("onetrace-loyalty"))load()});'
            . 'document.readyState==="loading"?document.addEventListener("DOMContentLoaded",load):load()'
            . '})(' . json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS) . ');</script>';
    }
}
