<?php

declare(strict_types=1);

namespace Onetrace\Connector;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Mail\Internal\EventMessageTable;
use Bitrix\Main\Mail\Internal\EventTypeTable;
use Onetrace\Connector\StoreMcp\CacheCodeStore;
use Onetrace\Connector\StoreMcp\Customers;
use Onetrace\Connector\StoreMcp\Tools;
use OneTrace\StoreMcp\CustomerTokens;
use OneTrace\StoreMcp\Server;
use OneTrace\StoreMcp\Verification;

Loc::loadMessages(__FILE__);

/**
 * MCP server of the store for the OneTrace AI chat bot (store MCP contract v1):
 * /bitrix/services/onetrace.connector/mcp.php?site=<site>. The bot reads orders, tracking, stock, delivery and the
 * return policy, and — when the owner allows it in the settings — cancels orders with nothing paid and passes return
 * and delivery change requests to the shop. Customers are confirmed by the login on the site or by a code by email.
 *
 * The token of a signed-in customer does not go into the page (the composite cache keeps pages): the login sets the
 * onetrace_chat cookie, and the tracker script asks chat-customer.php for the token when it sees the cookie.
 *
 * The platform key and the token secret are generated once (module options). Sites of one project share one
 * connection: the first of them is connected, so the bot does not get the same tools twice.
 */
final class StoreMcp
{
    public const READ_TOOLS = ['store_start_verification', 'store_verify', 'store_get_customer', 'store_find_orders', 'store_get_order', 'store_get_tracking', 'store_check_availability', 'store_get_delivery_options', 'store_get_return_policy'];

    public const ACTIONS = ['store_cancel_order', 'store_create_return', 'store_change_delivery'];

    /** Cookie of a signed-in customer for the tracker script: a random value per login, not the token itself. */
    public const COOKIE = 'onetrace_chat';

    private const SERVICES = '/bitrix/services/' . Config::MODULE;

    public static function enabled(string $siteId): bool
    {
        return Config::get('store_mcp', $siteId, 'N') === 'Y' && Config::server($siteId);
    }

    /**
     * Tools the owner allows the bot to use on the site.
     *
     * @return list<string>
     */
    public static function allowedTools(string $siteId): array
    {
        return array_values(array_intersect(array_merge(self::READ_TOOLS, self::ACTIONS), explode(',', Config::get('store_mcp_tools', $siteId, implode(',', self::READ_TOOLS)))));
    }

    public static function server(string $siteId): Server
    {
        return new Server(self::key(), self::tokens(), [
            'store_get_customer' => [Tools::class, 'customer'],
            'store_find_orders' => [Tools::class, 'findOrders'],
            'store_get_order' => [Tools::class, 'getOrder'],
            'store_get_tracking' => [Tools::class, 'tracking'],
            'store_check_availability' => [Tools::class, 'availability'],
            'store_get_delivery_options' => [Tools::class, 'delivery'],
            'store_get_return_policy' => [Tools::class, 'returnPolicy'],
            'store_cancel_order' => [Tools::class, 'cancel'],
            'store_create_return' => [Tools::class, 'createReturn'],
            'store_change_delivery' => [Tools::class, 'changeDelivery'],
        ], [
            'name' => 'bitrix:' . (string) parse_url(Connection::siteUrl($siteId), PHP_URL_HOST),
            'version' => Connection::VERSION,
            'enabled' => self::allowedTools($siteId),
            'verification' => new Verification(new CacheCodeStore(), [Customers::class, 'find'], [Customers::class, 'sendCode']),
        ]);
    }

    /** Address of the server of a site: HTTPS, as the platform calls only HTTPS servers. */
    public static function url(string $siteId): string
    {
        return 'https://' . (string) parse_url(Connection::siteUrl($siteId), PHP_URL_HOST) . self::SERVICES . '/mcp.php?site=' . rawurlencode($siteId);
    }

    public static function key(): string
    {
        return self::secret('store_mcp_key');
    }

    public static function tokens(): CustomerTokens
    {
        return new CustomerTokens(self::secret('store_mcp_secret'));
    }

    /**
     * The chat token of the current user for cdp.chatCustomer(); null for guests and administrators.
     */
    public static function customerToken(string $siteId): ?string
    {
        global $USER;

        if (!self::enabled($siteId) || !$USER instanceof \CUser || !$USER->IsAuthorized() || $USER->IsAdmin()) {
            return null;
        }

        return self::tokens()->issue('user:' . (int) $USER->GetID());
    }

