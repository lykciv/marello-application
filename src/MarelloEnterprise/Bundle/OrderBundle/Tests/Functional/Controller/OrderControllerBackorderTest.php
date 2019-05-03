<?php

namespace MarelloEnterprise\Bundle\OrderBundle\Tests\Functional\Controller;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Marello\Bundle\AddressBundle\Entity\MarelloAddress;
use Marello\Bundle\InventoryBundle\Entity\InventoryItem;
use Marello\Bundle\InventoryBundle\Provider\WarehouseTypeProviderInterface;
use Marello\Bundle\OrderBundle\Entity\Customer;
use Marello\Bundle\OrderBundle\Entity\Order;
use Marello\Bundle\OrderBundle\Migrations\Data\ORM\LoadOrderItemStatusData;
use Marello\Bundle\OrderBundle\Tests\Functional\DataFixtures\LoadOrderData;
use Marello\Bundle\PackingBundle\Entity\PackingSlip;
use Marello\Bundle\PackingBundle\Entity\PackingSlipItem;
use Marello\Bundle\ProductBundle\Entity\Product;
use Marello\Bundle\ProductBundle\Tests\Functional\DataFixtures\LoadProductData;
use Marello\Bundle\SalesBundle\Entity\SalesChannel;
use Marello\Bundle\SalesBundle\Tests\Functional\DataFixtures\LoadSalesData;
use Marello\Bundle\ShippingBundle\Method\ShippingMethodInterface;
use Marello\Bundle\ShippingBundle\Method\ShippingMethodProviderInterface;
use Marello\Bundle\ShippingBundle\Method\ShippingMethodTypeInterface;
use Oro\Bundle\TestFrameworkBundle\Test\WebTestCase;
use Oro\Bundle\WorkflowBundle\Model\WorkflowManager;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\HttpFoundation\Response;

class OrderControllerBackorderTest extends WebTestCase
{
    /**
     * @var WorkflowManager
     */
    protected $workflowManager;

    /**
     * @var Registry
     */
    protected $doctrine;
    
    public function setUp()
    {
        $this->initClient(
            [],
            $this->generateBasicAuthHeader()
        );

        $this->loadFixtures([
            LoadOrderData::class,
        ]);

        $this->workflowManager = $this->getContainer()->get('oro_workflow.manager');
        $this->doctrine = $this->getContainer()->get('doctrine');
    }

    /**
     * @return Order
     */
    public function testCreateNoBackorderNoPreorder()
    {
        $crawler = $this->client->request('GET', $this->getUrl('marello_order_order_create'));
        $result  = $this->client->getResponse();
        $this->assertHtmlResponseStatusCodeEquals($result, 200);

        /** @var Form $form */
        $form = $crawler->selectButton('Save and Close')->form();

        /** @var Customer $orderCustomer */
        $orderCustomer = $this->getReference('marello-customer-1');

        /** @var SalesChannel $salesChannel */
        $salesChannel = $this->getReference(LoadSalesData::CHANNEL_1_REF);

        /** @var Product $product */
        $product = $this->getReference(LoadProductData::PRODUCT_6_REF);

        $price = $product->getPrice($salesChannel->getCurrency())->getPrice()->getValue();
        $orderItems = [
            [
                'product' => $product->getId(),
                'quantity' => 1,
                'price' => $price,
                'tax' => 0.00,
                'taxCode' => $product->getTaxCode()->getCode(),
                'rowTotalExclTax' => $price,
                'rowTotalInclTax' => $price
            ],
        ];

        /** @var Order[] $ordersBefore */
        $ordersBefore = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();
        
        $submittedData = $this->getSubmittedData($form, $orderCustomer, $salesChannel, $orderItems);

        $this->client->followRedirects(true);

        $this->client->request($form->getMethod(), $form->getUri(), $submittedData);
        $result  = $this->client->getResponse();

        $this->assertResponseStatusCodeEquals($result, Response::HTTP_OK);
        /** @var Order[] $ordersBefore */
        $ordersAfter = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();
        $this->assertEquals(count($ordersBefore), count($ordersAfter));
    }

