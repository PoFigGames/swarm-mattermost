<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Service;

use Application\Config\IConfigDefinition as IDef;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Configurations\Model\IConfiguration;
use Interop\Container\ContainerInterface;
use Mattermost\Config\IConfig;
use Record\Exception\NotFoundException as RecordNotFoundException;

/**
 * Normalises the Mattermost config into a unified workspace list.
 *
 * The UI-managed Configurations record ('mattermost') is the source of truth when it exists and has
 * at least one active workspace. Otherwise config.php is used, in one of two layouts:
 *
 *   'mattermost' => ['url' => ..., 'token' => ..., 'team' => ..., ...]           // single server
 *   'mattermost' => ['prod' => ['url' => ..., 'token' => ...], 'dev' => [...]]   // several servers
 *
 * @package Mattermost\Service
 */
class WorkspaceResolver implements IWorkspaceResolver, InvokableService
{
    const LOG_PREFIX = WorkspaceResolver::class;
    // Resolved workspaces are cached for a short time. The listeners ask for them on every
    // request (and queue workers process many tasks per process), and the answer requires a
    // P4 lookup of the Configurations record. A short TTL keeps UI edits visible quickly.
    const CACHE_TTL = 30;

    private $services;
    private $cached      = null;
    private $cachedUntil = 0;

    /**
     * @param ContainerInterface $services Service locator.
     * @param array|null         $options  Unused options array.
     */
    public function __construct(ContainerInterface $services, ?array $options = null)
    {
        $this->services = $services;
    }

    /**
     * @inheritDoc
     */
    public function getWorkspaces(): array
    {
        if ($this->cached !== null && time() < $this->cachedUntil) {
            return $this->cached;
        }
        $this->cached      = $this->resolveWorkspaces();
        $this->cachedUntil = time() + self::CACHE_TTL;
        return $this->cached;
    }

    /**
     * Resolves the workspaces without caching.
     *
     * @return array
     */
    private function resolveWorkspaces(): array
    {
        // Check the P4-backed Configurations record first (UI-managed source of truth). Fall back to
        // config.php when the record is absent or has no active workspaces (e.g. fresh installs).
        $workspacesFromRecord = $this->resolveFromRecord();
        if ($workspacesFromRecord !== null) {
            return $workspacesFromRecord;
        }

        $config     = $this->services->get(IDef::CONFIG);
        $mattermost = $config[IConfig::MATTERMOST] ?? [];
        if (!is_array($mattermost)) {
            return [];
        }
        $multiEntries = array_filter(
            $mattermost,
            function ($value) {
                return is_array($value) && isset($value[IConfig::TOKEN]);
            }
        );
        if (!empty($multiEntries)) {
            return $this->resolveMulti($mattermost);
        }
        return $this->resolveLegacy($mattermost);
    }

    /**
     * @inheritDoc
     */
    public function hasConfiguredWorkspace(): bool
    {
        return !empty($this->getWorkspaces());
    }

