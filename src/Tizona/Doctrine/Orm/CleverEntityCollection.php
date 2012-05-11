<?php

namespace Tizona\Doctrine\Orm;

use Colada\Contracts;

/**
 * With magic helpers.
 *
 * Candidate for trait...
 *
 * @author Alexey Shockov <alexey@shockov.com>
 */
abstract class CleverEntityCollection extends EntityCollection
{
    /**
     * @throws \InvalidArgumentException
     *
     * @param string $property
     * @param mixed $value
     *
     * @return EntityFilter
     */
    // TODO Proper exception for unknown property.
    abstract protected function getFilterForProperty($property, $value);

    /**
     * @throws \InvalidArgumentException
     *
     * @param string $property
     * @param string $order
     *
     * @return EntityComparator
     */
    // TODO Proper exception for unknown property.
    abstract protected function getComparatorForProperty($property, $order);

    /**
     * @{inheritDoc}
     */
    public function findBy($filter)
    {
        Contracts::ensureCallable($filter);

        if (!(is_object($filter) && ($filter instanceof EntityFilter))) {
            return parent::findBy($filter);
        }

        return $this->findInQbBy(
            array($filter, 'updateQb'),
            $filter
        );
    }

    /**
     * @{inheritDoc}
     */
    public function acceptBy($filter)
    {
        Contracts::ensureCallable($filter);

        if (!(is_object($filter) && ($filter instanceof EntityFilter))) {
            return parent::findBy($filter);
        }

        return $this->findInQbBy(
            array($filter, 'updateQb'),
            $filter
        );
    }

    /**
     * @{inheritDoc}
     */
    public function sortBy($comparator)
    {
        Contracts::ensureCallable($comparator);

        if (!(is_object($comparator) && ($comparator instanceof EntityComparator))) {
            return parent::sortBy($comparator);
        }

        return $this->sortQbBy(
            array($comparator, 'updateQb'),
            $comparator
        );
    }

    public function __call($method, $arguments)
    {
        $methodMap = array(
            'findBy'    => 'findByProperty',
            'findFor'   => 'findByProperty',
            'acceptBy'  => 'acceptByProperty',
            'acceptFor' => 'acceptByProperty',
            'sortBy'    => 'sortByProperty',
            'with'      => 'withAssociation',
        );

        foreach ($methodMap as $pattern => $destinationMethod) {
            if (\Colada\Helpers\StringHelper::startsWith($method, $pattern)) {
                $property = substr($method, strlen($pattern) - 1);

                array_unshift($arguments, $property);

                return call_user_func_array(array($this, $destinationMethod), $arguments);
            }
        }
    }

    private function acceptByProperty($property, $value)
    {
        $filter = $this->getFilterForProperty($property, $value);

        return $this->filterQbBy(
            array($filter, 'updateQb'),
            $filter
        );
    }

    private function findByProperty($property, $value)
    {
        $filter = $this->getFilterForProperty($property, $value);

        return $this->findInQbBy(
            array($filter, 'updateQb'),
            $filter
        );
    }

    private function sortByProperty($property, $order)
    {
        $comparator = $this->getComparatorForProperty($property, $order);

        return $this->sortQbBy(
            array($comparator, 'updateQb'),
            $comparator
        );
    }

    private function withProperty($property)
    {
        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            $queryBuilder
                ->leftJoin($alias.$property, $property)
                ->addSelect($property);

            return new static($queryBuilder, $this->isDetachingEnabled());
        }

        return $this;
    }
}
