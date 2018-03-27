<?php

/*
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Omines\DataTablesBundle\Adapter\Doctrine;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Omines\DataTablesBundle\Adapter\AbstractAdapter;
use Omines\DataTablesBundle\Adapter\AdapterQuery;
use Omines\DataTablesBundle\Adapter\Doctrine\ORM\AutomaticQueryBuilder;
use Omines\DataTablesBundle\Adapter\Doctrine\ORM\QueryBuilderProcessorInterface;
use Omines\DataTablesBundle\Adapter\Doctrine\ORM\SearchCriteriaProvider;
use Omines\DataTablesBundle\Column\AbstractColumn;
use Omines\DataTablesBundle\DataTableState;
use Omines\DataTablesBundle\Exception\InvalidConfigurationException;
use Omines\DataTablesBundle\Exception\MissingDependencyException;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * ORMAdapter.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 * @author Robbert Beesems <robbert.beesems@omines.com>
 */
class ORMAdapter extends AbstractAdapter
{
    /** @var RegistryInterface */
    private $registry;

    /** @var EntityManager */
    private $manager;

    /** @var \Doctrine\ORM\Mapping\ClassMetadata */
    private $metadata;

    /** @var int */
    private $hydrationMode;

    /** @var QueryBuilderProcessorInterface[] */
    private $queryBuilderProcessors;

    /** @var QueryBuilderProcessorInterface[] */
    protected $criteriaProcessors;

