<?php

declare(strict_types=1);

namespace Mailadmin\Domain\Sieve;

use Mailadmin\Domain\Exception\InvalidSieveRule;

/**
 * A named Sieve script, and the answer to „did we write this?".
 *
 * The marker in the first line is the whole mechanism (architecture 9.4): a
 * script **without** it was written by the user or by another tool and is never
 * overwritten — it is shown read-only, and taking it over has to be confirmed.
 * We generate scripts, we do not parse them; that is why recognition is a
 * string in the header and not an analysis of the content.
 */
final readonly class SieveScript
{
    /**
     * The name the legacy system used. Keeping it is what makes an existing
     * script findable at all — M3 confirms (its open point 1).
     */
    public const string LEGACY_NAME = 'sieve';

    public const string MARKER = '# mailadmin-managed';

    /** Bumped when the rendered form changes in a way a reader should notice. */
    public const string MARKER_VERSION = '1';

    private function __construct(
        public string $name,
        public string $content,
    ) {
    }

    /**
     * @throws InvalidSieveRule
     */
    public static function of(string $name, string $content): self
    {
        return new self(self::guardName($name), $content);
    }

    /**
     * The header every generated script carries.
     */
    public static function markerLine(): string
    {
        return self::MARKER . ': v' . self::MARKER_VERSION;
    }

    /**
     * Did mailadmin write this script?
     *
     * Only the first few lines are inspected: a marker further down could be
     * part of a message body a user wrote.
     */
    public function isManaged(): bool
    {
        $head = substr($this->content, 0, 200);

        return str_starts_with(ltrim($head), self::MARKER);
    }

    /**
     * A script we did not write is read-only until someone says otherwise.
     */
    public function isForeign(): bool
    {
        return !$this->isManaged();
    }

    /**
     * The rule set this script was rendered from — read from the model line
     * {@see SieveRenderer} writes under the marker, never from the Sieve
     * (U-102).
     *
     * `null` for a foreign script, and for a managed one whose model line is
     * missing or does not decode: such a script is ours, but what it holds can
     * only be shown, not edited.
     */
    public function ruleSet(): ?SieveRuleSet
    {
        if (!$this->isManaged()) {
            return null;
        }

        $model = SieveRuleSetCodec::modelOf($this->content);

        return $model === null ? null : SieveRuleSetCodec::decode($model);
    }

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }

    /**
     * @throws InvalidSieveRule
     */
    private static function guardName(string $name): string
    {
        $scriptName = trim($name);

        if ($scriptName === '') {
            throw InvalidSieveRule::emptyScriptName();
        }

        // ManageSieve script names are UTF-8, but a name with a control
        // character, a quote or a newline breaks the protocol framing.
        if (preg_match('/["\r\n\x00-\x1F\x7F]/', $scriptName) === 1) {
            throw InvalidSieveRule::unusableScriptName($scriptName);
        }

        return $scriptName;
    }
}
