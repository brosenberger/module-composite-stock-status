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

namespace BroCode\CompositeStockStatus\Test\Unit\Model;

use BroCode\CompositeStockStatus\Model\CompositeTypeResolver;
use BroCode\CompositeStockStatus\Model\ResourceModel\GetProductTypeById;
use Magento\Catalog\Model\Product\Type;
use PHPUnit\Framework\TestCase;

class CompositeTypeResolverTest extends TestCase
{
    /**
     * @var CompositeTypeResolver
     */
    private $resolver;

    protected function setUp(): void
    {
        $productType = $this->createMock(Type::class);
        $productType->method('getCompositeTypes')->willReturn(['configurable', 'bundle', 'grouped']);

        $getProductTypeById = $this->createMock(GetProductTypeById::class);
        $getProductTypeById->method('execute')->willReturnMap([
            [1, 'configurable'],
            [2, 'bundle'],
            [3, 'grouped'],
            [4, 'simple'],
            [5, 'virtual'],
            [6, null],
        ]);

        $this->resolver = new CompositeTypeResolver($productType, $getProductTypeById);
    }

    /**
     * @dataProvider productProvider
     * @param int $productId
     * @param bool $expected
     */
    public function testRecognisesCompositeProducts(int $productId, bool $expected): void
    {
        $this->assertSame($expected, $this->resolver->isComposite($productId));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function productProvider(): array
    {
        return [
            'configurable'   => [1, true],
            'bundle'         => [2, true],
            'grouped'        => [3, true],
            'simple'         => [4, false],
            'virtual'        => [5, false],
            'deleted'        => [6, false],
        ];
    }

    /**
     * The list is read from Magento's type config rather than hardcoded, so a
     * third-party composite type is covered without touching this module.
     */
    public function testTakesTheCompositeListFromMagento(): void
    {
        $this->assertSame(['configurable', 'bundle', 'grouped'], $this->resolver->getCompositeTypes());
        $this->assertTrue($this->resolver->isCompositeType('bundle'));
        $this->assertFalse($this->resolver->isCompositeType('downloadable'));
    }
}
