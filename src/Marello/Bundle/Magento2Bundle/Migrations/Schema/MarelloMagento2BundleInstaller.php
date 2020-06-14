<?php

namespace Marello\Bundle\Magento2Bundle\Migrations\Schema;

use Doctrine\DBAL\Schema\Schema;
use Oro\Bundle\EntityConfigBundle\Entity\ConfigModel;
use Oro\Bundle\EntityExtendBundle\EntityConfig\ExtendScope;
use Oro\Bundle\EntityExtendBundle\Migration\ExtendOptionsManager;
use Oro\Bundle\EntityExtendBundle\Migration\Extension\ExtendExtension;
use Oro\Bundle\EntityExtendBundle\Migration\Extension\ExtendExtensionAwareInterface;
use Oro\Bundle\MigrationBundle\Migration\Installation;
use Oro\Bundle\MigrationBundle\Migration\QueryBag;

class MarelloMagento2BundleInstaller implements Installation, ExtendExtensionAwareInterface
{
    /**
     * @var ExtendExtension
     */
    private $extendExtension;

    /**
     * @param ExtendExtension $extendExtension
     */
    public function setExtendExtension(ExtendExtension $extendExtension)
    {
        $this->extendExtension = $extendExtension;
    }

    /**
     * {@inheritDoc}
     */
    public function getMigrationVersion()
    {
        return 'v1_1';
    }

    /**
     * {@inheritDoc}
     */
    public function up(Schema $schema, QueryBag $queries)
    {
        $this->updateIntegrationTransportTable($schema);

        $this->createWebsiteTable($schema);
        $this->createStoreTable($schema);

        $this->addWebsiteForeignKeys($schema);
        $this->addStoreForeignKeys($schema);

        $this->addSalesChannelWebsiteRelation($schema);

        $this->addMagentoProductTable($schema);
        $this->addMagentoProductForeignKeys($schema);

        $this->addMagentoProductTaxTable($schema);
        $this->addProductClassToTaxCodeRelation($schema);
        $this->addMagentoProductTaxForeignKeys($schema);
    }

