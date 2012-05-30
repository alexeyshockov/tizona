<?php

namespace Tizona\Doctrine\Orm;

/**
 * Base class for domain filters, that may be applied for persistent (loaded or not) collections.
 *
 * Concrete classes must implement those methods for each criteria:
 * * set{Criteria}Criteria() — check parameters and sets criteria in protected object's $criteria field (array) (constructs criteria).
 * * check{Criteria}Criteria($entity) — checks criteria in $entity (in loaded collection).
 * * updateQbFor{Criteria}Criteria($queryBuilder, $entityAlias) — updates query builder for criteria (in not loaded collection).
 *
 * Immutable.
 *
 * @author Alexey Shockov <alexey@shockov.com>
 */
abstract class EntityFilter
{
    /**
     * Defined criterias.
     *
     * @var array
     */
    protected $criteria = array();

    /**
     * @var \ReflectionClass
     */
    private $class;

    /**
     * @throws \InvalidArgumentException
     *
     * @param array|\Traversable $data Criteria/value pairs.
     *
     * @return EntityFilter
     */
    public static function fromArray($data)
    {
        $filter = new static();

        foreach ($data as $name => $value) {
            $methodName = 'set'.ucfirst($name).'Criteria';
            if (is_callable($filter, $methodName)) {
                $filter->{$methodName}($value);
            } else {
                // TODO Custom exception.
                throw new \InvalidArgumentException('Unknown criteria.');
            }
        }

        return $filter;
    }

    protected function __construct()
    {
        $this->class = new \ReflectionClass($this->getEntityClass());
    }

    /**
     * Class name of entity, for which this filter is defined.
     *
     * @return string
     */
    abstract protected function getEntityClass();

    /**
     * Checks entity for this filter.
     *
     * @param mixed $entity
     *
     * @return bool
     */
    public function __invoke($entity)
    {
        if (!is_object($entity) || !$this->class->isInstance($entity)) {
            return false;
        }

        $accepted = true;
        foreach (array_keys($this->criteria) as $criteria) {
            $accepted = $accepted && $this->{'check'.ucfirst($criteria).'Criteria'}($entity);

            // Optimization.
            if (!$accepted) {
                return $accepted;
            }
        }

        return $accepted;
    }

    /**
     * Updates Doctrine's query builder for this filter.
     *
     * @param \Doctrine\ORM\QueryBuilder $queryBuilder
     * @param string                     $alias
     */
    public function updateQb(\Doctrine\ORM\QueryBuilder $queryBuilder, $alias)
    {
        foreach (array_keys($this->criteria) as $criteria) {
            $this->{'updateQbFor'.ucfirst($criteria).'Criteria'}($queryBuilder, $alias);
        }
    }
}
