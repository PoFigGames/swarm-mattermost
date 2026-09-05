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
use P4\Spec\Change;
use Reviews\Model\Review;

/**
 * Interface IUtility.
 * @package Mattermost\Service
 */
interface IUtility
{
    // Keys for use in an array of file data
    public const FILES = 'files';
    public const COUNT = 'count';

    public const SERVICE_NAME = "MattermostUtility";

    public const NO_ASSOCIATED_PROJECTS_CHANNELS = "*without_project*";
    public const DEFAULT_PROJECTS_CHANNELS       = "*no_project_channel*";
    public const ALL_NOTIFICATIONS_CHANNELS      = "*all*";

    public const NO_PROJECT_CHANNEL = "no_project_channel_swarm_internal_only_channel";

    /**
     * Build file names from file data on the change taking into account pending status. The number of files is
     * restricted by the summary_file_limit value in the workspace configuration.
     *
     * Files will be returned in an array with a count of all the files to cover the case where the list
     * has been limited, for example
     *  [
     *      // 3 returned due to a restriction
     *      IUtility::FILES => ['file1', 'file2', 'file3'],
     *      // Real number of files
     *      IUtility::COUNT => 100
     *  ]
     * @param Change $change    the current change model
     * @param array  $workspace workspace block
     * @return array the file names
     * @throws ConfigException
     */
    public function getFileNames(Change $change, array $workspace): array;

    /**
     * Returns the host name url used for the link to open the review in Swarm
     *
     * @throws ConfigException
     * @return string - The host name url
     */
    public function getHostName(): string;

    /**
     * This is building the reply text from the activity model.
     *
     * @param Activity $activity        The activity model that is going to be used.
     * @param array    $projects        The list of projects.
     * @param array    $linkedProjects  The projects linked to this channel post.
     * @param array    $workspace       Workspace block, used to resolve the Mattermost @username.
     * @return string
     */
    public function buildActivityReply(
        Activity $activity,
        array $projects,
        array $linkedProjects,
        array $workspace = []
    ): string;

    /**
     * Format a Swarm user for a message the way Mattermost shows people:
     * "Full Name (@mattermost.username)". The @username is resolved through the user's email;
     * when the user has no Mattermost account the Swarm id is used instead: "Full Name (swarmid)".
     *
     * @param string $userId    Swarm user id.
     * @param array  $workspace Workspace block (url/token) used for the lookup.
     * @return string
     */
    public function formatUser(string $userId, array $workspace = []): string;

    /**
     * Returns a list of mappings (channels) based on the projects a review is associated with
     *
     * @param array      $projectList - List of projects a review or changelist is associated with
     * @param array|null $mappingList - List of mappings specified in the config under mattermost.project_channels
     * @param bool       $purePrivate - If all projects are private.
     * @return array        - List of mappings for review or changelist (channels)
     */
    public function getProjectMappings(array $projectList, ?array $mappingList, bool $purePrivate = false): array;

    /**
     * Build the list of projects we want to show. If markdown is true a bold title and one line per
     * project:branch is returned; otherwise a comma separated list.
     *
     * @param array|null $projects       The array of project ID, with their branches to iterate over.
     * @param bool       $markdown       If we should build a title and add new lines for the project list.
     * @param array      $linkedProjects The linked projects to this post.
     * @return string
     */
    public function buildProjectText(?array $projects, bool $markdown = true, array $linkedProjects = []): string;

    /**
     * Build the list of files to be shown on the Mattermost message. The number of files included is restricted by
     * the summary_file_limit value in the workspace configuration. When the list has been limited the text will
     * include an indicator at the end.
     *
     * @param Change $change    This is the change model.
     * @param array  $workspace Workspace block.
     * @param bool   $force     Build the list even if summary_file_names is off (used for reply_file_names).
     * @throws ConfigException
     * @return string
     */
    public function buildFileNames(Change $change, array $workspace, bool $force = false): string;

    /**
     * Build the title we show at the top of the Mattermost message.
     *
     * @param Change        $change    This is the change model.
     * @param Review|null   $review    This is the Review model or null.
     * @param Activity|null $activity  This is the Activity model or null.
     * @param array         $workspace Workspace block, used to resolve Mattermost @usernames.
     * @return string
     */
    public function buildTitle(
        Change $change,
        Review $review = null,
        Activity $activity = null,
        array $workspace = []
    ): string;

    /**
     * Build the url path to the review/change in Swarm. Multi p4d aware via P4_SERVER_ID.
     *
     * @param Change      $change This is the change model.
     * @param Review|null $review This is the Review model or null.
     * @return string
     */
    public function buildLink(Change $change, Review $review = null): string;

    /**
     * Build the description in markdown for Mattermost.
     *
     * @param Change $change This is the change model.
     * @return string
     */
    public function buildDescription(Change $change): string;

    /**
     * Build a space-separated string of Mattermost @mention tokens for the
     * users specified in the notify configuration.
     *
     * Supported notify values: author, branch_default_reviewers,
     * branch_moderators, project_default_reviewers, project_owners,
     * project_members, reviewers.
     * Project-level roles require both $review and $projects.
     * The reviewers role requires only $review.
     *
     * @param  array       $notifyConfig  Values from mattermost.notify config.
     * @param  Change      $change        The current change model.
     * @param  array|null  $projects      Projects for the review or change.
     * @param  Review|null $review        The review model if applicable.
     * @param  array       $workspace     Workspace block for this message.
     *
     * @return string Space-separated '@username' tokens or empty string.
     */
    public function buildMentions(
        array $notifyConfig,
        Change $change,
        ?array $projects,
        ?Review $review = null,
        array $workspace = []
    ): string;

    /**
     * Resolve a Swarm user email address to a Mattermost user.
     *
     * @param  string $email     The user's email address.
     * @param  array  $workspace Workspace block.
     *
     * @return array|null ['id' => ..., 'username' => ...] or null if not found.
     */
    public function resolveMattermostUser(string $email, array $workspace): ?array;

    /**
     * Resolve a Swarm user to a Mattermost user. The email address is tried first; when that
     * fails (no match, or the server hides email addresses from the bot) the Swarm user id is
     * tried as the Mattermost username.
     *
     * @param  string $userId    Swarm user id.
     * @param  array  $workspace Workspace block.
     *
     * @return array|null ['id' => ..., 'username' => ...] or null if not found.
     */
    public function resolveSwarmUser(string $userId, array $workspace): ?array;

    /**
     * Resolve an array of Swarm user IDs to Mattermost user IDs (used for direct messages).
     *
     * @param  array $userIds    Swarm user ID strings to resolve.
     * @param  array $workspace  Workspace block.
     *
     * @return array Array of unique Mattermost user ID strings.
     */
    public function buildMentionsFromUserIds(array $userIds, array $workspace = []): array;
}
