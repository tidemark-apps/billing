<?php

namespace App\Plugins;

/**
 * A plugin's migrations (or the step right after them) failed. By the time this is thrown the runner has already tried
 * to undo what ran; the message says what was rolled back and what could not be.
 */
class PluginMigrationFailed extends \RuntimeException
{
    /**
     * @param  array<int, string>  $rolledBack  migration names whose down() ran
     * @param  array<string, string>  $notRolledBack  migration name => why it could not be reverted
     */
    public function __construct(string $message, public readonly array $rolledBack = [], public readonly array $notRolledBack = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
