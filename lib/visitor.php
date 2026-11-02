<?php

declare(strict_types=1);

namespace Onetrace\Connector;

/**
 * The signed-in customer in the tracker (ADR-158 of the platform): with the identity key of the site, the tracker
 * gets the user id and its signature userHash = HMAC-SHA256(identity key, user id) — cdp.identify(id, {}, { userHash })
 * — and the platform trusts the user id of the browser when the project requires signed user ids. The page is the
 * same for every visitor (composite site), so the id comes from the service visitor.php, only for a browser with the
 * login cookie of the module; after logout the tracker forgets the customer.
 */
final class Visitor
{
    private const SERVICES = '/bitrix/services/' . Config::MODULE;

    public static function enabled(string $siteId): bool
    {
        return Config::storefront($siteId) && Config::enabled('tracker', $siteId) && Config::identityKey($siteId) !== '';
    }

    /**
     * The current customer for the tracker; nulls for guests and administrators (the admin opens the storefront as
     * someone else's support, not as a customer).
     *
     * @return array{userId: string|null, userHash: string|null}
     */
    public static function current(string $siteId): array
    {
        global $USER;

        if (!self::enabled($siteId) || !$USER instanceof \CUser || !$USER->IsAuthorized() || $USER->IsAdmin()) {
            return ['userId' => null, 'userHash' => null];
        }

        $userId = (string) (int) $USER->GetID();

        return ['userId' => $userId, 'userHash' => Connection::userHash($siteId, $userId)];
    }

    /**
     * The tracker script part: with the login cookie — the customer from visitor.php once per login in the tab, then
     * identify when the browser does not know this id or signature yet; without it — reset of a customer the tracker
     * still remembers.
     */
    public static function trackerScript(string $siteId): string
    {
        return sprintf(
            "<script>(function(w,d,u,n){var m=d.cookie.match(new RegExp('(?:^|; )'+n+'=([^;]*)')),k='onetrace_visitor',l=function(x){try{return localStorage.getItem(x)}catch(e){return null}};"
            . "if(!m){if(l('cdp_uid'))w.cdp.reset();return}try{if(sessionStorage.getItem(k)===m[1])return}catch(e){}"
            . "fetch(u,{credentials:'same-origin'}).then(function(r){return r.json()}).then(function(j){"
            . "if(j.userId&&(l('cdp_uid')!==j.userId||j.userHash&&l('cdp_uhash')!==j.userHash))w.cdp.identify(j.userId,{},j.userHash?{userHash:j.userHash}:{});else if(!j.userId&&l('cdp_uid'))w.cdp.reset();"
            . "try{sessionStorage.setItem(k,m[1])}catch(e){}}).catch(function(){})})(window,document,%s,%s);</script>",
            json_encode(self::SERVICES . '/visitor.php?site=' . rawurlencode($siteId), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS),
            json_encode(StoreMcp::COOKIE)
        );
    }
}
