<?php

namespace Tizona\Doctrine\Orm;

use Colada\IteratorProxy;

use Doctrine\ORM\Query;

/**
 * Lazy iteration; rewind() fix (standard query iterator may be iterated only once).
 *
 * @internal
 *
 * @author Alexey Shockov <alexey@shockov.com>
 */
class ResultSetIterator extends IteratorProxy
{
    /**
     * @var \Doctrine\ORM\Query
     */
    private $query;

    public function __construct(Query $query)
    {
        parent::__construct(new \EmptyIterator());

        $this->query = $query;
    }

    public function rewind()
    {
        $this->iterator = $this->query->iterate();
    }
}
