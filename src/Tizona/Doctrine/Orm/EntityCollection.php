<?php

namespace Tizona\Doctrine\Orm;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\PersistentCollection;

use Doctrine\Common\Collections\Collection;

use Colada\CollectionMapIterator;

use Colada\Contracts;

/**
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
        $this->iterator   = $this->getIterator();
    }

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
     */
    public function disableDetaching()
    {
        $this->detaching = false;
    }

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
     * @return \Iterator
     */
    public function getIterator()
    {
        if ($this->queryBuilder) {
            return new CollectionMapIterator(
                $this->queryBuilder->getQuery()->iterate(),
                function($row) {
                    $entity = $row[0];

                    if ($this->detaching) {
                        $this->queryBuilder->getEntityManager()->detach($entity);
                    }

                    return $entity;
                }
            );
        } else {
            return parent::getIterator();
        }
    }

    /**
     * @param callback $filter
     * @param callback $nativeFilter
     *
     * @return \Colada\Collection
     */
    protected function filterQbBy($filter, $nativeFilter)
    {
        Contracts::ensureCallable($filter, $nativeFilter);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            call_user_func($filter, $queryBuilder, $alias);

            return new static($queryBuilder, $this->detaching);
        } else {
            return $this->acceptBy($nativeFilter);
        }
    }

    /**
     * @param callback $filter
     * @param callback $nativeFilter
     *
     * @return \Colada\Option
     */
    protected function findInQbBy($filter, $nativeFilter)
    {
        Contracts::ensureCallable($filter, $nativeFilter);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            call_user_func($filter, $queryBuilder, $alias);

            $entity = $queryBuilder->getQuery()->getOneOrNullResult();

            return \Colada\Option::from($entity);
        } else {
            return $this->findBy($nativeFilter);
        }
    }

    /**
     * @param callback $comparator
     * @param callback $nativeComparator
     *
     * @return \Colada\Collection
     */
    protected function sortQbBy($comparator, $nativeComparator)
    {
        Contracts::ensureCallable($comparator, $nativeComparator);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            call_user_func($comparator, $queryBuilder, $alias);

            return new static($queryBuilder, $this->detaching);
        } else {
            return $this->sortBy($nativeComparator);
        }
    }

    /**
     * @param callback $folder
     * @param callback $nativeFolder
     * @param mixed    $accumulator
     *
     * @return mixed
     */
    protected function foldQbBy($folder, $nativeFolder, $accumulator)
    {
        Contracts::ensureCallable($folder, $nativeFolder);

        if ($this->queryBuilder) {
            $queryBuilder = clone $this->queryBuilder;

            $alias = $queryBuilder->getRootAliases();
            $alias = $alias[0];

            // TODO Do ->getQuery()->getOneOrNullResult() here?
            call_user_func($folder, $queryBuilder, $alias);

            return $queryBuilder->getQuery()->getOneOrNullResult();
        } else {
            return $this->foldBy($nativeFolder, $accumulator);
        }
    }
}
