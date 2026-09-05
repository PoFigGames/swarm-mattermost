<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Model;

use Notifications\Model\Notification;

/**
 * Model for a Mattermost notification. Thread ids are stored per
 * '<workspaceId>:<channel>' key as [THREAD_ID => root post id, CHANNEL_ID => resolved channel id].
 */
class Mattermost extends Notification implements IMattermost
{
    const UPGRADE_LEVEL = 0;
    const KEY_PREFIX    = 'notification-mattermost-';

    const THREAD_ID  = 'threadId';
    const CHANNEL_ID = 'channelId';
}
