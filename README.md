# Mattermost notifications for Helix Swarm (P4 Code Review)

A Helix Swarm module that sends code review notifications to **Mattermost**, the way the
built-in Slack module does for Slack. Built and used by PoFig Games Studio, where Swarm
runs alongside a self-hosted Mattermost.

## Why

Perforce ships Swarm with a Slack integration only. Teams on Mattermost are left with
generic webhooks and hand-written formatting. This module is a full port of the Slack module
to the Mattermost REST API v4, with a few extras:

- A post in the mapped channel when a review is requested or a change is committed, with the
  description, the changed files and a link back to Swarm.
- Threaded replies for comments, votes, state changes and new revisions, so one review is one
  thread.
- Emoji reactions on the review post that mirror the review state: 👍 approved, 👎 rejected,
  🖋 needs revision, 📦 archived (configurable).
- People are written the Mattermost way, `Kirill Bravichev (@kirill.bravichev)`, resolved by
  email or by matching Swarm login and Mattermost username.
- Optional @mentions of the author, reviewers, project owners and other roles, and direct
  messages for people mentioned in a comment.
- Several Mattermost servers with per-project channel routing (`*all*`,
  `*no_project_channel*`, `*without_project*` special keys, like the Slack module).
- An admin page inside Swarm (`/mattermost/configuration`, "Mattermost" in the menu) and
  a REST API to manage servers, so nothing has to be edited in `config.php`.

The module lives entirely in its own folder and survives Swarm upgrades. The only files
outside the module are a small patch to Swarm's `Configurations` module that teaches its
configuration API about a `mattermost` record.

This repository contains the module only; clone it into `/opt/perforce/swarm/module/Mattermost`.
The configuration page and the REST API additionally need a small patch to Swarm's own
`Configurations` module (it teaches the configuration API about a `mattermost` record).
That patch is not part of this repository because it modifies Perforce's code; it is applied
separately on the Swarm server, see "Managing the configuration through the Swarm API" below.

Posts review and commit notifications to Mattermost. A root post is created when a review is
requested (or a changelist is committed); later activity (state changes, votes, comments with
image attachments) is posted as replies in that thread. It is a port of the Slack module.

## Mattermost setup

1. System Console > Integrations > Bot Accounts: enable bot accounts and create a bot
   (e.g. `swarm`). Copy its access token.
2. Add the bot to the team and to every channel listed in `project_channels`
   (`/invite @swarm` in the channel).
3. Optional, for `user.name` / `user.icon` overrides: System Console > Integrations >
   Integration Management: enable "override usernames" and "override profile picture icons".
4. Optional, for `notify` mentions and `notify_mentioned_only` DMs: users are matched by the
   email stored in Perforce, so Swarm and Mattermost accounts must share email addresses.

## Swarm setup

