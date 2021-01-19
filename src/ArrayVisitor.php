<?php

namespace CupOfTea\Config;

use PhpParser\Node;
use PhpParser\NodeFinder;
use Illuminate\Support\Arr;
use PhpParser\NodeTraverser;
use PhpParser\BuilderFactory;
use PhpParser\NodeVisitorAbstract;

class ArrayVisitor extends NodeVisitorAbstract
{
    /**
     * @var array
     */
    protected $set;

    /**
     * @var array
     */
    protected $unset;

    /**
     * @var array
     */
    protected $current = [];

    /**
     * @var array
     */
    protected $indices = [];

    /**
     * @var \PhpParser\BuilderFactory
     */
    protected $builder;

    /**
     * @var array
     */
    protected $status;

    /**
     * @var \PhpParser\NodeFinder
     */
    protected $finder;

    /**
     * @var \PhpParser\Node\Expr\Array_[]
     */
    protected $cache = [];

    /**
     * Create a new ArrayVisitor instance.
     *
     * @param  array  $set
     * @param  array  $unset
     */
    public function __construct(array $set, array $unset = [])
    {
        $this->set = $set;
        $this->unset = $unset;

        $this->builder = new BuilderFactory();
    }

    /**
     * Called once before traversal.
     *
     * @param  \PhpParser\Node[]  $nodes
     * @return \PhpParser\Node[]|null
     */
    public function beforeTraverse(array $nodes)
    {
        $this->set = Arr::dot($this->set);
        $this->unset = array_flip($this->parseUnset($this->unset));

        $all = array_unique(array_merge(
            array_keys($this->set),
            array_keys($this->unset)
        ));

        $this->status = array_fill_keys($all, false);

        return null;
    }

    /**
     * Called when entering a node.
     *
     * @param  \PhpParser\Node  $node
     * @return \PhpParser\Node|int|null
     */
    public function enterNode(Node $node)
    {
        if ($this->done()) {
            return NodeTraverser::STOP_TRAVERSAL;
        }

        if ($node instanceof Node\Expr\ArrayItem) {
            $this->current[] = $node->key->value ?? $this->getIndex();
            $key = implode('.', $this->current);

            if (Arr::has($this->set, $key)) {
                $node->value = $this->builder->val(Arr::get($this->set, $key));
                $this->processed($key);

                if ($this->done()) {
                    return NodeTraverser::STOP_TRAVERSAL;
                }
            }
        } elseif (! $node instanceof Node\Expr\Array_) {
            return NodeTraverser::DONT_TRAVERSE_CHILDREN;
        }

        return null;
    }

    /**
     * Called when leaving a node.
     *
     * @param  \PhpParser\Node  $node
     * @return \PhpParser\Node|int|null
     */
    public function leaveNode(Node $node)
    {
        if ($node instanceof Node\Expr\ArrayItem) {
            $key = implode('.', $this->current);
            array_pop($this->current);

            if (Arr::has($this->unset, $key)) {
                $this->processed($key);

                return NodeTraverser::REMOVE_NODE;
            }
        }

        return null;
    }

    /**
     * Called once after traversal.
     *
     * @param  \PhpParser\Node[]  $nodes
     * @return \PhpParser\Node[]|null
     */
    public function afterTraverse(array $nodes)
    {
        if (! $this->done()) {
            foreach ($this->status as $key => $processed) {
                if ($processed || ! Arr::has($this->set, $key)) {
                    continue;
                }

                $this->addNode($nodes[0], $key, Arr::get($this->set, $key));
            }
        }

        return null;
    }

    /**
     * Convert the unset array into a flat array of dot notation keys.
     *
     * @param  array  $unset
     * @return array
     */
    protected function parseUnset(array $unset): array
    {
        return array_unique(collect($unset)->reduceWithKeys(function (?array $carry, $value, $key) {
            if (is_null($carry)) {
                $carry = [];
            }

            if (is_array($value)) {
                $subValues = $this->parseUnset($value);

                foreach ($subValues as $subValue) {
                    $carry[] = $key . '.' . $subValue;
                }
            } else {
                $carry[] = $value;
            }

            return $carry;
        }));
    }

    /**
     * Get the index for an indexed array item.
     *
     * @return int
     */
    protected function getIndex(): int
    {
        $key = implode('.', $this->current);

        if (! isset($this->indices[$key])) {
            $this->indices[$key] = 0;
        } else {
            $this->indices[$key]++;
        }

        return $this->indices[$key];
    }

    /**
     * Mark a key as processed.
     *
     * @param  string  $key
     * @return void
     */
    protected function processed(string $key): void
    {
        $this->status[$key] = true;
    }

    /**
     * Check if all changed have been processed.
     *
     * @return bool
     */
    protected function done(): bool
    {
        return count(array_unique($this->status)) === 1 && end($this->status) === true;
    }

    /**
     * Add a Node to the given Array using "dot" notation.
     *
     * @param  \PhpParser\Node\Expr\Array_  $array
     * @param  string  $dotKey
     * @param  mixed  $value
     * @return void
     */
    protected function addNode(Node\Expr\Array_ $array, string $dotKey, $value): void
    {
        $path = explode('.', $dotKey);
        $key = array_pop($path);

        $node = $this->findNode($array, $path);

        $node->items[] = new Node\Expr\ArrayItem(
            $this->builder->val($value),
            $this->builder->val($key)
        );
    }

    /**
     * Find or create the Array node at the given path.
     *
     * @param  \PhpParser\Node\Expr\Array_  $array
     * @param  array  $path
     * @return array|\ArrayAccess|mixed|\PhpParser\Node\Expr\Array_
     */
    protected function findNode(Node\Expr\Array_ $array, array $path)
    {
        $dotKey = implode('.', $path);
        $searchPath = [];

        do {
            $currentKey = implode('.', $path);

            if ($node = Arr::get($this->cache, $currentKey)) {
                $array = $node;

                break;
            }
        } while ($searchPath[] = array_pop($path));

        $searchPath = array_reverse(array_filter($searchPath));
        $currentPath = [];

        foreach ($searchPath as $key) {
            $currentPath[] = $key;
            $currentKey = implode('.', $currentPath);

            $node = $this->getFinder()->findFirst($array, function (Node $node) use ($key) {
                return $node instanceof Node\Expr\ArrayItem && $node->key && $node->key->value === $key;
            });

            if (! $node) {
                $array->items[] = new Node\Expr\ArrayItem(
                    $node = new Node\Expr\Array_([], ['kind' => Node\Expr\Array_::KIND_SHORT]),
                    $this->builder->val($key)
                );

                $this->cache[$currentKey] = $array = $node;
            } else {
                if (! $node->value instanceof Node\Expr\Array_) {
                    throw new InvalidKeyException(sprintf(
                        'Could not set "%s" because "%s" is not an array',
                        $dotKey,
                        $currentKey
                    ));
                }

                $array = $node->value;
            }
        }

        return $array;
    }

    /**
     * Get the NodeFinder instance.
     *
     * @return \PhpParser\NodeFinder
     */
    protected function getFinder(): NodeFinder
    {
        if (! $this->finder) {
            $this->finder = new NodeFinder();
        }

        return $this->finder;
    }
}
