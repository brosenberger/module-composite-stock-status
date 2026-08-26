<?php
/**
 * Copyright (C) 2026 Benjamin Rosenberger <bensch.rosenberger@gmail.com>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @copyright 2026 Benjamin Rosenberger
 * @author bensch.rosenberger@gmail.com
 * @license MIT
 * @link https://brocode.at
 */

declare(strict_types=1);

namespace BroCode\CompositeStockStatus\Test\Integration;

use BroCode\CompositeStockStatus\Model\ParentStockReviver;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\Data\ProductAttributeInterfaceFactory;
use Magento\Catalog\Api\ProductAttributeManagementInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\ConfigurableProduct\Api\Data\OptionInterfaceFactory;
use Magento\ConfigurableProduct\Api\Data\OptionValueInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Registry;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The behaviour unit tests cannot prove: that a parent imported the way every
 * ERP feed imports one -- structure first, stock second -- actually follows its
 * children back into stock.
 *
 * DB isolation is deliberately DISABLED. A product save rolls back a nested
 * transaction internally, and doing that inside the framework's own isolation
 * transaction fails with "Rolled back transaction has not been completed
 * correctly", an error that names nothing relevant. Cleanup is done in
 * tearDown() instead.
 *
 * @magentoAppIsolation enabled
 * @magentoDbIsolation disabled
 */
class CompositeStockStatusTest extends TestCase
{
    private const PARENT_SKU = 'brocode-css-parent';
    private const CHILD_SKUS = ['brocode-css-child-1', 'brocode-css-child-2'];
    private const ATTRIBUTE = 'brocode_css_option';

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var ProductInterfaceFactory
     */
    private $productFactory;

    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @var ParentStockReviver
     */
    private $reviver;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    protected function setUp(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $this->productRepository = $objectManager->get(ProductRepositoryInterface::class);
        $this->productFactory = $objectManager->get(ProductInterfaceFactory::class);
        $this->stockRegistry = $objectManager->get(StockRegistryInterface::class);
        $this->resource = $objectManager->get(ResourceConnection::class);
        $this->reviver = $objectManager->get(ParentStockReviver::class);
        $this->registry = $objectManager->get(Registry::class);
        $this->objectManager = $objectManager;

        $this->removeFixtureProducts();
    }

    protected function tearDown(): void
    {
        $this->removeFixtureProducts();
    }

    /**
     * The regression test for the whole module. Without it the parent stays out
     * of stock here forever, however much quantity its children get.
     */
    public function testAParentImportedWithoutStockFollowsItsChildrenBackIntoStock(): void
    {
        $this->createCatalogue();

        $this->assertSame(0, $this->parentIsInStock(), 'precondition: a parent born without stock data is out of stock');

        $this->stockChildren();

        $this->assertSame(
            1,
            $this->parentIsInStock(),
            'the parent must follow its children back into stock'
        );
    }

    /**
     * The mechanism behind the test above, pinned separately so a failure says
     * which half broke.
     */
    public function testTheParentIsBornWithTheAutomaticFlagSet(): void
    {
        $this->createCatalogue();

        $this->assertSame(
            1,
            $this->parentChangedAuto(),
            'stock_status_changed_auto must be set at birth, or core will never revive the parent'
        );
    }

    /**
     * The documented boundary, pinned so it cannot move silently.
     *
     * A manual out-of-stock on a composite parent survives child stock movement,
     * because nothing re-derives the parent while its marker is cleared. It is
     * reset by the next save of that parent -- which is also what core does, so
     * the decision was never durable across an edit either way.
     */
    public function testAManualOutOfStockSurvivesUntilTheParentIsSavedAgain(): void
    {
        $this->createCatalogue();
        $this->stockChildren();

        $stockItem = $this->stockRegistry->getStockItemBySku(self::PARENT_SKU);
        $stockItem->setIsInStock(false);
        $stockItem->setStockStatusChangedAuto(0);
        $this->stockRegistry->updateStockItemBySku(self::PARENT_SKU, $stockItem);

        $this->assertSame(0, $this->parentIsInStock(), 'precondition: the parent is off sale');

        $this->unstockChildren();
        $this->stockChildren();

        $this->assertSame(0, $this->parentIsInStock(), 'child stock movement alone does not overrule it');
        $this->assertSame(0, $this->parentChangedAuto(), 'and the marker stays cleared');

        // the next save of the parent puts it back under automatic control
        $parent = $this->productRepository->get(self::PARENT_SKU, true, null, true);
        $parent->setName(self::PARENT_SKU . ' edited');
        $this->productRepository->save($parent);

        $this->assertSame(1, $this->parentChangedAuto(), 'saving the parent restores automatic control');

        $this->unstockChildren();
        $this->stockChildren();

        $this->assertSame(1, $this->parentIsInStock(), 'after which the parent follows its children again');
    }