1. Copy the `Mattermost` folder to `/opt/perforce/swarm/module/Mattermost`.
2. Register the module in `/opt/perforce/swarm/config/custom.modules.config.php`
   (create the file if it does not exist, keep any modules already listed there):

   ```php
   <?php
   return ['Mattermost'];
   ```

   Class autoloading is handled by the module itself (`Module.php` registers a PSR-4
   autoloader for `Mattermost\`), so no composer changes are required.
3. Clear the config cache: `rm -rf /opt/perforce/swarm/data/cache/*`.
4. Optionally seed the settings in `data/config.php` (the configuration page and the REST
   API can be used instead):

```php
'mattermost' => [
    'url'   => 'https://mattermost.example.com',
    'token' => '<bot token>',
    'team'  => 'engineering',        // team name from the URL, needed to resolve channel names
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

Channels may be given by name (URL handle) or by id. All other options match the Slack module,
see `config/module.config.php`.

## Reactions on state changes

When a review is approved, rejected or archived the bot adds an emoji reaction to the review
post in the channel (in addition to the threaded reply). Defaults: approved `+1`, rejected `-1`,
needs revision `lower_left_fountain_pen`, archived `package`, needs review has no reaction. Only one state
reaction is kept on the post; the previous one is removed on the next transition. Override in `data/config.php` (emoji names without colons, empty string
disables the reaction):

```php
'mattermost' => [
    'reactions' => [
        'approved'       => 'white_check_mark',
        'rejected'       => 'x',
        'archived'       => 'file_cabinet',
        'needs_review'   => '',
        'needs_revision' => 'lower_left_fountain_pen',
    ],
],
```

## API mapping

| Slack                                   | Mattermost (`/api/v4`)                                   |
|-----------------------------------------|----------------------------------------------------------|
| `chat.postMessage` + blocks, `thread_ts`| `POST /posts` with markdown `message`, `root_id`         |
| `username` / `icon_url`                 | `props.override_username` / `props.override_icon_url`    |
| channel name accepted directly          | `GET /teams/name/{team}/channels/name/{name}` → `channel_id` |
| `users.lookupByEmail`, `<@ID>`          | `GET /users/email/{email}`, `@username`                  |
| `conversations.open`                    | `GET /users/me` + `POST /channels/direct`                |
| 3-step `files.*` upload + image block   | `POST /files?channel_id=&filename=` + `file_ids` on post |

Thread ids are stored under the `notification-mattermost-` key prefix as
`['<workspace>:<channel>' => ['threadId' => <root post id>, 'channelId' => <channel id>]]`.

## Configuration page

The module ships its own configuration page at `/mattermost/configuration` (for example
`https://swarm.example.com/mattermost/configuration`). It is a small standalone page
(plain JavaScript, no build step) that lives inside the module, so it is not affected by
Swarm upgrades: the built-in React "Configuration" page only knows about Slack and cannot
be extended without rebuilding Perforce's bundles.

The page requires an authenticated administrator and uses the Configurations REST API:

| Action                    | Request                                                         |
|---------------------------|-----------------------------------------------------------------|
| Load servers              | `GET /api/v11/configurations/mattermost`                        |
| Add / update a server     | `PATCH /api/v11/configurations/mattermost/workspaces/{id}`      |
| Delete a server           | `DELETE /api/v11/configurations/mattermost/workspaces/{id}`     |

Each server has: id, URL, team, bot access token (write-only, leave blank to keep the stored
one), project-to-channel mappings, the file-name / restricted-changelist / mention options,
the notify roles, the summary file limit and the custom bot user settings.

Requirements:

* The `Configurations` module with Mattermost support (see `Managing the configuration
  through the Swarm API`) must be installed, otherwise the API returns `Unknown configuration`.
* Texts are English by default. If `public/locales/<lang>/configuration.json` contains a
  `mattermost` section, the page uses those strings instead.

A "Mattermost" entry is added to the Swarm menu through the `menu_helpers` block in
`config/module.config.php`. Delete that block if you prefer to keep the menu untouched;
the page remains reachable by URL.

### Troubleshooting

* **403 on save while the list loads fine** — the page could not find the session CSRF token.
  The controller asks the Swarm `csrf` service for it and the page also looks at the
  `data-csrf` attribute of `<body>` and a `csrf-token` meta tag. A yellow warning is shown
  on the page when no token was found. Check where your Swarm version publishes the token:

  ```bash
  grep -rn "csrf" /opt/perforce/swarm/module/Application/view/layout/layout.phtml
  ```

* **404 for `/mattermost/configuration`** — the route is registered from
  `config/module.config.php`; clear the Swarm config cache after installing the module:

  ```bash
  rm -rf /opt/perforce/swarm/data/cache/*
  ```

### Notes on how the module integrates with Swarm

These points were learned the hard way and matter after every Swarm upgrade:

* **Autoloading.** Swarm's composer class map covers only the modules shipped by Perforce.
  `Module.php` therefore registers its own PSR-4 autoloader for `Mattermost\`. Without it
  Swarm dies with a white page and `Class "Mattermost\Config\IConfig" not found` in
  `/var/log/php-fpm/www-error.log`.
* **Module registration.** The module must be listed in `config/custom.modules.config.php`;
  copying it to `module/` is not enough. Check this file after an upgrade.
* **No Perforce access while listeners attach.** `shouldAttach()` runs before Swarm defines
  the `VERSION_NAME` constant used by the P4 connection, so the listeners always attach and
  check for configured servers when the event is handled (inside the queue worker).
* **Reading the configuration record.** The record is read through the Configurations DAO,
  not through `Configurations\Service\FetchConfig`: that service merges the record with
  `ConfigManager` defaults, and `ConfigManager` only knows the keys defined by the Swarm core.
* **Menu entry.** The `menu_helpers` block plus `Mattermost\Menu\Helper\MattermostMenuHelper`
  (found by the core by module name, like `WebhooksMenuHelper`) add the "Mattermost" entry
  for admin and super users. `GET /api/v11/menus` shows whether the core picked it up.
* **Queue workers cache code.** A Swarm queue worker is one long-running PHP request (up to
  ten minutes) and loads the module classes once at startup. After replacing module files,
  notifications keep running the old code until the workers restart on their own; run
  `systemctl restart php-fpm` to restart them immediately (cron starts new workers within a minute).
* **Diagnostics.** Set `'log' => ['priority' => 7]` in `data/config.php`, clear the cache,
  trigger a review event and run
  `grep -i mattermost data/log | grep '"worker":[0-9]'`. Restore the log level afterwards.

## Managing the configuration through the Swarm API

When the Configurations module knows about Mattermost (see the `Configurations` changes shipped with
this module), the settings can be managed through the same REST endpoints as Slack. The P4-backed
record wins over `config.php`; the first `GET` seeds the record from `config.php`.

```
GET    /api/v11/configurations/mattermost
PATCH  /api/v11/configurations/mattermost                          {"name": "..."}
PATCH  /api/v11/configurations/mattermost/workspaces/{workspaceId} {"url": "...", "token": "...", "team": "...", ...}
DELETE /api/v11/configurations/mattermost/workspaces/{workspaceId}
```

A new workspace requires `token` and `url`; on later PATCHes a blank `token` or `url` keeps the stored
value. Workspace fields are camelCase in the API (`projectChannels`, `summaryFileNames`, ...), with
`projectChannels` as a list of `{"project": "...", "channels": ["..."]}` objects.

## Кратко по-русски

Модуль для Helix Swarm (P4 Code Review), который отправляет уведомления о ревью в Mattermost:
пост в канал при создании ревью или коммите, ответы в треде на комментарии и смену статуса,
реакции-эмодзи по состоянию ревью, упоминания участников и страница настроек прямо в Swarm.
Это порт штатного модуля Slack на API Mattermost. Репозиторий клонируется в
`/opt/perforce/swarm/module/Mattermost`, дальше по инструкции выше.

## License

See [LICENSE.txt](LICENSE.txt).
