<?php

namespace Marello\Bundle\OroCommerceBundle\EventListener\Doctrine;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Marello\Bundle\OroCommerceBundle\Entity\OroCommerceSettings;
use Marello\Bundle\OroCommerceBundle\Event\RemoteProductCreatedEvent;
use Marello\Bundle\OroCommerceBundle\ImportExport\Reader\ProductExportCreateReader;
use Marello\Bundle\OroCommerceBundle\ImportExport\Writer\AbstractExportWriter;
use Marello\Bundle\OroCommerceBundle\ImportExport\Writer\AbstractProductExportWriter;
use Marello\Bundle\OroCommerceBundle\Integration\Connector\OroCommerceProductPriceConnector;
use Marello\Bundle\OroCommerceBundle\Integration\OroCommerceChannelType;
use Marello\Bundle\PricingBundle\Entity\BasePrice;
use Marello\Bundle\PricingBundle\Entity\ProductChannelPrice;
use Marello\Bundle\PricingBundle\Entity\ProductPrice;
use Marello\Bundle\ProductBundle\Entity\Product;
use Marello\Bundle\SalesBundle\Entity\SalesChannel;
use Oro\Bundle\EntityBundle\Event\OroEventManager;
use Oro\Bundle\IntegrationBundle\Async\Topics;
use Oro\Bundle\IntegrationBundle\Entity\Channel;
use Oro\Component\MessageQueue\Client\Message;
use Oro\Component\MessageQueue\Client\MessagePriority;

class ReverseSyncProductPriceListener extends AbstractReverseSyncListener
{
    const SYNC_FIELDS = [
        'value',
        'currency',
    ];

    /**
     * @param OnFlushEventArgs $event
     */
    public function onFlush(OnFlushEventArgs $event)
    {
        parent::init($event->getEntityManager());

        foreach ($this->getEntitiesToSync() as $entity) {
            $this->scheduleSync($entity);
        }
    }

    /**
     * @param RemoteProductCreatedEvent $event
     */
    public function onRemoteProductCreated(RemoteProductCreatedEvent $event)
    {
        $product = $event->getProduct();
        $salesChannel = $event->getSalesChannel();
        $finalPrice = $this->getFinalPrice($product, $salesChannel);
        if ($finalPrice) {
            $this->scheduleSync($finalPrice);
        }
    }

    /**
     * @return array
     */
    protected function getEntitiesToSync()
    {
        $entities = $this->unitOfWork->getScheduledEntityInsertions();
        $entities = array_merge($entities, $this->entityManager->getUnitOfWork()->getScheduledEntityUpdates());
        $entities = array_merge($entities, $this->entityManager->getUnitOfWork()->getScheduledEntityDeletions());
        return $this->filterEntities($entities);
    }

    /**
     * @param array $entities
     * @return array
     */
    private function filterEntities(array $entities)
    {
        $result = [];

        foreach ($entities as $entity) {
            if ($entity instanceof Product) {
                $productPriceChanged = false;
                $changeSet = $this->unitOfWork->getEntityChangeSet($entity);
                foreach (array_keys($changeSet) as $fieldName) {
                    if (in_array($fieldName, ['prices', 'channelPrices'])) {
                        $productPriceChanged = true;
                        break;
                    }
                }
                $data = $entity->getData();
                if ($productPriceChanged === true) {
                    foreach ($entity->getChannels() as $salesChannel) {
                        if ($salesChannel->getIntegrationChannel()) {
                            $finalPrice = $this->getFinalPrice($entity, $salesChannel);
                            if (!isset($data[AbstractProductExportWriter::PRICE_ID_FIELD]) ||
                                count($data[AbstractProductExportWriter::PRICE_ID_FIELD]) <
                                count($this->getIntegrationChannels($finalPrice)
                                )
                            ) {
                                $key = sprintf(
                                    '%s_%s',
                                    $entity->getSku(),
                                    $finalPrice->getCurrency()
                                );
                                $result[$key] = $finalPrice;
                            }
                        }
                    }
                }
            }
            if ($entity instanceof ProductPrice || $entity instanceof ProductChannelPrice) {
                if ($this->isSyncRequired($entity)) {
                    $key = sprintf('%s_%s', $entity->getProduct()->getSku(), $entity->getCurrency());
                    if ($entity instanceof ProductChannelPrice) {
                        $key = sprintf('%s_%s', $key, $entity->getChannel()->getId());
                    }
                    $result[$key] = $entity;
                }
            }
        }

        usort($result, function ($a, $b) {
            if ($a instanceof ProductChannelPrice && $b instanceof ProductPrice) {
                return -1;
            } elseif ($b instanceof ProductChannelPrice && $a instanceof ProductPrice) {
                return 1;
            } else {
                return 0;
            }
        });

        return $result;
    }