    /**
     * @return Order
     */
    public function testCreateWithBackorderNoPreorderNoMaxQty()
    {
        $crawler = $this->client->request('GET', $this->getUrl('marello_order_order_create'));
        $result  = $this->client->getResponse();
        $this->assertHtmlResponseStatusCodeEquals($result, 200);

        /** @var Form $form */
        $form = $crawler->selectButton('Save and Close')->form();

        /** @var Customer $orderCustomer */
        $orderCustomer = $this->getReference('marello-customer-1');

        /** @var SalesChannel $salesChannel */
        $salesChannel = $this->getReference(LoadSalesData::CHANNEL_1_REF);

        /** @var Product $product */
        $product = $this->getReference(LoadProductData::PRODUCT_6_REF);

        $inventoryItemManager = $this->getContainer()->get('doctrine')
            ->getManagerForClass(InventoryItem::class);
        /** @var InventoryItem $inventoryItem */
        $inventoryItem = $inventoryItemManager
            ->getRepository(InventoryItem::class)
            ->findOneBy(['product' => $product->getId()]);
        $inventoryItem->setBackorderAllowed(true);
        $inventoryItemManager->persist($inventoryItem);
        $inventoryItemManager->flush($inventoryItem);

        $price = $product->getPrice($salesChannel->getCurrency())->getPrice()->getValue();
        $orderItems = [
            [
                'product' => $product->getId(),
                'quantity' => 1,
                'price' => $price,
                'tax' => 0.00,
                'taxCode' => $product->getTaxCode()->getCode(),
                'rowTotalExclTax' => $price,
                'rowTotalInclTax' => $price
            ],
        ];

        /** @var Order[] $ordersBefore */
        $ordersBefore = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();

        $submittedData = $this->getSubmittedData($form, $orderCustomer, $salesChannel, $orderItems);

        $this->client->followRedirects(true);

        $this->client->request($form->getMethod(), $form->getUri(), $submittedData);
        $result  = $this->client->getResponse();

        $this->assertResponseStatusCodeEquals($result, Response::HTTP_OK);
        /** @var Order[] $ordersBefore */
        $ordersAfter = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();
        $this->assertEquals(count($ordersBefore), count($ordersAfter));
    }

    /**
     * @return Order
     */
    public function testCreateNoBackorderWithPreorder()
    {
        $crawler = $this->client->request('GET', $this->getUrl('marello_order_order_create'));
        $result  = $this->client->getResponse();
        $this->assertHtmlResponseStatusCodeEquals($result, 200);

        /** @var Form $form */
        $form = $crawler->selectButton('Save and Close')->form();

        /** @var Customer $orderCustomer */
        $orderCustomer = $this->getReference('marello-customer-1');

        /** @var SalesChannel $salesChannel */
        $salesChannel = $this->getReference(LoadSalesData::CHANNEL_1_REF);

        /** @var Product $product */
        $product = $this->getReference(LoadProductData::PRODUCT_6_REF);

        $inventoryItemManager = $this->getContainer()->get('doctrine')
            ->getManagerForClass(InventoryItem::class);
        /** @var InventoryItem $inventoryItem */
        $inventoryItem = $inventoryItemManager
            ->getRepository(InventoryItem::class)
            ->findOneBy(['product' => $product->getId()]);
        $inventoryItem
            ->setBackorderAllowed(false)
            ->setCanPreorder(true);
        $inventoryItemManager->persist($inventoryItem);
        $inventoryItemManager->flush($inventoryItem);

        $price = $product->getPrice($salesChannel->getCurrency())->getPrice()->getValue();
        $orderItems = [
            [
                'product' => $product->getId(),
                'quantity' => 1,
                'price' => $price,
                'tax' => 0.00,
                'taxCode' => $product->getTaxCode()->getCode(),
                'rowTotalExclTax' => $price,
                'rowTotalInclTax' => $price
            ],
        ];

        /** @var Order[] $ordersBefore */
        $ordersBefore = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();

        $submittedData = $this->getSubmittedData($form, $orderCustomer, $salesChannel, $orderItems);

        $this->client->followRedirects(true);

        $this->client->request($form->getMethod(), $form->getUri(), $submittedData);
        $result  = $this->client->getResponse();

        $this->assertResponseStatusCodeEquals($result, Response::HTTP_OK);
        /** @var Order[] $ordersBefore */
        $ordersAfter = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();
        $this->assertEquals(count($ordersBefore) + 1, count($ordersAfter));
    }

