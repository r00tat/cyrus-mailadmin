<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Model\Virtual;

/**
 * What every classified `virtual` row can answer.
 *
 * The point of the interface is that application code asks the *type*, never
 * the field pattern — the distinction happens once, in
 * {@see VirtualEntryClassifier}, and nowhere else (architecture 3.4).
 */
interface VirtualEntry
{
    public function type(): VirtualEntryType;

    /**
     * The row this entry was read from, unchanged.
     */
    public function row(): VirtualRow;

    /**
     * The left-hand side, verbatim: an address, `@domain`, or a Cyrus user name
     * depending on the type.
     */
    public function alias(): string;

    public function destinations(): DestinationList;

    /**
     * Postfix only delivers rows with `status = 1`.
     */
    public function isEnabled(): bool;
}
