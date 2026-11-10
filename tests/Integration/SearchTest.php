<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use Onetrace\Connector\Config;
use Onetrace\Connector\Search\Search;
use Onetrace\Connector\Search\Widget;
use Onetrace\Connector\Tests\TestCase;

final class SearchTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('part_search', 'Y', self::SITE);
        Widget::forget();
    }

    protected function tearDown(): void
    {
        foreach (Search::AI_PARAMS as $param) {
            unset($_REQUEST[$param]);
        }

        Widget::forget();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function respond(int $status, array $body = []): void
    {
        $urls = &$this->urls;
        $this->platform->responder = static function (Request $request) use ($status, $body, &$urls): ?Response {
            if (!str_contains($request->getUrl(), '/api/v1/search')) {
                return null;
            }

            // The widget and AI search are off in the project, unless the test answers about the widget itself.
            if (str_contains($request->getUrl(), '/api/v1/search/widget') && !array_key_exists('enabled', $body)) {
                return new Response(200, ['content-type' => 'application/json'], '{"enabled":false}');
            }

            $urls[] = $request->getUrl();

            return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
        };
    }

    /**
     * AI search on in the project; $ai answers POST /search/ai, $usual — GET /search.
     *
     * @param array<string, mixed> $ai
     * @param array<string, mixed> $usual
     * @param list<array<string, mixed>> $bodies the bodies of POST /search/ai
     */
    private function respondAi(array $ai, array $usual, array &$bodies): void
    {
        $urls = &$this->urls;
        $this->platform->responder = static function (Request $request) use ($ai, $usual, &$urls, &$bodies): ?Response {
            $url = $request->getUrl();

            if (!str_contains($url, '/api/v1/search')) {
                return null;
            }

            if (str_contains($url, '/api/v1/search/widget')) {
                return new Response(200, ['content-type' => 'application/json'], '{"enabled":false,"ai":{"mode":"auto","answer":true},"texts":{}}');
            }

            $urls[] = $url;
            $isAi = str_contains($url, '/api/v1/search/ai');

            if ($isAi) {
                $bodies[] = (array) json_decode((string) $request->getBody(), true);
            }

            return new Response(200, ['content-type' => 'application/json'], (string) json_encode($isAi ? $ai : $usual));
        };
    }

    public function testShowsTheProductsAiFoundWithTheAiBlockAboveThem(): void
    {
        $boot = $this->product('Hiking Boot Onetrace');
        $runner = $this->product('Trail Runner Onetrace');
        $this->visitor('browser-7');
        $bodies = [];
        $state = ['text' => 'hiking shoes', 'categories' => [], 'brands' => [], 'attributes' => [], 'price_min' => null, 'price_max' => 700, 'exclude' => ['contoso'], 'sort' => 'relevance', 'refinements' => []];
        $this->respondAi(['request_id' => 'req-ai', 'total' => 2, 'items' => [['id' => (string) $runner], ['id' => (string) $boot]], 'ai' => [
            'understood' => [['type' => 'price', 'key' => 'price', 'label' => '–700']], 'refinements' => ['waterproof'], 'relaxed' => 0, 'state' => $state,
        ]], ['total' => 0, 'items' => []], $bodies);

        $html = $this->searchPage('hiking shoes under 700, not Contoso');
        $this->flush();

        self::assertLessThan(strpos($html, 'Hiking Boot Onetrace'), strpos($html, 'Trail Runner Onetrace'), 'The order of AI.');
        self::assertCount(1, $this->urls, 'The usual search is not asked.');
        self::assertSame(['q' => 'hiking shoes under 700, not Contoso', 'page' => 1, 'per_page' => 2, 'anonymousId' => 'browser-7'], array_intersect_key($bodies[0], ['q' => 1, 'page' => 1, 'per_page' => 1, 'anonymousId' => 1]));
        self::assertSame(['query' => 'hiking shoes under 700, not Contoso', 'results' => 2, 'request_id' => 'req-ai'], $this->platform->events('search')[0]['properties']);
        self::assertSame(1, preg_match('/<div class="onetrace-search-ai" data-cdp-search-ai="([^"]*)"><\/div>/', $html, $match));
        self::assertLessThan(strpos($html, 'Trail Runner Onetrace'), strpos($html, 'data-cdp-search-ai'), 'The block above the products.');
        $data = json_decode(html_entity_decode($match[1], ENT_QUOTES), true);
        self::assertSame(['hiking shoes under 700, not Contoso'], $data['turns']);
        self::assertSame([(string) $runner, (string) $boot], $data['products']);
        self::assertSame($state, $data['state']);
        self::assertStringEndsWith('?q=hiking%20shoes%20under%20700%2C%20not%20Contoso', $data['url']);
    }

    public function testRefinesRemovesChipsAndTurnsAiOffByTheLinksOfTheBlock(): void
    {
        $dress = $this->product('Linen Dress Onetrace');
        $bodies = [];
        $encode = static fn (array $value): string => rtrim(strtr(base64_encode((string) json_encode($value)), '+/', '-_'), '=');
        $this->respondAi(['ai' => null], ['total' => 1, 'items' => [['id' => (string) $dress]]], $bodies);

        // A refinement: the phrase with the previous parse; AI does not want it — the usual search of the query.
        $_REQUEST['ai_refine'] = 'cheaper';
        $_REQUEST['ai_previous'] = $encode(['text' => 'dress']);
        $html = $this->searchPage('linen dress');
        self::assertStringContainsString('Linen Dress Onetrace', $html);
        self::assertStringNotContainsString('data-cdp-search-ai', $html);
        self::assertSame(['q' => 'cheaper', 'previous' => ['text' => 'dress']], array_intersect_key($bodies[0], ['q' => 1, 'previous' => 1]));
        self::assertStringContainsString('q=linen%20dress', $this->urls[1]);

        // A removed chip: the parse without it; a broken parameter is not sent.
        unset($_REQUEST['ai_refine'], $_REQUEST['ai_previous']);
        $_REQUEST['ai_state'] = $encode(['text' => 'dress', 'brands' => []]);
        $this->searchPage('linen dress');
        self::assertSame(['text' => 'dress', 'brands' => []], $bodies[1]['state']);
        $_REQUEST['ai_state'] = 'not json!';
        $this->searchPage('linen dress');
        self::assertArrayNotHasKey('state', $bodies[2]);

        // "Regular search": AI is not asked.
        $_REQUEST['ai'] = '0';
        $this->searchPage('linen dress');
        self::assertCount(3, $bodies);
    }

    /**
     * The search page of the catalog with the component of the module.
     *
     * @param array<string, mixed> $params
     */
    private function searchPage(string $query, array $params = []): string
    {
        global $APPLICATION;

        $_REQUEST['q'] = $_GET['q'] = $query;
        ob_start();
        $APPLICATION->IncludeComponent('onetrace:catalog.search', '', $params + [
            'IBLOCK_TYPE' => 'onetrace_test', 'IBLOCK_ID' => self::catalogIblock(), 'PAGE_ELEMENT_COUNT' => 2, 'PRICE_CODE' => ['BASE'],
            'ELEMENT_SORT_FIELD' => 'sort', 'ELEMENT_SORT_ORDER' => 'asc', 'CACHE_TYPE' => 'N', 'HIDE_NOT_AVAILABLE' => 'N',
            'DETAIL_URL' => '/catalog/#ELEMENT_CODE#/', 'SECTION_URL' => '/catalog/#SECTION_CODE#/',
        ]);

        return (string) ob_get_clean();
    }

    public function testShowsTheProductsOfThePlatformInItsOrderAndCountsTheSearch(): void
    {
        $dress = $this->product('Linen Dress Onetrace');
        $skirt = $this->product('Linen Skirt Onetrace');
        $this->visitor('browser-7');
        $this->respond(200, ['request_id' => 'req-1', 'total' => 7, 'items' => [['id' => (string) $skirt], ['id' => (string) $dress]]]);

        // A typo: the search module of Bitrix would find nothing.
        $html = $this->searchPage('lnen dres');
        $this->flush();
        $search = $this->platform->events('search');

        self::assertGreaterThan(0, strpos($html, 'Linen Skirt Onetrace'));
        self::assertLessThan(strpos($html, 'Linen Dress Onetrace'), strpos($html, 'Linen Skirt Onetrace'), 'The order of the platform.');
        self::assertStringContainsString('q=lnen%20dres', $this->urls[0]);
        self::assertStringContainsString('per_page=2', $this->urls[0]);
        self::assertStringContainsString('anonymousId=browser-7', $this->urls[0]);
        self::assertStringContainsString('language=ru', $this->urls[0]);
        self::assertStringContainsString('search=page-4', $html, 'Pages over the number found by the platform.');
        self::assertCount(1, $search);
        self::assertSame(['query' => 'lnen dres', 'results' => 7, 'request_id' => 'req-1'], $search[0]['properties']);
        self::assertContract($search[0]);
    }

    public function testSortsByPriceOnThePlatform(): void
    {
        $this->respond(200, ['total' => 0, 'items' => []]);

        $this->searchPage('dress', ['ELEMENT_SORT_FIELD' => 'CATALOG_PRICE_1', 'ELEMENT_SORT_ORDER' => 'desc']);

        self::assertStringContainsString('sort=price_desc', $this->urls[0]);
    }

    public function testKeepsTheSearchOfBitrixOnErrorsAndWithTheSettingOff(): void
    {
        $this->respond(500, ['message' => 'Server error']);

        $html = $this->searchPage('dress');

        self::assertCount(1, $this->urls);
        self::assertSame([], $this->platform->events('search'));
        self::assertIsString($html);

        Config::set('part_search', 'N', self::SITE);
        $this->searchPage('dress');

        self::assertCount(1, $this->urls, 'The setting is off: no request.');
    }

    public function testKeepsTheSearchOfBitrixOverTheMonthlyLimitOfThePlan(): void
    {
        $this->respond(402, ['code' => 'quota_exceeded', 'message' => 'The monthly search limit of the plan is reached.']);

        $html = $this->searchPage('dress');

        self::assertCount(1, $this->urls, 'No retries.');
        self::assertSame([], $this->platform->events('search'));
        self::assertIsString($html);
    }

    public function testTheWidgetDrawsTheResultsAndTheModuleDoesNotSearch(): void
    {
        Config::set('part_search_widget', 'Y', self::SITE);
        $this->respond(200, ['enabled' => true, 'request_id' => 'req-1', 'total' => 1, 'items' => []]);

        $html = $this->searchPage('lnen dres');
        $this->searchPage('dress');
        $this->flush();

        self::assertStringContainsString('<div data-cdp-search-results data-query-param="q" data-cart-url="/bitrix/services/onetrace.connector/cart.php?site=s1&amp;id={id}"></div>', $html);
        self::assertCount(1, $this->urls, 'The state of the widget is cached; no search of the module.');
        self::assertStringContainsString('/api/v1/search/widget?language=ru', $this->urls[0]);
        self::assertSame([], $this->platform->events('search'), 'The tracker sends the search event.');
    }

    public function testTheSearchOfTheModuleWhenTheWidgetIsOffOrUnavailable(): void
    {
        $product = $this->product('Linen Dress Onetrace');
        Config::set('part_search_widget', 'Y', self::SITE);

        foreach ([[200, ['enabled' => false]], [500, ['message' => 'Server error']], [402, ['code' => 'feature_unavailable']]] as [$status, $widget]) {
            $this->urls = [];
            Widget::forget();
            $this->platform->responder = function (Request $request) use ($status, $widget, $product): ?Response {
                $this->urls[] = $request->getUrl();
                [$code, $body] = str_contains($request->getUrl(), '/search/widget') ? [$status, $widget] : [200, ['total' => 1, 'items' => [['id' => (string) $product]]]];

                return new Response($code, ['content-type' => 'application/json'], (string) json_encode($body));
            };

            $html = $this->searchPage('dress');

            self::assertStringNotContainsString('data-cdp-search-results', $html);
            self::assertStringContainsString('Linen Dress Onetrace', $html, 'The results of the module.');
            self::assertCount(2, $this->urls);
        }

        // The setting off: the platform is asked about the widget only for the AI search mode, once in TTL.
        $this->urls = [];
        Widget::forget();
        Config::set('part_search_widget', 'N', self::SITE);
        $html = $this->searchPage('dress');
        $this->searchPage('dress');

        self::assertStringNotContainsString('data-cdp-search-results', $html);
        self::assertCount(3, $this->urls);
        self::assertStringContainsString('/search/widget', $this->urls[0]);
    }
}
