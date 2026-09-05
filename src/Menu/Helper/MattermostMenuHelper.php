<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Menu\Helper;

use Application\Menu\Helper\BaseMenuHelper;
use Interop\Container\ContainerInterface;

/**
 * Menu helper for the Mattermost configuration page. Mirrors the Webhooks module:
 * the id matches the 'menu_helpers' key and the roles are hard-coded so only
 * administrators see the entry.
 * @package Mattermost\Menu\Helper
 */
class MattermostMenuHelper extends BaseMenuHelper
{
    /**
     * @param ContainerInterface $container
     * @param array|null         $options
     */
    public function __construct(ContainerInterface $container, array $options = null)
    {
        parent::__construct($container, $options);
        $this->id    = 'mattermost';
        $this->roles = ['admin', 'super'];
    }
}
