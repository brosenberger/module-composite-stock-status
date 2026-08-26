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

use BroCode\CompositeStockStatus\Model\CompositeTypeResolver;
use BroCode\CompositeStockStatus\Model\ResourceModel\SetAutomaticStockStatusFlag;
use BroCode\CompositeStockStatus\Plugin\MarkNewCompositeStockAsAutomatic;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class MarkNewCompositeStockAsAutomaticTest extends TestCase
{
    /**
     * @var CompositeTypeResolver&MockObject
     */
    private $compositeTypeResolver;

    /**
     * @var SetAutomaticStockStatusFlag&MockObject
     */
    private $setFlag;

    /**
     * @var ProductRepositoryInterface&MockObject
     */
    private $subject;

    /**
     * @var MarkNewCompositeStockAsAutomatic
     */
    private $plugin;

    protected function setUp(): void
    {
        $this->compositeTypeResolver = $this->createMock(CompositeTypeResolver::class);
        $this->setFlag = $this->createMock(SetAutomaticStockStatusFlag::class);
        $this->subject = $this->createMock(ProductRepositoryInterface::class);
        $this->plugin = new MarkNewCompositeStockAsAutomatic(
            $this->compositeTypeResolver,
            $this->setFlag
        );
    }

    /**
     * The whole point: a composite created without stock data must not be
     * created unable to recover.
     */
    public function testFlagsANewlyCreatedComposite(): void
    {
        $this->compositeTypeResolver->method('isCompositeType')->with('configurable')->willReturn(true);
        $this->setFlag->expects($this->once())->method('execute')->with(7);

        $this->saveCycle($this->product(null, 'configurable'), $this->product(7, 'configurable'));
    }

    /**
     * A simple product's flag is genuinely meaningful -- it is what stops a
     * manual "out of stock" being undone by the next quantity write.
     */
    public function testIgnoresANewlyCreatedSimpleProduct(): void
    {
        $this->compositeTypeResolver->method('isCompositeType')->with('simple')->willReturn(false);
        $this->setFlag->expects($this->never())->method('execute');

        $this->saveCycle($this->product(null, 'simple'), $this->product(7, 'simple'));
    }

    /**
     * Only creation is touched. A merchant who later takes a configurable off
     * sale by hand keeps that decision -- otherwise this module would quietly
     * override the admin on the next child stock write.
     */
    public function testIgnoresAnUpdateToAnExistingComposite(): void
    {
        $this->compositeTypeResolver->expects($this->never())->method('isCompositeType');
        $this->setFlag->expects($this->never())->method('execute');

        $this->saveCycle($this->product(7, 'configurable'), $this->product(7, 'configurable'));
    }

    /**
     * Two products in flight at once must not borrow each other's "was new"
     * answer -- a bulk import saves many products through one plugin instance.
     */
    public function testKeepsConcurrentSavesApart(): void
    {
        $this->compositeTypeResolver->method('isCompositeType')->willReturn(true);
        $this->setFlag->expects($this->once())->method('execute')->with(7);

        $existing = $this->product(3, 'configurable');
        $fresh = $this->product(null, 'configurable');

        $this->plugin->beforeSave($this->subject, $existing);
        $this->plugin->beforeSave($this->subject, $fresh);

        $this->plugin->afterSave($this->subject, $this->product(3, 'configurable'), $existing);
        $this->plugin->afterSave($this->subject, $this->product(7, 'configurable'), $fresh);
    }

    /**
     * afterSave without a matching beforeSave (another plugin short-circuited
     * the call) must not be treated as a creation.
     */
    public function testTreatsAnUnpairedAfterSaveAsNotNew(): void
    {
        $this->setFlag->expects($this->never())->method('execute');

        $this->plugin->afterSave($this->subject, $this->product(7, 'configurable'), $this->product(7, 'configurable'));
    }

    /**
     * @param ProductInterface&MockObject $incoming
     * @param ProductInterface&MockObject $saved
     * @return void
     */
    private function saveCycle(MockObject $incoming, MockObject $saved): void
    {
        $this->plugin->beforeSave($this->subject, $incoming);
        $this->plugin->afterSave($this->subject, $saved, $incoming);
    }

    /**
     * @param int|null $id
     * @param string $typeId
     * @return ProductInterface&MockObject
     */
    private function product(?int $id, string $typeId): MockObject
    {
        $product = $this->createMock(ProductInterface::class);
        $product->method('getId')->willReturn($id);
        $product->method('getTypeId')->willReturn($typeId);

        return $product;
    }
}