    /**
     * DoctrineAdapter constructor.
     *
     * @param RegistryInterface|null $registry
     */
    public function __construct(RegistryInterface $registry = null)
    {
        if (null === $registry) {
            throw new MissingDependencyException('Install doctrine/doctrine-bundle to use the ORMAdapter');
        }

        parent::__construct();
        $this->registry = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function configure(array $options)
    {
        $resolver = new OptionsResolver();
        $this->configureOptions($resolver);
        $options = $resolver->resolve($options);

        // Enable automated mode or just get the general default entity manager
        if (null === ($this->manager = $this->registry->getManagerForClass($options['entity']))) {
            throw new InvalidConfigurationException(sprintf('Doctrine has no manager for entity "%s", is it correctly imported and referenced?', $options['entity']));
        }
        $this->metadata = $this->manager->getClassMetadata($options['entity']);
        if (empty($options['query'])) {
            $options['query'] = [new AutomaticQueryBuilder($this->manager, $this->metadata)];
        }

        // Set options
        $this->hydrationMode = $options['hydrate'];
        $this->queryBuilderProcessors = $options['query'];
        $this->criteriaProcessors = $options['criteria'];
    }

    /**
     * @param mixed $processor
     */
    public function addCriteriaProcessor($processor)
    {
        $this->criteriaProcessors[] = $this->normalizeProcessor($processor);
    }

    /**
     * @param AdapterQuery $query
     */
    protected function prepareQuery(AdapterQuery $query)
    {
        $state = $query->getState();
        $query->set('qb', $builder = $this->createQueryBuilder($state));
        $query->set('rootAlias', $rootAlias = $builder->getDQLPart('from')[0]->getAlias());

        // Provide default field mappings if needed
        foreach ($state->getDataTable()->getColumns() as $column) {
            if (null === $column->getField() && isset($this->metadata->fieldMappings[$name = $column->getName()])) {
                $column->setField("{$rootAlias}.{$name}");
            }

            // For ORM all actual fields default to orderable & searchable as RDBMS can always handle that
            if (null !== $column->getOrderField()) {
                //$column->setOrderable($column->getO)
            }
        }

        /** @var Query\Expr\From $fromClause */
        $fromClause = $builder->getDQLPart('from')[0];
        $identifier = "{$fromClause->getAlias()}.{$this->metadata->getSingleIdentifierFieldName()}";
        $query->setTotalRows($this->getCount($builder, $identifier));

        // Get record count after filtering
        $this->buildCriteria($builder, $state);
        $query->setFilteredRows($this->getCount($builder, $identifier));

        // Perform mapping of all referred fields and implied fields
        $aliases = $this->getAliases($query);
        $query->set('aliases', $aliases);
        $query->setIdentifierPropertyPath($this->mapFieldToPropertyPath($identifier, $aliases));
    }

    /**
     * @param AdapterQuery $query
     * @return array
     */
    protected function getAliases(AdapterQuery $query)
    {
        /** @var QueryBuilder $builder */
        $builder = $query->get('qb');
        $aliases = [];

        /** @var Query\Expr\From $from */
        foreach ($builder->getDQLPart('from') as $from) {
            $aliases[$from->getAlias()] = [null, $this->manager->getMetadataFactory()->getMetadataFor($from->getFrom())];
        }

        // Alias all joins
        foreach ($builder->getDQLPart('join') as $joins) {
            /** @var Query\Expr\Join $join */
            foreach ($joins as $join) {
                list($origin, $target) = explode('.', $join->getJoin());

                $mapping = $aliases[$origin][1]->getAssociationMapping($target);
                $aliases[$join->getAlias()] = [$join->getJoin(), $this->manager->getMetadataFactory()->getMetadataFor($mapping['targetEntity'])];
            }
        }

        return $aliases;
    }

    /**
     * {@inheritdoc}
     */
    protected function mapPropertyPath(AdapterQuery $query, AbstractColumn $column)
    {
        return $this->mapFieldToPropertyPath($column->getField(), $query->get('aliases'));
    }

    /**
     * @param AdapterQuery $query
     * @return \Traversable
     */
    protected function getResults(AdapterQuery $query): \Traversable
    {
        /** @var QueryBuilder $builder */
        $builder = $query->get('qb');
        $state = $query->getState();

        // Apply definitive view state for current 'page' of the table
        foreach ($state->getOrderBy() as list($column, $direction)) {
            /** @var AbstractColumn $column */
            if ($column->isOrderable()) {
                $builder->addOrderBy($column->getOrderField(), $direction);
            }
        }
        if ($state->getLength() > 0) {
            $builder
                ->setFirstResult($state->getStart())
                ->setMaxResults($state->getLength())
            ;
        }

        foreach ($builder->getQuery()->iterate([], $this->hydrationMode) as $result) {
            yield $entity = $result[0];
            if (Query::HYDRATE_OBJECT === $this->hydrationMode) {
                $this->manager->detach($entity);
            }
        }
    }

    /**
     * @param DataTableState $state
     */
    protected function buildCriteria(QueryBuilder $queryBuilder, DataTableState $state)
    {
        foreach ($this->criteriaProcessors as $provider) {
            $provider->process($queryBuilder, $state);
        }
    }

    /**
     * @param DataTableState $state
     * @return QueryBuilder
     */
    protected function createQueryBuilder(DataTableState $state): QueryBuilder
    {
        /** @var QueryBuilder $queryBuilder */
        $queryBuilder = $this->manager->createQueryBuilder();

        // Run all query builder processors in order
        foreach ($this->queryBuilderProcessors as $processor) {
            $processor->process($queryBuilder, $state);
        }

        return $queryBuilder;
    }

    /**
     * @param $identifier
     * @return int
     */
    protected function getCount(QueryBuilder $queryBuilder, $identifier)
    {
        $qb = clone $queryBuilder;

        $qb->resetDQLPart('orderBy');
        $gb = $qb->getDQLPart('groupBy');
        if (empty($gb) || !in_array($identifier, $gb, true)) {
            $qb->select($qb->expr()->count($identifier));

            return (int) $qb->getQuery()->getSingleScalarResult();
        } else {
            $qb->resetDQLPart('groupBy');
            $qb->select($qb->expr()->countDistinct($identifier));

            return (int) $qb->getQuery()->getSingleScalarResult();
        }
    }

    /**
     * @param string $field
     * @param array $aliases
     * @return string
     */
    private function mapFieldToPropertyPath($field, array $aliases = [])
    {
        $parts = explode('.', $field);
        if (count($parts) < 2) {
            throw new InvalidConfigurationException(sprintf("Field name '%s' must consist at least of an alias and a field separated with a period", $field));
        }
        list($origin, $target) = $parts;

        $path = [$target];
        $current = $aliases[$origin][0];

        while (null !== $current) {
            list($origin, $target) = explode('.', $current);
            $path[] = $target;
            $current = $aliases[$origin][0];
        }

        if (Query::HYDRATE_ARRAY === $this->hydrationMode) {
            return '[' . implode('][', array_reverse($path)) . ']';
        } else {
            return implode('.', array_reverse($path));
        }
    }

    /**
     * @param OptionsResolver $resolver
     */
    protected function configureOptions(OptionsResolver $resolver)
    {
        $providerNormalizer = function (Options $options, $value) {
            return array_map([$this, 'normalizeProcessor'], (array) $value);
        };

        $resolver
            ->setDefaults([
                'hydrate' => Query::HYDRATE_OBJECT,
                'query' => [],
                'criteria' => function (Options $options) {
                    return [new SearchCriteriaProvider()];
                },
            ])
            ->setRequired('entity')
            ->setAllowedTypes('entity', ['string'])
            ->setAllowedTypes('hydrate', 'int')
            ->setAllowedTypes('query', [QueryBuilderProcessorInterface::class, 'array', 'callable'])
            ->setAllowedTypes('criteria', [QueryBuilderProcessorInterface::class, 'array', 'callable', 'null'])
            ->setNormalizer('query', $providerNormalizer)
            ->setNormalizer('criteria', $providerNormalizer)
        ;
    }

    /**
     * @param callable|QueryBuilderProcessorInterface $provider
     * @return QueryBuilderProcessorInterface
     */
    private function normalizeProcessor($provider)
    {
        if ($provider instanceof QueryBuilderProcessorInterface) {
            return $provider;
        } elseif (is_callable($provider)) {
            return new class($provider) implements QueryBuilderProcessorInterface {
                private $callable;

                public function __construct(callable $value)
                {
                    $this->callable = $value;
                }

                public function process(QueryBuilder $queryBuilder, DataTableState $state)
                {
                    return call_user_func($this->callable, $queryBuilder, $state);
                }
            };
        }

        throw new InvalidConfigurationException('Provider must be a callable or implement QueryBuilderProcessorInterface');
    }
}
