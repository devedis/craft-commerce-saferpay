# Craft Commerce Saferpay

## Example

Use the [Spoke & Chain Craft Commerce Demo](https://github.com/craftcms/spoke-and-chain) to setup a demo project.

### Setup

Add following to the composer.json of the demo project:

```
"repositories": [
    {
      "type": "path",
      "url": "/dev/craft-commerce-saferpay"
    }
]
```

Add additional mounts in ddev by creating a new docker-composer yaml configuration file in `.ddev`,
for example .ddev/docker-compose.mounts.yaml with the following content:

```yaml
services:
  web:
    volumes:
      - "/Users/andi/Documents/devedis/Craft/craft-commerce-saferpay:/dev/craft-commerce-saferpay"
```
