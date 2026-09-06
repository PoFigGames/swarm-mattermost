<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

use Application\Controller\IndexControllerFactory;
use Application\Factory\InvokableServiceFactory;
use Events\Listener\ListenerFactory as EventListenerFactory;
use Laminas\Router\Http\Segment;
use Mattermost\Config\IConfig;
use Mattermost\Controller\IndexController;
use Mattermost\Listener\Activity;
use Mattermost\Listener\Comment;
use Mattermost\Model\IMattermostDAO;
use Mattermost\Model\MattermostDAO;
use Mattermost\Service\Api;
use Mattermost\Service\IApi;
use Mattermost\Service\IMattermost;
use Mattermost\Service\IUtility;
use Mattermost\Service\IWorkspaceResolver;
use Mattermost\Service\Mattermost;
use Mattermost\Service\Utility;
use Mattermost\Service\WorkspaceResolver;

$listeners = [Activity::class, Comment::class];

return [
    // Everything in the 'mattermost' namespace is meant to be configurable.
    //
    // Several Mattermost servers can be configured by nesting one block per server:
    //   IConfig::MATTERMOST => [
    //       'prod' => [IConfig::URL => 'https://mm.example.com', IConfig::TOKEN => '...', IConfig::TEAM => 'dev', ...],
    //       'lab'  => [IConfig::URL => 'https://mm-lab.example.com', IConfig::TOKEN => '...', IConfig::TEAM => 'qa'],
    //   ]
    IConfig::MATTERMOST => [
        // REQUIRED, base url of the Mattermost server, e.g. 'https://mattermost.example.com'
        // IConfig::URL => "",
        // REQUIRED, access token of the bot account (System Console > Integrations > Bot Accounts)
        // or a personal access token. Posting to a channel requires the bot to be a member of it.
        // IConfig::TOKEN => "",
        // Team name (the part of the URL after the host) used to resolve channel names to ids.
        // Not needed when only channel ids are used in project_channels.
        // IConfig::TEAM => "",
        // This allows individual projects to be configured to send messages to
        // different Mattermost channels. There are three special values:
        //    '*no_project_channel*'   Notifications with a project which isn't specified here
        //    '*without_project*'      Notifications with no associated project
        //    '*all*'                  All notifications, even those matched already
        //
        // Individual projects can be specified, and directed to one or more
        // channels. Either the channel name (URL handle, e.g. 'town-square') or a channel id can be used.
        IConfig::PROJECT_CHANNELS => [
//            'myproject' => [
//                'myproject-channel',
//            ],
//            // Project with no setting will go to no-project-channel
//            '*no_project_channel*' => [
//                'no-project-channel',
//            ],
//            // No project on this review/change.
//            '*without_project*' => [
//                'no-projects-linked',
//            ],
//            // All notification get sent here even if we matched above.
//            '*all*' => [
//                'all-notifications',
//            ]
        ],

        // These two options control whether a list of files in the review is included. They can
        // be either inserted directly into the main message, or added as a reply to the message.
        // Recommended that you don't set both to true, or you'll just be repeating information.
        IConfig::SUMMARY_FILE_NAMES => false,
        IConfig::REPLY_FILE_NAMES => false,
        IConfig::BYPASS_RESTRICTED_CHANGELIST => false,
        IConfig::NOTIFY_MENTIONED_ONLY => false,
        // Notify specific users via @mention in Mattermost messages. Users are matched by email, so the
        // bot needs permission to look users up by email (the default for members of the same team).
        // Valid values: 'author', 'branch_default_reviewers', 'branch_moderators',
        //               'project_default_reviewers', 'project_owners', 'project_members', 'reviewers'
        // IConfig::NOTIFY => [
        //     IConfig::NOTIFY_AUTHOR,
        //     IConfig::NOTIFY_DEFAULT_REVIEWERS,
        //     IConfig::NOTIFY_BRANCH_MODERATORS,
        // ],
        IConfig::NOTIFY => [],
        // The number of files that will be listed in a message
        IConfig::SUMMARY_FILE_LIMIT => 10,
        IConfig::USER => [
            // Overrides the bot's display name and avatar per post. Requires
            // "Enable integrations to override usernames / profile picture icons"
            // (ServiceSettings.EnablePostUsernameOverride / EnablePostIconOverride) in the System Console.
            IConfig::ENABLED => true,
            IConfig::NAME => 'Helix Swarm', // The name that the swarm bot uses when posting messages
            IConfig::ICON => // The icon that the swarm bot uses when posting messages
               'https://swarm.workshop.perforce.com/view/guest/perforce_software/slack/main/images/60x60-Helix-Bee.png',
            // Forces Mattermost to show the user header (name and avatar) for every message
            IConfig::FORCE_USER_HEADER => false,
        ],
        // Emoji reactions the bot adds to the review post when the review changes state.
        // Use Mattermost emoji names without colons; an empty string disables the reaction.
        // Only one state reaction is kept on the post: the previous one is removed on the next transition.
        IConfig::REACTIONS => [
            IConfig::REACTION_APPROVED       => '+1',
            IConfig::REACTION_REJECTED       => '-1',
            IConfig::REACTION_ARCHIVED       => 'package',
            IConfig::REACTION_NEEDS_REVIEW   => '',
            IConfig::REACTION_NEEDS_REVISION => 'lower_left_fountain_pen',
        ],
    ],
    'listeners' => $listeners,
    // Standalone configuration page: /mattermost/configuration (administrators only).
    // It manages the same 'mattermost' record as the Configurations REST API.
    'router' => [
        'routes' => [
            'mattermost-configuration' => [
                'type'    => Segment::class,
                'options' => [
                    'route'    => '/mattermost/configuration[/]',
                    'defaults' => [
                        'controller' => IndexController::class,
                        'action'     => 'index',
                    ],
                ],
            ],
        ],
    ],
    'controllers' => [
        'factories' => [
            IndexController::class => IndexControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_map'        => [
            IndexController::TEMPLATE => __DIR__ . '/../view/mattermost/index/index.phtml',
        ],
        'template_path_stack' => [
            __DIR__ . '/../view',
        ],
    ],
    // Adds a "Mattermost" entry to the Swarm navigation menu (same mechanism as the
    // Webhooks module, see Mattermost\Menu\Helper\MattermostMenuHelper for the roles).
    'menu_helpers' => [
        'mattermost' => [
            'title'    => 'Mattermost',
            'target'   => '/mattermost/configuration',
            'priority' => 206,
            // Menu entries get the classes "menuItem menuItem-<id> <cssClass>". A cssClass
            // containing "component" would make the React menu route the click client-side
            // (Swarm's own React pages); this entry is a server-rendered page, so keep it plain.
            // public/custom/mattermost/mattermost.css hooks on menuItem-mattermost for the icon.
            'cssClass' => 'mattermost',
        ],
    ],
    'service_manager' => [
        'factories' => [
            Activity::class          => EventListenerFactory::class,
            Comment::class           => EventListenerFactory::class,
            Api::class               => InvokableServiceFactory::class,
            Mattermost::class        => InvokableServiceFactory::class,
            Utility::class           => InvokableServiceFactory::class,
            MattermostDAO::class     => InvokableServiceFactory::class,
            WorkspaceResolver::class => InvokableServiceFactory::class,
        ],
        'aliases' => [
            IApi::SERVICE_NAME               => Api::class,
            IMattermost::SERVICE_NAME        => Mattermost::class,
            IUtility::SERVICE_NAME           => Utility::class,
            IMattermostDAO::SERVICE_NAME     => MattermostDAO::class,
            IWorkspaceResolver::SERVICE_NAME => WorkspaceResolver::class,
        ]
    ],
    EventListenerFactory::EVENT_LISTENER_CONFIG => [
        EventListenerFactory::TASK_COMMIT => [
            Activity::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleCommit',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        EventListenerFactory::TASK_REVIEW => [
            Activity::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleReview',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ],
        EventListenerFactory::TASK_COMMENT => [
            Comment::class => [
                [
                    EventListenerFactory::PRIORITY => -110,
                    EventListenerFactory::CALLBACK => 'handleReview',
                    EventListenerFactory::MANAGER_CONTEXT => 'queue'
                ]
            ]
        ]
    ]
];
