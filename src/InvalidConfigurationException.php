<?php

namespace CupOfTea\Config;

use InvalidArgumentException;

class InvalidConfigurationException extends InvalidArgumentException
{
    public function __construct(string $reason)
    {
        parent::__construct('The config was not valid: ' . $reason);
    }
}
