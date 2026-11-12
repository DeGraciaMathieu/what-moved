# What moved

Reviewing a pull request written by an AI starts with understanding what it moved. This action comments on the pull request with a map of the project's contexts: each class is a dot, red when the pull request changed it, green when it added it, with only the dependencies the pull request touches. You know where to look before opening the diff.

![A pull request adding a Vins context, mapped by What moved](docs/example.svg)

Here, the pull request adds a `Vins` context with three classes, changes two classes in `Http` and three in `Carnet`, and adds eight dependencies, drawn in green.

The comment gives the figures, the image, and a link to a report to explore every class and its dependencies.

## Usage

```yaml
name: What moved

on: pull_request

permissions:
  contents: read
  pull-requests: write

concurrency:
  group: what-moved-${{ github.event.pull_request.number }}
  cancel-in-progress: true

jobs:
  graph:
    runs-on: ubuntu-latest
    steps:
      - uses: DeGraciaMathieu/what-moved@v1
        with:
          s3-bucket: my-bucket
          s3-endpoint: https://fra1.digitaloceanspaces.com
          public-url: https://my-bucket.fra1.digitaloceanspaces.com
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.S3_KEY }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.S3_SECRET }}
```

The inputs and their defaults are described in `action.yml`.

## Contexts and layers

The context of a class is the segment after the root namespace: `App\Carnet\Domain\Fondue` belongs to `Carnet`. The next segment gives the layer, `Domain`, `Application` or `Infrastructure`, and `application` by default. Every class of the contexts listed in `web` falls in the web layer.

A class uses another one when its name appears in the code once resolved: a type, a `new`, a static call, an `instanceof`, an attribute, a `::class`. Imports alone and docblocks do not count.

## The map

The `positions` file places each context, so the map stays the same from one pull request to the next. A missing context goes next to the one it shares the most dependencies with.

```json
{
    "Http": [150, 125],
    "Carnet": [370, 290]
}
```

## Publishing

A private repository cannot show an image stored in it: the image and the report go to an S3 bucket, under a path nobody can guess, readable by whoever has the link. This is temporary.
