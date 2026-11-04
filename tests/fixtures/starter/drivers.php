<?php

use Kipchak\Driver\Config\Config;
use Kipchak\Driver\Logger\Logger;
use Psr\Container\ContainerInterface;

/**
 * Initialise the drivers your API uses here, in order: Config first, as every other driver reads its settings, then
 * Logger. Install another driver with Composer, add its config file to config/ and initialise it below. Each
 * driver's page on https://kipchak.dev/docs/drivers shows how.
 *
 * @var ContainerInterface $container
 */

Config::initialise($container);
Logger::initialise($container);
