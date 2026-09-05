<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Model;

use Application\Model\AbstractDAO;

/**
 * DAO for a Mattermost notification
 */
class MattermostDAO extends AbstractDAO implements IMattermostDAO
{
    const MODEL = Mattermost::class;
}
