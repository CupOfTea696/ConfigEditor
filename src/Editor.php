<?php

namespace CupOfTea\Config;

use PhpParser\Node;
use Illuminate\Support\Arr;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use CupOfTea\Package\Package;
use PhpParser\PrettyPrinter\Standard;
use CupOfTea\Package\Contracts\Package as PackageContract;

class Editor implements PackageContract
{
    use Package;

    /**
     * Package Vendor.
     *
     * @const string
     */
    public const VENDOR = 'CupOfTea';

    /**
     * Package Name.
     *
     * @const string
     */
    public const PACKAGE = 'ConfigEditor';

    /**
     * Package Version.
     *
     * @const string
     */
    public const VERSION = 'v1.0.0';

    /**
     * @var string
     */
    protected $configContents;

    /**
     * @var \PhpParser\Parser
     */
    protected $parser;

    /**
     * @var \PhpParser\NodeTraverser
     */
    protected $traverser;

    /**
     * @var \PhpParser\PrettyPrinter\Standard
     */
    protected $printer;

    /**
     * @var \PhpParser\Node\Stmt[]
     */
    protected $tree;

    /**
     * @var array
     */
    protected $set = [];

    /**
     * @var array
     */
    protected $unset = [];

    /**
     * @var \PhpParser\Node\Expr\Array_[]
     */
    protected $config;

    /**
     * Create a new Editor instance.
     *
     * @param  string  $config
     */
    public function __construct(string $config)
    {
        $this->configContents = $config;

        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $this->traverser = new NodeTraverser();
        $this->printer = new Standard();
    }

    /**
     * Set the value of a config key.
     *
     * @param  array|string  $key
     * @param  mixed  $value
     * @return $this
     */
    public function set($key, $value = null): self
    {
        if (! is_array($key)) {
            return $this->set([$key => $value]);
        }

        foreach ($key as $k => $v) {
            $this->set[$k] = $v;
        }

        return $this;
    }

    /**
     * Unset the value of a config key.
     *
     * @param  array|string  $key
     * @return $this
     */
    public function unset($key): self
    {
        if (! is_array($key)) {
            return $this->unset([$key]);
        }

        foreach ($key as $k => $v) {
            if (is_string($k)) {
                $this->unset[$k] = $v;
            } else {
                $this->unset[] = $v;
            }
        }

        return $this;
    }

    /**
     * Apply edits and Compile the config.
     *
     * @return string
     */
    public function compile(): string
    {
        if (! count($this->set)) {
            return $this->configContents;
        }

        $this->validate();

        $this->traverser->addVisitor(new ArrayVisitor($this->set, $this->unset));
        $this->traverser->traverse($this->config);

        return $this->doCompile();
    }

    /**
     * Compile the Node Tree.
     *
     * @return string
     */
    protected function doCompile(): string
    {
        return $this->printer->prettyPrintFile($this->getNodeTree()) . PHP_EOL;
    }

    /**
     * Validate the config.
     *
     * @return void
     */
    protected function validate(): void
    {
        $tree = $this->getNodeTree();
        $return = Arr::first($tree, function ($node) {
            return $node instanceof Node\Stmt\Return_;
        });

        if (! $return) {
            throw new InvalidConfigurationException('The config must return an array, but no return statement was present');
        }

        if (! $return->expr instanceof Node\Expr\Array_) {
            throw new InvalidConfigurationException('The return value of the config must be an array');
        }

        $this->config = [$return->expr];
    }

    /**
     * Get the Node tree.
     *
     * @return \PhpParser\Node\Stmt[]
     */
    protected function getNodeTree(): array
    {
        if (! $this->tree) {
            $this->parse();
        }

        return $this->tree;
    }

    /**
     * Parse the config.
     *
     * @return void
     */
    protected function parse(): void
    {
        $this->tree = $this->parser->parse($this->configContents) ?? [];
    }
}
