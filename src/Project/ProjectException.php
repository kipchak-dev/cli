<?php

declare(strict_types=1);

namespace Kipchak\Cli\Project;

use RuntimeException;

/**
 * The tool cannot work on this directory: it is not a Kipchak project, its dependencies are not installed, or its
 * kipchak/core version is not supported.
 */
final class ProjectException extends RuntimeException {}
