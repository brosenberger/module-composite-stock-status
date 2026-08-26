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

namespace BroCode\CompositeStockStatus\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;

/**
 * Marks a product's stock status as automatically maintained.
 *
 * A single-column write rather than a stock-item save: this runs inside a
 * product save, and going back through the stock item repository from there
 * re-enters the save it was called from. stock_status_changed_auto drives no
 * index and no cache -- it only tells core whether it is allowed to move the
 * status on its own -- so writing it directly costs nothing.
 */
class SetAutomaticStockStatusFlag
{
    /**
     * @var ResourceConnection
     */
    private $resource;

    /**
     * @param \Magento\Framework\App\ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * @param int $productId
     * @return void
     */
    public function execute(int $productId): void
    {
        $connection = $this->resource->getConnection();
        $connection->update(
            $this->resource->getTableName('cataloginventory_stock_item'),
            ['stock_status_changed_auto' => 1],
            ['product_id = ?' => $productId]
        );
    }
}
