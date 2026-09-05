<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Service;

use Activity\Model\Activity;
use Activity\Model\IActivity;
use Application\Config\IConfigDefinition as IDef;
use Application\Config\IDao;
use Application\Config\Services;
use Application\Connection\ConnectionFactory;
use Application\Factory\InvokableService;
use Application\Filter\Linkify;
use Application\Log\SwarmLogger;
use Attachments\Model\Attachment;
use Exception;
use Interop\Container\ContainerInterface;
use Mail\MailAction;
use Mattermost\Config\IConfig;
use Mattermost\Model\IMattermostDAO;
use Mattermost\Model\Mattermost as MattermostModel;
use Mattermost\Model\Message;
use Notifications\Model\INotification;
use P4\Spec\Change;
use Projects\Model\Project;
use Record\Key\AbstractKey;
use Reviews\Model\Review;

/**
 * Class MattermostService.
 * @package Mattermost\Service
 */
class Mattermost implements IMattermost, InvokableService
{
    private const LOG_PREFIX = Mattermost::class;
    // Mattermost allows at most this many attachments on a single post
    public const MAX_FILES_PER_POST = 10;

    private $services;
    private $logger;
    private $utility;
    private $workspaceResolver;
    private $api;
    private $purePrivate = false;

    /**
     * Mattermost service constructor.
     * @param ContainerInterface $services
     * @param array|null         $options
     */
    public function __construct(ContainerInterface $services, array $options = null)
    {
        $this->services          = $services;
        $this->logger            = $services->get(SwarmLogger::SERVICE);
        $this->utility           = $services->get(IUtility::SERVICE_NAME);
        $this->workspaceResolver = $services->get(IWorkspaceResolver::SERVICE_NAME);
        $this->api               = $services->get(IApi::SERVICE_NAME);
    }

    /**
     * @inheritDoc
     */
    public function handleCreateMessage(Change $change, Activity $activity, $review = null, $projects = null)
    {
        $topic = $this->getTopic($change, $review);
        $dao   = $this->services->get(IMattermostDAO::SERVICE_NAME);
        $p4    = $this->services->get(ConnectionFactory::P4_ADMIN);

        $this->logger->trace(sprintf("%s: Topic %s is running handleCreateMessage", self::LOG_PREFIX, $topic));

        // If we have a model just return early as we shouldn't be creating a new post if it exists.
        if ($dao->exists($topic, $p4)) {
            return;
        }

        $type       = $change->getType();
        $workspaces = $this->workspaceResolver->getWorkspaces();
        $callouts   = Linkify::getCallouts($change->getDescription());
        foreach ($workspaces as $workspace) {
            $bypass = $workspace[IConfig::BYPASS_RESTRICTED_CHANGELIST] ?? false;
            if ($type !== Change::PUBLIC_CHANGE && !$bypass) {
                $this->logger->debug(sprintf("%s: Not allowed to post private information...", self::LOG_PREFIX));
                continue;
            }
            if ($projects === null) {
                $projects = $this->getProjects($change, $review);
            }

            // DM-only path first: it avoids the P4 DAO + API calls needed to build channel mentions.
            // User ids are server-scoped, so resolve them per workspace.
            $notifyMentionedOnly = $workspace[IConfig::NOTIFY_MENTIONED_ONLY] ?? false;
            if ($notifyMentionedOnly && !empty($callouts)) {
                $userIds = $this->utility->buildMentionsFromUserIds($callouts, $workspace);
                if (!empty($userIds)) {
                    $message = new Message($this->services, $change, $projects, $review, $activity, '', $workspace);
                    $this->dmMentionedUsers($userIds, $message, null, $workspace);
                    $this->saveEmptyModel($topic);
                    continue;
                }
            }

            // Only resolve channel @mentions when the channel path will execute.
            $mentions = $this->utility->buildMentions(
                $workspace[IConfig::NOTIFY] ?? [],
                $change,
                $projects,
                $review,
                $workspace
            );
            $message  = new Message($this->services, $change, $projects, $review, $activity, $mentions, $workspace);

            $channelThreads  = null;
            $projectChannels = $this->utility->getProjectMappings(
                $projects,
                $workspace[IConfig::PROJECT_CHANNELS] ?? [],
                $this->purePrivate
            );
            $this->logger->trace(
                sprintf("%s: ProjectChannels are: %s", self::LOG_PREFIX, json_encode($projectChannels))
            );
            foreach ($projectChannels as $channel => $linkedProjects) {
                $this->postSummary($message, (string) $channel, $linkedProjects, $workspace, $channelThreads);
            }
            $this->createModel($change, $channelThreads, $review);
        }
    }