    /**
     * The cookie of a signed-in customer (login, or a session restored from "remember me" on the next page);
     * removed on logout.
     */
    public static function rememberLogin(string $siteId): void
    {
        $request = Application::getInstance()->getContext()->getRequest();

        if (!headers_sent() && self::enabled($siteId) && (string) $request->getCookieRaw(self::COOKIE) === '') {
            setcookie(self::COOKIE, bin2hex(random_bytes(8)), ['path' => '/', 'samesite' => 'Lax', 'secure' => $request->isHttps()]);
        }
    }

    public static function forgetLogin(): void
    {
        if (!headers_sent() && (string) Application::getInstance()->getContext()->getRequest()->getCookieRaw(self::COOKIE) !== '') {
            setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
        }
    }

    /**
     * The tracker script part: with the cookie of a signed-in customer, the token from chat-customer.php (kept in the
     * tab for the same login).
     */
    public static function trackerScript(string $siteId): string
    {
        return sprintf(
            "<script>(function(w,d,u,n){var m=d.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)'));if(!m)return;var k='onetrace_chat_token',s=null;"
            . "try{s=JSON.parse(sessionStorage.getItem(k)||'null')}catch(e){}if(s&&s.c===m[1]&&s.e>Date.now()){w.cdp.chatCustomer(s.t);return}"
            . "fetch(u,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){w.cdp.chatCustomer(j.token||null);"
            . "try{sessionStorage.setItem(k,JSON.stringify({c:m[1],t:j.token||null,e:Date.now()+3600000}))}catch(e){}}).catch(function(){})})(window,document,%s,%s);</script>",
            json_encode(self::SERVICES . '/chat-customer.php?site=' . rawurlencode($siteId), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS),
            json_encode(self::COOKIE)
        );
    }

    /**
     * After the settings are saved: the mail events of the module, then the server of each project connected to its
     * bot, and the ones that are turned off disconnected.
     */
    public static function connect(): void
    {
        $previous = self::statuses();
        $statuses = [];
        $groups = [];

        foreach (array_keys(Config::sites()) as $siteId) {
            if (self::enabled($siteId)) {
                $groups[Config::url($siteId) . '|' . Config::secretKey($siteId)][] = $siteId;
            }
        }

        if ($groups !== []) {
            self::installMailEvents();
        }

        foreach ($groups as $siteIds) {
            $siteId = $siteIds[0];
            $url = self::url($siteId);

            try {
                $result = Connection::client($siteId)->chats()->connectStoreMcp($url, self::key(), self::siteName($siteId), 'bitrix');
                $status = ['status' => (string) ($result['status'] ?? 'error'), 'error' => (string) ($result['error'] ?? ''), 'tools' => array_values(array_filter((array) ($result['tools'] ?? []), 'is_string'))];
            } catch (\Throwable $error) {
                $status = ['status' => 'error', 'error' => $error->getMessage(), 'tools' => []];
            }

            $statuses[$siteId] = $status + ['url' => $url, 'sites' => $siteIds, 'at' => time()];
        }

        foreach ($previous as $siteId => $status) {
            if (isset($statuses[$siteId]) && $statuses[$siteId]['url'] === $status['url']) {
                continue;
            }

            try {
                if (Config::server($siteId)) {
                    Connection::client($siteId)->chats()->disconnectStoreMcp($status['url']);
                }
            } catch (\Throwable $error) {
                Queue::log('store MCP of site ' . $siteId . ' was not disconnected: ' . $error->getMessage());
            }
        }

        Option::set(Config::MODULE, 'store_mcp_status', (string) json_encode($statuses));
    }

    /**
     * The connection that serves a site, if any.
     *
     * @return array{status: string, error: string, tools: list<string>, url: string, sites: list<string>, at: int}|null
     */
    public static function status(string $siteId): ?array
    {
        foreach (self::statuses() as $status) {
            if (in_array($siteId, $status['sites'], true)) {
                return $status;
            }
        }

        return null;
    }

