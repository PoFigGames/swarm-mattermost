<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Listener;

use Application\Config\ConfigException;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Application\Log\SwarmLogger;
use Attachments\Model\Attachment;
use Comments\Model\Comment as CommentModel;
use Events\Listener\AbstractEventListener;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Mattermost\Model\Mattermost;
use Mattermost\Service\IMattermost;
use Mattermost\Service\IWorkspaceResolver;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Record\Exception\NotFoundException;
use Record\Lock\Lock;
use Reviews\Model\IReview;
use Reviews\Model\Review;

/**
 * Comment listener that forwards review comments to the Mattermost service.
 */
class Comment extends AbstractEventListener
{
    const LOG_PREFIX = Comment::class;

    protected mixed $mattermostService = null;

    /**
     * Ensure we get a service locator and event config on construction.
     *
     * @param ServiceLocator $services    the service locator to use
     * @param array          $eventConfig the event config for this listener
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->mattermostService = $this->services->get(IMattermost::SERVICE_NAME);
    }

    /**
     * Only attach when at least one Mattermost server is configured with a valid url and token.
     *
     * @param  mixed $eventName   the event name (unused; required to match parent signature)
     * @param  mixed $eventDetail the event detail (unused; required to match parent signature)
     * @return bool
     */
    protected function shouldAttach($eventName, $eventDetail): bool
    {
        // Always attach. The configured servers live in a Perforce-backed record, and Perforce
        // cannot be used this early in the bootstrap (Swarm has not defined VERSION_NAME yet),
        // so the check is done when the event is handled, see hasConfiguredWorkspace().
        return true;
    }

    /**
     * Whether at least one Mattermost server with a valid url and token is configured.
     * Evaluated at event time, when Perforce is available.
     *
     * @return bool
     */
    protected function hasConfiguredWorkspace(): bool
    {
        try {
            return $this->services->get(IWorkspaceResolver::SERVICE_NAME)->hasConfiguredWorkspace();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * This function is called on a review comment event
     *
     * @param Event $event
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     * @throws ConfigException
     */
    public function handleReview(Event $event): void
    {
        if (!$this->hasConfiguredWorkspace()) {
            return;
        }
        parent::log($event);
        $services = $this->services;
        $logger   = $services->get(SwarmLogger::SERVICE);
        $id       = $event->getParam(IReview::FIELD_ID);
        $activity = $event->getParam('activity');
        $p4Admin  = $services->get(ConnectionFactory::P4_ADMIN);

        try {
            // fetch comment record
            $comment = CommentModel::fetch($id, $p4Admin);
            $topic   = $comment->get('topic');
            // handle review comments
            if (str_starts_with($topic, 'reviews/')) {
                $context  = $comment->getFileContext();
                $review   = $context['review'] ?: str_replace('reviews/', '', $topic);
                $review   = Review::fetch($review, $p4Admin);
                $reviewId = $review->getId();
                $lock     = new Lock(Mattermost::KEY_PREFIX . "review-" . $reviewId, $p4Admin);
                try {
                    $lock->lock();
                    $changeDAO        = $this->services->get(IDao::CHANGE_DAO);
                    $change           = $changeDAO->fetchById($review->getChanges()[0], $p4Admin);
                    $imageAttachments = [];
                    foreach ($comment->getAttachments() as $attachmentId) {
                        try {
                            $attachment = Attachment::fetchById($attachmentId, $p4Admin);
                            if ($attachment->isWebSafeImage()) {
                                $imageAttachments[] = $attachment;
                            }
                        } catch (NotFoundException $e) {
                            $logger->err(
                                sprintf(
                                    "%s: Attachment %s not found: %s",
                                    self::LOG_PREFIX,
                                    $attachmentId,
                                    $e->getMessage()
                                )
                            );
                        }
                    }
                    $this->mattermostService->handleThreadedMessage($change, $activity, $review, $imageAttachments);
                } catch (Exception $e) {
                    $logger->err($e);
                } finally {
                    $lock->unlock();
                }
            }
        } catch (Exception $e) {
            $logger->err($e);
        }
    }
}