    /**
     * Try to load workspaces from the P4-backed Configurations record. Returns the active workspace
     * list if the record exists and has at least one active (non-deleted) workspace with a usable
     * url and token; returns null otherwise so the caller falls back to config.php.
     *
     * @return array|null Active workspace list, or null to trigger fallback.
     */
    private function resolveFromRecord(): ?array
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        try {
            // Read the record directly through the DAO. Configurations\Service\FetchConfig is not
            // used on purpose: it merges the record with ConfigManager defaults, and ConfigManager
            // only knows the keys defined by the Swarm core, so it fails for 'mattermost'.
            $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
            $record  = $this->services->get(IDao::CONFIGURATION_DAO)
                ->fetchById(IConfig::MATTERMOST, $p4Admin)
                ->toArray();
        } catch (RecordNotFoundException $e) {
            $logger->debug(self::LOG_PREFIX . ': no Configurations record, using config.php');
            return null;
        } catch (\Throwable $e) {
            // Catches both Exception and Error (e.g. P4 extension not yet available at bootstrap).
            $logger->warn(
                self::LOG_PREFIX . ': unable to read the Configurations record, using config.php: '
                . $e->getMessage()
            );
            return null;
        }
        $workspaces = $record[IConfiguration::WORKSPACES] ?? null;
        if (!is_array($workspaces) || empty($workspaces)) {
            return null;
        }
        $active = [];
        foreach ($workspaces as $ws) {
            // Record keys are camelCase (IConfiguration); snake_case is accepted as well.
            if (!is_array($ws) || !empty($ws[IConfiguration::IS_DELETED]) || !empty($ws['is_deleted'])) {
                continue;
            }
            $id        = (string) ($ws[IConfig::ID] ?? IWorkspaceResolver::DEFAULT_ID);
            $workspace = $this->normalise($id, $this->recordToWorkspace($ws));
            if ($workspace !== null) {
                $active[] = $workspace;
            }
        }
        if (empty($active)) {
            $logger->debug(self::LOG_PREFIX . ': Configurations record has no usable workspace, using config.php');
            return null;
        }
        $logger->debug(
            self::LOG_PREFIX . ': loaded ' . count($active) . ' workspace(s) from the Configurations record'
        );
        return $active;
    }

    /**
     * Convert a workspace as stored in the Configurations record to the config.php shape read by the
     * Mattermost service and utility (snake_case keys, project_channels as a keyed dict).
     *
     * @param array $ws Record workspace; camelCase (record) and snake_case (config.php) keys are accepted.
     * @return array
     */
    private function recordToWorkspace(array $ws): array
    {
        // project_channels: array-of-objects [{project, channels}] -> keyed dict {'*all*': ['ch']}
        $pc = $ws[IConfiguration::PROJECT_CHANNELS] ?? $ws[IConfig::PROJECT_CHANNELS] ?? [];
        if (!empty($pc) && isset($pc[0]) && is_array($pc[0])) {
            $dict = [];
            foreach ($pc as $row) {
                if (!isset($row[IConfiguration::PROJECT], $row[IConfiguration::CHANNELS])) {
                    continue;
                }
                $project = trim((string) $row[IConfiguration::PROJECT]);
                // Normalise bare 'all' -> '*all*' for user convenience
                if ($project === 'all') {
                    $project = IUtility::ALL_NOTIFICATIONS_CHANNELS;
                }
                $dict[$project] = (array) $row[IConfiguration::CHANNELS];
            }
            $pc = $dict;
        }
        $user = (array) ($ws[IConfig::USER] ?? []);
        if (isset($user[IConfiguration::FORCE_USER_HEADER]) && !isset($user[IConfig::FORCE_USER_HEADER])) {
            $user[IConfig::FORCE_USER_HEADER] = $user[IConfiguration::FORCE_USER_HEADER];
        }
        return [
            IConfig::URL                          => $ws[IConfig::URL] ?? '',
            IConfig::TOKEN                        => $ws[IConfig::TOKEN] ?? '',
            IConfig::TEAM                         => $ws[IConfig::TEAM] ?? '',
            IConfig::PROJECT_CHANNELS             => $pc,
            IConfig::SUMMARY_FILE_NAMES           => (bool) ($ws[IConfiguration::SUMMARY_FILE_NAMES]
                ?? $ws[IConfig::SUMMARY_FILE_NAMES] ?? false),
            IConfig::REPLY_FILE_NAMES             => (bool) ($ws[IConfiguration::REPLY_FILE_NAMES]
                ?? $ws[IConfig::REPLY_FILE_NAMES] ?? false),
            IConfig::BYPASS_RESTRICTED_CHANGELIST => (bool) ($ws[IConfiguration::BYPASS_RESTRICTED_CHANGELIST]
                ?? $ws[IConfig::BYPASS_RESTRICTED_CHANGELIST] ?? false),
            IConfig::NOTIFY_MENTIONED_ONLY        => (bool) ($ws[IConfiguration::NOTIFY_MENTIONED_ONLY]
                ?? $ws[IConfig::NOTIFY_MENTIONED_ONLY] ?? false),
            IConfig::NOTIFY                       => (array) ($ws[IConfig::NOTIFY] ?? []),
            IConfig::SUMMARY_FILE_LIMIT           => (int) ($ws[IConfiguration::SUMMARY_FILE_LIMIT]
                ?? $ws[IConfig::SUMMARY_FILE_LIMIT] ?? 10),
            IConfig::USER                         => $user,
            IConfig::REACTIONS                    => (array) ($ws[IConfig::REACTIONS] ?? []),
        ];
    }

    /**
     * Resolves multi-server config into a list array.
     *
     * @param array $mattermost The full mattermost config block.
     * @return array List of workspace arrays with embedded 'id'.
     */
    private function resolveMulti(array $mattermost): array
    {
        $result = [];
        foreach ($mattermost as $id => $block) {
            if (!is_array($block) || !isset($block[IConfig::TOKEN])) {
                continue;
            }
            $workspace = $this->normalise((string) $id, $block);
            if ($workspace !== null) {
                $result[] = $workspace;
            }
        }
        return $result;
    }

    /**
     * Resolves a flat (single server) config block into a one-element list.
     *
     * @param array $mattermost The full flat mattermost config block.
     * @return array One-element list, or empty if no valid url/token found.
     */
    private function resolveLegacy(array $mattermost): array
    {
        $workspace = $this->normalise(self::DEFAULT_ID, $mattermost);
        return $workspace === null ? [] : [$workspace];
    }

    /**
     * Validate url/token and embed the id. Returns null (and logs) when the block is unusable.
     *
     * @param string $id    Workspace id
     * @param array  $block Config block
     * @return array|null
     */
    private function normalise(string $id, array $block): ?array
    {
        $logger = $this->services->get(SwarmLogger::SERVICE);
        $token  = trim((string) ($block[IConfig::TOKEN] ?? ''));
        $url    = rtrim(trim((string) ($block[IConfig::URL] ?? '')), '/');
        if ($token === '') {
            $logger->debug(sprintf('%s: workspace "%s" has a blank token, skipping', self::LOG_PREFIX, $id));
            return null;
        }
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            $logger->debug(
                sprintf(
                    '%s: workspace "%s" has no valid url (expected http(s)://host), skipping',
                    self::LOG_PREFIX,
                    $id
                )
            );
            return null;
        }
        return [IConfig::ID => $id, IConfig::URL => $url, IConfig::TOKEN => $token] + $block;
    }
}
