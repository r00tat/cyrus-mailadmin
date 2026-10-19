<?php

declare(strict_types=1);

namespace Mailadmin\Application\Maintenance;

/**
 * `skipped` is a first-class answer: a probe that did not run says so rather
 * than reporting `ok`.
 */
enum ProbeStatus: string
{
    case Ok = 'ok';
    case Error = 'error';
    case Skipped = 'skipped';
}
