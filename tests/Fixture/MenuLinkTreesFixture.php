<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\Fixture;

use Crustum\Mongo\TestSuite\TestFixture;

/**
 * Port of `Cake\Test\Fixture\MenuLinkTreesFixture` for the ODM test harness.
 */
class MenuLinkTreesFixture extends TestFixture
{
    /**
     * The connection name.
     *
     * @var string
     */
    public string $connection = 'test_mongo';

    /**
     * The collection name.
     *
     * @var string
     */
    public string $collection = 'menu_link_trees';

    /**
     * Documents to insert.
     *
     * Tree marker values (`lft`, `rght`) are numeric (cake
     * `MenuLinkTreesFixture` defines integer columns), `parent_id` is a hex
     * ObjectId or null.
     *
     * @var list<array<string, mixed>>
     */
    public array $records = [
        ['_id' => '000000000000000000000001', 'menu' => 'main-menu', 'lft' => 1, 'rght' => 10, 'parent_id' => null, 'ancestors' => [], 'url' => '/link1.html', 'title' => 'Link 1', 'sort' => 0],
        ['_id' => '000000000000000000000002', 'menu' => 'main-menu', 'lft' => 2, 'rght' => 3, 'parent_id' => '000000000000000000000001', 'ancestors' => ['000000000000000000000001'], 'url' => 'http://example.com', 'title' => 'Link 2', 'sort' => 0],
        ['_id' => '000000000000000000000003', 'menu' => 'main-menu', 'lft' => 4, 'rght' => 9, 'parent_id' => '000000000000000000000001', 'ancestors' => ['000000000000000000000001'], 'url' => '/what/even-more-links.html', 'title' => 'Link 3', 'sort' => 1],
        ['_id' => '000000000000000000000004', 'menu' => 'main-menu', 'lft' => 5, 'rght' => 8, 'parent_id' => '000000000000000000000003', 'ancestors' => ['000000000000000000000001', '000000000000000000000003'], 'url' => '/lorem/ipsum.html', 'title' => 'Link 4', 'sort' => 0],
        ['_id' => '000000000000000000000005', 'menu' => 'main-menu', 'lft' => 6, 'rght' => 7, 'parent_id' => '000000000000000000000004', 'ancestors' => ['000000000000000000000001', '000000000000000000000003', '000000000000000000000004'], 'url' => '/what/the.html', 'title' => 'Link 5', 'sort' => 0],
        ['_id' => '000000000000000000000006', 'menu' => 'main-menu', 'lft' => 11, 'rght' => 14, 'parent_id' => null, 'ancestors' => [], 'url' => '/yeah/another-link.html', 'title' => 'Link 6', 'sort' => 1],
        ['_id' => '000000000000000000000007', 'menu' => 'main-menu', 'lft' => 12, 'rght' => 13, 'parent_id' => '000000000000000000000006', 'ancestors' => ['000000000000000000000006'], 'url' => 'https://cakephp.org', 'title' => 'Link 7', 'sort' => 0],
        ['_id' => '000000000000000000000008', 'menu' => 'main-menu', 'lft' => 15, 'rght' => 16, 'parent_id' => null, 'ancestors' => [], 'url' => '/page/who-we-are.html', 'title' => 'Link 8', 'sort' => 2],
        ['_id' => '000000000000000000000009', 'menu' => 'categories', 'lft' => 1, 'rght' => 10, 'parent_id' => null, 'ancestors' => [], 'url' => '/cagetory/electronics.html', 'title' => 'electronics', 'sort' => 0],
        ['_id' => '000000000000000000000010', 'menu' => 'categories', 'lft' => 2, 'rght' => 9, 'parent_id' => '000000000000000000000009', 'ancestors' => ['000000000000000000000009'], 'url' => '/category/televisions.html', 'title' => 'televisions', 'sort' => 0],
        ['_id' => '000000000000000000000011', 'menu' => 'categories', 'lft' => 3, 'rght' => 4, 'parent_id' => '000000000000000000000010', 'ancestors' => ['000000000000000000000009', '000000000000000000000010'], 'url' => '/category/tube.html', 'title' => 'tube', 'sort' => 0],
        ['_id' => '000000000000000000000012', 'menu' => 'categories', 'lft' => 5, 'rght' => 8, 'parent_id' => '000000000000000000000010', 'ancestors' => ['000000000000000000000009', '000000000000000000000010'], 'url' => '/category/lcd.html', 'title' => 'lcd', 'sort' => 1],
        ['_id' => '000000000000000000000013', 'menu' => 'categories', 'lft' => 6, 'rght' => 7, 'parent_id' => '000000000000000000000012', 'ancestors' => ['000000000000000000000009', '000000000000000000000010', '000000000000000000000012'], 'url' => '/category/plasma.html', 'title' => 'plasma', 'sort' => 0],
        ['_id' => '000000000000000000000014', 'menu' => 'categories', 'lft' => 11, 'rght' => 20, 'parent_id' => null, 'ancestors' => [], 'url' => '/category/portable.html', 'title' => 'portable', 'sort' => 1],
        ['_id' => '000000000000000000000015', 'menu' => 'categories', 'lft' => 12, 'rght' => 15, 'parent_id' => '000000000000000000000014', 'ancestors' => ['000000000000000000000014'], 'url' => '/category/mp3.html', 'title' => 'mp3', 'sort' => 0],
        ['_id' => '000000000000000000000016', 'menu' => 'categories', 'lft' => 13, 'rght' => 14, 'parent_id' => '000000000000000000000015', 'ancestors' => ['000000000000000000000014', '000000000000000000000015'], 'url' => '/category/flash.html', 'title' => 'flash', 'sort' => 0],
        ['_id' => '000000000000000000000017', 'menu' => 'categories', 'lft' => 16, 'rght' => 17, 'parent_id' => '000000000000000000000014', 'ancestors' => ['000000000000000000000014'], 'url' => '/category/cd.html', 'title' => 'cd', 'sort' => 1],
        ['_id' => '000000000000000000000018', 'menu' => 'categories', 'lft' => 18, 'rght' => 19, 'parent_id' => '000000000000000000000014', 'ancestors' => ['000000000000000000000014'], 'url' => '/category/radios.html', 'title' => 'radios', 'sort' => 2],
    ];
}