    /**
     * @param ProductPrice|ProductChannelPrice|BasePrice $entity
     * @return bool
     */
    protected function isSyncRequired(BasePrice $entity)
    {
        $changeSet = $this->unitOfWork->getEntityChangeSet($entity);

        if (count($changeSet) === 0) {
            if (in_array($entity, $this->unitOfWork->getScheduledEntityDeletions())) {
                return true;
            }

            return false;
        }

        foreach (array_keys($changeSet) as $fieldName) {
            if (in_array($fieldName, self::SYNC_FIELDS)) {
                $oldValue = $changeSet[$fieldName][0];
                $newValue = $changeSet[$fieldName][1];
                if ($fieldName === 'value') {
                    $oldValue = (float)$oldValue;
                    $newValue = (float)$newValue;
                }
                if ($oldValue !== $newValue) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param BasePrice|ProductPrice|ProductChannelPrice $entity
     */
    protected function scheduleSync(BasePrice $entity)
    {
        if (!in_array($entity, $this->processedEntities)) {
            $integrationChannels = $this->getIntegrationChannels($entity);
            $data = $entity->getProduct()->getData();
            foreach ($integrationChannels as $integrationChannel) {
                $product = $entity->getProduct();
                $salesChannel = $this->getSalesChannel($product, $integrationChannel);
                if ($salesChannel !== null) {
                    $finalPrice = $this->getFinalPrice($product, $salesChannel);
                    if ($entity->getValue() === null || $entity === $finalPrice ||
                        in_array($entity, $this->unitOfWork->getScheduledEntityDeletions())) {
                        $entityName = ClassUtils::getClass($finalPrice);
                        $channelId = $integrationChannel->getId();
                        if (isset($data[AbstractProductExportWriter::PRICE_ID_FIELD]) &&
                            isset($data[AbstractProductExportWriter::PRICE_ID_FIELD][$channelId]) &&
                            $data[AbstractProductExportWriter::PRICE_ID_FIELD][$channelId] !== null
                        ) {
                            $connector_params = [
                                'processorAlias' => 'marello_orocommerce_product_price.export',
                                AbstractExportWriter::ACTION_FIELD => AbstractExportWriter::UPDATE_ACTION,
                                ProductExportCreateReader::SKU_FILTER => $product->getSku(),
                                'value' => $finalPrice->getValue(),
                                'currency' => $finalPrice->getCurrency(),
                            ];
                        } else {
                            $connector_params = [
                                'processorAlias' => 'marello_orocommerce_product_price.export',
                                AbstractExportWriter::ACTION_FIELD => AbstractExportWriter::CREATE_ACTION,
                                ProductExportCreateReader::SKU_FILTER => $product->getSku(),
                                'value' => $finalPrice->getValue(),
                                'currency' => $finalPrice->getCurrency(),
                            ];
                        }

                        if (!empty($connector_params)) {
                            $connector_params['entityName'] = $entityName;
                            /** @var OroCommerceSettings $transport */
                            $transport = $integrationChannel->getTransport();
                            $settingsBag = $transport->getSettingsBag();
                            if ($integrationChannel->isEnabled()) {
                                $this->producer->send(
                                    sprintf('%s.orocommerce', Topics::REVERS_SYNC_INTEGRATION),
                                    new Message(
                                        [
                                            'integration_id'       => $integrationChannel->getId(),
                                            'connector_parameters' => $connector_params,
                                            'connector'            => OroCommerceProductPriceConnector::TYPE,
                                            'transport_batch_size' => 100,
                                        ],
                                        MessagePriority::HIGH
                                    )
                                );
                            } elseif ($settingsBag->get(OroCommerceSettings::DELETE_REMOTE_DATA_ON_DEACTIVATION) === false) {
                                $transportData = $transport->getData();
                                $transportData[AbstractExportWriter::NOT_SYNCHRONIZED]
                                [OroCommerceProductPriceConnector::TYPE]
                                [$this->generateConnectionParametersKey($connector_params, ['value'])] = $connector_params;
                                $transport->setData($transportData);
                                $this->entityManager->persist($transport);
                                /** @var OroEventManager $eventManager */
                                $eventManager = $this->entityManager->getEventManager();
                                $eventManager->removeEventListener(
                                    'onFlush',
                                    'marello_orocommerce.event_listener.doctrine.reverse_sync_product_price'
                                );
                                $this->entityManager->flush($transport);
                            }

                            $this->processedEntities[] = $entity;
                        }
                    }
                }
            }
        }
    }

    /**
     * @param BasePrice $entity
     * @return Channel[]
     */
    protected function getIntegrationChannels(BasePrice $entity)
    {
        $integrationChannels = [];
        if ($entity instanceof ProductChannelPrice) {
            $salesChannel = $entity->getChannel();
            $channel = $salesChannel->getIntegrationChannel();
            if ($channel && $channel->getType() === OroCommerceChannelType::TYPE &&
                $channel->getSynchronizationSettings()->offsetGetOr('isTwoWaySyncEnabled', false)) {
                $connectors = $channel->getConnectors();
                if (in_array(OroCommerceProductPriceConnector::TYPE, $connectors)) {
                    $integrationChannels[] = $channel;
                }
            }
        } elseif ($entity instanceof ProductPrice) {
            /** @var SalesChannel[] $salesChannels */
            $salesChannels = $entity->getProduct()->getChannels();
            $integrationChannels = [];
            foreach ($salesChannels as $salesChannel) {
                $channel = $salesChannel->getIntegrationChannel();
                if ($channel && $channel->getType() === OroCommerceChannelType::TYPE &&
                    $channel->getSynchronizationSettings()->offsetGetOr('isTwoWaySyncEnabled', false)
                ) {
                    $integrationChannels[$channel->getId()] = $channel;
                }
            }
        }

        return $integrationChannels;
    }

    /**
     * @param Product $product
     * @param Channel $integrationChannel
     * @return SalesChannel|null
     */
    private function getSalesChannel(Product $product, Channel $integrationChannel)
    {
        foreach ($product->getChannels() as $salesChannel) {
            if ($salesChannel->getIntegrationChannel() === $integrationChannel) {
                return $salesChannel;
            }
        }

        return null;
    }

    /**
     * @param Product $product
     * @param SalesChannel $salesChannel
     * @return BasePrice
     */
    public static function getFinalPrice(Product $product, SalesChannel $salesChannel)
    {
        if ($channelPrice = $product->getSalesChannelPrice($salesChannel)) {
            return $channelPrice->getSpecialPrice() ? : $channelPrice->getDefaultPrice();
        }
        $defaultPrice = $product->getPrice($salesChannel->getCurrency());

        return $defaultPrice->getSpecialPrice() ? : $defaultPrice->getDefaultPrice();
    }
}
