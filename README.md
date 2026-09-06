# Mattermost notifications for Helix Swarm (P4 Code Review)

A Helix Swarm module that sends code review notifications to **Mattermost**, the way the
built-in Slack module does for Slack. It is a port of the Slack module to the Mattermost REST
API v4, built and used by PoFig Games Studio.

The module lives entirely in `module/Mattermost`, stores its settings through Swarm's standard
`Configurations` module without modifying it, and ships its own admin page and REST API.
Nothing in the Swarm core is patched, so Swarm upgrades do not affect it.

## Features

- A post in the mapped channel when a review is requested or a change is committed, with the
  description, the changed files and a link back to Swarm.
- Threaded replies for comments, votes, state changes and new revisions: one review, one thread.
  Image attachments of comments are uploaded to the thread.
- Emoji reactions on the review post that mirror the review state: 👍 approved, 👎 rejected,
  🖋 needs revision, 📦 archived. Only the current state reaction is kept.
- People are written the Mattermost way, `Full Name (@userName)`. The Mattermost account is
  found by email, or by the Swarm login used as the Mattermost username when the emails differ.
- Optional @mentions of the author, reviewers, project owners, project members, default
  reviewers and branch moderators, and direct messages for people mentioned in a comment.
- Several Mattermost servers, each with its own project-to-channel routing (`*all*`,
  `*no_project_channel*` and `*without_project*` special keys, like the Slack module).
- An admin page inside Swarm (`/mattermost/configuration`, "Mattermost" in the menu, with its
  own icon) and a REST API, so nothing has to be edited in `config.php`.

## Requirements

- Helix Swarm 2026.3 or later (developed against build 3030911). The module uses the public
  module APIs of Swarm and Laminas only.
- Mattermost 9.x or later with API v4 and a bot account.
- PHP as required by Swarm.

## Installation

### Swarm

1. Clone this repository into the Swarm module folder:

   ```bash
   git clone https://github.com/PoFigGames/swarm-mattermost /opt/perforce/swarm/module/Mattermost
   ```

2. Register the module in `/opt/perforce/swarm/config/custom.modules.config.php` (create the
   file if it does not exist, keep any modules already listed there):

   ```php
   <?php
   return ['Mattermost'];
   ```

   Class autoloading is handled by the module itself, no composer changes are needed.

3. Install the menu icon: copy `public/custom/mattermost` to
   `/opt/perforce/swarm/public/custom/mattermost`. Swarm includes every CSS file under
   `public/custom` automatically.

4. Clear the cache and restart the queue workers:

   ```bash
   rm -rf /opt/perforce/swarm/data/cache/* && systemctl restart php-fpm
   ```

Repeat steps 2 to 4 after a Swarm upgrade: the module folder is not touched by the upgrade,
but check that the module is still registered and that `public/custom` survived.

### Mattermost

1. System Console → Integrations → Bot Accounts: enable bot accounts and create a bot, for
   example `swarm`. Copy its access token.
2. Add the bot to the team and to every channel it should post to (`/invite @swarm` in the
   channel). Posting to a channel the bot is not a member of fails.
3. Optional, for the custom bot name and avatar: System Console → Integrations →
   Integration Management: enable "override usernames" and "override profile picture icons".
4. Optional, for mentions and direct messages: Mattermost accounts are matched by the email
   stored in Perforce, or by username when the Swarm login equals the Mattermost username.

## Configuration

Open `https://<swarm>/mattermost/configuration` as an administrator (also reachable from the
"Mattermost" entry in the menu) and add a server:

| Field | Meaning |
|-------|---------|
| Server ID | Any unique name, used in logs and to keep several servers apart. |
| Server URL | Base URL of Mattermost, for example `https://mattermost.example.com`. |
| Team | Team name from the Mattermost URL (`https://server/<team>/channels/...`). Needed to resolve channel names; not needed when only channel ids are used. |
| Bot access token | Token of the bot account. Write-only: leave blank when editing to keep the stored one. |
| Project channels | Project id → one or more channels (URL handle such as `town-square`, or channel id). `*all*` sends every notification there, `*no_project_channel*` catches projects without a mapping, `*without_project*` catches reviews without a project. |
| Summary file names / Reply file names | List the changed files in the post, or as a reply in the thread. |
| Bypass restricted changelist | Also notify about restricted changelists. |
| Notify mentioned only | Send direct messages to people mentioned in a comment instead of posting to the channel. |
| Notify | Roles to @mention in the post. |
| Summary file limit | Maximum number of files listed. |
| Custom bot user | Name, avatar URL and "force user header" for the posts (needs the Mattermost overrides above). |

Settings are stored in the Perforce key `swarm-configuration-mattermost` through Swarm's
standard `Configurations` module. Alternatively the same options can be put in
`data/config.php`; the stored record wins when it exists, and the first use of the page or
of a notification seeds the record from `config.php`:

```php
'mattermost' => [
    'url'   => 'https://mattermost.example.com',
    'token' => '<bot token>',
    'team'  => 'engineering',
    'project_channels' => [
        'myproject' => ['myproject-reviews'],
        '*all*'     => ['swarm-notifications'],
    ],
    'notify' => ['author', 'reviewers'],
],
```

Several servers can be configured by nesting a block per server:

```php
'mattermost' => [
    'prod' => ['url' => '...', 'token' => '...', 'team' => '...', 'project_channels' => [...]],
    'lab'  => ['url' => '...', 'token' => '...', 'team' => '...', 'project_channels' => [...]],
],
```

All option names and defaults are listed in `config/module.config.php`.

### Reactions

Defaults: approved `+1`, rejected `-1`, needs revision `lower_left_fountain_pen`, archived
`package`, needs review none. Override in `data/config.php` with Mattermost emoji names without
colons; an empty string disables the reaction:

```php
'mattermost' => [
    'reactions' => [
        'approved'       => 'white_check_mark',
        'rejected'       => 'x',
        'needs_revision' => 'lower_left_fountain_pen',
        'needs_review'   => '',
        'archived'       => 'file_cabinet',
    ],
],
```

### REST API

The page uses the module's own endpoints; they can also be called by scripts with an
administrator session or ticket:

```
GET    /api/v11/mattermost/configuration
PATCH  /api/v11/mattermost/configuration/workspaces/{id}
DELETE /api/v11/mattermost/configuration/workspaces/{id}
```

`PATCH` creates the server when the id is unknown and merges the given fields otherwise. Field
names are camelCase as on the page (`url`, `token`, `team`, `projectChannels` as a list of
`{"project": "...", "channels": ["..."]}`, `summaryFileNames`, `notify`, `user`, ...). A new
server requires `url` and `token`; a blank `url` or `token` on update keeps the stored value.
`DELETE` is a soft delete; the last active server cannot be deleted. Responses look like
`{"data": {"configurations": [{"id": "mattermost", "workspaces": [...]}]}}`. Requests from a
browser session need the `X-CSRF-TOKEN` header.

## How it works

| Slack | Mattermost (`/api/v4`) |
|-------|------------------------|
| `chat.postMessage` with blocks, `thread_ts` | `POST /posts` with markdown `message`, `root_id` |
| `username` / `icon_url` | `props.override_username` / `props.override_icon_url` |
| channel name accepted directly | `GET /teams/name/{team}/channels/name/{name}` → `channel_id` |
| `users.lookupByEmail`, `<@ID>` | `GET /users/email/{email}` or `GET /users/username/{name}`, `@username` |
| `conversations.open` | `GET /users/me` + `POST /channels/direct` |
| `files.*` upload + image block | `POST /files?channel_id=&filename=` + `file_ids` on the post |
| — | `POST /reactions`, `DELETE /users/{bot}/posts/{post}/reactions/{emoji}` |

Thread ids are stored in Perforce keys with the `notification-mattermost-` prefix as
`['<server>:<channel>' => ['threadId' => <root post id>, 'channelId' => <channel id>]]`.
Channel ids, user lookups and the bot id are cached in Redis for a day (misses for five minutes).

Menu entry: the `menu_helpers` block in `config/module.config.php` plus
`Mattermost\Menu\Helper\MattermostMenuHelper` (found by Swarm by module name, like
`WebhooksMenuHelper`) add the entry for admin and super users. The React menu draws a generic
icon for entries it does not know; `public/custom/mattermost/mattermost.css` hides it and shows
the Mattermost mark in the same slot and colours. The mark is the Mattermost icon from
[Simple Icons](https://simpleicons.org) (CC0); Mattermost is a trademark of Mattermost, Inc.

## Troubleshooting

- **White page after registering the module.** Look at `/var/log/php-fpm/www-error.log`.
  `Class "Mattermost\..." not found` means the module was copied without `Module.php`, which
  registers the autoloader.
- **404 for `/mattermost/configuration`, no menu entry.** The module is not listed in
  `config/custom.modules.config.php`, or the cache was not cleared.
- **403 on save while the list loads.** The page could not find the session CSRF token. It reads
  the `data-csrf` attribute of `<body>` and the Swarm `csrf` service; a warning is shown on
  the page when none was found.
- **Notifications do not arrive.** Set `'log' => ['priority' => 7]` in `data/config.php`,
  clear the cache, trigger a review event and run
  `grep -i mattermost data/log | grep '"worker":[0-9]'`. The log shows which server was
  loaded, which channel was resolved and every Mattermost API call with its response code.
  Restore the log level afterwards.
- **Changed code has no effect on notifications.** A queue worker is one long PHP request of
  up to ten minutes and loads the module once at startup. `systemctl restart php-fpm` restarts
  the workers immediately.
- **Listeners and Perforce.** Listeners attach before Swarm defines the constants the P4
  connection needs, so `shouldAttach()` must not touch Perforce. The module always attaches
  and checks for configured servers when the event is handled.

## Кратко по-русски

Модуль для Helix Swarm (P4 Code Review), который отправляет уведомления о ревью в Mattermost:
пост в канал при создании ревью или коммите, ответы в треде на комментарии и смену статуса,
реакции-эмодзи по состоянию ревью, упоминания участников и страница настроек прямо в Swarm.
Это порт штатного модуля Slack на API Mattermost. Ядро Swarm не патчится: настройки хранятся
через штатный модуль Configurations, API и страница свои. Репозиторий клонируется в
`/opt/perforce/swarm/module/Mattermost`, дальше по инструкции выше.

## License

See [LICENSE.txt](LICENSE.txt).
