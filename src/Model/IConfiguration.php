<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Model;

use Configurations\Model\IConfiguration as ISwarmConfiguration;

/**
 * Field names of the Mattermost configuration record. The record is stored through Swarm's
 * standard Configurations module (key swarm-configuration-mattermost) and uses its field names;
 * only the Mattermost-specific fields are added here.
 * @package Mattermost\Model
 */
interface IConfiguration extends ISwarmConfiguration
{
    // Id of the record holding all configured servers
    const RECORD_ID = 'mattermost';

    const URL  = 'url';
    const TEAM = 'team';
}
