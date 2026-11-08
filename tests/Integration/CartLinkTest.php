<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Server;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;
use Onetrace\Connector\CartLink;
use Onetrace\Connector\Config;
use Onetrace\Connector\Tests\TestCase;

/**
 * Cart links of shoppers' AI assistants: ?onetrace_cart=ID:QTY,… on a page of the store.
 */
final class CartLinkTest extends TestCase
{
    private const UTM = 'utm_source=chatgpt&utm_medium=ai_assistant&utm_campaign=shopper_mcp';

    protected function setUp(): void
    {
        parent::setUp();
        $basket = Basket::loadItemsForFUser(Fuser::getId(), self::SITE);

        foreach ($basket as $item) {
            $item->delete();
        }

        $basket->save();
    }

    /**
     * @param array<string, string> $server
     */
    private function request(string $uri, array $server = []): HttpRequest
    {
        $query = [];
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

        return new HttpRequest(new Server($server + ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => 'shop.example']), $query, [], [], []);
    }

    /**
     * @return array<int, float> product id => quantity in the cart of the visitor
     */
    private function cart(): array
    {
        $quantities = [];

        foreach (Basket::loadItemsForFUser(Fuser::getId(), self::SITE) as $item) {
            $quantities[(int) $item->getProductId()] = (float) $item->getQuantity();
        }

        ksort($quantities);

        return $quantities;
    }

    public function testPutsProductsAndAnOfferIntoTheCartAndOpensTheCartWithTheUtmTags(): void
    {
        $shirt = $this->product('Linen Shirt', 25.0);
        $scarf = $this->product('Silk Scarf', 40.0);
        $dress = $this->product('Linen Dress', 50.0);
        [, $blue] = $this->offers($dress, [[3, 'Y'], [5, 'Y']]);

        $target = CartLink::target(self::SITE, $this->request('/?utm_source=chatgpt&onetrace_cart=' . rawurlencode("{$shirt}:2,{$scarf}:1,{$blue}:3") . '&utm_medium=ai_assistant&utm_campaign=shopper_mcp'));

        self::assertSame('/personal/cart/?' . self::UTM, $target, 'Every other parameter in its place.');
        self::assertSame([$shirt => 2.0, $scarf => 1.0, $blue => 3.0], $this->cart());
    }

    public function testSkipsProductsThatCannotBeBoughtAndProductsWithOffers(): void
    {
        $shirt = $this->product('Linen Shirt', 25.0);
        $soldOut = $this->product('Sold Out Hat', 15.0);
        \Bitrix\Catalog\Model\Product::update($soldOut, ['QUANTITY' => 0, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'N']);
        $hidden = $this->product('Hidden Belt', 12.0);
        (new \CIBlockElement())->Update($hidden, ['ACTIVE' => 'N']);
        $dress = $this->product('Linen Dress', 50.0);
        $this->offers($dress, [[3, 'Y']]);

        $target = CartLink::target(self::SITE, $this->request("/catalog/?onetrace_cart={$soldOut}:1,{$hidden}:1,{$dress}:1,999999999:1,{$shirt}:1&" . self::UTM));

        self::assertSame('/personal/cart/?' . self::UTM, $target);
        self::assertSame([$shirt => 1.0], $this->cart());
    }

    public function testNothingAddedOpensTheSamePageWithoutTheParameter(): void
    {
        $soldOut = $this->product('Sold Out Hat', 15.0);
        \Bitrix\Catalog\Model\Product::update($soldOut, ['QUANTITY' => 0, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'N']);

        self::assertSame('/catalog/hats/?utm_source=chatgpt&PAGEN_1=2', CartLink::target(self::SITE, $this->request("/catalog/hats/?utm_source=chatgpt&onetrace_cart={$soldOut}:1&PAGEN_1=2")));
        self::assertSame('/', CartLink::target(self::SITE, $this->request('/?onetrace_cart=garbage')));
        self::assertSame('/?utm_source=chatgpt', CartLink::target(self::SITE, $this->request('/?onetrace.cart=1:1&utm_source=chatgpt')), 'PHP reads "onetrace.cart" as onetrace_cart: removed too, no redirect loop.');
        self::assertSame('/', CartLink::target(self::SITE, $this->request('/?onetrace_cart[]=1:1')));
        self::assertSame([], $this->cart());
    }

    public function testAtMostTwentyPairsAndQuantitiesFromOneToNinetyNine(): void
    {
        $shirt = $this->product('Linen Shirt', 25.0);
        $scarf = $this->product('Silk Scarf', 40.0);
        $belt = $this->product('Leather Belt', 30.0);

        self::assertSame([[7, 1], [8, 99], [9, 1], [10, 5], [11, 1]], CartLink::pairs('7:0,8:500,9:-4,10: 5 ,11:+1,12:abc,13,:3,0:1,x:1,14:2.5,15:2:7,'), 'Invalid pairs are ignored; the last colon splits.');

        CartLink::target(self::SITE, $this->request('/?onetrace_cart=' . rawurlencode("{$shirt}:500,{$scarf}:0,{$belt}:-2")));
        self::assertSame([$shirt => 99.0, $scarf => 1.0, $belt => 1.0], $this->cart());

        // 20 pairs of unknown products, the 21st one is a product: it is not read.
        $link = implode(',', array_fill(0, 20, '999999999:1')) . ",{$belt}:1";
        self::assertSame('/catalog/?' . self::UTM, CartLink::target(self::SITE, $this->request('/catalog/?onetrace_cart=' . rawurlencode($link) . '&' . self::UTM)));
        self::assertSame(1.0, $this->cart()[$belt]);
    }

    public function testTheCartPageOfTheSettings(): void
    {
        $shirt = $this->product('Linen Shirt', 25.0);
        Config::set('cart_page', '/basket/?step=1', self::SITE);

        self::assertSame('/basket/?step=1&' . self::UTM, CartLink::target(self::SITE, $this->request("/?onetrace_cart={$shirt}:1&" . self::UTM)));

        Config::set('cart_page', 'https://evil.example/', self::SITE);
        self::assertSame('/personal/cart/', CartLink::cartPage(self::SITE), 'Only a page of the site.');
    }

    public function testOnlyGetPagesOfASiteConnectedToOneTrace(): void
    {
        $shirt = $this->product('Linen Shirt', 25.0);
        $uri = "/?onetrace_cart={$shirt}:1";

        self::assertTrue(CartLink::requested($this->request($uri)));
        self::assertFalse(CartLink::requested($this->request('/?' . self::UTM)));
        self::assertFalse(CartLink::requested($this->request($uri, ['REQUEST_METHOD' => 'POST'])));
        self::assertFalse(CartLink::requested($this->request($uri, ['REQUEST_METHOD' => 'HEAD'])), 'Link previews do not fill a cart.');
        self::assertFalse(CartLink::requested($this->request($uri, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])));
        self::assertFalse(CartLink::requested($this->request("/bitrix/services/onetrace.connector/cart.php?onetrace_cart={$shirt}:1")));

        foreach (['url', 'write_key', 'secret_key'] as $name) {
            Config::set($name, '', self::SITE);
        }

        self::assertNull(CartLink::target(self::SITE, $this->request($uri)));
        self::assertSame([], $this->cart());
    }
}
