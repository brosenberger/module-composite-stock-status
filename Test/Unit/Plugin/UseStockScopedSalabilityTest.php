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

namespace BroCode\CompositeStockStatus\Test\Unit\Plugin;

use BroCode\CompositeStockStatus\Plugin\UseStockScopedSalability;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;

class UseStockScopedSalabilityTest extends TestCase
{
    private const VETO = 'IF(inventory_stock_item.is_in_stock = 0, 0, MAX(stock.is_salable))';

    /**
     * @var UseStockScopedSalability
     */
    private $plugin;

    protected function setUp(): void
    {
        $this->plugin = new UseStockScopedSalability();
    }

    /**
     * The column arrives as a Zend_Db_Expr rather than a string. Testing for a
     * string silently matched nothing and the fix appeared to do nothing at all.
     */
    public function testReplacesTheDefaultStockVeto(): void
    {
        $select = $this->select([
            ['parent_product_entity', 'sku', 'sku'],
            ['stock', new \Zend_Db_Expr('SUM(stock.quantity)'), 'quantity'],
            ['stock', new \Zend_Db_Expr(self::VETO), 'is_salable'],
        ]);

        $select->expects($this->once())
            ->method('setPart')
            ->with(
                Select::COLUMNS,
                $this->callback(static function (array $columns): bool {
                    return (string) $columns[2][1] === 'MAX(stock.is_salable)'
                        && $columns[2][2] === 'is_salable'
                        && (string) $columns[1][1] === 'SUM(stock.quantity)'
                        && $columns[0][1] === 'sku';
                })
            );

        $this->plugin->afterGetSelect(new \stdClass(), $select);
    }

    /**
     * 2.4.8 names the method execute(); 2.4.9 names it getSelect(). One plugin
     * carries both, and a plugin method for a method the subject lacks is never
     * called.
     */
    public function testCoversTheOlderMethodName(): void
    {
        $select = $this->select([['stock', new \Zend_Db_Expr(self::VETO), 'is_salable']]);
        $select->expects($this->once())->method('setPart');

        $this->plugin->afterExecute(new \stdClass(), $select);
    }

    /**
     * If Magento changes the expression, this plugin must do nothing rather than
     * guess -- a silently rewritten select would be worse than an unfixed one.
     */
    public function testLeavesAnUnrecognisedSelectAlone(): void
    {
        $select = $this->select([
            ['stock', new \Zend_Db_Expr('MAX(stock.is_salable)'), 'is_salable'],
        ]);
        $select->expects($this->never())->method('setPart');

        $this->plugin->afterGetSelect(new \stdClass(), $select);
    }

    public function testReturnsTheSelect(): void
    {
        $select = $this->select([['stock', new \Zend_Db_Expr(self::VETO), 'is_salable']]);

        $this->assertSame($select, $this->plugin->afterGetSelect(new \stdClass(), $select));
    }

    /**
     * @param array $columns
     * @return Select&\PHPUnit\Framework\MockObject\MockObject
     */
    private function select(array $columns)
    {
        $select = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPart', 'setPart'])
            ->getMock();
        $select->method('getPart')->with(Select::COLUMNS)->willReturn($columns);

        return $select;
    }
}
