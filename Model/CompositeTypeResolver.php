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

use BroCode\CompositeStockStatus\Model\ResourceModel\GetProductTypeById;
use Magento\Catalog\Model\Product\Type;

/**
 * Answers "does this product derive its stock status from children?".
 *
 * The composite list comes from Magento's own type config rather than a
 * hardcoded array, so a third-party composite type is covered automatically.
 */
class CompositeTypeResolver
{
    /**
     * @var Type
     */
    private $productType;

    /**
     * @var GetProductTypeById
     */
    private $getProductTypeById;

    /**
     * @param \Magento\Catalog\Model\Product\Type $productType
     * @param \BroCode\CompositeStockStatus\Model\ResourceModel\GetProductTypeById $getProductTypeById
     */
    public function __construct(Type $productType, GetProductTypeById $getProductTypeById)
    {
        $this->productType = $productType;
        $this->getProductTypeById = $getProductTypeById;
    }

    /**
     * @param int $productId
     * @return bool
     */
    public function isComposite(int $productId): bool
    {
        $typeId = $this->getProductTypeById->execute($productId);

        return $typeId !== null && $this->isCompositeType($typeId);
    }

    /**
     * @param string $typeId
     * @return bool
     */
    public function isCompositeType(string $typeId): bool
    {
        return in_array($typeId, $this->productType->getCompositeTypes(), true);
    }

    /**
     * @return string[]
     */
    public function getCompositeTypes(): array
    {
        return $this->productType->getCompositeTypes();
    }
}
