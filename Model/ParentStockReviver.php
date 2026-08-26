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

namespace BroCode\CompositeStockStatus\Model;

use BroCode\CompositeStockStatus\Model\ResourceModel\GetLatchedCompositeParents;
use Magento\Catalog\Api\ProductRepositoryInterface;
use BroCode\CompositeStockStatus\Model\ResourceModel\SetAutomaticStockStatusFlag;
use Magento\CatalogInventory\Model\StockRegistryStorage;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\InventoryCatalogApi\Model\CompositeProductStockStatusProcessorInterface;
use Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface;

/**
 * Repairs composite parents that are already stuck out of stock.
 *
 * The plugin only helps products created after it is installed. A catalogue
 * imported before that is full of parents core will never revive, and the only
 * way out is to clear the flag and ask core to re-derive the status.
 *
 * Deliberately two steps rather than one: clear the latch, then hand the work
 * to Magento's own per-type processors through the MSI pool. Re-implementing
 * the "is any child in stock" rule here would mean maintaining a second copy of
 * logic that differs between configurable, bundle and grouped.
 */
class ParentStockReviver
{
    /**
     * @var GetLatchedCompositeParents
     */
    private $getLatchedCompositeParents;

    /**
     * @var CompositeTypeResolver
     */
    private $compositeTypeResolver;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var SetAutomaticStockStatusFlag
     */
    private $setAutomaticStockStatusFlag;

    /**
     * @var StockRegistryStorage
     */
    private $stockRegistryStorage;

    /**
     * @var CompositeProductStockStatusProcessorInterface
     */
    private $compositeProductStockStatusProcessor;

    /**
     * @var GetSkusByProductIdsInterface
     */
    private $getSkusByProductIds;

    /**
     * @param \BroCode\CompositeStockStatus\Model\ResourceModel\GetLatchedCompositeParents $getLatchedCompositeParents
     * @param \BroCode\CompositeStockStatus\Model\CompositeTypeResolver $compositeTypeResolver
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     * @param \BroCode\CompositeStockStatus\Model\ResourceModel\SetAutomaticStockStatusFlag $setAutomaticStockStatusFlag
     * @param \Magento\CatalogInventory\Model\StockRegistryStorage $stockRegistryStorage
     * @param \Magento\InventoryCatalogApi\Model\CompositeProductStockStatusProcessorInterface $compositeProductStockStatusProcessor
     * @param \Magento\InventoryCatalogApi\Model\GetSkusByProductIdsInterface $getSkusByProductIds
     */
    public function __construct(
        GetLatchedCompositeParents $getLatchedCompositeParents,
        CompositeTypeResolver $compositeTypeResolver,
        ProductRepositoryInterface $productRepository,
        SetAutomaticStockStatusFlag $setAutomaticStockStatusFlag,
        StockRegistryStorage $stockRegistryStorage,
        CompositeProductStockStatusProcessorInterface $compositeProductStockStatusProcessor,
        GetSkusByProductIdsInterface $getSkusByProductIds
    ) {
        $this->getLatchedCompositeParents = $getLatchedCompositeParents;
        $this->compositeTypeResolver = $compositeTypeResolver;
        $this->productRepository = $productRepository;
        $this->setAutomaticStockStatusFlag = $setAutomaticStockStatusFlag;
        $this->stockRegistryStorage = $stockRegistryStorage;
        $this->compositeProductStockStatusProcessor = $compositeProductStockStatusProcessor;
        $this->getSkusByProductIds = $getSkusByProductIds;
    }

    /**
     * List the parents that cannot come back in stock on their own.
     *
     * @param string[] $skus
     * @return array<int, array{entity_id: int, sku: string, type_id: string}>
     */
    public function findLatched(array $skus = []): array
    {
        return $this->getLatchedCompositeParents->execute(
            $this->compositeTypeResolver->getCompositeTypes(),
            $skus
        );
    }

    /**
     * Clear the latch on each parent, then let core re-derive its stock status.
     *
     * @param string[] $skus
     * @return string[] SKUs of the parents that were unlatched.
     */
    public function revive(array $skus = []): array
    {
        $revived = [];
        $childSkus = [];

        foreach ($this->findLatched($skus) as $parent) {
            $this->unlatch($parent['entity_id']);

            $revived[] = $parent['sku'];
            $childSkus[] = $this->getChildSkus($parent['entity_id']);
        }

        $childSkus = array_values(array_unique(array_merge([], ...$childSkus)));

        if ($childSkus) {
            $this->compositeProductStockStatusProcessor->execute($childSkus);
        }

        return $revived;
    }

    /**
     * Write the flag straight to the row rather than through a stock-item save.
     *
     * A repository round-trip is served from the request-level stock registry
     * cache, so the loaded item can still hold the pre-latch values. Setting a
     * field to what the cached object already says is not a data change, the
     * save becomes a no-op, and the repair silently does nothing. Dropping the
     * cached entry afterwards makes the fresh value visible to the processors
     * that run next.
     *
     * @param int $productId
     * @return void
     */
    private function unlatch(int $productId): void
    {
        $this->setAutomaticStockStatusFlag->execute($productId);
        $this->stockRegistryStorage->removeStockItem($productId);
    }

    /**
     * getChildrenIds() is declared on the abstract product type, so this covers
     * configurable, bundle and grouped without branching per type.
     *
     * @param int $productId
     * @return string[]
     */
    private function getChildSkus(int $productId): array
    {
        try {
            $product = $this->productRepository->getById($productId);
        } catch (NoSuchEntityException $e) {
            return [];
        }

        $childIds = $product->getTypeInstance()->getChildrenIds($productId);
        $flattened = [];

        array_walk_recursive(
            $childIds,
            static function ($id) use (&$flattened): void {
                $flattened[] = (int) $id;
            }
        );

        if (!$flattened) {
            return [];
        }

        return array_values($this->getSkusByProductIds->execute(array_unique($flattened)));
    }
}
