<?php
/**
 * Mattermost Perforce Swarm module
 *
 * @copyright   2026 PoFig Games Studio. All rights reserved.
 * @license     Please see LICENSE.txt in top-level readme folder of this distribution.
 */
namespace Mattermost\Model;

use Activity\Model\Activity;
use Interop\Container\ContainerInterface;
use Mattermost\Service\IUtility;
use P4\Spec\Change;
use Reviews\Model\Review;

/**
 * Class to build a post body that can be sent to POST /api/v4/posts.
 * Mattermost renders Markdown, so the message is a single markdown string rather than Slack blocks.
 */
class Message
{
    // Mattermost rejects posts longer than ServiceSettings.MaxPostSize (16383 by default). Leave headroom.
    const MESSAGE_CHAR_LIMIT = 16000;
    // Keep individual sections readable even when the overall limit would allow more
    const SECTION_TXT_CHAR_LIMIT = 4000;
    // Limit a description to 1000 characters
    const DESC_TXT_CHAR_LIMIT = 1000;

    private $utility;
    private $change;
    private $workspace;
    private $title;
    private $link;
    private $description;
    private $projects;
    private $fileNames;
    private $mentions;

    /**
     * Construct the message
     * @param ContainerInterface $services  application services
     * @param Change             $change    change
     * @param array|null         $projects  optional projects if set
     * @param Review|null        $review    optional review
     * @param Activity|null      $activity  optional activity data.
     * @param string             $mentions  optional space-separated @mention tokens.
     * @param array              $workspace workspace config block; per-workspace settings override globals.
     */
    public function __construct(
        ContainerInterface $services,
        Change $change,
        ?array $projects,
        Review $review = null,
        Activity $activity = null,
        string $mentions = '',
        array $workspace = []
    ) {
        $this->change      = $change;
        $this->workspace   = $workspace;
        $this->projects    = $projects;
        $this->mentions    = $mentions;
        $this->utility     = $services->get(IUtility::SERVICE_NAME);
        $this->title       = $this->utility->buildTitle($change, $review, $activity, $workspace);
        $this->link        = $this->utility->buildLink($change, $review);
        $this->description = $this->utility->buildDescription($change);
        $this->fileNames   = $this->utility->buildFileNames($change, $workspace);
    }

    /**
     * Truncate text to fit within a limit
     * @param string $text   the text
     * @param bool   $isList if true the text will be truncated to the last new line before the set limit
     * @param int    $limit  the limit to use for truncating the string
     * @return string
     */
    private function truncate(string $text, bool $isList = false, int $limit = self::SECTION_TXT_CHAR_LIMIT): string
    {
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, 0, $limit);
            if ($isList) {
                $index = mb_strrpos($text, "\n");
                if ($index !== false) {
                    $text = mb_substr($text, 0, $index);
                }
            }
            $text = $text . "\n...";
        }
        return $text;
    }

    /**
     * Build the base post body
     * @param string      $channelId the channel id
     * @param string      $message   markdown text
     * @param string|null $parent    the root post id for a threaded reply
     * @return array
     */
    private function post(string $channelId, string $message, ?string $parent = null): array
    {
        $post = [
            "channel_id" => $channelId,
            "message"    => $this->truncate($message, false, self::MESSAGE_CHAR_LIMIT),
        ];
        if ($parent !== null && $parent !== '') {
            $post["root_id"] = $parent;
        }
        return $post;
    }

    /**
     * Build a post body for a summary (the root post of a review/change thread)
     * @param string $channelId      the channel id
     * @param array  $linkedProjects The linked projects to this channel.
     * @return array
     */
    public function getSummary(string $channelId, array $linkedProjects = []): array
    {
        $host        = $this->utility->getHostName();
        $projectText = $this->utility->buildProjectText($this->projects, true, $linkedProjects);
        $sections    = [];
        // Notify mentions go on their own line above the title. People already @mentioned in the
        // title (the author, the committer) are dropped from that line to avoid a double mention.
        $mentions = array_filter(
            preg_split('/\s+/', trim($this->mentions)) ?: [],
            function (string $token): bool {
                return $token !== '' && stripos($this->title, $token) === false;
            }
        );
        if (!empty($mentions)) {
            $sections[] = implode(' ', $mentions);
        }
        $sections[] = "#### " . $this->title;
        $sections[] = $this->truncate($this->description, false, self::DESC_TXT_CHAR_LIMIT);
        if ($projectText) {
            $sections[] = $this->truncate($projectText, true);
        }
        if ($this->fileNames) {
            $sections[] = $this->truncate($this->fileNames, true);
        }
        $sections[] = sprintf("[Open in Swarm](%s%s)", $host, $this->link);

        return $this->post($channelId, implode("\n\n", $sections));
    }

    /**
     * Build a post body for a threaded reply
     * @param Activity $activity       the activity to use to build the message
     * @param string   $channelId      the channel id
     * @param string   $parent         the root post id ('' for a standalone post, e.g. a DM)
     * @param array    $linkedProjects The linked projects to this channel.
     * @return array
     */
    public function getReply(Activity $activity, string $channelId, string $parent, array $linkedProjects = []): array
    {
        $text = $this->utility->buildActivityReply(
            $activity,
            $this->projects ?? [],
            $linkedProjects,
            $this->workspace
        );
        return $this->post($channelId, $text, $parent);
    }

    /**
     * Build a threaded reply listing the changed files (reply_file_names option)
     * @param string $channelId the channel id
     * @param string $parent    the root post id
     * @return array|null null when there is nothing to list
     */
    public function getFileNamesReply(string $channelId, string $parent): ?array
    {
        $text = $this->utility->buildFileNames($this->change, $this->workspace, true);
        if ($text === '') {
            return null;
        }
        return $this->post($channelId, $this->truncate($text, true), $parent);
    }
}
