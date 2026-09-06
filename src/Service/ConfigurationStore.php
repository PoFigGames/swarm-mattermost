<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Service;

use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Configurations\Model\Configuration;
use Interop\Container\ContainerInterface;
use Mattermost\Config\IConfig;
use Mattermost\Model\IConfiguration;
use Record\Exception\NotFoundException as RecordNotFoundException;

/**
 * @inheritDoc
 */
class ConfigurationStore implements IConfigurationStore, InvokableService
{
    const LOG_PREFIX = ConfigurationStore::class;

    private $services;
    private $logger;

    /**
     * @param ContainerInterface $services
     * @param array|null         $options
     */
    public function __construct(ContainerInterface $services, ?array $options = null)
    {
        $this->services = $services;
        $this->logger   = $services->get(SwarmLogger::SERVICE);
    }

    /**
     * @inheritDoc
     */
    public function find(): ?Configuration
    {
        try {
            $record = $this->dao()->fetchById(IConfiguration::RECORD_ID, $this->p4());
            return $record instanceof Configuration ? $record : null;
        } catch (RecordNotFoundException $e) {
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function getOrCreate(): Configuration
    {
        $record = $this->find();
        if ($record !== null) {
            return $record;
        }
        $data   = $this->seedFromConfigFile();
        $record = new Configuration($this->p4());
        $record->set($data);
        return $this->save($record);
    }

    /**
     * @inheritDoc
     */
    public function save(Configuration $configuration): Configuration
    {
        return $this->dao()->save($configuration);
    }

    /**
     * Initial record built from the 'mattermost' block of config.php (single or multi server layout).
     *
     * @return array
     */
    private function seedFromConfigFile(): array
    {
        $resolver   = $this->services->get(IWorkspaceResolver::SERVICE_NAME);
        $workspaces = [];
        foreach ($resolver->getWorkspacesFromConfigFile() as $workspace) {
            $workspaces[] = self::workspaceToRecord($workspace);
        }
        $this->logger->info(
            sprintf('%s: seeding the record with %d server(s) from config.php', self::LOG_PREFIX, count($workspaces))
        );
        return [
            IConfiguration::ID         => IConfiguration::RECORD_ID,
            IConfiguration::NAME       => 'Mattermost',
            IConfiguration::WORKSPACES => $workspaces,
        ];
    }

    /**
     * Convert a config.php workspace block (snake_case, project_channels as a dict) to the record
     * layout (camelCase, projectChannels as a list of {project, channels}).
     *
     * @param array $workspace
     * @return array
     */
    public static function workspaceToRecord(array $workspace): array
    {
        $projectChannels = [];
        foreach ((array) ($workspace[IConfig::PROJECT_CHANNELS] ?? []) as $project => $channels) {
            $projectChannels[] = [
                IConfiguration::PROJECT  => (string) $project,
                IConfiguration::CHANNELS => array_values((array) $channels),
            ];
        }
        $user = (array) ($workspace[IConfig::USER] ?? []);
        return [
            IConfiguration::ID                           => (string) ($workspace[IConfig::ID]
                ?? IWorkspaceResolver::DEFAULT_ID),
            IConfiguration::URL                          => (string) ($workspace[IConfig::URL] ?? ''),
            IConfiguration::TOKEN                        => (string) ($workspace[IConfig::TOKEN] ?? ''),
            IConfiguration::TEAM                         => (string) ($workspace[IConfig::TEAM] ?? ''),
            IConfiguration::PROJECT_CHANNELS             => $projectChannels,
            IConfiguration::SUMMARY_FILE_NAMES           => (bool) ($workspace[IConfig::SUMMARY_FILE_NAMES]
                ?? false),
            IConfiguration::REPLY_FILE_NAMES             => (bool) ($workspace[IConfig::REPLY_FILE_NAMES] ?? false),
            IConfiguration::BYPASS_RESTRICTED_CHANGELIST => (bool) ($workspace[IConfig::BYPASS_RESTRICTED_CHANGELIST]
                ?? false),
            IConfiguration::NOTIFY_MENTIONED_ONLY        => (bool) ($workspace[IConfig::NOTIFY_MENTIONED_ONLY]
                ?? false),
            IConfiguration::NOTIFY                       => array_values(
                (array) ($workspace[IConfig::NOTIFY] ?? [])
            ),
            IConfiguration::SUMMARY_FILE_LIMIT           => (int) ($workspace[IConfig::SUMMARY_FILE_LIMIT] ?? 10),
            IConfiguration::USER                         => [
                IConfiguration::ENABLED           => (bool) ($user[IConfig::ENABLED] ?? true),
                IConfiguration::NAME              => (string) ($user[IConfig::NAME] ?? 'Helix Swarm'),
                IConfiguration::ICON              => (string) ($user[IConfig::ICON] ?? ''),
                IConfiguration::FORCE_USER_HEADER => (bool) ($user[IConfig::FORCE_USER_HEADER] ?? false),
            ],
            IConfiguration::IS_DELETED                   => false,
        ];
    }

    private function dao()
    {
        return $this->services->get(IDao::CONFIGURATION_DAO);
    }

    private function p4()
    {
        return $this->services->get(ConnectionFactory::P4_ADMIN);
    }
}
