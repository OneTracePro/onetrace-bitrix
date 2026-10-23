<?php

declare(strict_types=1);

namespace Onetrace\Connector\Tests\Integration;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Server;
use Bitrix\Sale\Basket;
use Bitrix\Sale\Fuser;
use Onetrace\Connector\Search\Cart;
use Onetrace\Connector\Tests\TestCase;

/**
 * "Add to cart" of the search widget: the service cart.php of the module.
 */
final class SearchCartTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $basket = Basket::loadItemsForFUser(Fuser::getId(), self::SITE);

        foreach ($basket as $item) {
            $item->delete();
        }

        $basket->save();
        \Onetrace\Connector\Queue::clear();
    }

    /**
     * @param array<string, string> $headers server variables of the headers
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function post(int $id, int $quantity = 1, array $headers = ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], string $method = 'POST'): array
    {
        $server = new Server(['REQUEST_METHOD' => $method, 'HTTP_HOST' => 'shop.example:8080', 'SERVER_PORT' => 8080] + $headers);

        return Cart::endpoint(self::SITE, new HttpRequest($server, ['site' => self::SITE, 'id' => (string) $id], ['quantity' => (string) $quantity], [], []));
    }

    public function testAddsASimpleProductToTheCartOfTheVisitor(): void
    {
        $shirt = $this->product('Linen Shirt', 25.0);
        $scarf = $this->product('Silk Scarf', 40.0);
        $this->visitor('browser-5');

        self::assertSame(['status' => 200, 'body' => ['ok' => true, 'count' => 1]], $this->post($shirt, 2));
        self::assertSame(['status' => 200, 'body' => ['ok' => true, 'count' => 1]], $this->post($shirt), 'The same line.');
        self::assertSame(['status' => 200, 'body' => ['ok' => true, 'count' => 2]], $this->post($scarf));

        $quantities = [];

        foreach (Basket::loadItemsForFUser(Fuser::getId(), self::SITE) as $item) {
            $quantities[(int) $item->getProductId()] = (float) $item->getQuantity();
        }

        $this->flush();
        $added = $this->platform->events('add_to_cart');

        self::assertSame([$shirt => 3.0, $scarf => 1.0], $quantities);
        self::assertSame((string) $shirt, $added[0]['properties']['product_id'] ?? null, 'add_to_cart of the module as for any cart change.');
    }

    public function testAProductWithOffersOrThatCannotBeBoughtOpensItsPage(): void
    {
        $dress = $this->product('Linen Dress', 50.0);
        $this->offers($dress, [[3, 'Y'], [2, 'Y']]);
        $soldOut = $this->product('Sold Out Hat', 15.0);
        \Bitrix\Catalog\Model\Product::update($soldOut, ['QUANTITY' => 0, 'QUANTITY_TRACE' => 'Y', 'CAN_BUY_ZERO' => 'N']);

        foreach ([$dress, $soldOut] as $id) {
            $response = $this->post($id);
            $code = \CIBlockElement::GetList([], ['ID' => $id], false, false, ['CODE'])->Fetch()['CODE'];

            self::assertSame(200, $response['status']);
            self::assertSame(['ok' => false, 'redirect' => 'http://shop.example:8080/catalog/' . $code . '/'], $response['body']);
        }

        self::assertCount(0, Basket::loadItemsForFUser(Fuser::getId(), self::SITE));
    }

    public function testRefusesRequestsWithoutTheHeaderOrNotPostAndUnknownProducts(): void
    {
        $shirt = $this->product('Linen Shirt', 25.0);

        self::assertSame(['status' => 400, 'body' => ['ok' => false]], $this->post($shirt, 1, []), 'A form of another site cannot set X-Requested-With.');
        self::assertSame(405, $this->post($shirt, 1, ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'], 'GET')['status']);
        self::assertSame(['status' => 404, 'body' => ['ok' => false, 'redirect' => 'http://shop.example:8080/']], $this->post(999999999));
        self::assertCount(0, Basket::loadItemsForFUser(Fuser::getId(), self::SITE));
    }
}
