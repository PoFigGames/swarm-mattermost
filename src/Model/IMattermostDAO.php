<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Model;

use Application\Model\IModelDAO;

/**
 * Interface for a MattermostDAO
 */
interface IMattermostDAO extends IModelDAO
{
    const SERVICE_NAME = 'MattermostDAO';
}
