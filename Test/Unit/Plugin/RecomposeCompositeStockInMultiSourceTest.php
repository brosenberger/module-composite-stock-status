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

use BroCode\CompositeStockStatus\Plugin\RecomposeCompositeStockInMultiSource;
use Magento\InventoryApi\Api\Data\SourceItemInterface;
use Magento\InventoryApi\Api\SourceItemsSaveInterface;
use Magento\InventoryCatalogApi\Model\CompositeProductStockStatusProcessorInterface;
use Magento\InventoryCatalogApi\Model\IsSingleSourceModeInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RecomposeCompositeStockInMultiSourceTest extends TestCase
{
    /**
     * @var CompositeProductStockStatusProcessorInterface&MockObject
     */
    private $processor;

    /**
     * @var IsSingleSourceModeInterface&MockObject
     */
    private $isSingleSourceMode;

    /**
     * @var SourceItemsSaveInterface&MockObject
     */
    private $subject;

    protected function setUp(): void
    {
        $this->processor = $this->createMock(CompositeProductStockStatusProcessorInterface::class);
        $this->isSingleSourceMode = $this->createMock(IsSingleSourceModeInterface::class);
        $this->subject = $this->createMock(SourceItemsSaveInterface::class);
    }

    /**
     * The gate core applies is exactly what this undoes, so the multi-source
     * case is the one that must act.
     */
    public function testRecomputesInMultiSourceMode(): void
    {
        $this->isSingleSourceMode->method('execute')->willReturn(false);
        $this->processor->expects($this->once())->method('execute')->with(['A', 'B']);

        $this->plugin()->afterExecute($this->subject, null, [$this->item('A'), $this->item('B')]);
    }

    /**
     * In single-source mode core already did this. Running again would be
     * correct but wasteful on every source-item write in the system.
     */
    public function testDoesNothingInSingleSourceMode(): void
    {
        $this->isSingleSourceMode->method('execute')->willReturn(true);
        $this->processor->expects($this->never())->method('execute');

        $this->plugin()->afterExecute($this->subject, null, [$this->item('A')]);
    }

    public function testCollapsesRepeatedSkus(): void
    {
        $this->isSingleSourceMode->method('execute')->willReturn(false);
        $this->processor->expects($this->once())
            ->method('execute')
            ->with($this->callback(static fn (array $skus): bool => array_values($skus) === ['A']));

        $this->plugin()->afterExecute($this->subject, null, [$this->item('A'), $this->item('A')]);
    }

    public function testDoesNothingForAnEmptyBatch(): void
    {
        $this->isSingleSourceMode->method('execute')->willReturn(false);
        $this->processor->expects($this->never())->method('execute');

        $this->plugin()->afterExecute($this->subject, null, []);
    }

    /**
     * @return RecomposeCompositeStockInMultiSource
     */
    private function plugin(): RecomposeCompositeStockInMultiSource
    {
        return new RecomposeCompositeStockInMultiSource($this->processor, $this->isSingleSourceMode);
    }

    /**
     * @param string $sku
     * @return SourceItemInterface&MockObject
     */
    private function item(string $sku): MockObject
    {
        $item = $this->createMock(SourceItemInterface::class);
        $item->method('getSku')->willReturn($sku);

        return $item;
    }
}