    /**
     * Core clears the marker on every product save, so setting it once at
     * creation is not enough -- the second write of a normal import loses it.
     */
    public function testTheMarkerSurvivesASecondProductSave(): void
    {
        $this->createCatalogue();
        $this->assertSame(1, $this->parentChangedAuto(), 'set at creation');

        $parent = $this->productRepository->get(self::PARENT_SKU, true, null, true);
        $parent->setName(self::PARENT_SKU . ' renamed');
        $this->productRepository->save($parent);

        $this->assertSame(
            1,
            $this->parentChangedAuto(),
            'and re-applied on the next save, which core would otherwise clear'
        );
    }

    /**
     * The repair path, for catalogues that were imported before the module was
     * installed.
     */
    public function testTheReviverRepairsAnAlreadyLatchedParent(): void
    {
        $this->createCatalogue();
        $this->stockChildren();
        $this->latchParentByHand();

        $this->assertSame(0, $this->parentIsInStock(), 'precondition: the parent is stuck out of stock');
        $this->assertNotEmpty($this->reviver->findLatched([self::PARENT_SKU]), 'and it is detected as stuck');

        $revived = $this->reviver->revive([self::PARENT_SKU]);

        $this->assertSame([self::PARENT_SKU], $revived);
        $this->assertSame(1, $this->parentIsInStock(), 'the repaired parent follows its in-stock children');
    }

    /**
     * A parent whose children really are all out of stock must stay out of
     * stock -- reviving is not "set everything in stock".
     */
    public function testTheReviverDoesNotReviveAParentWithNoStockedChildren(): void
    {
        $this->createCatalogue();
        $this->latchParentByHand();

        $this->reviver->revive([self::PARENT_SKU]);

        $this->assertSame(0, $this->parentIsInStock(), 'no child has stock, so the parent must stay out');
    }

    /**
     * Build the catalogue the way an ERP feed does: every product created with
     * no stock information at all, stock arriving in a later call.
     *
     * @return void
     */
    private function createCatalogue(): void
    {
        $optionIds = $this->attributeOptionIds();
        $childIds = [];

        foreach (array_values(self::CHILD_SKUS) as $i => $sku) {
            $child = $this->productFactory->create();
            $child->setSku($sku);
            $child->setName($sku);
            $child->setPrice(10);
            $child->setTypeId('simple');
            $child->setAttributeSetId(4);
            $child->setStatus(1);
            $child->setVisibility(1);
            $child->setWebsiteIds([1]);
            $child->setData(self::ATTRIBUTE, $optionIds[$i]);
            $this->productRepository->save($child);
            $childIds[] = (int) $this->productRepository->get($sku)->getId();
        }

        $parent = $this->productFactory->create();
        $parent->setSku(self::PARENT_SKU);
        $parent->setName(self::PARENT_SKU);
        $parent->setPrice(10);
        $parent->setTypeId('configurable');
        $parent->setAttributeSetId(4);
        $parent->setStatus(1);
        $parent->setVisibility(4);
        $parent->setWebsiteIds([1]);

        $attribute = $this->objectManager->get(ProductAttributeRepositoryInterface::class)->get(self::ATTRIBUTE);
        $valueFactory = $this->objectManager->get(OptionValueInterfaceFactory::class);
        $values = [];
        foreach ($optionIds as $optionId) {
            $value = $valueFactory->create();
            $value->setValueIndex($optionId);
            $values[] = $value;
        }

        $option = $this->objectManager->get(OptionInterfaceFactory::class)->create();
        $option->setAttributeId((string) $attribute->getAttributeId());
        $option->setLabel($attribute->getDefaultFrontendLabel());
        $option->setPosition(0);
        $option->setValues($values);

        $extension = $parent->getExtensionAttributes();
        $extension->setConfigurableProductOptions([$option]);
        $extension->setConfigurableProductLinks($childIds);
        $parent->setExtensionAttributes($extension);

        $this->productRepository->save($parent);
    }

