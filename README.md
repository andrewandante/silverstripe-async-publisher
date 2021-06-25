# SilverStripe Asynchronous Publishing

Pushes writing and publishing to a Queued Job to avoid in-browser timeouts

## Installation

Add the following to your `composer.json`:

```json
{
    "require": {
        "andrewandante/silverstripe-async-publisher": "dev-main"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:andrewandante/silverstripe-async-publisher.git"
        }
    ]
}
```

then run `composer update andrewandante/silverstripe-async-publisher`

Once the module is installed, simply apply `AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension`
to any classes that you wish to enable Queued Publishing for:

```yaml
---
name: async-publisher-config
---
My\SuperSlow\Page:
  extensions:
      - AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension
```

## Features

- replaces the "Save" and "Publish" buttons with "Queue Save" and "Queue Publish"
- adds "Force Save" and "Force Publish" to the "More Options" menu (in case of emergency)
- prevents editing while jobs are in the queue to prevent weird race conditions

## Screenshots

![CMS Actions](docs/img/cms-actions.png)

![Pending Jobs Warning](docs/img/pending-jobs-warning.png)

## TODOS

- test with Unpublish and Archive
- have a better representation of state when there are pending jobs
- have an extension hook that can influence should/should not default to queue (e.g. if a UserForm has > 50 fields)
- make it more configurable/extensible in general

## Maintainers

 * Andrew Aitken-Fincham <andrew.aitkenfincham@silverstripe.com>
