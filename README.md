# What moved

Reviewing a pull request written by an AI starts with understanding what it moved. This action comments on the pull request with a map of the project's contexts: each class is a dot, red when the pull request changed it, green when it added it, with only the dependencies the pull request touches. You know where to look before opening the diff.

![A pull request making Labo depend on Vins, mapped by What moved](docs/example.svg)

Here, the pull request changes the only class of `Labo` and makes it depend on `Vins`: the new dependency is drawn in green, and the existing ones of the changed class in grey. Every other relation of the project stays off the map.

The comment gives the figures, the image, and a link to a report to explore every class and its dependencies.

## Usage

```yaml
name: What moved

on: pull_request

permissions:
  contents: write
  pull-requests: write

concurrency:
  group: what-moved-${{ github.event.pull_request.number }}
  cancel-in-progress: true

jobs:
  graph:
    runs-on: ubuntu-latest
    steps:
      - uses: DeGraciaMathieu/what-moved@v4
```

The inputs and their defaults are described in `action.yml`.

## Dependencies

A class uses another one when its name appears in the code once resolved: a type, a `new`, a static call, an `instanceof`, an attribute, a `::class`. Imports alone and docblocks do not count.

## Configuration

The config file, `.github/what-moved.json` by default, sets the contexts, their place on the map, and the layers. Every key is optional.

```json
{
    "contexts": {
        "Http":   { "prefixes": ["Http", "Policies"], "position": [150, 125] },
        "Carnet": { "position": [370, 290] },
        "Notes":  { "prefixes": ["Carnet\\Notes"], "position": null }
    },
    "exclude": ["Providers"],
    "layers": [
        { "name": "Domain", "color": "#1e1b16", "segments": ["Domain", "Model"] },
        { "name": "Application", "color": "#6b6357", "segments": ["Application", "UseCase"] },
        { "name": "Infrastructure", "color": "#a8998a", "segments": ["Infrastructure", "Adapter"] },
        { "name": "Web", "color": "#d9d1c2", "contexts": ["Http"] }
    ],
    "default-layer": "Application"
}
```

### Contexts

The context of a class is found from its name without the root namespace:

- the context whose prefix, among the `prefixes` of `contexts`, is the longest to match the class: `App\Carnet\Notes\Domain\Note` belongs to `Notes`, and `App\Policies\FonduePolicy` to `Http`;
- or else the first segment: `App\Carnet\Domain\Fondue` belongs to `Carnet`.

So a project that declares nothing gets one context per first segment, and `prefixes` both split a context and merge several into one. A context without `prefixes`, like `Carnet` above, is still found by its first segment: declaring it only places it on the map. The classes that match a prefix of `exclude` stay off the map.

### Layers

A class takes the first layer, in the order of the file, that recognises it:

- `contexts`: the class belongs to one of these contexts;
- `segments`: one of the segments between the prefix of the context and the class name is one of these names, at any depth. `App\Carnet\Domain\Fondue` and `App\Carnet\Model\Entity\Fondue` both fall in `Domain` above. A segment inside the prefix does not count: with a context declared as `Carnet\Application\Queries`, its classes fall in `default-layer`.

A class no layer recognises falls in `default-layer`, which must be one of the layers.

The layer gives the colour of the dot, and the order of the file gives the order of the legend and of the dots inside each context. Without `layers`, the action uses four layers: `Domain`, `Application` and `Infrastructure`, recognised by the segment of the same name, and `Http`, recognised by its context.

### Positions

The `position` of a context places it on a 780 by 610 map, so the map stays the same from one pull request to the next. A context without a position, or with a null one, goes next to the one it shares the most dependencies with.

## Publishing

The image is committed to an orphan branch of the repository, `what-moved-assets` by default, one file per pull request, overwritten at each run. The comment links it through `github.com/<owner>/<repo>/raw/<commit>/...`, so it shows to whoever can read the repository, private or not, and never from a stale cache. This is why the action needs `contents: write`.

The report is kept as an artifact of the run: the comment links it, and it opens once downloaded.
