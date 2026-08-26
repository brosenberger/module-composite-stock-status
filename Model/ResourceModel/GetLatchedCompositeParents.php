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
 * Finds composite products that can no longer come back in stock on their own.
 *
 * "Latched" is exactly the pair core refuses to act on: out of stock, with
 * stock_status_changed_auto cleared.
 */
class GetLatchedCompositeParents
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
     * @param string[] $compositeTypes
     * @param string[] $skus Restrict to these SKUs; empty means the whole catalogue.
     * @return array<int, array{entity_id: int, sku: string, type_id: string}>
     */
    public function execute(array $compositeTypes, array $skus = []): array
    {
        if (!$compositeTypes) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from(
                ['p' => $this->resource->getTableName('catalog_product_entity')],
                ['entity_id', 'sku', 'type_id']
            )
            ->join(
                ['si' => $this->resource->getTableName('cataloginventory_stock_item')],
                'si.product_id = p.entity_id',
                []
            )
            ->where('p.type_id IN (?)', $compositeTypes)
            ->where('si.is_in_stock = ?', 0)
            ->where('si.stock_status_changed_auto = ?', 0);

        if ($skus) {
            $select->where('p.sku IN (?)', $skus);
        }

        $rows = [];
        foreach ($connection->fetchAll($select) as $row) {
            $rows[] = [
                'entity_id' => (int) $row['entity_id'],
                'sku' => (string) $row['sku'],
                'type_id' => (string) $row['type_id'],
            ];
        }

        return $rows;
    }
}
