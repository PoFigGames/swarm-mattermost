<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Filter;

use Configurations\Filter\WorkspaceInputFilter as SwarmWorkspaceInputFilter;
use Interop\Container\ContainerInterface;
use Laminas\Filter\StringTrim;
use Laminas\Validator\Regex;
use Mattermost\Model\IConfiguration;

/**
 * Validates one Mattermost server as sent to the REST API: Swarm's standard workspace filter
 * (id, token, project channels, notify options, bot user) plus the Mattermost server url and team.
 * @package Mattermost\Filter
 */
class WorkspaceInputFilter extends SwarmWorkspaceInputFilter
{
    const SERVICE_NAME = 'MattermostWorkspaceInputFilter';

    /**
     * @param ContainerInterface $services
     * @param array|null         $options
     */
    public function __construct(ContainerInterface $services, ?array $options = null)
    {
        parent::__construct($services, $options);
        $this->addUrl();
        $this->addTeam();
    }

    /**
     * Base url of the Mattermost server. Required for a new server (checked by the controller);
     * blank on update keeps the stored value.
     */
    protected function addUrl(): void
    {
        $this->add(
            [
                'name'        => IConfiguration::URL,
                'required'    => false,
                'allow_empty' => true,
                'filters'     => [['name' => StringTrim::class]],
                'validators'  => [
                    [
                        'name'    => Regex::class,
                        'options' => [
                            'pattern'  => '#^https?://[^\s/]+#i',
                            'messages' => [Regex::NOT_MATCH => 'url must start with http:// or https://'],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Team name as it appears in the Mattermost URL; optional when only channel ids are used.
     */
    protected function addTeam(): void
    {
        $this->add(
            [
                'name'        => IConfiguration::TEAM,
                'required'    => false,
                'allow_empty' => true,
                'filters'     => [['name' => StringTrim::class]],
                'validators'  => [
                    [
                        'name'    => Regex::class,
                        'options' => [
                            'pattern'  => '/^[a-z0-9][a-z0-9._-]*$/',
                            'messages' => [
                                Regex::NOT_MATCH => 'team must be the team name from the Mattermost URL',
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}
