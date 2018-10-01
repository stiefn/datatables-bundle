<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Adapter\Doctrine\ORM;

use Doctrine\ORM\Query\Expr\Comparison;
use Doctrine\ORM\QueryBuilder;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTableState;
use Omines\DataTablesBundle\Adapter\Doctrine\ORM\QueryBuilderProcessorInterface;

/**
 * SearchCriteriaProvider.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */
class SearchCriteriaProvider implements QueryBuilderProcessorInterface
{
    /**
     * {@inheritdoc}
     */
    public function process(QueryBuilder $queryBuilder, DataTableState $state)
    {
        $this->processSearchColumns($queryBuilder, $state);
        $this->processGlobalSearch($queryBuilder, $state);
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param DataTableState $state
     */
    private function processSearchColumns(QueryBuilder $queryBuilder, DataTableState $state)
    {
        foreach ($state->getSearchColumns() as $searchInfo) {
            /** @var AbstractColumn $column */
            $column = $searchInfo['column'];
            $search = $searchInfo['search'];

            if (strlen($search) > 0 && null !== ($filter = $column->getFilter())) {
                // Get the field name, if column is a join column
                // Analogous to AutomaticQueryBuilder::getSelectColumns
                $field = $column->getField();
                $parts = explode('.', $column->getField());
                if (count($parts) > 1) {
                    $currentPart = array_shift($parts);
                    $currentAlias = $currentPart;
                    $isRoot = true;
                    while (count($parts) > 1) {
                        $previousPart = $currentPart;
                        $currentPart = array_shift($parts);
                        $currentAlias = ($isRoot ? '' : $previousPart . '_') . $currentPart;
                        $isRoot = false;
                    }
                    $field = $currentAlias . '.' . $parts[0];
                }

                if($filter->getOperator() == 'LIKE') {
                    $queryBuilder->andWhere($queryBuilder->expr()->like($field, $queryBuilder->expr()->literal('%' . $search . '%')));
                } else {
                    $queryBuilder->andWhere(new Comparison($field, $filter->getOperator(), $queryBuilder->expr()->literal($search)));
                }
            }
        }
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @param DataTableState $state
     */
    private function processGlobalSearch(QueryBuilder $queryBuilder, DataTableState $state)
    {
        if (!empty($globalSearch = $state->getGlobalSearch())) {
            $expr = $queryBuilder->expr();
            $comparisons = $expr->orX();
            foreach ($state->getDataTable()->getColumns() as $column) {
                if ($column->isGlobalSearchable() && !empty($field = $column->getField())) {
                    $comparisons->add($expr->like($field, $expr->literal("%{$globalSearch}%")));
                }
            }
            $queryBuilder->andWhere($comparisons);
        }
    }
}
