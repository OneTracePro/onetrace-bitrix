<?php

declare(strict_types=1);

namespace Onetrace\Connector\StoreMcp;

use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Event as MailEvent;
use Bitrix\Main\UserPhoneAuthTable;
use Bitrix\Main\UserTable;
use Bitrix\Sale\Order;
use OneTrace\StoreMcp\Context;
use OneTrace\StoreMcp\ToolError;
use Onetrace\Connector\Queue;

Loc::loadMessages(__FILE__);

/**
 * Customers of the store MCP server: "user:<id>" (an account, from the login on the site or a phone) or
 * "email:<address>". Bitrix makes an account for every buyer, so a customer is one or more accounts: the account
 * and the other accounts with its email (a guest checkout with the same email makes one more).
 */
final class Customers
{
    public const CODE_EVENT = 'ONETRACE_STORE_MCP_CODE';

    /**
     * Accounts of a customer.
     *
     * @return list<int>
     */
    public static function userIds(string $customer): array
    {
        if (preg_match('/^user:(\d+)$/', $customer, $match) === 1) {
            $user = UserTable::getRow(['select' => ['ID', 'EMAIL'], 'filter' => ['=ID' => (int) $match[1]]]);

            if ($user === null) {
                return [];
            }

            $email = trim((string) $user['EMAIL']);

            return $email !== '' ? array_values(array_unique(array_merge([(int) $user['ID']], self::byEmail($email)))) : [(int) $user['ID']];
        }

        return preg_match('/^email:(.+@.+)$/', $customer, $match) === 1 ? self::byEmail($match[1]) : [];
    }

    /**
     * Who asks for a code: the accounts with this email, or the account with this phone (phone login or the
     * personal phone). With an order number the order must be theirs.
     */
    public static function find(string $contact, ?string $order): ?string
    {
        $contact = trim($contact);
        $customer = null;

        if (str_contains($contact, '@')) {
            $customer = self::byEmail($contact) !== [] ? 'email:' . strtolower($contact) : null;
        } else {
            $phone = UserPhoneAuthTable::normalizePhoneNumber($contact);
            $row = $phone !== '' ? UserPhoneAuthTable::getRow(['select' => ['USER_ID'], 'filter' => ['=PHONE_NUMBER' => $phone]]) : null;
            $row ??= UserTable::getRow(['select' => ['USER_ID' => 'ID'], 'filter' => ['=ACTIVE' => 'Y', ['LOGIC' => 'OR', ['=PERSONAL_MOBILE' => $contact], ['=PERSONAL_PHONE' => $contact]]], 'order' => ['ID' => 'DESC']]);
            $customer = $row !== null ? 'user:' . (int) $row['USER_ID'] : null;
        }

        if ($customer !== null && $order !== null && trim($order) !== '') {
            try {
                self::order(['order' => $order], new Context($customer, 'en', null, false, null));
            } catch (ToolError) {
                return null;
            }
        }

        return $customer;
    }

    /**
     * The code by the mail event ONETRACE_STORE_MCP_CODE of the site (its template can be changed in the mail
     * templates); a handler of the onetrace.connector:onStoreMcpSendCode event may send it itself (e.g. by SMS) and
     * return a success result.
     */
    public static function sendCode(string $customerId, string $contact, string $code, string $channel): void
    {
        $siteId = \defined('SITE_ID') ? (string) SITE_ID : '';
        $event = new \Bitrix\Main\Event('onetrace.connector', 'onStoreMcpSendCode', ['customer' => $customerId, 'contact' => $contact, 'code' => $code, 'channel' => $channel, 'site' => $siteId]);
        $event->send();

        foreach ($event->getResults() as $result) {
            if ($result->getType() === \Bitrix\Main\EventResult::SUCCESS) {
                return;
            }
        }

        if ($channel !== 'email') {
            throw new ToolError(ToolError::UNAVAILABLE, (string) Loc::getMessage('ONETRACE_STORE_MCP_EMAIL_ONLY'));
        }

        $status = MailEvent::sendImmediate(['EVENT_NAME' => self::CODE_EVENT, 'LID' => $siteId, 'C_FIELDS' => ['EMAIL_TO' => $contact, 'CODE' => $code]]);

        if ($status !== MailEvent::SEND_RESULT_SUCCESS) {
            Queue::log('store MCP code email: ' . $status);
        }
    }

    /**
     * Orders of the customer, newest first.
     *
     * @return list<Order>
     */
    public static function orders(string $customer, int $limit): array
    {
        $userIds = self::userIds($customer);

        if ($userIds === []) {
            return [];
        }

        $orders = [];
        $rows = Order::getList(['select' => ['ID'], 'filter' => ['@USER_ID' => $userIds], 'order' => ['DATE_INSERT' => 'DESC', 'ID' => 'DESC'], 'limit' => $limit]);

        while ($row = $rows->fetch()) {
            $order = Order::load((int) $row['ID']);

            if ($order instanceof Order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * The order of the customer by its number (the account number the customer sees); an order of someone else is
     * "not found".
     *
     * @param array<string, mixed> $args
     */
    public static function order(array $args, Context $ctx): Order
    {
        $number = is_scalar($args['order'] ?? null) ? trim((string) $args['order'], " #№\t") : '';
        $order = $number !== '' ? Order::loadByAccountNumber($number) : null;

        if (!$order instanceof Order && ctype_digit($number)) {
            $order = Order::load((int) $number);
        }

        if (!$order instanceof Order || !in_array((int) $order->getUserId(), self::userIds((string) $ctx->customerId), true)) {
            throw new ToolError(ToolError::NOT_FOUND, (string) Loc::getMessage('ONETRACE_STORE_MCP_NO_ORDER'));
        }

        return $order;
    }

    /**
     * @return list<int>
     */
    private static function byEmail(string $email): array
    {
        $ids = [];
        $rows = UserTable::getList(['select' => ['ID'], 'filter' => ['=EMAIL' => trim($email)]]);

        while ($row = $rows->fetch()) {
            $ids[] = (int) $row['ID'];
        }

        return $ids;
    }
}