    /**
     * @return Order
     */
    public function testCreateWithBackorderWithMaxQtyNoPreorder()
    {
        $crawler = $this->client->request('GET', $this->getUrl('marello_order_order_create'));
        $result  = $this->client->getResponse();
        $this->assertHtmlResponseStatusCodeEquals($result, 200);

        /** @var Form $form */
        $form = $crawler->selectButton('Save and Close')->form();

        /** @var Customer $orderCustomer */
        $orderCustomer = $this->getReference('marello-customer-1');

        /** @var SalesChannel $salesChannel */
        $salesChannel = $this->getReference(LoadSalesData::CHANNEL_1_REF);

        /** @var Product $product */
        $product = $this->getReference(LoadProductData::PRODUCT_6_REF);

        $inventoryItemManager = $this->getContainer()->get('doctrine')
            ->getManagerForClass(InventoryItem::class);
        /** @var InventoryItem $inventoryItem */
        $inventoryItem = $inventoryItemManager
            ->getRepository(InventoryItem::class)
            ->findOneBy(['product' => $product->getId()]);
        $inventoryItem
            ->setBackorderAllowed(true)
            ->setMaxQtyToBackorder(5)
            ->setCanPreorder(false);
        $inventoryItemManager->persist($inventoryItem);
        $inventoryItemManager->flush($inventoryItem);

        $price = $product->getPrice($salesChannel->getCurrency())->getPrice()->getValue();
        $orderItems = [
            [
                'product' => $product->getId(),
                'quantity' => 1,
                'price' => $price,
                'tax' => 0.00,
                'taxCode' => $product->getTaxCode()->getCode(),
                'rowTotalExclTax' => $price,
                'rowTotalInclTax' => $price
            ],
        ];

        /** @var Order[] $ordersBefore */
        $ordersBefore = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();

        $submittedData = $this->getSubmittedData($form, $orderCustomer, $salesChannel, $orderItems);

        $this->client->followRedirects(true);

        $this->client->request($form->getMethod(), $form->getUri(), $submittedData);
        $result  = $this->client->getResponse();

        $this->assertResponseStatusCodeEquals($result, Response::HTTP_OK);
        /** @var Order[] $ordersBefore */
        $ordersAfter = $this->getContainer()->get('doctrine')
            ->getManagerForClass('MarelloOrderBundle:Order')
            ->getRepository('MarelloOrderBundle:Order')
            ->findAll();
        $this->assertEquals(count($ordersBefore) + 1, count($ordersAfter));
    }

    /**
     * @param Form $form
     * @param Customer $orderCustomer
     * @param SalesChannel $salesChannel
     * @param $orderItems
     * @return array
     */
    private function getSubmittedData($form, $orderCustomer, $salesChannel, $orderItems)
    {
        /** @var ShippingMethodProviderInterface $shippingMethodsProvider */
        $shippingMethodsProvider = $this->getContainer()->get('marello_shipping.shipping_method_provider');
        $shippingMethods = $shippingMethodsProvider->getShippingMethods();
        /** @var ShippingMethodInterface $shippingMethod */
        $shippingMethod = reset($shippingMethods);
        $shippingMethodTypes = $shippingMethod->getTypes();
        /** @var ShippingMethodTypeInterface $shippingMethodType */
        $shippingMethodType = reset($shippingMethodTypes);
        $submittedData = [
            'input_action' => 'save_and_stay',
            'marello_order_order' => [
                '_token' => $form['marello_order_order[_token]']->getValue(),
                'customer' => $orderCustomer->getId(),
                'salesChannel' => $salesChannel->getId(),
                'items' => $orderItems,
                'billingAddress' => $this->getAddressFormData($orderCustomer->getPrimaryAddress()),
                'shippingAddress' => $this->getAddressFormData($orderCustomer->getPrimaryAddress()),
                'calculateShipping' => true,
                'shippingMethod' => $shippingMethod->getIdentifier(),
                'shippingMethodType' => $shippingMethodType->getIdentifier(),
                'estimatedShippingCostAmount' => 5.00
            ]
        ];

        return $submittedData;
    }

    /**
     * @param MarelloAddress $address
     * @return array
     */
    private function getAddressFormData(MarelloAddress $address)
    {
        return [
            'namePrefix' => $address->getNamePrefix(),
            'firstName' => $address->getFirstName(),
            'middleName' => $address->getMiddleName(),
            'lastName' => $address->getLastName(),
            'nameSuffix' => $address->getNameSuffix(),
            'country' => $address->getCountryIso2(),
            'street' => $address->getStreet(),
            'street2' => $address->getStreet2(),
            'city' => $address->getCity(),
            'region' => $address->getRegionCode(),
            'region_text' => $address->getRegionText(),
            'postalCode' => $address->getPostalCode(),
            'phone' =>$address->getPhone(),
            'company' => $address->getCompany()
        ];
    }
}
