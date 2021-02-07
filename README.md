# Config Editor

Programatically edit array-based php configuration files.

## Installation

Config Editor can be installed via [Composer][composer] using the require command.

```
$ composer require cupoftea/config-editor
```

## Usage

### Basic Example

```php
/**
 * Config Editor does not include file read & write operations
 * and must be passed a string representing the file contents.
 */
$config = file_get_contents('config/my_config.php');
$editor = new \CupOfTea\Config\Editor($config);

// Values can be set by passing a key and a value, or passing it an associative array with the values you want to set.
// Editor::set() returns the editor instance for easy chaining.
$editor->set('foo', 'bar')
    ->set(['baz' => 'qux', 'quux' => 'quuz']);

// Nested values can be set using array "dot" notation
$editor->set('paths.views', 'resources/views')
    ->set('paths.lang', 'resources/lang');

// Use the unset() method to completely remove a key from the configuration.
$editor->unset('foo')
    ->unset(['baz', 'quux']);

// Once you are done making edits, you can compile the config back to a string and write it to a file.
$newConfig = $editor->compile();
file_put_contents('config/my_edited_config.php', $newConfig);
```

### Configuration File

A configuration file must return an array. The array cannot be computed at runtime.

When using an invalid configuration file, the `Editor::compile()` method
will throw a `CupOfTea\Config\InvalidConfigurationException`.

Valid:
```php
<?php

use Illuminate\Support\Arr;

function env($key, $default = null) {
    return $_ENV[$key] ?? $default;
}

return [
    'computed_val' => Arr::get($_SERVER, 'DOCUMENT_ROOT'),
    'env_val' => env('HOME'),
    'string' => 'str',
];
```

Invalid:
```php
<?php

function cfg($arr) {
    return $arr;
}

return cfg(['foo' => 'bar']);
```

### Invalid Keys

Config Editor does not allow setting nested values on keys that do not have an array value, and will throw an Exception
if you attempt to do so at when compiling.

```php
$config = <<<'CODE'
<?php

return ['foo' => ['bar' => 'baz']];
CODE;
$editor = new \CupOfTea\Config\Editor($config);

// throws \CupOfTea\Config\InvalidKeyException
$editor->set('foo.bar.baz', 'qux')->compile();

// throws \CupOfTea\Config\InvalidKeyException
$editor
    ->set('paths', 'string')
    ->set('paths.config', 'configs/')
    ->compile();
```


[composer]: https://getcomposer.org/
