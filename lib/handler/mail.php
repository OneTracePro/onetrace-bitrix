<?php

declare(strict_types=1);

namespace Onetrace\Connector\Handler;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Mail\Converter;
use Bitrix\Main\Mail\EventMessageCompiler;
use Bitrix\Main\Mail\StopException;
use Bitrix\Main\SiteTable;
use Bitrix\Sale\Order;
use Bitrix\Sale\Shipment;
use OneTrace\Resource\Emails;
use Onetrace\Connector\Config;
use Onetrace\Connector\Queue;
use Onetrace\Connector\StoreEmails;

/**
 * Store emails through OneTrace (module "main", the mail events of Bitrix): main:OnBeforeEventSend comes for every
 * email template of a mail event before Bitrix builds and sends the email, with the template by reference and the
 * result of the event — the only place that knows the event type, can skip the native email for some or all of its
 * recipients and still marks the event as sent. The module builds the email the way Bitrix will
 * (EventMessageCompiler) and, by the settings of the "Store emails" section:
 *
 * - order emails of a kind with an enabled OneTrace template go to the customer as the order data (the template
 *   builds the email in the language of the site);
 * - with "Send store emails through OneTrace" on, the rest goes to the customer as is: the subject, HTML (a text
 *   email — escaped, with links), the text, the sender name, Reply-To and the attachments, one request per recipient;
 * - copies to shop addresses (CC, BCC, the sender address, the order email, "Copies of all emails") never go through
 *   OneTrace — the platform would make customer profiles of the staff addresses; Bitrix sends them;
 * - recipients the platform accepted leave the native email; the others stay in it — Bitrix sends them itself on any
 *   error (no secret key, the platform unavailable, 402, 422, 429, an attachment it does not take).
 */
final class Mail
{
    /**
     * The native rest of a partly sent email, for main:OnBeforeMailSend that follows it: the event type and the To
     * addresses (lower case).
     *
     * @var array{event: string, to: list<string>}|null
     */
    private static ?array $rest = null;

    /**
     * @param array<string, mixed> $fields fields of the mail event
     * @param array<string, mixed> $message the email template (EMAIL_TO, CC, BCC change for a partly sent email)
     * @param array<string, bool>|null $result the event result: Was and Success when OneTrace sent everything
     *
     * @return false|null false — Bitrix skips this template
     */
    public static function onBeforeEventSend(&$fields, &$message, $context = null, &$result = null): ?bool
    {
        self::$rest = null;

        try {
            if (!is_array($fields) || !is_array($message) || !self::handle($fields, $message)) {
                return null;
            }
        } catch (\Throwable $error) {
            Queue::log('store email: ' . $error->getMessage());

            return null;
        }

        if (is_array($result)) {
            $result['Was'] = true;
            $result['Success'] = true;
        }

        return false;
    }

    /**
     * Sends what OneTrace takes; true — every recipient is sent, the native email is not needed.
     *
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $message
     */
    private static function handle(array $fields, array &$message): bool
    {
        $event = self::event();

        if ($event === null) {
            return false;
        }

        $eventName = (string) ($event['EVENT_NAME'] ?? $message['EVENT_NAME'] ?? '');
        $sites = array_values(array_filter(array_map('trim', explode(',', (string) ($event['LID'] ?? '')))));
        $siteId = $sites[0] ?? '';
        $kind = $siteId !== '' && isset(Config::sites()[$siteId]) ? StoreEmails::kind($eventName, $siteId) : null;
        $settings = $kind !== null ? StoreEmails::settings($siteId) : null;

        // Nothing to send through OneTrace: no relay and no template of this kind (copies to the shop go natively then).
        if ($kind === null || $settings === null || (!$settings['relay'] && Emails::route($settings, $kind) !== Emails::TEMPLATE)) {
            return false;
        }

        $compiled = self::compile($event, $fields, $message, $sites);
        $content = $compiled !== null ? self::content($compiled, $siteId) : null;

        if ($content === null) {
            return false;
        }

        $recipients = self::recipients($compiled, $siteId, $fields, ($event['DUPLICATE'] ?? 'N') === 'Y');
        $eventKey = (int) ($event['ID'] ?? 0) > 0 ? (string) $event['ID'] : md5($eventName . serialize($fields));
        $sent = [];
        $native = [];

        foreach ($recipients as $lower => $recipient) {
            // Copies to shop addresses always stay with Bitrix.
            $email = $recipient['admin'] ? null : self::email($recipient, $kind, $settings, $content, $siteId, $fields);

            if ($email !== null && self::deliver($siteId, $email, StoreEmails::key($siteId, 'mail', $eventKey, (string) ($message['ID'] ?? ''), substr(md5($lower), 0, 12)), $eventName)) {
                $sent[$lower] = $recipient;
            } else {
                $native[$lower] = $recipient;
            }
        }

        if ($sent === [] || $recipients === []) {
            return false;
        }

        if ($native === []) {
            return true;
        }

        self::keep($message, $native);
        self::$rest = ['event' => $eventName, 'to' => array_map('mb_strtolower', self::addresses((string) $message['EMAIL_TO']))];

        return false;
    }

