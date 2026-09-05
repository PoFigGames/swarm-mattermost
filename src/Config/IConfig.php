<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Config;

/**
 * Configuration keys for the Mattermost module. The Slack module relies on
 * keys defined in the core IConfigDefinition; Mattermost keeps its own so the
 * module does not depend on core changes.
 * @package Mattermost\Config
 */
interface IConfig
{
    // Top-level key in config.php
    const MATTERMOST = 'mattermost';

    // Per-server ("workspace") keys
    const ID                           = 'id';
    const URL                          = 'url';
    const TOKEN                        = 'token';
    const TEAM                         = 'team';
    const PROJECT_CHANNELS             = 'project_channels';
    const SUMMARY_FILE_NAMES           = 'summary_file_names';
    const REPLY_FILE_NAMES             = 'reply_file_names';
    const BYPASS_RESTRICTED_CHANGELIST = 'bypass_restricted_changelist';
    const NOTIFY_MENTIONED_ONLY        = 'notify_mentioned_only';
    const NOTIFY                       = 'notify';
    const SUMMARY_FILE_LIMIT           = 'summary_file_limit';
    const USER                         = 'user';
    const ENABLED                      = 'enabled';
    const NAME                         = 'name';
    const ICON                         = 'icon';
    const FORCE_USER_HEADER            = 'force_user_header';
    // Reactions (emoji names without colons) added to the root post when a review changes state.
    // An empty string disables the reaction for that state.
    const REACTIONS                    = 'reactions';
    const REACTION_APPROVED            = 'approved';
    const REACTION_REJECTED            = 'rejected';
    const REACTION_ARCHIVED            = 'archived';
    const REACTION_NEEDS_REVIEW        = 'needs_review';
    const REACTION_NEEDS_REVISION      = 'needs_revision';
    const DEFAULT_REACTIONS            = [
        self::REACTION_APPROVED       => '+1',
        self::REACTION_REJECTED       => '-1',
        self::REACTION_ARCHIVED       => 'package',
        self::REACTION_NEEDS_REVIEW   => '',
        self::REACTION_NEEDS_REVISION => 'lower_left_fountain_pen',
    ];

    // Values accepted in the 'notify' list
    const NOTIFY_AUTHOR                    = 'author';
    const NOTIFY_DEFAULT_REVIEWERS         = 'branch_default_reviewers';
    const NOTIFY_BRANCH_MODERATORS         = 'branch_moderators';
    const NOTIFY_PROJECT_DEFAULT_REVIEWERS = 'project_default_reviewers';
    const NOTIFY_PROJECT_OWNERS            = 'project_owners';
    const NOTIFY_PROJECT_MEMBERS           = 'project_members';
    const NOTIFY_REVIEWERS                 = 'reviewers';
}
