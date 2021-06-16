# SilverStripe Asynchronous Publishing

Pushes publishing to a Queued Job to avoid in-browser timeouts

VERY ALPHA

## Example configuration
```yaml
---
name: async-publisher-config
Page:
  extensions:
      - AndrewAndante\SilverStripe\AsyncPublisher\Extension\AsyncPublisherExtension
```

## Maintainers
 * Andrew Aitken-Fincham <andrew.aitkenfincham@silverstripe.com>