    /**
     * @param Schema $schema
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    protected function updateIntegrationTransportTable(Schema $schema)
    {
        $table = $schema->getTable('oro_integration_transport');
        $table->addColumn('m2_api_url', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('m2_api_token', 'string', ['notnull' => false, 'length' => 255]);
        $table->addColumn('m2_sync_start_date', 'date', ['notnull' => false]);
        $table->addColumn('m2_sync_range', 'string', ['notnull' => false, 'length' => 50]);
        $table->addColumn('m2_initial_sync_start_date', 'datetime', ['notnull' => false]);
        $table->addColumn('m2_websites_sales_channel_map', 'json', [
            'notnull' => false,
            'comment' => '(DC2Type:json)'
        ]);
        $table->addColumn('m2_del_remote_data_on_deact', 'boolean', ['notnull' => false]);
        $table->addColumn('m2_del_remote_data_on_del', 'boolean', ['notnull' => false]);
    }

    /**
     * @param Schema $schema
     */
    protected function createWebsiteTable(Schema $schema)
    {
        $table = $schema->createTable('marello_m2_website');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('channel_id', 'integer');
        $table->addColumn('code', 'string', ['length' => 32, 'precision' => 0]);
        $table->addColumn('name', 'string', ['length' => 255, 'precision' => 0]);
        $table->addColumn('origin_id', 'integer', ['notnull' => false, 'precision' => 0, 'unsigned' => true]);
        $table->addIndex(['channel_id'], 'IDX_D427981972F5A1AA', []);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['channel_id', 'origin_id'], 'unq_site_idx');
    }

    /**
     * @param Schema $schema
     */
    protected function createStoreTable(Schema $schema)
    {
        $table = $schema->createTable('marello_m2_store');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('channel_id', 'integer');
        $table->addColumn('code', 'string', ['length' => 32, 'precision' => 0]);
        $table->addColumn('name', 'string', ['length' => 255, 'precision' => 0]);
        $table->addColumn('base_currency_code', 'string', ['length' => 3, 'precision' => 0, 'notnull' => false]);
        $table->addColumn('origin_id', 'integer', ['notnull' => false, 'precision' => 0, 'unsigned' => true]);
        $table->addColumn('website_id', 'integer', []);
        $table->addColumn('is_active', 'boolean', ['default' => false]);
        $table->addColumn('locale_id', 'string', ['notnull' => false, 'length' => 255, 'precision' => 0]);
        $table->addColumn('localization_id', 'integer', ['notnull' => false]);
        $table->addIndex(['website_id'], 'IDX_C14EB5DC18F45C82', []);
        $table->addIndex(['channel_id'], 'IDX_C14EB5DC72F5A1AA', []);
        $table->addIndex(['localization_id'], 'IDX_C14EB5DC6A2856C7', []);
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(['code', 'channel_id'], 'unq_store_code_idx');
    }

    /**
     * @param Schema $schema
     */
    public function addWebsiteForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('marello_m2_website');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_integration_channel'),
            ['channel_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    /**
     * @param Schema $schema
     */
    public function addStoreForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('marello_m2_store');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_integration_channel'),
            ['channel_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );

        $table->addForeignKeyConstraint(
            $schema->getTable('marello_m2_website'),
            ['website_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );

        $table->addForeignKeyConstraint(
            $schema->getTable('oro_localization'),
            ['localization_id'],
            ['id'],
            ['onDelete' => 'SET NULL']
        );
    }

    /**
     * Please check comments to {@see \Marello\Bundle\Magento2Bundle\Model\ExtendWebsite} before modifying this code
     *
     * @param Schema $schema
     */
    protected function addSalesChannelWebsiteRelation(Schema $schema)
    {
        $table = $schema->getTable('marello_sales_sales_channel');
        $targetTable = $schema->getTable('marello_m2_website');

        $this->extendExtension->addManyToOneRelation(
            $schema,
            $targetTable,
            'salesChannel',
            $table,
            'name',
            [
                ExtendOptionsManager::MODE_OPTION => ConfigModel::MODE_READONLY,
                'extend' => [
                    'is_extend' => true,
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'without_default' => true,
                    'cascade' => [],
                    'on_delete' => 'SET NULL',
                ],
                'dataaudit' => ['auditable' => true]
            ]
        );

        $this->extendExtension->addManyToOneInverseRelation(
            $schema,
            $targetTable,
            'salesChannel',
            $table,
            'magento2Websites',
            ['name'],
            ['name'],
            ['name'],
            [
                ExtendOptionsManager::MODE_OPTION => ConfigModel::MODE_READONLY,
                'extend' => [
                    'is_extend' => true,
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'without_default' => true,
                    'on_delete' => 'SET NULL',
                ],
                'datagrid' => ['is_visible' => false],
                'form' => ['is_enabled' => false],
                'view' => ['is_displayable' => false],
                'merge' => ['display' => false],
                'importexport' => ['excluded' => true],
            ]
        );
    }

    /**
     * @param Schema $schema
     */
    protected function addMagentoProductTable(Schema $schema)
    {
        $table = $schema->createTable('marello_m2_product');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('sku', 'string', ['length' => 255]);
        $table->addColumn('product_id', 'integer');
        $table->addColumn('channel_id', 'integer');
        $table->addColumn('origin_id', 'integer', ['notnull' => false, 'precision' => 0, 'unsigned' => true]);
        $table->addColumn('created_at', 'datetime');
        $table->addColumn('updated_at', 'datetime');
        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['product_id', 'channel_id'],
            'unq_product_channel_idx'
        );

        $this->extendExtension->addEnumField(
            $schema,
            $table,
            'status',
            'marello_m2_p_status',
            false,
            true,
            [
                'extend' => ['owner' => ExtendScope::OWNER_CUSTOM]
            ]
        );
    }

    /**
     * @param Schema $schema
     */
    protected function addMagentoProductForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('marello_m2_product');
        $table->addForeignKeyConstraint(
            $schema->getTable('marello_product_product'),
            ['product_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );

        $table->addForeignKeyConstraint(
            $schema->getTable('oro_integration_channel'),
            ['channel_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    /**
     * @param Schema $schema
     */
    protected function addMagentoProductTaxTable(Schema $schema)
    {
        $table = $schema->createTable('marello_m2_product_tax_class');
        $table->addColumn('id', 'integer', ['precision' => 0, 'autoincrement' => true]);
        $table->addColumn('origin_id', 'integer', ['notnull' => false, 'precision' => 0, 'unsigned' => true]);
        $table->addColumn('class_name', 'string', ['length' => 255]);
        $table->addColumn('channel_id', 'integer');
        $table->setPrimaryKey(['id']);
    }

    /**
     * @param Schema $schema
     */
    protected function addMagentoProductTaxForeignKeys(Schema $schema)
    {
        $table = $schema->getTable('marello_m2_product_tax_class');
        $table->addForeignKeyConstraint(
            $schema->getTable('oro_integration_channel'),
            ['channel_id'],
            ['id'],
            ['onDelete' => 'CASCADE']
        );
    }

    /**
     * @param Schema $schema
     * @throws \Doctrine\DBAL\Schema\SchemaException
     */
    protected function addProductClassToTaxCodeRelation(Schema $schema)
    {
        $table = $schema->getTable('marello_tax_tax_code');
        $targetTable = $schema->getTable('marello_m2_product_tax_class');

        $this->extendExtension->addManyToOneRelation(
            $schema,
            $targetTable,
            'taxCode',
            $table,
            'code',
            [
                ExtendOptionsManager::MODE_OPTION => ConfigModel::MODE_READONLY,
                'extend' => [
                    'is_extend' => true,
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'without_default' => true,
                    'on_delete' => 'SET NULL',
                ],
                'dataaudit' => ['auditable' => true]
            ]
        );

        $this->extendExtension->addManyToOneInverseRelation(
            $schema,
            $targetTable,
            'taxCode',
            $table,
            'magento2ProductTaxClasses',
            ['class_name'],
            ['class_name'],
            ['class_name'],
            [
                ExtendOptionsManager::MODE_OPTION => ConfigModel::MODE_READONLY,
                'extend' => [
                    'is_extend' => true,
                    'owner' => ExtendScope::OWNER_CUSTOM,
                    'without_default' => true,
                    'on_delete' => 'SET NULL',
                ],
                'datagrid' => ['is_visible' => false],
                'form' => ['is_enabled' => false],
                'view' => ['is_displayable' => false],
                'merge' => ['display' => false],
                'importexport' => ['excluded' => true],
            ]
        );
    }
}
