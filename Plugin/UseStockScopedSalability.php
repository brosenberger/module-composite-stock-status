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

use Magento\Framework\DB\Select;

/**
 * Stop a secondary stock's salability being vetoed by the default stock's flag.
 *
 * The sibling index select for configurable parents computes:
 *
 *   IS_SALABLE => IF(inventory_stock_item.is_in_stock = 0, 0, MAX(stock.is_salable))
 *
 * where inventory_stock_item is joined on the parent's row for the DEFAULT stock:
 *
 *   AND inventory_stock_item.stock_id = <default stock id>
 *
 * This builder only ever runs for NON-default stocks -- the stock indexer skips
 * the default stock outright -- so the join imports a value scoped to a
 * different stock and lets it veto the answer. A parent whose children really
 * are salable in a secondary stock is indexed unsalable there because of the
 * default stock's flag. That is the corruption reported as
 * magento/inventory#3350, and the reason core refuses to maintain composite
 * parent status at all once a second source exists.
 *
 * Rewriting the column to MAX(stock.is_salable) makes each stock answer from its
 * own data. The now-unused LEFT JOIN is left in place: it selects no columns and
 * matches at most one row, so it cannot change the result.
 *
 * Written as a plugin rather than a preference because the class differs across
 * versions -- 2.4.8 implements SelectBuilderInterface::execute(), 2.4.9
 * implements SiblingSelectBuilderInterface::getSelect() -- and a plugin method
 * for a method the subject does not have is simply never called.
 */
class UseStockScopedSalability
{
    /**
     * Magento 2.4.8 and earlier.
     *
     * @param object $subject
     * @param \Magento\Framework\DB\Select $result
     * @return \Magento\Framework\DB\Select
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterExecute($subject, Select $result): Select
    {
        return $this->dropDefaultStockVeto($result);
    }

    /**
     * Magento 2.4.9 and later.
     *
     * @param object $subject
     * @param \Magento\Framework\DB\Select $result
     * @return \Magento\Framework\DB\Select
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterGetSelect($subject, Select $result): Select
    {
        return $this->dropDefaultStockVeto($result);
    }

    /**
     * @param \Magento\Framework\DB\Select $select
     * @return \Magento\Framework\DB\Select
     */
    private function dropDefaultStockVeto(Select $select): Select
    {
        $columns = $select->getPart(Select::COLUMNS);
        $rewritten = [];
        $changed = false;

        foreach ($columns as $column) {
            [$correlation, $expression, $alias] = array_pad((array) $column, 3, null);

            // The builder passes this column as a Zend_Db_Expr, not a string.
            $rendered = $expression instanceof \Zend_Db_Expr ? (string) $expression : $expression;

            if (is_string($rendered) && str_contains($rendered, 'inventory_stock_item.is_in_stock')) {
                $expression = new \Zend_Db_Expr('MAX(stock.is_salable)');
                $changed = true;
            }

            $rewritten[] = [$correlation, $expression, $alias];
        }

        if ($changed) {
            $select->setPart(Select::COLUMNS, $rewritten);
        }

        return $select;
    }
}
