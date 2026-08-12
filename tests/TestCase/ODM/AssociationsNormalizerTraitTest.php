<?php
declare(strict_types=1);

namespace Crustum\Mongo\Test\TestCase\ODM;

use Crustum\Mongo\ODM\AssociationsNormalizerTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests AssociationsNormalizerTrait.
 */
class AssociationsNormalizerTraitTest extends TestCase
{
    /**
     * @return array<string, array{0: array|string, 1: array}>
     */
    public static function normalizeAssociationsProvider(): array
    {
        $expected = [
            'First' => [
                'associated' => [
                    'Second' => [],
                    'Third' => [],
                    'Fourth' => [],
                ],
            ],
        ];

        return [
            'dot notation' => [
                ['First.Second', 'First.Third', 'First.Fourth'],
                $expected,
            ],
            'contain style list' => [
                ['First' => ['Second', 'Third', 'Fourth']],
                $expected,
            ],
            'single nested child' => [
                ['First' => ['Second']],
                [
                    'First' => [
                        'associated' => [
                            'Second' => [],
                        ],
                    ],
                ],
            ],
            'deeper contain style' => [
                ['First' => ['Second' => ['Third']]],
                [
                    'First' => [
                        'associated' => [
                            'Second' => [
                                'associated' => [
                                    'Third' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'mixed options and associations' => [
                ['Comments' => ['fields' => ['_id', 'body'], 'Users']],
                [
                    'Comments' => [
                        'fields' => ['_id', 'body'],
                        'associated' => [
                            'Users' => [],
                        ],
                    ],
                ],
            ],
            'options only' => [
                ['Tags' => ['onlyIds' => true]],
                [
                    'Tags' => [
                        'onlyIds' => true,
                    ],
                ],
            ],
            'lowercase aliases' => [
                ['authors' => ['supervisors' => ['tags']]],
                [
                    'authors' => [
                        'associated' => [
                            'supervisors' => [
                                'associated' => [
                                    'tags' => [],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param array|string $associations
     * @param array $expected
     */
    #[DataProvider('normalizeAssociationsProvider')]
    public function testNormalizeAssociations(array|string $associations, array $expected): void
    {
        $normalizer = new class {
            use AssociationsNormalizerTrait {
                normalizeAssociations as public;
            }
        };

        $this->assertSame($expected, $normalizer->normalizeAssociations($associations));
    }
}