    /**
     * Mail event types of the module and their templates for the sites (once): the code to the customer and the
     * request to the shop address. The templates can then be changed in Settings → Mail events.
     */
    public static function installMailEvents(): void
    {
        $sites = Config::sites();
        $events = [
            Customers::CODE_EVENT => ['#EMAIL_TO#', ['EMAIL_TO', 'CODE']],
            Tools::REQUEST_EVENT => ['#EMAIL_TO#', ['EMAIL_TO', 'ORDER_ID', 'ORDER_REAL_ID', 'KIND', 'REQUEST']],
        ];

        foreach ($events as $event => [$to, $fields]) {
            foreach (array_unique(array_merge(['ru', 'en'], array_values($sites))) as $language) {
                if (EventTypeTable::getCount(['=EVENT_NAME' => $event, '=LID' => $language]) === 0) {
                    $description = implode("\n", array_map(static fn (string $field): string => '#' . $field . '# — ' . self::message($event . '_' . $field, $language), $fields));
                    (new \CEventType())->Add(['LID' => $language, 'EVENT_NAME' => $event, 'NAME' => self::message($event, $language), 'DESCRIPTION' => $description, 'EVENT_TYPE' => 'email']);
                }
            }

            if (EventMessageTable::getCount(['=EVENT_NAME' => $event]) > 0) {
                continue;
            }

            foreach (array_unique(array_values($sites)) as $language) {
                (new \CEventMessage())->Add([
                    'ACTIVE' => 'Y',
                    'EVENT_NAME' => $event,
                    'LID' => array_keys(array_filter($sites, static fn (string $siteLanguage): bool => $siteLanguage === $language)),
                    'LANGUAGE_ID' => $language,
                    'EMAIL_FROM' => '#DEFAULT_EMAIL_FROM#',
                    'EMAIL_TO' => $to,
                    'SUBJECT' => self::message($event . '_SUBJECT', $language),
                    'BODY_TYPE' => 'text',
                    'MESSAGE' => self::message($event . '_MESSAGE', $language),
                ]);
            }
        }
    }

    /** On uninstall: the mail event types of the module and their templates. */
    public static function uninstallMailEvents(): void
    {
        foreach ([Customers::CODE_EVENT, Tools::REQUEST_EVENT] as $event) {
            $messages = EventMessageTable::getList(['select' => ['ID'], 'filter' => ['=EVENT_NAME' => $event]]);

            while ($message = $messages->fetch()) {
                \CEventMessage::Delete((int) $message['ID']);
            }

            \CEventType::Delete($event);
        }
    }

    /**
     * @return array<string, array{status: string, error: string, tools: list<string>, url: string, sites: list<string>, at: int}>
     */
    private static function statuses(): array
    {
        $stored = json_decode(Option::get(Config::MODULE, 'store_mcp_status', '{}'), true);
        $statuses = [];

        foreach (is_array($stored) ? $stored : [] as $siteId => $status) {
            if (is_array($status) && is_string($status['url'] ?? null)) {
                $statuses[(string) $siteId] = [
                    'status' => (string) ($status['status'] ?? 'error'),
                    'error' => (string) ($status['error'] ?? ''),
                    'tools' => array_values(array_filter((array) ($status['tools'] ?? []), 'is_string')),
                    'url' => $status['url'],
                    'sites' => array_values(array_map('strval', (array) ($status['sites'] ?? []))),
                    'at' => (int) ($status['at'] ?? 0),
                ];
            }
        }

        return $statuses;
    }

    private static function siteName(string $siteId): string
    {
        $site = \Bitrix\Main\SiteTable::getRow(['select' => ['SITE_NAME', 'NAME'], 'filter' => ['=LID' => $siteId]]);

        return trim((string) (($site['SITE_NAME'] ?? '') ?: ($site['NAME'] ?? ''))) ?: $siteId;
    }

    private static function message(string $code, string $language): string
    {
        $messages = Loc::loadLanguageFile(__FILE__, in_array($language, ['ru', 'en'], true) ? $language : 'en');

        return (string) ($messages['ONETRACE_STORE_MCP_EVENT_' . $code] ?? $code);
    }

    private static function secret(string $option): string
    {
        $value = Option::get(Config::MODULE, $option, '');

        if (strlen($value) < 32) {
            $value = bin2hex(random_bytes(24));
            Option::set(Config::MODULE, $option, $value);
        }

        return $value;
    }
}
