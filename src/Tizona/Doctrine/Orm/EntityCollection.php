<?php

namespace Tizona\Doctrine\Orm;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\PersistentCollection;

use Doctrine\Common\Collections\Collection;

use Colada\CollectionMapIterator;

use Colada\Contracts;

/**
 * Ready to use persistent collection of entities with lazy slice() and count() methods.
 *
 * @author Alexey Shockov <alexey@shockov.com>
 */
class EntityCollection extends \Colada\IteratorCollection
{
    /**
     * @var \Doctrine\ORM\QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var bool
     */
    private $detaching;

    /**
     * @see http://www.doctrine-project.org/jira/browse/DDC-1637
     *
     * @see \Doctrine\ORM\PersistentCollection::getOwner()
     * @see \Doctrine\ORM\PersistentCollection::getMapping()
     */
    private function fromDoctrinePersistentCollection(PersistentCollection $collection)
    {
        // :(
        $collectionClass       = new \ReflectionClass($collection);
        $entityManagerProperty = $collectionClass->getProperty('em');
        $entityManagerProperty->setAccessible(true);

        $entityManager = $entityManagerProperty->getValue($collection);
        $owner         = $collection->getOwner();
        $mapping       = $collection->getMapping();

        $queryBuilder = $entityManager
            ->getRepository($mapping['targetEntity'])
            ->createQueryBuilder('target')
            // TODO Для один-много (стороны, где много), будет всегда примерно так. А вот для много-много, если связь только с
            // одной стороны...
            ->where('target.'.$mapping['mappedBy'].' = :owner')
            ->setParameter('owner', $owner);

        $this->fromQueryBuilder($queryBuilder);
    }

    private function fromQueryBuilder(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        $this->iterator     = $this->getQbIterator()->orThrow(new \RuntimeException('You should go away from PHP.'));
    }

    /**
     * Query builder (not loaded collection) or entities (loaded) collection.
     *
     * For Doctrine's PersistentCollection internal query builder will be created.
     *
     * @param \Doctrine\ORM\QueryBuilder|\Doctrine\ORM\PersistentCollection|mixed $entities
     * @param bool $detaching
     */
    public function __construct($entities, $detaching = false)
    {
        if ($entities instanceof QueryBuilder) {
            $this->fromQueryBuilder($entities);
        } elseif (($entities instanceof PersistentCollection) && !$entities->isInitialized()) {
            $this->fromDoctrinePersistentCollection($entities);
        } else {
            parent::__construct($entities);
        }

        $this->detaching = (bool) $detaching;
    }

    /**
     * Save objects references inside (attach to UnitOfWork in Doctrine). Default behaviour.
     *
     * P.S. Only for "lazy" (not already loaded) collections.
     */
    public function disableDetaching()
    {
        $this->detaching = false;
    }

    /**
     * Enables detaching while iterating through internal Doctrine's query results.
     *
     * Useful for dealing with memory problems on large collections.
     *
     * P.S. Only for "lazy" (not already loaded) collections.
     */
    public function enableDetaching()
    {
        if ($this->queryBuilder) {
            $this->detaching = true;
        }
    }

    /**
     * @return bool
     */
    public function isDetachingEnabled()
    {
        return $this->detaching;
    }

    /**
     * @{inheritDoc}
     */
    public function slice($offset, $length)
    {
        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $queryBuilder
                ->setFirstResult($offset)
                ->setMaxResults($length);

            return new static($queryBuilder, $this->detaching);
        } else {
            return parent::slice($offset, $length);
        }
    }

    /**
     * @{inheritDoc}
     */
    public function count()
    {
        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            return (int) $queryBuilder
                ->select('COUNT('.$alias.')')
                ->resetDQLPart('orderBy')
                ->setMaxResults(null)
                ->setFirstResult(null)
                ->getQuery()
                ->getSingleScalarResult();
        } else {
            return parent::count();
        }
    }

    /**
     * Only if query builder available.
     *
     * @return \Colada\Option
     */
    protected function getQbIterator()
    {
        $iterator = option(null);
        if ($this->queryBuilder) {
            $iterator = option(new CollectionMapIterator(
                new ResultSetIterator($this->queryBuilder->getQuery()),
                function($row) {
                    $entity = $row[0];

                    if ($this->detaching) {
                        // TODO Check this code on PHP 5.3.
                        $this->queryBuilder->getEntityManager()->detach($entity);
                    }

                    return $entity;
                }
            ));
        }

        return $iterator;
    }

    /**
     * @param callback $qbFilter
     * @param callback $nativeFilter
     *
     * @return \Colada\Collection
     */
    protected function filterQbBy($qbFilter, $nativeFilter)
    {
        Contracts::ensureCallable($qbFilter, $nativeFilter);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            call_user_func($qbFilter, $queryBuilder, $alias);

            return new static($queryBuilder, $this->detaching);
        } else {
            return $this->acceptBy($nativeFilter);
        }
    }

    /**
     * @param callback $qbFilter
     * @param callback $nativeFilter
     *
     * @return \Colada\Option
     */
    protected function findInQbBy($qbFilter, $nativeFilter)
    {
        Contracts::ensureCallable($qbFilter, $nativeFilter);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            call_user_func($qbFilter, $queryBuilder, $alias);

            $entity = $queryBuilder->getQuery()->getOneOrNullResult();

            return \Colada\Option::from($entity);
        } else {
            return $this->findBy($nativeFilter);
        }
    }

    /**
     * @param callback $qbComparator
     * @param callback $nativeComparator
     *
     * @return \Colada\Collection
     */
    protected function sortQbBy($qbComparator, $nativeComparator)
    {
        Contracts::ensureCallable($qbComparator, $nativeComparator);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            call_user_func($qbComparator, $queryBuilder, $alias);

            return new static($queryBuilder, $this->detaching);
        } else {
            return $this->sortBy($nativeComparator);
        }
    }

    /**
     * @param callback $qbFolder
     * @param callback $nativeFolder
     * @param mixed    $accumulator For native filter.
     *
     * @return mixed
     */
    protected function foldQbBy($qbFolder, $nativeFolder, $accumulator)
    {
        Contracts::ensureCallable($qbFolder, $nativeFolder);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            // TODO Do ->getQuery()->getOneOrNullResult() here?
            call_user_func($qbFolder, $queryBuilder, $alias);

            return $queryBuilder->getQuery()->getOneOrNullResult();
        } else {
            return $this->foldBy($nativeFolder, $accumulator);
        }
    }
}
