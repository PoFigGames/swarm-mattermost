<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Service;

use Activity\Model\Activity;
use Application\Config\ConfigException;
use P4\Exception;
use P4\Spec\Change;
use Reviews\Model\Review;

/**
 * Interface IMattermost.
 * @package Mattermost\Service
 */
interface IMattermost
{
    public const SERVICE_NAME = "MattermostService";

    /**
     * This function is called when a review is created or a changelist is submitted
     *
     * @param Change      $change   - The changelist object
     * @param Activity    $activity - The activity that will be on the event.
     * @param Review|null $review   - If set, the changelist object represents a review rather than a change
     * @param mixed|null  $projects - If set, use the given projects, otherwise work out the projects from the review
     *                                or change
     *
     * @throws ConfigException|Exception
     */
    public function handleCreateMessage(
        Change $change,
        Activity $activity,
        Review $review = null,
        $projects = null
    );

    /**
     * This function is called when a review is updated, and we want to add a reply to the thread.
     *
     * @param Change      $change           - The changelist object
     * @param Activity    $activity         - The activity that will be on the event.
     * @param Review|null $review           - If set, the changelist object represents a review rather than a change
     * @param array       $imageAttachments Web-safe image Attachment objects to upload.
     *
     * @throws ConfigException|Exception
     */
    public function handleThreadedMessage(
        Change $change,
        Activity $activity,
        Review $review = null,
        array $imageAttachments = []
    );

    /**
     * This function is called when a new project is linked to a review and doesn't have a thread id yet.
     * It will create the root post, save the thread to the key data and return the thread so it can be used
     * right away.
     *
     * @param Change     $change         - The changelist object
     * @param string     $channel        - The configured channel (name or id) we are going to post to.
     * @param Review     $review         - The changelist object represents a review rather than a change
     * @param array      $workspace      - The workspace block
     * @param array|null $projects       - The projects for this given item.
     * @param array      $linkedProjects - The linked projects by channel.
     *
     * @return array|null [Mattermost::THREAD_ID => root post id, Mattermost::CHANNEL_ID => channel id] or null
     *
     * @throws ConfigException|Exception
     */
    public function handleMissingMessage(
        Change $change,
        string $channel,
        Review $review,
        array $workspace,
        array $projects = null,
        array $linkedProjects = []
    ): ?array;

    /**
     * Apply the bot username/icon overrides and create the post.
     *
     * @param array $body      - The post body (channel_id, message, root_id, file_ids)
     * @param array $workspace - The workspace block
     * @return object|null The created post or null on failure
     * @throws ConfigException
     */
    public function postMessage(array $body, array $workspace): ?object;
}
