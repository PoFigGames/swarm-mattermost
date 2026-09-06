<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Controller;

use Exception;

/**
 * Carries input filter messages to the API error response.
 * @package Mattermost\Controller
 */
class ValidationException extends Exception
{
    private $messages;

    /**
     * @param array $messages Messages as returned by InputFilter::getMessages()
     */
    public function __construct(array $messages)
    {
        parent::__construct('Validation failed');
        $this->messages = $messages;
    }

    /**
     * @return array
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