    /**
     * A real configurable attribute, because a fixture built from bare
     * catalog_product_super_link rows cannot be re-saved -- the configurable
     * validator rejects children that share an (empty) attribute value.
     *
     * @return int[]
     */
    private function attributeOptionIds(): array
    {
        $attributeRepository = $this->objectManager->get(ProductAttributeRepositoryInterface::class);

        try {
            $attribute = $attributeRepository->get(self::ATTRIBUTE);
        } catch (NoSuchEntityException $e) {
            $optionFactory = $this->objectManager->get(AttributeOptionInterfaceFactory::class);
            $options = [];
            foreach (['One', 'Two'] as $label) {
                $option = $optionFactory->create();
                $option->setLabel($label);
                $options[] = $option;
            }

            $attribute = $this->objectManager->get(ProductAttributeInterfaceFactory::class)->create();
            $attribute->setAttributeCode(self::ATTRIBUTE);
            $attribute->setEntityTypeId(4);
            $attribute->setFrontendInput('select');
            $attribute->setFrontendLabel('BroCode CSS Option');
            $attribute->setIsUserDefined(true);
            $attribute->setScope('global');
            $attribute->setIsRequired(false);
            $attribute->setIsGlobal(1);
            $attribute->setOptions($options);
            $attributeRepository->save($attribute);

            $attribute = $attributeRepository->get(self::ATTRIBUTE);
            $groupId = $this->resource->getConnection()->fetchOne(
                'SELECT attribute_group_id FROM ' . $this->resource->getTableName('eav_attribute_group')
                . ' WHERE attribute_set_id = ? ORDER BY sort_order LIMIT 1',
                [4]
            );
            $this->objectManager->get(ProductAttributeManagementInterface::class)
                ->assign(4, (int) $groupId, self::ATTRIBUTE, 100);
        }

        $ids = [];
        foreach ($attribute->getOptions() as $option) {
            if ($option->getValue() !== '' && $option->getValue() !== null) {
                $ids[] = (int) $option->getValue();
            }
        }

        return $ids;
    }

    /**
     * @return void
     */
    private function unstockChildren(): void
    {
        foreach (self::CHILD_SKUS as $sku) {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
            $stockItem->setQty(0);
            $stockItem->setIsInStock(false);
            $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
        }
    }

    /**
     * @return void
     */
    private function stockChildren(): void
    {
        foreach (self::CHILD_SKUS as $sku) {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku);
            $stockItem->setQty(50);
            $stockItem->setIsInStock(true);
            $this->stockRegistry->updateStockItemBySku($sku, $stockItem);
        }
    }

    /**
     * Reproduce the pre-module state: out of stock with the automatic flag
     * cleared, which is what core writes for a product created without stock.
     *
     * @return void
     */
    private function latchParentByHand(): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName('cataloginventory_stock_item'),
            ['is_in_stock' => 0, 'stock_status_changed_auto' => 0],
            ['product_id = ?' => $this->productId(self::PARENT_SKU)]
        );
    }

    /**
     * @param string $sku
     * @return int
     */
    private function productId(string $sku): int
    {
        return (int) $this->productRepository->get($sku, false, null, true)->getId();
    }

    /**
     * Read the row rather than a loaded model: the stored flag is the thing
     * under test, and a model can serve a cached or derived value.
     *
     * @return int
     */
    private function parentIsInStock(): int
    {
        return (int) $this->parentStockColumn('is_in_stock');
    }

    /**
     * @return int
     */
    private function parentChangedAuto(): int
    {
        return (int) $this->parentStockColumn('stock_status_changed_auto');
    }

    /**
     * @param string $column
     * @return string
     */
    private function parentStockColumn(string $column): string
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('cataloginventory_stock_item'), [$column])
            ->where('product_id = ?', $this->productId(self::PARENT_SKU));

        return (string) $connection->fetchOne($select);
    }

    /**
     * @return void
     */
    private function removeFixtureProducts(): void
    {
        // Product deletion is refused outside a secure area, and because DB
        // isolation is off, a fixture that survives teardown is inherited by the
        // next test -- with the stock the previous test gave it.
        $wasSecure = $this->registry->registry('isSecureArea');
        $this->registry->unregister('isSecureArea');
        $this->registry->register('isSecureArea', true);

        try {
            foreach (array_merge([self::PARENT_SKU], self::CHILD_SKUS) as $sku) {
                try {
                    $this->productRepository->deleteById($sku);
                } catch (NoSuchEntityException $e) {
                    // absent is the desired state
                }
            }
        } finally {
            $this->registry->unregister('isSecureArea');
            if ($wasSecure !== null) {
                $this->registry->register('isSecureArea', $wasSecure);
            }
        }
    }
}
