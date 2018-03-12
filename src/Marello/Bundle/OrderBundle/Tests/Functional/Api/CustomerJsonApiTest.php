<?php

namespace Marello\Bundle\OrderBundle\Tests\Functional\Api;

use Symfony\Component\HttpFoundation\Response;

use Marello\Bundle\OrderBundle\Entity\Customer;
use Marello\Bundle\CoreBundle\Tests\Functional\RestJsonApiTestCase;
use Marello\Bundle\OrderBundle\Tests\Functional\DataFixtures\LoadCustomerData;

class CustomerJsonApiTest extends RestJsonApiTestCase
{
    const TESTING_ENTITY = 'customers';

    protected function setUp()
    {
        parent::setUp();
        $this->loadFixtures([
            LoadCustomerData::class
        ]);
    }

    /**
     * Test cget (getting a list of customers) of Customer entity
     */
    public function testGetListOfCustomers()
    {
        $response = $this->cget(['entity' => self::TESTING_ENTITY], []);

        $this->assertJsonResponse($response);
        $this->assertResponseStatusCodeEquals($response, Response::HTTP_OK);
        $this->assertResponseCount(10, $response);
        $this->assertResponseContains('cget_customer_list.yml', $response);
    }

    /**
     * Test get customer by id
     */
    public function testGetCustomerById()
    {
        $customer = $this->getReference('marello-customer-1');
        $response = $this->get(
            ['entity' => self::TESTING_ENTITY, 'id' => $customer->getId()],
            []
        );

        $this->assertJsonResponse($response);
        $this->assertResponseContains('get_customer_by_id.yml', $response);
    }

    /**
     * Get a single customer by email
     */
    public function testGetCustomerFilteredByEmail()
    {
        /** @var Customer $customer */
        $customer = $this->getReference('marello-customer-1');
        $response = $this->cget(
            ['entity' => self::TESTING_ENTITY],
            [
                'filter' => ['email' =>  $customer->getEmail() ]
            ]
        );

        $this->assertJsonResponse($response);
        $this->assertResponseCount(1, $response);
        $this->assertResponseContains('get_customer_by_email.yml', $response);
    }

    /**
     * Create a new customer
     */
    public function testCreateNewCustomer()
    {
        $response = $this->post(
            ['entity' => self::TESTING_ENTITY],
            'customer_create.yml'
        );

        $this->assertJsonResponse($response);

        $responseContent = json_decode($response->getContent());

        /** @var Customer $customer */
        $customer = $this->getEntityManager()->find(Customer::class, $responseContent->data->id);
        $this->assertEquals($customer->getEmail(), $responseContent->data->attributes->email);
    }
}