    /**
     * @inheritDoc
     */
    public function handleMissingMessage(
        Change $change,
        string $channel,
        Review $review,
        array $workspace,
        array $projects = null,
        array $linkedProjects = []
    ): ?array {
        $bypass = $workspace[IConfig::BYPASS_RESTRICTED_CHANGELIST] ?? false;
        if ($change->getType() !== Change::PUBLIC_CHANGE && !$bypass) {
            $this->logger->debug(sprintf("%s: Not allowed to post private information...", self::LOG_PREFIX));
            return null;
        }
        $message        = new Message($this->services, $change, $projects, $review, null, '', $workspace);
        $channelThreads = null;
        $this->postSummary($message, $channel, $linkedProjects, $workspace, $channelThreads);
        $this->createModel($change, $channelThreads, $review);
        return $channelThreads[$this->getCompositeKey($workspace, $channel)] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function handleThreadedMessage(
        $change,
        $activity,
        Review $review = null,
        array $imageAttachments = []
    ) {
        $topic    = $this->getTopic($change, $review);
        $projects = $this->getProjects($change, $review);
        $dao      = $this->services->get(IMattermostDAO::SERVICE_NAME);
        $p4       = $this->services->get(ConnectionFactory::P4_ADMIN);

        $this->logger->trace(sprintf("%s: Topic %s is running handleThreadedMessage", self::LOG_PREFIX, $topic));

        // If we have no model for this topic attempt to create it.
        if (!$dao->exists($topic, $p4)) {
            $this->logger->warn(sprintf("%s: No threaded ids for %s", self::LOG_PREFIX, $topic));
            $this->handleCreateMessage($change, $activity, $review, $projects);
            if (!$dao->exists($topic, $p4)) {
                $this->logger->warn(sprintf("%s: Nothing was posted for %s, skipping reply", self::LOG_PREFIX, $topic));
                return;
            }
        }
        $threadIds = $dao->fetchById($topic, $p4)->getThreadIds() ?: [];

        $workspaces = $this->workspaceResolver->getWorkspaces();
        foreach ($workspaces as $workspace) {
            $message             = new Message($this->services, $change, $projects, $review, null, '', $workspace);
            $notifyMentionedOnly = $workspace[IConfig::NOTIFY_MENTIONED_ONLY] ?? false;
            if ($notifyMentionedOnly && $activity !== null) {
                $details  = $activity->getRawValue(IActivity::DETAILS);
                $desc     = (is_array($details) && !empty($details['transitionComment']))
                    ? (string) $details['transitionComment']
                    : (string) $activity->getRawValue(IActivity::DESCRIPTION);
                $callouts = Linkify::getCallouts($desc);
                if (!empty($callouts)) {
                    $userIds = $this->utility->buildMentionsFromUserIds($callouts, $workspace);
                    if (!empty($userIds)) {
                        $this->dmMentionedUsers($userIds, $message, $activity, $workspace);
                        continue;
                    }
                }
            }

            // The initial post was DM-only (empty threadIds saved by handleCreateMessage). Falling through to
            // the channel loop would trigger handleMissingMessage and create a channel post, violating the
            // DM-only guarantee.
            if ($notifyMentionedOnly && empty($threadIds)) {
                continue;
            }

            $projectChannels = $this->utility->getProjectMappings(
                $projects,
                $workspace[IConfig::PROJECT_CHANNELS] ?? [],
                $this->purePrivate
            );
            $this->logger->trace(
                sprintf("%s: ProjectChannels are: %s", self::LOG_PREFIX, json_encode($projectChannels))
            );
            foreach ($projectChannels as $channel => $linkedProjects) {
                $channel = (string) $channel;
                $thread  = $threadIds[$this->getCompositeKey($workspace, $channel)] ?? null;
                // handleMissingMessage requires a Review; commit-based events cannot recover a missing thread.
                if (empty($thread[MattermostModel::THREAD_ID]) && $review !== null) {
                    $thread = $this->handleMissingMessage(
                        $change,
                        $channel,
                        $review,
                        $workspace,
                        $projects,
                        $linkedProjects
                    );
                }
                if (empty($thread[MattermostModel::THREAD_ID])) {
                    $this->logger->warn(sprintf("%s: No threaded ids for %s", self::LOG_PREFIX, $channel));
                    continue;
                }
                $channelId = $thread[MattermostModel::CHANNEL_ID] ?? $this->api->resolveChannelId($channel, $workspace);
                if (!$channelId) {
                    $this->logger->warn(sprintf("%s: No channel id for %s", self::LOG_PREFIX, $channel));
                    continue;
                }
                $this->logger->debug(sprintf("%s: replying in %s", self::LOG_PREFIX, $channel));
                $rootId = $thread[MattermostModel::THREAD_ID];
                $reply  = $message->getReply($activity, $channelId, $rootId, $linkedProjects);
                $this->attachImages($reply, $imageAttachments, $channelId, $workspace);
                $this->postMessage($reply, $workspace);
                $this->applyStateReaction($activity, $rootId, $workspace);
            }
        }
    }

    /**
     * Mirror a review state change as an emoji reaction on the root post. Only one state
     * reaction is kept: the reactions configured for the other states are removed first.
     *
     * @param Activity|null $activity  The activity that triggered the reply
     * @param string        $rootId    The root post id
     * @param array         $workspace The workspace block
     * @return void
     */
    private function applyStateReaction($activity, string $rootId, array $workspace): void
    {
        if (!$activity instanceof Activity) {
            return;
        }
        $stateByAction = [
            MailAction::REVIEW_APPROVED       => IConfig::REACTION_APPROVED,
            MailAction::REVIEW_REJECTED       => IConfig::REACTION_REJECTED,
            MailAction::REVIEW_ARCHIVED       => IConfig::REACTION_ARCHIVED,
            MailAction::REVIEW_NEEDS_REVIEW   => IConfig::REACTION_NEEDS_REVIEW,
            MailAction::REVIEW_NEEDS_REVISION => IConfig::REACTION_NEEDS_REVISION,
        ];
        $action = (string) $activity->getRawValue(IActivity::ACTION);
        if (!isset($stateByAction[$action])) {
            return;
        }
        $reactions = $this->getReactionConfig($workspace);
        $wanted    = trim((string) ($reactions[$stateByAction[$action]] ?? ''), ': ');
        try {
            $current = $this->api->getBotReactions($rootId, $workspace);
            foreach (array_unique(array_filter($reactions)) as $emoji) {
                $emoji = trim((string) $emoji, ': ');
                if ($emoji !== '' && $emoji !== $wanted && in_array($emoji, $current, true)) {
                    $this->api->removeReaction($rootId, $emoji, $workspace);
                }
            }
            if ($wanted !== '' && !in_array($wanted, $current, true)) {
                $this->logger->debug(sprintf('%s: reacting :%s: on post %s', self::LOG_PREFIX, $wanted, $rootId));
                $this->api->addReaction($rootId, $wanted, $workspace);
            }
        } catch (Exception $e) {
            $this->logger->err(sprintf('%s: reaction failed: %s', self::LOG_PREFIX, $e->getMessage()));
        }
    }

    /**
     * Reactions for a workspace: workspace block, then the global 'mattermost.reactions' block
     * of config.php, then the built-in defaults.
     *
     * @param array $workspace The workspace block
     * @return array state => emoji name
     */
    private function getReactionConfig(array $workspace): array
    {
        $config = $this->services->get(IDef::CONFIG);
        $global = $config[IConfig::MATTERMOST][IConfig::REACTIONS] ?? [];
        $local  = $workspace[IConfig::REACTIONS] ?? [];
        return array_merge(
            IConfig::DEFAULT_REACTIONS,
            is_array($global) ? $global : [],
            is_array($local) ? $local : []
        );
    }

    /**
     * Resolve the channel, post the summary and record the thread. Optionally posts the file list as a reply.
     *
     * @param Message    $message        The message builder
     * @param string     $channel        The configured channel (name or id)
     * @param array      $linkedProjects The projects linked to this channel
     * @param array      $workspace      The workspace block
     * @param array|null $channelThreads Accumulator of composite key => thread data
     * @return void
     */
    private function postSummary(
        Message $message,
        string $channel,
        array $linkedProjects,
        array $workspace,
        ?array &$channelThreads
    ): void {
        $channelId = $this->api->resolveChannelId($channel, $workspace);
        if ($channelId === null) {
            $this->logger->warn(sprintf("%s: skipping unresolved channel %s", self::LOG_PREFIX, $channel));
            return;
        }
        $this->logger->debug(sprintf("%s: posting to %s", self::LOG_PREFIX, $channel));
        $post   = $this->postMessage($message->getSummary($channelId, $linkedProjects), $workspace);
        $postId = $post->id ?? null;
        if (!$postId) {
            $this->logger->debug(sprintf("%s: found no post id in response for %s", self::LOG_PREFIX, $channel));
            return;
        }
        $channelThreads[$this->getCompositeKey($workspace, $channel)] = [
            MattermostModel::THREAD_ID  => (string) $postId,
            MattermostModel::CHANNEL_ID => $channelId,
        ];
        if (($workspace[IConfig::REPLY_FILE_NAMES] ?? false) === true) {
            $fileReply = $message->getFileNamesReply($channelId, (string) $postId);
            if ($fileReply !== null) {
                $this->postMessage($fileReply, $workspace);
            }
        }
    }

    /**
     * Upload each web-safe image attachment and attach it to the reply via file_ids so the images render
     * inline within the same comment post.
     *
     * @param array  $reply            The post body to add file ids to.
     * @param array  $imageAttachments Web-safe image Attachment objects to upload.
     * @param string $channelId        The channel the reply is posted to.
     * @param array  $workspace        The workspace block.
     * @return void
     */
    private function attachImages(array &$reply, array $imageAttachments, string $channelId, array $workspace): void
    {
        $fileIds = [];
        foreach ($imageAttachments as $attachment) {
            if (count($fileIds) >= self::MAX_FILES_PER_POST) {
                $this->logger->warn(
                    sprintf(
                        "%s: more than %d attachments, the rest are dropped",
                        self::LOG_PREFIX,
                        self::MAX_FILES_PER_POST
                    )
                );
                break;
            }
            $fileId = $this->uploadAttachment($attachment, $channelId, $workspace);
            if ($fileId) {
                $fileIds[] = $fileId;
            }
        }
        if ($fileIds) {
            $reply['file_ids'] = $fileIds;
        }
    }

    /**
     * Upload an attachment to Mattermost and return its file id. Any failure is logged and null is
     * returned so the parent notification is not aborted.
     *
     * @param Attachment $attachment The attachment record to upload.
     * @param string     $channelId  The channel the file will be posted to.
     * @param array      $workspace  The workspace block.
     * @return string|null The Mattermost file id, or null on failure.
     */
    public function uploadAttachment(Attachment $attachment, string $channelId, array $workspace): ?string
    {
        try {
            $name     = (string) $attachment->get(Attachment::FIELD_NAME);
            $mimeType = (string) $attachment->get(Attachment::FIELD_TYPE);
            $bytes    = $this->services->get('depot_storage')->read($attachment->get(Attachment::FIELD_DEPOT_FILE));
            $fileId   = $this->api->uploadFile($channelId, $name, $bytes, $mimeType, $workspace);
            if ($fileId) {
                $this->logger->debug(sprintf("%s: uploaded %s (file_id=%s)", self::LOG_PREFIX, $name, $fileId));
            }
            return $fileId;
        } catch (Exception $e) {
            $this->logger->err(sprintf("%s: uploadAttachment failed: %s", self::LOG_PREFIX, $e->getMessage()));
            return null;
        }
    }

    /**
     * @inheritDoc
     */
    public function postMessage(array $body, array $workspace): ?object
    {
        $this->logger->debug(sprintf("%s: posting message", self::SERVICE_NAME));
        $userConfig = $workspace[IConfig::USER] ?? [];
        if (($userConfig[IConfig::ENABLED] ?? true) === true) {
            $props = [];
            $name  = $userConfig[IConfig::NAME] ?? null;
            $icon  = $userConfig[IConfig::ICON] ?? null;
            if ($name) {
                // Mattermost collapses consecutive posts from the same user; a changing username keeps the header
                if (($userConfig[IConfig::FORCE_USER_HEADER] ?? false) === true) {
                    $name = $name . "-" . $this->getTime();
                }
                $props['override_username'] = $name;
            }
            if ($icon) {
                $props['override_icon_url'] = $icon;
            }
            if ($props) {
                // The server only honours the overrides when the post is flagged as coming from a webhook
                $props['from_webhook'] = 'true';
                $body['props']         = $props + (array) ($body['props'] ?? []);
            }
        }
        return $this->api->createPost($body, $workspace);
    }

    /**
     * This is for only tests to be mock.
     * @return int
     */
    public function getTime(): int
    {
        return time();
    }

    /**
     * Sleep wrapper so the DM pacing can be stubbed out in tests.
     *
     * @param int $seconds Number of seconds to sleep.
     * @return void
     */
    protected function sleep(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * Key used in the stored thread ids for a workspace/channel pair.
     */
    private function getCompositeKey(array $workspace, string $channel): string
    {
        return ($workspace[IConfig::ID] ?? IWorkspaceResolver::DEFAULT_ID) . ':' . $channel;
    }

    /**
     * If we have channel threads to record we should do that now.
     *
     * @param Change      $change         - The changelist object
     * @param array|null  $channelThreads - The array of composite keys and their thread data.
     * @param Review|null $review         - If set, the changelist object represents a review rather than a change
     * @return void
     */
    private function createModel(Change $change, ?array $channelThreads, ?Review $review): void
    {
        if (!$channelThreads) {
            return;
        }
        $id = $this->getTopic($change, $review);
        try {
            $this->logger->debug(sprintf("%s: Creating a thread ID for %s", self::LOG_PREFIX, $id));
            $p4  = $this->services->get(ConnectionFactory::P4_ADMIN);
            $dao = $this->services->get(IMattermostDAO::SERVICE_NAME);
            if ($dao->exists($id, $p4)) {
                $model = $dao->fetchById($id, $p4);
                $model->setThreadIds(array_merge($channelThreads, $model->getThreadIds() ?: []));
            } else {
                $model = new MattermostModel($p4, $id);
                $model->setThreadIds($channelThreads);
            }
            $dao->save($model);
        } catch (Exception $saveThreadError) {
            $this->logger->debug(sprintf("%s: failed to create a thread ID for %s", self::LOG_PREFIX, $id));
            $this->logger->debug($saveThreadError->getMessage());
        }
    }

    /**
     * Record that a topic was handled (DM-only) without any channel thread.
     *
     * @param string $topic The topic
     * @return void
     */
    private function saveEmptyModel(string $topic): void
    {
        try {
            $p4  = $this->services->get(ConnectionFactory::P4_ADMIN);
            $dao = $this->services->get(IMattermostDAO::SERVICE_NAME);
            if (!$dao->exists($topic, $p4)) {
                $model = new MattermostModel($p4, $topic);
                $model->setThreadIds([]);
                $dao->save($model);
            }
        } catch (Exception $e) {
            $this->logger->debug(sprintf('%s: could not save empty model for %s', self::LOG_PREFIX, $topic));
        }
    }

    /**
     * If the review is not null get the projects from the review, otherwise find the affected projects
     * based on the change
     * @param Change $change the change
     * @param mixed  $review the review
     * @return mixed
     */
    private function getProjects(Change $change, $review = null)
    {
        $services = $this->services;
        $p4       = $services->get(ConnectionFactory::P4_ADMIN);

        if ($review !== null) {
            $projects = $review->getProjects();
        } else {
            $affected = $services->get(Services::AFFECTED_PROJECTS);
            $projects = $affected->findByChange($p4, $change);
        }
        // If we have projects we will fetch all the projects and filter out private projects. If we are returned no
        // projects we can assume that all projects are private. If one is returned then one of the linked projects is
        // public, so we can publish the message to the public.
        if ($projects) {
            $projectDAO        = $services->get(IDao::PROJECT_DAO);
            $this->purePrivate = !$projectDAO->fetchAll(
                [
                    AbstractKey::FETCH_BY_IDS      => array_unique(array_keys($projects)),
                    Project::FETCH_INCLUDE_PRIVATE => false
                ],
                $p4
            )->count();
        }
        return $projects;
    }

    /**
     * Return the topic.
     *
     * @param Change      $change - The changelist object
     * @param Review|null $review - If set, the changelist object represents a review rather than a change
     * @return string
     */
    public function getTopic(Change $change, ?Review $review): string
    {
        return $review
            ? INotification::REVIEW_TOPIC . $review->getId() : INotification::CHANGE_TOPIC . $change->getId();
    }

    /**
     * Post a message to each mentioned user via a direct message.
     *
     * @param array         $userIds   Resolved Mattermost user ids.
     * @param Message       $message   The message to send.
     * @param Activity|null $activity  Activity for reply; null uses summary.
     * @param array         $workspace The workspace block.
     * @return void
     */
    private function dmMentionedUsers(array $userIds, Message $message, ?Activity $activity, array $workspace): void
    {
        $total = count($userIds);
        $i     = 0;
        foreach ($userIds as $userId) {
            $i++;
            $channelId = $this->api->openDirectChannel($userId, $workspace);
            if ($channelId === null) {
                continue;
            }
            $payload = $activity === null
                ? $message->getSummary($channelId, [])
                : $message->getReply($activity, $channelId, '', []);
            $this->postMessage($payload, $workspace);
            if ($i < $total) {
                $this->sleep(1);
            }
        }
    }
}
