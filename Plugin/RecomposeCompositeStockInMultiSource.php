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

namespace BroCode\CompositeStockStatus\Plugin;

use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Model\CompositeProductStockStatusProcessorInterface;
use Magento\InventoryCatalogApi\Model\IsSingleSourceModeInterface;

/**
 * Maintain composite parent stock status in multi-source mode too.
 *
 * Core gates the recompute on IsSingleSourceMode -- fewer than two ENABLED
 * sources -- so on any install with a second source the parent's stored status
 * is never updated again. The gate exists because the recompute used to corrupt
 * non-default stocks; with the default-stock veto removed from the sibling index
 * select that no longer happens, so the recompute is safe to run unconditionally.
 *
 * Re-running it in single-source mode is harmless: core's own plugin has already
 * produced the same answer.
 */
class RecomposeCompositeStockInMultiSource
{
    /**
     * @var CompositeProductStockStatusProcessorInterface
     */
    private $compositeProductStockStatusProcessor;

    /**
     * @var IsSingleSourceModeInterface
     */
    private $isSingleSourceMode;

    /**
     * @param \Magento\InventoryCatalogApi\Model\CompositeProductStockStatusProcessorInterface $compositeProductStockStatusProcessor
     * @param \Magento\InventoryCatalogApi\Model\IsSingleSourceModeInterface $isSingleSourceMode
     */
    public function __construct(
        CompositeProductStockStatusProcessorInterface $compositeProductStockStatusProcessor,
        IsSingleSourceModeInterface $isSingleSourceMode
    ) {
        $this->compositeProductStockStatusProcessor = $compositeProductStockStatusProcessor;
        $this->isSingleSourceMode = $isSingleSourceMode;
    }

    /**
     * @param \Magento\InventoryApi\Api\SourceItemsSaveInterface $subject
     * @param mixed $result
     * @param \Magento\InventoryApi\Api\Data\SourceItemInterface[] $sourceItems
     * @return void
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute(SourceItemsSaveInterface $subject, $result, array $sourceItems): void
    {
        if ($this->isSingleSourceMode->execute()) {
            // Core's own plugin has already produced the same answer.
            return;
        }

        $skus = [];
        foreach ($sourceItems as $sourceItem) {
            $skus[] = $sourceItem->getSku();
        }

        if ($skus) {
            $this->compositeProductStockStatusProcessor->execute(array_unique($skus));
        }
    }
}
