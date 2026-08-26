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

use BroCode\CompositeStockStatus\Model\CompositeTypeResolver;
use BroCode\CompositeStockStatus\Model\ResourceModel\SetAutomaticStockStatusFlag;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Keep composite products marked as automatically maintained.
 *
 * Core will only move a parent back INTO stock when stock_status_changed_auto is
 * set:
 *
 *   ChangeParentStockStatus::isNeedToUpdateParent()
 *     return $parent->getIsInStock() !== $childrenIsInStock
 *         && ($childrenIsInStock === false || $parent->getStockStatusChangedAuto());
 *
 * A product created through the API without stock data is written with
 * is_in_stock = 0 and that flag at 0, so a catalogue imported structure-first,
 * stock-second produces parents that can never recover.
 *
 * Setting the flag once at creation is not enough: core clears it again on every
 * subsequent product save, including a save that says nothing about stock at all.
 * Measured on a grouped product -- create (flag 1), attach links (flag 0), rename
 * (flag 0). Since a real import always writes a parent at least twice, a
 * creation-only fix silently stops working on the second call.
 *
 * The flag is therefore re-applied on every save of a composite product.
 *
 * The boundary, stated plainly: a merchant who sets a composite out of stock by
 * hand keeps that decision through child stock movements, because nothing
 * re-derives a parent whose marker is cleared. It is reset by the next save of
 * that parent, after which the parent follows its children again. Core behaves
 * the same way -- it also clears the marker on the next product save -- so the
 * decision was never durable across an edit. To take a composite off sale for
 * good, disable it or take its children out of stock.
 */
class KeepCompositeStockAutomatic
{
    /**
     * @var CompositeTypeResolver
     */
    private $compositeTypeResolver;

    /**
     * @var SetAutomaticStockStatusFlag
     */
    private $setAutomaticStockStatusFlag;

    /**
     * @param \BroCode\CompositeStockStatus\Model\CompositeTypeResolver $compositeTypeResolver
     * @param \BroCode\CompositeStockStatus\Model\ResourceModel\SetAutomaticStockStatusFlag $setAutomaticStockStatusFlag
     */
    public function __construct(
        CompositeTypeResolver $compositeTypeResolver,
        SetAutomaticStockStatusFlag $setAutomaticStockStatusFlag
    ) {
        $this->compositeTypeResolver = $compositeTypeResolver;
        $this->setAutomaticStockStatusFlag = $setAutomaticStockStatusFlag;
    }

    /**
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $subject
     * @param \Magento\Catalog\Api\Data\ProductInterface $result
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param bool $saveOptions
     * @return \Magento\Catalog\Api\Data\ProductInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterSave(
        ProductRepositoryInterface $subject,
        ProductInterface $result,
        ProductInterface $product,
        $saveOptions = false
    ): ProductInterface {
        if ($result->getId()
            && $this->compositeTypeResolver->isCompositeType((string) $result->getTypeId())
        ) {
            $this->setAutomaticStockStatusFlag->execute((int) $result->getId());
        }

        return $result;
    }
}
