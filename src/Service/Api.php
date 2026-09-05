<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Service;

use Application\Config\IConfigDefinition as IDef;
use Application\Factory\InvokableService;
use Application\Log\SwarmLogger;
use Exception;
use Interop\Container\ContainerInterface;
use Laminas\Http\Client;
use Laminas\Http\Request;
use Mattermost\Config\IConfig;
use Redis\RedisService;

/**
 * Mattermost REST API v4 client.
 * @package Mattermost\Service
 */
class Api implements IApi, InvokableService
{
    const LOG_PREFIX   = Api::class;
    const API_PATH     = '/api/v4';
    // Cross-request lookups (users, channels, bot id) are cached in Redis for a day
    const CACHE_TTL    = 86400;
    const CACHE_PREFIX = 'notification-mattermost-';
    // Mattermost ids are 26 lowercase base32 characters
    const ID_PATTERN   = '/^[a-z0-9]{26}$/';
    // Empty string marks a confirmed miss so we do not hammer the API for unknown users/channels
    const NOT_FOUND    = '';
    // Misses are cached briefly so a channel the bot has just been invited to is picked up quickly
    const MISS_TTL     = 300;

    private $services;
    private $logger;
    private array $cache = [];

    /**
     * @param ContainerInterface $services
     * @param array|null         $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services = $services;
        $this->logger   = $services->get(SwarmLogger::SERVICE);
    }

    /**
     * @inheritDoc
     */
    public function request(
        string $method,
        string $path,
        array $workspace,
        $body = null,
        array $query = [],
        ?string $contentType = null
    ) {
        $baseUrl = rtrim(trim((string) ($workspace[IConfig::URL] ?? '')), '/');
        $token   = trim((string) ($workspace[IConfig::TOKEN] ?? ''));
        if ($baseUrl === '' || $token === '') {
            $this->logger->err(
                sprintf(
                    '%s: workspace "%s" has no url or token configured',
                    self::LOG_PREFIX,
                    $workspace[IConfig::ID] ?? '?'
                )
            );
            return null;
        }
        $uri = $baseUrl . self::API_PATH . '/' . ltrim($path, '/');
        $this->logger->debug(sprintf('%s: %s %s', self::LOG_PREFIX, $method, $uri));
        try {
            $request = new Request();
            $request->setMethod($method);
            $request->setUri($uri);
            foreach ($query as $key => $value) {
                $request->getQuery()->set($key, $value);
            }
            $headers = [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ];
            if ($body !== null) {
                if ($contentType === null) {
                    $body        = json_encode($body);
                    $contentType = 'application/json';
                    $this->logger->debug(sprintf('%s: request body %s', self::LOG_PREFIX, $body));
                }
                $headers['Content-Type'] = $contentType;
                $request->setContent($body);
            }
            $request->getHeaders()->addHeaders($headers);

            $client = $this->createHttpClient();
            $client->setOptions($this->getHttpClientOptions($request->getUri()->getHost()));
            $response = $client->dispatch($request);
            $rawBody  = (string) $response->getBody();
            $decoded  = json_decode($rawBody);

            if (!$response->isSuccess()) {
                // Mattermost error bodies look like {"id": "...", "message": "...", "status_code": 404}
                $reason = is_object($decoded) && isset($decoded->message) ? $decoded->message : $rawBody;
                $this->logger->err(
                    sprintf(
                        '%s: %s %s failed, HTTP %s %s: %s',
                        self::LOG_PREFIX,
                        $method,
                        $uri,
                        $response->getStatusCode(),
                        $response->getReasonPhrase(),
                        $reason
                    )
                );
                return null;
            }
            $this->logger->trace(sprintf('%s: response %s', self::LOG_PREFIX, $rawBody));
            return $decoded;
        } catch (Exception $e) {
            $this->logger->err(
                sprintf('%s: %s %s unexpected error %s', self::LOG_PREFIX, $method, $uri, $e->getMessage())
            );
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function createPost(array $post, array $workspace): ?object
    {
        $response = $this->request(Request::METHOD_POST, 'posts', $workspace, $post);
        return is_object($response) ? $response : null;
    }

    /**
     * @inheritDoc
     */
    public function resolveChannelId(string $channel, array $workspace): ?string
    {
        $channel = trim($channel);
        if (preg_match(self::ID_PATTERN, $channel)) {
            return $channel;
        }
        $name = ltrim($channel, '~#');
        $team = trim((string) ($workspace[IConfig::TEAM] ?? ''));
        if ($team === '') {
            $this->logger->err(
                sprintf(
                    '%s: cannot resolve channel "%s", workspace "%s" has no team configured',
                    self::LOG_PREFIX,
                    $channel,
                    $workspace[IConfig::ID] ?? '?'
                )
            );
            return null;
        }
        $cacheKey = $this->cacheKey($workspace, 'channel', $team . '/' . $name);
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            return $cached === self::NOT_FOUND ? null : $cached;
        }
        $response  = $this->request(
            Request::METHOD_GET,
            sprintf('teams/name/%s/channels/name/%s', rawurlencode($team), rawurlencode($name)),
            $workspace
        );
        $channelId = is_object($response) && !empty($response->id) ? (string) $response->id : null;
        if ($channelId === null) {
            $this->logger->warn(
                sprintf('%s: channel "%s" not found in team "%s"', self::LOG_PREFIX, $name, $team)
            );
        }
        $this->cacheSet(
            $cacheKey,
            $channelId ?? self::NOT_FOUND,
            $channelId === null ? self::MISS_TTL : self::CACHE_TTL
        );
        return $channelId;
    }

    /**
     * @inheritDoc
     */
    public function findUserByEmail(string $email, array $workspace): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $cacheKey = $this->cacheKey($workspace, 'user', strtolower($email));
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            if ($cached === self::NOT_FOUND) {
                return null;
            }
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['id'], $decoded['username'])) {
                return $decoded;
            }
        }
        $response = $this->request(
            Request::METHOD_GET,
            'users/email/' . rawurlencode($email),
            $workspace
        );
        $user     = null;
        if (is_object($response) && !empty($response->id) && !empty($response->username)) {
            $user = ['id' => (string) $response->id, 'username' => (string) $response->username];
        }
        $this->cacheSet(
            $cacheKey,
            $user === null ? self::NOT_FOUND : json_encode($user),
            $user === null ? self::MISS_TTL : self::CACHE_TTL
        );
        return $user;
    }

    /**
     * @inheritDoc
     */
    public function findUserByUsername(string $username, array $workspace): ?array
    {
        $username = strtolower(ltrim(trim($username), '@'));
        if ($username === '') {
            return null;
        }
        $cacheKey = $this->cacheKey($workspace, 'username', $username);
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null) {
            if ($cached === self::NOT_FOUND) {
                return null;
            }
            $decoded = json_decode($cached, true);
            if (is_array($decoded) && isset($decoded['id'], $decoded['username'])) {
                return $decoded;
            }
        }
        $response = $this->request(
            Request::METHOD_GET,
            'users/username/' . rawurlencode($username),
            $workspace
        );
        $user     = null;
        if (is_object($response) && !empty($response->id) && !empty($response->username)) {
            $user = ['id' => (string) $response->id, 'username' => (string) $response->username];
        }
        $this->cacheSet(
            $cacheKey,
            $user === null ? self::NOT_FOUND : json_encode($user),
            $user === null ? self::MISS_TTL : self::CACHE_TTL
        );
        return $user;
    }

    /**
     * @inheritDoc
     */
    public function getBotUserId(array $workspace): ?string
    {
        $cacheKey = $this->cacheKey($workspace, 'me', md5((string) ($workspace[IConfig::TOKEN] ?? '')));
        $cached   = $this->cacheGet($cacheKey);
        if ($cached !== null && $cached !== self::NOT_FOUND) {
            return $cached;
        }
        $response = $this->request(Request::METHOD_GET, 'users/me', $workspace);
        $id       = is_object($response) && !empty($response->id) ? (string) $response->id : null;
        if ($id !== null) {
            $this->cacheSet($cacheKey, $id);
        }
        return $id;
    }

    /**
     * @inheritDoc
     */
    public function addReaction(string $postId, string $emojiName, array $workspace): bool
    {
        $botId = $this->getBotUserId($workspace);
        if ($botId === null || $postId === '' || $emojiName === '') {
            return false;
        }
        $response = $this->request(
            Request::METHOD_POST,
            'reactions',
            $workspace,
            ['user_id' => $botId, 'post_id' => $postId, 'emoji_name' => trim($emojiName, ':')]
        );
        return is_object($response) && !empty($response->emoji_name);
    }

    /**
     * @inheritDoc
     */
    public function removeReaction(string $postId, string $emojiName, array $workspace): bool
    {
        $botId = $this->getBotUserId($workspace);
        if ($botId === null || $postId === '' || $emojiName === '') {
            return false;
        }
        $response = $this->request(
            Request::METHOD_DELETE,
            sprintf(
                'users/%s/posts/%s/reactions/%s',
                rawurlencode($botId),
                rawurlencode($postId),
                rawurlencode(trim($emojiName, ':'))
            ),
            $workspace
        );
        return $response !== null;
    }

    /**
     * @inheritDoc
     */
    public function getBotReactions(string $postId, array $workspace): array
    {
        $botId = $this->getBotUserId($workspace);
        if ($botId === null || $postId === '') {
            return [];
        }
        $response = $this->request(Request::METHOD_GET, 'posts/' . rawurlencode($postId) . '/reactions', $workspace);
        $names    = [];
        foreach (is_array($response) ? $response : [] as $reaction) {
            if (is_object($reaction) && ($reaction->user_id ?? '') === $botId && !empty($reaction->emoji_name)) {
                $names[] = (string) $reaction->emoji_name;
            }
        }
        return $names;
    }

    /**
     * @inheritDoc
     */
    public function openDirectChannel(string $userId, array $workspace): ?string
    {
        $botId = $this->getBotUserId($workspace);
        if ($botId === null) {
            $this->logger->warn(sprintf('%s: could not determine bot user id', self::LOG_PREFIX));
            return null;
        }
        $response = $this->request(Request::METHOD_POST, 'channels/direct', $workspace, [$botId, $userId]);
        if (is_object($response) && !empty($response->id)) {
            return (string) $response->id;
        }
        $this->logger->warn(sprintf('%s: could not open DM channel for %s', self::LOG_PREFIX, $userId));
        return null;
    }

    /**
     * @inheritDoc
     */
    public function uploadFile(
        string $channelId,
        string $filename,
        string $bytes,
        string $mimeType,
        array $workspace
    ): ?string {
        // Mattermost accepts the raw file as the request body when channel_id and filename are query params
        $response = $this->request(
            Request::METHOD_POST,
            'files',
            $workspace,
            $bytes,
            ['channel_id' => $channelId, 'filename' => $filename],
            $mimeType ?: 'application/octet-stream'
        );
        $fileId   = $response->file_infos[0]->id ?? null;
        if ($fileId === null) {
            $this->logger->err(sprintf('%s: no file id returned for upload of %s', self::LOG_PREFIX, $filename));
            return null;
        }
        return (string) $fileId;
    }

    /**
     * Create the HTTP client, separated so tests can stub it.
     *
     * @return Client
     */
    protected function createHttpClient(): Client
    {
        return new Client();
    }

    /**
     * Build http client options from config, applying per-host overrides.
     *
     * @param string $host The host the request goes to
     * @return array
     */
    private function getHttpClientOptions(string $host): array
    {
        $config  = $this->services->get(IDef::CONFIG);
        $options = (array) ($config['http_client_options'] ?? []);
        if (isset($options['hosts'][$host])) {
            $options = (array) $options['hosts'][$host] + $options;
        }
        unset($options['hosts']);
        return $options;
    }

    /**
     * Build a workspace-scoped cache key.
     */
    private function cacheKey(array $workspace, string $type, string $value): string
    {
        return self::CACHE_PREFIX . $type . '-' . ($workspace[IConfig::ID] ?? 'default') . '-' . $value;
    }

    /**
     * Read from the in-memory cache, falling back to Redis.
     *
     * @return string|null Cached value, or null when nothing is cached
     */
    private function cacheGet(string $key): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        try {
            $cached = $this->services->get(RedisService::class)->get($key);
            if ($cached !== null && $cached !== false) {
                $this->cache[$key] = (string) $cached;
                return $this->cache[$key];
            }
        } catch (Exception $e) {
            // Redis unavailable, in-memory cache only
        }
        return null;
    }

    /**
     * Write to the in-memory cache and Redis.
     */
    private function cacheSet(string $key, string $value, int $ttl = self::CACHE_TTL): void
    {
        $this->cache[$key] = $value;
        try {
            $this->services->get(RedisService::class)->set($key, $value, $ttl);
        } catch (Exception $e) {
            // Redis unavailable, in-memory cache only
        }
    }
}
