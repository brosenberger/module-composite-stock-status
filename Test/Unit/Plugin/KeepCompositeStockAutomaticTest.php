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
use BroCode\CompositeStockStatus\Plugin\KeepCompositeStockAutomatic;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class KeepCompositeStockAutomaticTest extends TestCase
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
     * @var KeepCompositeStockAutomatic
     */
    private $plugin;

    protected function setUp(): void
    {
        $this->compositeTypeResolver = $this->createMock(CompositeTypeResolver::class);
        $this->setFlag = $this->createMock(SetAutomaticStockStatusFlag::class);
        $this->subject = $this->createMock(ProductRepositoryInterface::class);
        $this->plugin = new KeepCompositeStockAutomatic($this->compositeTypeResolver, $this->setFlag);
    }

    public function testFlagsACompositeProduct(): void
    {
        $this->compositeTypeResolver->method('isCompositeType')->with('configurable')->willReturn(true);
        $this->setFlag->expects($this->once())->method('execute')->with(7);

        $product = $this->product(7, 'configurable');
        $this->plugin->afterSave($this->subject, $product, $product);
    }

    /**
     * Core clears the flag on EVERY subsequent product save, not just the first,
     * so a creation-only fix silently stops working on the second write -- and a
     * real import always writes a parent at least twice.
     */
    public function testFlagsAnExistingCompositeOnEverySave(): void
    {
        $this->compositeTypeResolver->method('isCompositeType')->willReturn(true);
        $this->setFlag->expects($this->exactly(3))->method('execute')->with(7);

        $product = $this->product(7, 'grouped');
        $this->plugin->afterSave($this->subject, $product, $product);
        $this->plugin->afterSave($this->subject, $product, $product);
        $this->plugin->afterSave($this->subject, $product, $product);
    }

    /**
     * A simple product's flag is genuinely meaningful -- it is what stops a
     * manual "out of stock" being undone by the next quantity write.
     */
    public function testIgnoresASimpleProduct(): void
    {
        $this->compositeTypeResolver->method('isCompositeType')->with('simple')->willReturn(false);
        $this->setFlag->expects($this->never())->method('execute');

        $product = $this->product(7, 'simple');
        $this->plugin->afterSave($this->subject, $product, $product);
    }

    public function testIgnoresAProductWithoutAnId(): void
    {
        $this->compositeTypeResolver->expects($this->never())->method('isCompositeType');
        $this->setFlag->expects($this->never())->method('execute');

        $product = $this->product(null, 'configurable');
        $this->plugin->afterSave($this->subject, $product, $product);
    }

    public function testReturnsTheSavedProduct(): void
    {
        $this->compositeTypeResolver->method('isCompositeType')->willReturn(true);
        $product = $this->product(7, 'bundle');

        $this->assertSame($product, $this->plugin->afterSave($this->subject, $product, $product));
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