    /**
     * main:OnBeforeMailSend of the native rest of a partly sent email: an address in To leaves CC and BCC, including
     * the copy Bitrix adds for "Copies of all emails" after the template is built, so nobody gets the email twice.
     */
    public static function onBeforeMailSend(\Bitrix\Main\Event $event): ?\Bitrix\Main\EventResult
    {
        $rest = self::$rest;
        self::$rest = null;
        $params = $event->getParameter(0);

        if ($rest === null || !is_array($params) || !is_array($params['HEADER'] ?? null) || ($params['HEADER']['X-EVENT_NAME'] ?? '') !== $rest['event']) {
            return null;
        }

        try {
            $headers = $params['HEADER'];
            $seen = array_fill_keys(array_merge($rest['to'], array_map('mb_strtolower', self::addresses((string) ($params['TO'] ?? '')))), true);

            foreach (['CC', 'BCC'] as $name) {
                if (!isset($headers[$name])) {
                    continue;
                }

                $kept = [];

                foreach (self::addresses((string) $headers[$name]) as $address) {
                    if (!isset($seen[mb_strtolower($address)])) {
                        $seen[mb_strtolower($address)] = true;
                        $kept[] = $address;
                    }
                }

                if ($kept === []) {
                    unset($headers[$name]);
                } else {
                    $headers[$name] = implode(', ', $kept);
                }
            }
        } catch (\Throwable $error) {
            Queue::log('store email: ' . $error->getMessage());

            return null;
        }

        return $headers !== $params['HEADER'] ? new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, ['HEADER' => $headers]) : null;
    }

    /**
     * The request of a customer recipient: the order template, the email as is, or null — natively.
     *
     * @param array{address: string, field: string, admin: bool, duplicate: bool} $recipient
     * @param array{relay: bool, templates: array<string, bool>} $settings
     * @param array<string, mixed> $content
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>|null
     */
    private static function email(array $recipient, string $kind, array $settings, array $content, string $siteId, array $fields): ?array
    {
        $relay = ['to' => $recipient['address'], 'kind' => $kind, 'language' => Config::language($siteId)] + $content;
        $route = Emails::route($settings, $kind);

        if ($route === Emails::TEMPLATE) {
            $orderId = (int) ($fields['ORDER_REAL_ID'] ?? 0);
            $order = $orderId > 0 ? Order::load($orderId) : null;
            $email = $order instanceof Order && (string) $order->getSiteId() === $siteId
                ? StoreEmails::orderEmail($order, $kind, $kind === 'order_shipped' ? self::shipment($order, $fields) : null, null, $recipient['address'])
                : null;

            if ($email !== null) {
                return $email + array_intersect_key($content, ['attachments' => true]);
            }

            $route = $settings['relay'] ? Emails::RELAY : Emails::NATIVE;
        }

        return $route === Emails::RELAY ? $relay : null;
    }

    /**
     * @param array<string, mixed> $email
     */
    private static function deliver(string $siteId, array $email, string $key, string $eventName): bool
    {
        try {
            StoreEmails::send($siteId, $email, $key);

            return true;
        } catch (\Throwable $error) {
            Queue::log(sprintf('store email %s (%s) goes natively: %s', $eventName, (string) $email['kind'], $error->getMessage()));

            return false;
        }
    }

    /**
     * The mail event Bitrix handles now (Event::handleEvent): its id, type, sites, DUPLICATE and files — the handler
     * gets only the fields and the template.
     *
     * @return array<string, mixed>|null
     */
    private static function event(): ?array
    {
        foreach (debug_backtrace(0, 16) as $frame) {
            if (($frame['function'] ?? '') === 'handleEvent' && isset($frame['class']) && is_a($frame['class'], \Bitrix\Main\Mail\Event::class, true) && is_array($frame['args'][0] ?? null)) {
                return $frame['args'][0];
            }
        }

        return null;
    }

    /**
     * The email as Bitrix builds it; null — the template stops sending (StopException) or the site has no charset.
     *
     * @param array<string, mixed> $event
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $message
     * @param list<string> $sites
     */
    private static function compile(array $event, array $fields, array $message, array $sites): ?EventMessageCompiler
    {
        $site = SiteTable::getRow(['select' => ['CHARSET' => 'CULTURE.CHARSET'], 'filter' => ['=LID' => $sites[0]]]);

        if (empty($site['CHARSET'])) {
            return null;
        }

        $compiled = EventMessageCompiler::createInstance(['EVENT' => $event, 'FIELDS' => $fields, 'MESSAGE' => $message, 'SITE' => $sites, 'CHARSET' => (string) $site['CHARSET']]);

        try {
            $compiled->compile();
        } catch (StopException) {
            return null;
        }

        return $compiled;
    }

    /**
     * Subject, HTML, text, sender name, Reply-To and attachments of the built email; null — an attachment the
     * platform does not take (the email goes natively with it).
     *
     * @return array<string, mixed>|null
     */
    private static function content(EventMessageCompiler $compiled, string $siteId): ?array
    {
        $charset = (string) $compiled->getMailCharset();
        $utf8 = static fn (string $value): string => $charset === '' || strcasecmp($charset, 'utf-8') === 0 ? $value : (string) mb_convert_encoding($value, 'UTF-8', $charset);
        $body = $utf8((string) $compiled->getMailBody());
        $base = StoreEmails::origin($siteId);

        if ($compiled->getMailContentType() === 'html') {
            $html = (string) preg_replace_callback(
                '/(\s(?:href|src)\s*=\s*)(["\'])(\/[^"\']*)\2/i',
                static fn (array $match): string => $match[1] . $match[2] . StoreEmails::absolute($match[3], $base) . $match[2],
                $body
            );
            $text = trim(Converter::htmlToText($html));
        } else {
            $text = trim($body);
            $html = self::textToHtml($text);
        }

        $headers = (array) $compiled->getMailHeaders();
        $attachments = self::attachments((array) ($compiled->getMailAttachment() ?? []));

        if ($attachments === null) {
            return null;
        }

        return array_filter([
            'subject' => trim($utf8((string) $compiled->getMailSubject())),
            'html' => $html,
            'text' => $text,
            'from_name' => self::senderName($utf8((string) ($headers['From'] ?? ''))),
            'reply_to' => self::addresses((string) ($headers['Reply-To'] ?? ''))[0] ?? null,
            'attachments' => $attachments,
        ], static fn ($value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** A text email as HTML: escaped, with line breaks and clickable links. */
    public static function textToHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = (string) preg_replace('#\bhttps?://[^\s<>"\']+#i', '<a href="$0">$0</a>', $escaped);

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5">' . nl2br($linked) . '</div>';
    }

    /**
     * Attachments of the email (of the template and of the event) for the platform; null — a type it does not take,
     * more than 5 files or more than 5 MB.
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return list<array{name: string, type: string, content: string}>|null
     */
    private static function attachments(array $items): ?array
    {
        $attachments = [];
        $total = 0;

        foreach ($items as $item) {
            $type = strtolower(trim(explode(';', (string) ($item['CONTENT_TYPE'] ?? ''))[0]));
            $type = in_array($type, ['image/jpg', 'image/pjpeg'], true) ? 'image/jpeg' : $type;
            $path = is_string($item['PATH'] ?? null) ? $item['PATH'] : '';
            $contents = isset($item['CONTENT']) ? (string) $item['CONTENT'] : ($path !== '' && is_file($path) ? (string) file_get_contents($path) : null);

            if ($contents === null || !in_array($type, StoreEmails::ATTACHMENT_TYPES, true)) {
                return null;
            }

            $total += strlen($contents);
            $attachments[] = Emails::attachment(mb_substr((string) ($item['NAME'] ?? 'attachment'), 0, 200) ?: 'attachment', $type, $contents);
        }

        return count($attachments) <= StoreEmails::MAX_ATTACHMENTS && $total <= StoreEmails::MAX_ATTACHMENT_BYTES ? $attachments : null;
    }

    /**
     * Recipients of the email by address: To, CC and BCC; a copy to a shop address (CC, BCC, the sender address, the
     * order email of the "Online store", "Copies of all emails") is a staff copy that stays with Bitrix; "duplicate" —
     * the copy Bitrix adds itself for "Copies of all emails".
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, array{address: string, field: string, admin: bool, duplicate: bool}>
     */
    private static function recipients(EventMessageCompiler $compiled, string $siteId, array $fields, bool $duplicate): array
    {
        $headers = (array) $compiled->getMailHeaders();
        $site = SiteTable::getRow(['select' => ['EMAIL'], 'filter' => ['=LID' => $siteId]]);
        $copies = array_map('mb_strtolower', self::addresses((string) Option::get('main', 'all_bcc', '')));
        $shop = array_merge($copies, array_map('mb_strtolower', self::addresses(implode(',', [
            (string) Option::get('main', 'email_from', ''),
            (string) ($site['EMAIL'] ?? ''),
            (string) Option::get('sale', 'order_email', ''),
            (string) ($fields['SALE_EMAIL'] ?? ''),
            (string) ($fields['DEFAULT_EMAIL_FROM'] ?? ''),
        ]))));
        $recipients = [];

        foreach (['to' => (string) $compiled->getMailTo(), 'cc' => (string) ($headers['CC'] ?? ''), 'bcc' => (string) ($headers['BCC'] ?? '')] as $field => $list) {
            foreach (self::addresses($list) as $address) {
                $lower = mb_strtolower($address);
                $recipients[$lower] ??= [
                    'address' => $address,
                    'field' => $field,
                    'admin' => $field !== 'to' || in_array($lower, $shop, true),
                    'duplicate' => $field === 'bcc' && $duplicate && in_array($lower, $copies, true),
                ];
            }
        }

        return $recipients;
    }

    /**
     * Leaves in the native email only the recipients OneTrace did not take (the staff copies and the refused ones),
     * each once. The copy of "Copies of all emails" is not listed: Bitrix adds it again; when it is all that is left,
     * it becomes the recipient. An empty To takes the first staff copy of CC or BCC. onBeforeMailSend() then drops
     * the addresses of To from the BCC Bitrix adds.
     *
     * @param array<string, mixed> $message
     * @param array<string, array{address: string, field: string, admin: bool, duplicate: bool}> $native
     */
    private static function keep(array &$message, array $native): void
    {
        $explicit = array_filter($native, static fn (array $recipient): bool => !$recipient['duplicate']) ?: $native;
        $lists = ['to' => [], 'cc' => [], 'bcc' => []];

        foreach ($explicit as $recipient) {
            $lists[$recipient['duplicate'] ? 'to' : $recipient['field']][] = $recipient['address'];
        }

        if ($lists['to'] === []) {
            $lists['to'][] = array_shift($lists['cc']) ?? array_shift($lists['bcc']);
        }

        $message['EMAIL_TO'] = implode(', ', $lists['to']);
        $message['CC'] = implode(', ', $lists['cc']);
        $message['BCC'] = implode(', ', $lists['bcc']);
    }

    /**
     * The shipment of a shipping email: SHIPMENT_ID ("delivery allowed"), the tracking number of the tracking
     * number email, otherwise the first shipment of the order.
     *
     * @param array<string, mixed> $fields
     */
    private static function shipment(Order $order, array $fields): ?Shipment
    {
        $id = (int) ($fields['SHIPMENT_ID'] ?? 0);
        $tracking = trim((string) ($fields['ORDER_TRACKING_NUMBER'] ?? ''));
        $first = null;

        /** @var Shipment $shipment */
        foreach ($order->getShipmentCollection() as $shipment) {
            if ($shipment->isSystem()) {
                continue;
            }

            if ($id > 0 ? (int) $shipment->getId() === $id : ($tracking !== '' && trim((string) $shipment->getField('TRACKING_NUMBER')) === $tracking)) {
                return $shipment;
            }

            $first ??= $shipment;
        }

        return $first;
    }

    /**
     * Valid addresses of a list: "Anna <anna@example.com>, shop@example.com".
     *
     * @return list<string>
     */
    private static function addresses(string $list): array
    {
        preg_match_all('/[^\s<>,;"\'()]+@[^\s<>,;"\'()]+/u', $list, $matches);

        return array_values(array_unique(array_filter($matches[0], static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_EMAIL) !== false)));
    }

    /** The display name of a From header: "Shop <shop@example.com>" — "Shop"; none — null. */
    private static function senderName(string $from): ?string
    {
        if (preg_match('/^\s*"?([^"<]*?)"?\s*<[^>]+>\s*$/u', $from, $match) !== 1 || trim($match[1]) === '') {
            return null;
        }

        return mb_substr(trim($match[1]), 0, 100);
    }
}
