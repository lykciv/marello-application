<?php

namespace Marello\Bundle\Magento2Bundle\Tests\Unit\Transport\Rest\Iterator;

use Marello\Bundle\Magento2Bundle\Model\Magento2TransportSettings;
use Marello\Bundle\Magento2Bundle\Transport\Rest\Iterator\WebsiteIterator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WebsiteIteratorTest extends TestCase
{
    /**
     * @dataProvider iterationDataProvider
     *
     * @param array $websiteData
     * @param array $salesChannelToOriginWebsiteIdsValueMap
     * @param array $expectedResult
     */
    public function testIteration(
        array $websiteData,
        array $salesChannelToOriginWebsiteIdsValueMap,
        array $expectedResult
    ) {
        /** @var Magento2TransportSettings|MockObject $settingsBag */
        $settingsBag = self::createMock(Magento2TransportSettings::class);
        $settingsBag
            ->expects(empty($salesChannelToOriginWebsiteIdsValueMap) ? self::never() : self::atLeastOnce())
            ->method('getSalesChannelIdByOriginWebsiteId')
            ->willReturnMap($salesChannelToOriginWebsiteIdsValueMap);

        $iterator = new WebsiteIterator($websiteData, $settingsBag);
        self::assertSame($expectedResult, \iterator_to_array($iterator));
    }

    /**
     * @return array|\array[][]
     */
    public function iterationDataProvider(): array
    {
        return [
            'Empty website data' => [
                'websiteData' => [],
                'salesChannelToOriginWebsiteIdsValueMap' => [],
                'expectedResult' => []
            ],
            'Process invalid website data, to have clear validation messages' => [
                'websiteData' => [
                    [
                        'code' => 'MAG',
                        'name' => 'Main Website',
                    ]
                ],
                'salesChannelToOriginWebsiteIdsValueMap' => [],
                'expectedResult' => [
                    [
                        'code' => 'MAG',
                        'name' => 'Main Website',
                        'salesChannelId' => null,
                    ]
                ]
            ],
            'Skip Admin website' => [
                'websiteData' => [
                    [
                        'id' => 0,
                        'code' => 'Admin',
                        'name' => 'Admin Website',
                    ]
                ],
                'salesChannelToOriginWebsiteIdsValueMap' => [],
                'expectedResult' => []
            ],
            'Website data' => [
                'websiteData' => [
                    [
                        'id' => 0,
                        'code' => 'Admin',
                        'name' => 'Admin Website',
                    ],
                    [
                        'id' => 1,
                        'code' => 'MAIN',
                        'name' => 'Main Website',
                    ],
                    [
                        'id' => 2,
                        'code' => 'MAG',
                        'name' => 'MAG Website',
                    ],
                    [
                        'id' => 3,
                        'code' => 'MAG2',
                        'name' => 'MAG2 Website',
                    ]
                ],
                'salesChannelToOriginWebsiteIdsValueMap' => [
                    [1,5],
                    [2,1],
                ],
                'expectedResult' => [
                    [
                        'id' => 1,
                        'code' => 'MAIN',
                        'name' => 'Main Website',
                        'salesChannelId' => 5
                    ],
                    [
                        'id' => 2,
                        'code' => 'MAG',
                        'name' => 'MAG Website',
                        'salesChannelId' => 1
                    ],
                    [
                        'id' => 3,
                        'code' => 'MAG2',
                        'name' => 'MAG2 Website',
                        'salesChannelId' => null
                    ]
                ]
            ]
        ];
    }
}
