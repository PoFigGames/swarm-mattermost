<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */

namespace Mattermost\Listener;

use Activity\Model\IActivity;
use Application\Config\IDao;
use Application\Connection\ConnectionFactory;
use Events\Listener\AbstractEventListener;
use Exception;
use Laminas\EventManager\Event;
use Laminas\ServiceManager\ServiceLocatorInterface as ServiceLocator;
use Mail\MailAction;
use Mattermost\Model\Mattermost;
use Mattermost\Service\IMattermost;
use Mattermost\Service\IWorkspaceResolver;
use P4\Connection\Exception\CommandException;
use P4\Spec\Change;
use P4\Spec\Exception\Exception as P4SpecException;
use P4\Spec\Exception\NotFoundException;
use Record\Lock\Lock;
use Reviews\Filter\Keywords;
use Reviews\Model\IReview;
use Reviews\Model\Review;

/**
 * Activity listener that forwards review/commit events to the Mattermost service.
 */
class Activity extends AbstractEventListener
{
    const LOG_PREFIX = Activity::class;

    protected $mattermostService = null;

    /**
     * Ensure we get a service locator and event config on construction.
     *
     * @param   ServiceLocator  $services       the service locator to use
     * @param   array           $eventConfig    the event config for this listener
     */
    public function __construct(ServiceLocator $services, array $eventConfig)
    {
        parent::__construct($services, $eventConfig);
        $this->mattermostService = $this->services->get(IMattermost::SERVICE_NAME);
    }

    /**
     * Only attach when at least one Mattermost server is configured with a valid url and token.
     * Without this guard the listener fires in environments that have no Mattermost config,
     * producing ERR/WARN log entries.
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
     * This function is called when a review is created or updated
     *
     * @param Event $event
     */
    public function handleReview(Event $event)
    {
        if (!$this->hasConfiguredWorkspace()) {
            return;
        }
        $id       = $event->getParam(IReview::FIELD_ID);
        $activity = $event->getParam('activity');
        $quiet    = $event->getParam('quiet');
        $data     = (array) $event->getParam('data') + ['quiet' => null];
        if ($activity && (!$quiet && !$data['quiet'])) {
            $action  = $activity->getRawValue(IActivity::ACTION);
            $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);
            $lock    = new Lock(Mattermost::KEY_PREFIX . "review-" . $id, $p4Admin);
            try {
                $this->logger->debug(sprintf("%s: handle review id %s", self::LOG_PREFIX, $id));
                $review = $event->getParam('review');
                if (!$review instanceof Review) {
                    $reviewDAO = $this->services->get(IDao::REVIEW_DAO);
                    // We fetch the unfiltered review so the listener has all information at hand.
                    $review = $reviewDAO->fetchByIdUnrestricted($id, $p4Admin);
                    $event->setParam('review', $review);
                }
                $changeDAO = $this->services->get(IDao::CHANGE_DAO);
                $change    = $changeDAO->fetchById($review->getChanges()[0], $p4Admin);

                // Only requested reviews create a root post, everything else goes into the thread.
                $this->logger->trace(sprintf("%s: Review %s is action of %s", self::LOG_PREFIX, $id, $action));
                $lock->lock();
                if ($action === IActivity::REQUESTED) {
                    $this->mattermostService->handleCreateMessage($change, $activity, $review);
                } elseif (in_array($action, [MailAction::REVIEW_TESTS, MailAction::REVIEW_TESTS_NO_AUTH])) {
                    $this->logger->trace(
                        sprintf("%s: Review %s, ignoring action '%s'", self::LOG_PREFIX, $id, $action)
                    );
                } else {
                    $this->mattermostService->handleThreadedMessage($change, $activity, $review);
                }
            } catch (NotFoundException $notFoundError) {
                $this->logger->err(
                    sprintf("%s: unexpected error %s", self::LOG_PREFIX, $notFoundError->getMessage())
                );
            } catch (\Exception $e) {
                $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
            } finally {
                try {
                    $lock->unlock();
                } catch (CommandException $unlockError) {
                    $this->logger->err(
                        sprintf("%s: unlock failed for review %s", self::LOG_PREFIX, $unlockError->getMessage())
                    );
                }
            }
        } else {
            $this->logger->debug(
                sprintf("%s: no activity to process in handleReview for review id %s", self::LOG_PREFIX, $id)
            );
        }
    }

    /**
     * This function is called when a changelist is committed, this also happens when a review is created
     *
     * @param Event $event
     */
    public function handleCommit(Event $event)
    {
        if (!$this->hasConfiguredWorkspace()) {
            return;
        }
        $p4Admin = $this->services->get(ConnectionFactory::P4_ADMIN);

        $this->logger->debug(sprintf("%s: handle commit", self::LOG_PREFIX));

        // task.change doesn't include the change object; fetch it if we need to
        $change   = $event->getParam('change');
        $activity = $event->getParam('activity');
        $quiet    = $event->getParam('quiet');
        if ($activity && !$quiet) {
            try {
                if (!$change instanceof Change) {
                    $changeDAO = $this->services->get(IDao::CHANGE_DAO);
                    $change    = $changeDAO->fetchById($event->getParam(IReview::FIELD_ID), $p4Admin);
                    $event->setParam('change', $change);
                }
                // don't send the message if the commit is related to the review (stops copied messages)
                $keywords = $this->services->get(Keywords::SERVICE);
                $matches  = $keywords->getMatches($change->getRawValue('Description'));
                if ($matches && $matches[IReview::FIELD_ID]) {
                    $this->logger->debug(
                        sprintf("%s: committing review %s, do nothing", self::LOG_PREFIX, $matches[IReview::FIELD_ID])
                    );
                    return;
                }
                $lock = new Lock(Mattermost::KEY_PREFIX . "commit-" . $change->getId(), $p4Admin);
                try {
                    $lock->lock();
                    $this->mattermostService->handleCreateMessage($change, $activity);
                } catch (Exception $e) {
                    $this->logger->err(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $e->getMessage()));
                } finally {
                    try {
                        $lock->unlock();
                    } catch (CommandException $unlockError) {
                        $this->logger->err(
                            sprintf("%s: unlock failed for commit %s", self::LOG_PREFIX, $unlockError->getMessage())
                        );
                    }
                }
            } catch (NotFoundException $notFoundExceptionError) {
                $this->logger->debug(
                    sprintf("%s: unexpected error %s", self::LOG_PREFIX, $notFoundExceptionError->getMessage())
                );
            } catch (P4SpecException $specError) {
                $this->logger->debug(sprintf("%s: unexpected error %s", self::LOG_PREFIX, $specError->getMessage()));
            }
        } else {
            $this->logger->debug(sprintf("%s: no activity to process in handleCommit", self::LOG_PREFIX));
        }
    }
}
