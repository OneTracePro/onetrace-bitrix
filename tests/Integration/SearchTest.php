<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use OneTrace\Http\Request;
use OneTrace\Http\Response;
use Onetrace\Connector\Config;
use Onetrace\Connector\Tests\TestCase;

final class SearchTest extends TestCase
{
    /** @var list<string> */
    private array $urls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('part_search', 'Y', self::SITE);
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

            $urls[] = $request->getUrl();

            return new Response($status, ['content-type' => 'application/json'], (string) json_encode($body));
        };
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
}
