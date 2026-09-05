<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Service;

/**
 * Thin client for the Mattermost REST API (v4). All calls are scoped to a
 * workspace block which provides the server url and the bot/access token.
 * @package Mattermost\Service
 */
interface IApi
{
    const SERVICE_NAME = 'MattermostApi';

    /**
     * Perform an API request and return the decoded JSON response.
     *
     * @param string      $method      HTTP method (GET, POST, ...)
     * @param string      $path        Path relative to /api/v4, e.g. 'posts'
     * @param array       $workspace   Workspace block (url + token)
     * @param mixed       $body        Request body. Arrays/objects are JSON encoded unless $contentType is given,
     *                                 in which case the body is sent as-is (used for raw file uploads).
     * @param array       $query       Query string parameters
     * @param string|null $contentType Explicit content type for a raw body
     * @return mixed Decoded JSON (object or array) on success, null on any failure
     */
    public function request(
        string $method,
        string $path,
        array $workspace,
        $body = null,
        array $query = [],
        ?string $contentType = null
    );

    /**
     * Create a post. Body keys: channel_id, message, root_id, file_ids, props.
     *
     * @param array $post      The post body
     * @param array $workspace Workspace block
     * @return object|null The created post (with 'id'), or null on failure
     */
    public function createPost(array $post, array $workspace): ?object;

    /**
     * Resolve a configured channel (name or id) to a channel id. Channel names are looked up within
     * the workspace's team. Values already matching the Mattermost id format are returned as-is.
     *
     * @param string $channel   Channel name (with or without leading '~') or id
     * @param array  $workspace Workspace block
     * @return string|null Channel id, or null if it could not be resolved
     */
    public function resolveChannelId(string $channel, array $workspace): ?string;

    /**
     * Look up a Mattermost user by email.
     *
     * @param string $email     The email address
     * @param array  $workspace Workspace block
     * @return array|null ['id' => ..., 'username' => ...] or null when not found
     */
    public function findUserByEmail(string $email, array $workspace): ?array;

    /**
     * Look up a Mattermost user by username. Unlike the email lookup this works for any
     * account, regardless of the "show email address" privacy setting of the server.
     *
     * @param string $username  The Mattermost username (without the leading @)
     * @param array  $workspace Workspace block
     * @return array|null ['id' => ..., 'username' => ...] or null when not found
     */
    public function findUserByUsername(string $username, array $workspace): ?array;

    /**
     * The user id of the account owning the workspace token.
     *
     * @param array $workspace Workspace block
     * @return string|null
     */
    public function getBotUserId(array $workspace): ?string;

    /**
     * Add an emoji reaction from the bot account to a post.
     *
     * @param string $postId    Post id
     * @param string $emojiName Emoji name without colons, e.g. '+1'
     * @param array  $workspace Workspace block
     * @return bool True when the reaction was added
     */
    public function addReaction(string $postId, string $emojiName, array $workspace): bool;

    /**
     * Remove the bot account's emoji reaction from a post.
     *
     * @param string $postId    Post id
     * @param string $emojiName Emoji name without colons
     * @param array  $workspace Workspace block
     * @return bool True when the reaction was removed
     */
    public function removeReaction(string $postId, string $emojiName, array $workspace): bool;

    /**
     * Emoji names of the reactions the bot account currently has on a post.
     *
     * @param string $postId    Post id
     * @param array  $workspace Workspace block
     * @return array List of emoji names
     */
    public function getBotReactions(string $postId, array $workspace): array;

    /**
     * Open (or fetch) a direct message channel between the bot and the given user.
     *
     * @param string $userId    Mattermost user id
     * @param array  $workspace Workspace block
     * @return string|null The DM channel id or null on failure
     */
    public function openDirectChannel(string $userId, array $workspace): ?string;

    /**
     * Upload a file so it can be attached to a post in the given channel.
     *
     * @param string $channelId Channel the file will be posted to
     * @param string $filename  File name
     * @param string $bytes     Raw file content
     * @param string $mimeType  MIME type of the content
     * @param array  $workspace Workspace block
     * @return string|null The file id or null on failure
     */
    public function uploadFile(
        string $channelId,
        string $filename,
        string $bytes,
        string $mimeType,
        array $workspace
    ): ?string;
}
