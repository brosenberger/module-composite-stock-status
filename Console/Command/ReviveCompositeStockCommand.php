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

namespace BroCode\CompositeStockStatus\Console\Command;

use BroCode\CompositeStockStatus\Model\ParentStockReviver;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Repairs composite parents that core will never bring back in stock.
 */
class ReviveCompositeStockCommand extends Command
{
    private const ARG_SKUS = 'sku';
    private const OPT_DRY_RUN = 'dry-run';

    /**
     * @var ParentStockReviver
     */
    private $parentStockReviver;

    /**
     * @var State
     */
    private $appState;

    /**
     * @param \BroCode\CompositeStockStatus\Model\ParentStockReviver $parentStockReviver
     * @param \Magento\Framework\App\State $appState
     * @param string|null $name
     */
    public function __construct(
        ParentStockReviver $parentStockReviver,
        State $appState,
        ?string $name = null
    ) {
        $this->parentStockReviver = $parentStockReviver;
        $this->appState = $appState;
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('brocode:composite-stock:revive');
        $this->setDescription(
            'Let stuck configurable, bundle and grouped products follow their children back into stock'
        );
        $this->addArgument(
            self::ARG_SKUS,
            InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
            'Parent SKUs to repair. Omit to scan the whole catalogue.'
        );
        $this->addOption(
            self::OPT_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            'List what would be repaired without writing anything'
        );

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->appState->setAreaCode(Area::AREA_ADMINHTML);

        $skus = (array) $input->getArgument(self::ARG_SKUS);
        $latched = $this->parentStockReviver->findLatched($skus);

        if (!$latched) {
            $output->writeln('<info>Nothing to repair: no composite product is stuck out of stock.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Found <comment>%d</comment> stuck parent(s):', count($latched)));
        foreach ($latched as $parent) {
            $output->writeln(sprintf('  %s (%s)', $parent['sku'], $parent['type_id']));
        }

        if ($input->getOption(self::OPT_DRY_RUN)) {
            $output->writeln('<info>Dry run: nothing was written.</info>');

            return Command::SUCCESS;
        }

        $revived = $this->parentStockReviver->revive($skus);

        $output->writeln(sprintf(
            '<info>Unlatched %d parent(s). Stock status re-derived from their children.</info>',
            count($revived)
        ));

        return Command::SUCCESS;
    }
}
