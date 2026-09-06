<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Service;

use Configurations\Model\Configuration;

/**
 * Access to the Mattermost configuration record (the servers managed through the UI / REST API).
 * The record is kept by Swarm's standard Configurations module as swarm-configuration-mattermost.
 * @package Mattermost\Service
 */
interface IConfigurationStore
{
    const SERVICE_NAME = 'MattermostConfigurationStore';

    /**
     * The record, or null when none has been created yet. Never writes.
     *
     * @return Configuration|null
     */
    public function find(): ?Configuration;

    /**
     * The record, created on first use from the 'mattermost' block of config.php.
     *
     * @return Configuration
     */
    public function getOrCreate(): Configuration;

    /**
     * Persist the record.
     *
     * @param Configuration $configuration
     * @return Configuration
     */
    public function save(Configuration $configuration): Configuration;
}
