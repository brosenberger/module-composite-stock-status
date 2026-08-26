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
 * Stops a composite product from being created unable to come back in stock.
 *
 * A product created through the API without stock data gets is_in_stock = 0 and
 * stock_status_changed_auto = 0. Core only moves a parent back INTO stock when
 * that flag is set:
 *
 *   ChangeParentStockStatus::isNeedToUpdateParent()
 *     return $parent->getIsInStock() !== $childrenIsInStock
 *         && ($childrenIsInStock === false || $parent->getStockStatusChangedAuto());
 *
 * So a catalogue imported structure-first, stock-second -- the order essentially
 * every ERP feed uses -- produces parents that are stuck out of stock
 * permanently, however much quantity their children later receive. The flag
 * means "this status was set automatically rather than by a human", and a
 * freshly created product has had no human decision applied to it.
 *
 * Hooked on product creation rather than on the stock item save because the
 * stock row is created at different points in different Magento versions: on
 * 2.4.8 it does not exist yet when StockItemRepository::save() runs, on 2.4.9 it
 * already does. Product creation is the same event on both.
 *
 * Only creation is touched, so a merchant who later takes a configurable off
 * sale by hand keeps that decision.
 */
class MarkNewCompositeStockAsAutomatic
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
     * @var bool[]
     */
    private $isNewBySubjectId = [];

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
     * Remember whether this was a create, before the save assigns an id.
     *
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $subject
     * @param \Magento\Catalog\Api\Data\ProductInterface $product
     * @param bool $saveOptions
     * @return array
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeSave(
        ProductRepositoryInterface $subject,
        ProductInterface $product,
        $saveOptions = false
    ): array {
        $this->isNewBySubjectId[spl_object_id($product)] = !$product->getId();

        return [$product, $saveOptions];
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
        $key = spl_object_id($product);
        $wasNew = $this->isNewBySubjectId[$key] ?? false;
        unset($this->isNewBySubjectId[$key]);

        if ($wasNew
            && $result->getId()
            && $this->compositeTypeResolver->isCompositeType((string) $result->getTypeId())
        ) {
            $this->setAutomaticStockStatusFlag->execute((int) $result->getId());
        }

        return $result;
    }
}
