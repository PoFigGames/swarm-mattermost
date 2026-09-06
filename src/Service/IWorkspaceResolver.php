<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Service;

/**
 * Interface for the Mattermost workspace (server) resolver service.
 * @package Mattermost\Service
 */
interface IWorkspaceResolver
{
    const SERVICE_NAME = 'MattermostWorkspaceResolver';
    const DEFAULT_ID   = 'default';

    /**
     * Returns a valid-only list of workspace blocks.
     *
     * Each element has its workspace id embedded under the 'id' key.
     * Legacy flat config is wrapped with id 'default'.
     *
     * @return array List of workspace arrays, each with embedded 'id'.
     */
    public function getWorkspaces(): array;

    /**
     * Returns true if at least one valid workspace is configured.
     *
     * @return bool True when at least one workspace has a non-blank url and token.
     */
    public function hasConfiguredWorkspace(): bool;

    /**
     * Valid workspaces defined in the 'mattermost' block of config.php only, ignoring the
     * configuration record. Used to seed the record on first use.
     *
     * @return array List of workspace arrays, each with embedded 'id'.
     */
    public function getWorkspacesFromConfigFile(): array;
}
