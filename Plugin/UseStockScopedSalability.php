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
 * Each composite type builds its sibling index select differently, but all three
 * fold the parent's legacy cataloginventory_stock_item row into the answer:
 *
 *   configurable  IF(inventory_stock_item.is_in_stock = 0, 0, MAX(stock.is_salable))
 *   grouped       IF(inventory_stock_item.is_in_stock = 0, 0, MAX(child_stock.is_salable))
 *   bundle        IF(legacy_stock_item.is_in_stock = 0 OR options.sku IS NULL, 0, ...)
 *
 * Configurable and bundle join that row on the DEFAULT stock explicitly; grouped
 * joins it with no stock filter at all, which comes to the same thing because the
 * legacy table only ever holds the legacy stock. These builders only ever run for
 * NON-default stocks -- InventoryIndexer's Stock\Strategy\Sync and
 * SkuListsProcessor both skip the default stock -- so in every case a value
 * scoped to one stock vetoes the answer for another.
 *
 * A parent whose children are genuinely salable in a secondary stock is indexed
 * unsalable there. Combined with core refusing to maintain that flag at all once
 * a second source exists, an imported parent starts at zero and is unsalable
 * everywhere, permanently.
 *
 * Two rewrites, deliberately narrow. Anything that does not match exactly is left
 * untouched: a silently mangled index select would be far worse than an unfixed
 * one, so a future change to these expressions must fail visibly as the bug
 * returning, not as broken SQL.
 *
 * Written as a plugin rather than a preference because the classes differ across
 * versions -- 2.4.8 implements SelectBuilderInterface::execute(), 2.4.9
 * implements SiblingSelectBuilderInterface::getSelect() -- and a plugin method
 * for a method the subject does not have is simply never called.
 */
class UseStockScopedSalability
{
    /**
     * IF(<alias>.is_in_stock = 0, 0, <answer>) -- configurable and grouped.
     */
    private const VETO_WRAPPER = '/^IF\(\s*\w+\.is_in_stock\s*=\s*0\s*,\s*0\s*,\s*(.+)\)$/s';

    /**
     * "<alias>.is_in_stock = 0 OR " as one term of a larger condition -- bundle.
     */
    private const VETO_TERM = '/\w+\.is_in_stock\s*=\s*0\s+OR\s+/';

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

            // The builders pass this column as a Zend_Db_Expr, not a string.
            $rendered = $expression instanceof \Zend_Db_Expr ? (string) $expression : $expression;
            $stripped = is_string($rendered) ? $this->strip($rendered) : null;

            if ($stripped !== null) {
                $expression = new \Zend_Db_Expr($stripped);
                $changed = true;
            }

            $rewritten[] = [$correlation, $expression, $alias];
        }

        if ($changed) {
            $select->setPart(Select::COLUMNS, $rewritten);
        }

        return $select;
    }

    /**
     * Remove the legacy-flag veto, or return null when the expression is not one
     * this plugin recognises.
     *
     * @param string $expression
     * @return string|null
     */
    private function strip(string $expression): ?string
    {
        if (!str_contains($expression, '.is_in_stock')) {
            return null;
        }

        $matches = [];
        if (preg_match(self::VETO_WRAPPER, trim($expression), $matches)) {
            return trim($matches[1]);
        }

        $reduced = preg_replace(self::VETO_TERM, '', $expression, 1);

        return $reduced !== null && $reduced !== $expression ? $reduced : null;
    }
}
