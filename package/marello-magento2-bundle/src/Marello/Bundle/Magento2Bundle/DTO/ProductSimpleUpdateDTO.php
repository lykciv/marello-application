<?php

namespace Marello\Bundle\Magento2Bundle\DTO;

use Marello\Bundle\Magento2Bundle\Entity\Website;
use Marello\Bundle\ProductBundle\Entity\ProductStatus;

class ProductSimpleUpdateDTO
{
    /** @var int */
    protected $marrelloProductId;

    /** @var string */
    protected $sku;

    /** @var string */
    protected $name;

    /** @var float */
    protected $price;

    /** @var Website[] */
    protected $websites = [];

    /** @var ProductStatus */
    protected $status;

    /**
     * @param int $marrelloProductId
     * @param string $sku
     * @param string $name
     * @param array $websites
     * @param ProductStatus $status
     */
    public function __construct(
        int $marrelloProductId,
        string $sku,
        string $name,
        array $websites,
        ProductStatus $status
    ) {
        $this->marrelloProductId = $marrelloProductId;
        $this->sku = $sku;
        $this->name = $name;
        $this->websites = $websites;
        $this->status = $status;
        $this->price = static::DEFAULT_PRICE;
    }

    /**
     * @return int
     */
    public function getMarrelloProductId(): int
    {
        return $this->marrelloProductId;
    }

    /**
     * @return string
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getPrice(): string
    {
        return $this->price;
    }

    /**
     * @return Website[]
     */
    public function getWebsites(): array
    {
        return $this->websites;
    }

    /**
     * @return ProductStatus
     */
    public function getStatus(): ProductStatus
    {
        return $this->status;
    }
}
