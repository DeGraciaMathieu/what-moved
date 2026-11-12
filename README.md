# What moved

Reviewing a pull request written by an AI starts with understanding what it moved. This action comments on the pull request with a map of the project's contexts: each class is a dot, red when the pull request changed it, green when it added it, with only the dependencies the pull request touches. You know where to look before opening the diff.

![A pull request making Shipping depend on Billing, mapped by What moved](docs/hero.svg)

Here, the pull request changes one class of `Shipping` and makes it depend on `Billing`: the new dependency is drawn in green, the existing ones of the changed class in grey. Every other relation of the project stays off the map.

## The comment

The action posts one comment on the pull request and updates it at each push:

```markdown
### What moved

**0** classes added, **1** changed, **0** removed, out of 30 · **1** dependency added, **0** removed.

Contexts touched: `Shipping` (1).

![What the pull request moved](https://github.com/acme/shop/raw/3f2c…/pr-42/graph.svg)

[Explore every class](https://github.com/acme/shop/actions/runs/…/artifacts/…): download the report, then open `report.html`.
```

The report holds the same map, then every class of the project: click a dot to see what it depends on and what uses it.

## Reading the map

- A **circle** is a context, sized by its number of classes. Under its name, `cohesion` is the share of its dependencies that stay inside it.
- A **dot** is a class. Its colour gives its layer, or red when the pull request changed it and green when it added it. The touched classes sit at the centre.
- A **red ring** marks a context the pull request touched, with `hot:` the number of classes it touched there.
- A **green arrow** is a dependency the pull request added, as thick as the number of dependencies it added between the two contexts. A **grey arrow** is an existing dependency from or to a touched class.
- A **dashed green circle** is a context the pull request created:

![A pull request adding a Reviews context](docs/new-context.svg)

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

| Input | Default | |
|---|---|---|
| `source` | `app` | Folder of the classes to map, from the project root. |
| `namespace` | `App` | Root namespace of the classes in the folder. |
| `config` | `.github/what-moved.json` | Config file of the contexts and the layers, see below. |
| `assets-branch` | `what-moved-assets` | Orphan branch that keeps the image of each pull request. |
| `php-version` | `8.2` | PHP version that runs the analysis. |
| `github-token` | `${{ github.token }}` | Token that pushes the image and posts the comment. |

## Configuration

The config file sets the contexts, their place on the map, and the layers. Every key is optional: without a file, each first segment of the namespace is a context and the layers are `Domain`, `Application`, `Infrastructure` and `Http`. Placing the contexts is what makes the map readable, though: see [Positions](#positions).

```json
{
    "contexts": {
        "Http":     { "prefixes": ["Http", "Policies"], "position": [140, 140] },
        "Catalog":  { "position": [400, 110] },
        "Ordering": { "position": [380, 310] },
        "Billing":  { "position": null }
    },
    "exclude": ["Providers"],
    "layers": [
        { "name": "Domain", "color": "#1e1b16", "segments": ["Domain"] },
        { "name": "Application", "color": "#6b6357", "segments": ["Application"] },
        { "name": "Infrastructure", "color": "#a8998a", "segments": ["Infrastructure"] },
        { "name": "Web", "color": "#d9d1c2", "contexts": ["Http"] }
    ],
    "default-layer": "Application"
}
```

### Contexts

The context of a class is found from its name without the root namespace:

- the context whose prefix, among the `prefixes` of `contexts`, is the longest to match the class: `App\Policies\OrderPolicy` belongs to `Http` above;
- or else the first segment: `App\Catalog\Domain\Product` belongs to `Catalog`.

A context without `prefixes`, like `Catalog` above, is still found by its first segment: declaring it only places it on the map. The classes that match a prefix of `exclude` stay off the map.

### Layers

A class takes the first layer, in the order of the file, that recognises it:

- `contexts`: the class belongs to one of these contexts;
- `segments`: one of the segments between the prefix of the context and the class name is one of these names, at any depth. `App\Catalog\Domain\Product` and `App\Catalog\Domain\Entity\Product` both fall in `Domain` above. A segment inside the prefix does not count: with a context declared as `Ordering\Application`, its classes fall in `default-layer`.

A class no layer recognises falls in `default-layer`, which must be one of the layers. The layer gives the colour of the dot, and the order of the file gives the order of the legend and of the dots inside each context.

### Positions

The `position` of a context places it on a 780 by 610 map, so the map stays the same from one pull request to the next. A context without a position, or with a null one, goes next to the placed context it shares the most dependencies with; with no placed neighbour, it goes to the middle of the map, where the unplaced contexts pile up. Place every context once, and leave a null position to the ones still moving.

## Recipes

### A Laravel project

List the contexts of `app/` with a place on the map, merge the policies into the controllers, and leave the service providers out, since they depend on everything:

```json
{
    "contexts": {
        "Http":   { "prefixes": ["Http", "Policies"], "position": [150, 150] },
        "Models": { "position": [400, 300] },
        "Jobs":   { "position": [620, 150] }
    },
    "exclude": ["Providers"]
}
```

### Split a context too big to read

In one `Ordering` context, a pull request that makes the checkout depend on the cart only turns a dot red: the dependency stays inside the circle.

![The pull request with one Ordering context](docs/split-before.svg)

Declare its modules as contexts of their own:

```json
{
    "contexts": {
        "Cart":     { "prefixes": ["Ordering\\Cart"], "position": [250, 320] },
        "Checkout": { "prefixes": ["Ordering\\Checkout"], "position": [450, 300] }
    }
}
```

The same pull request now shows the new dependency from `Checkout` to `Cart`:

![The same pull request with Ordering split into Cart and Checkout](docs/split-after.svg)

### Other layer names

A hexagonal project names its layers after ports and adapters:

```json
{
    "layers": [
        { "name": "Model", "color": "#1e1b16", "segments": ["Model", "Entity", "ValueObject"] },
        { "name": "Use cases", "color": "#6b6357", "segments": ["UseCase", "Port"] },
        { "name": "Adapters", "color": "#a8998a", "segments": ["Adapter"] }
    ],
    "default-layer": "Use cases"
}
```

### Code outside `app/`

For classes in `src/` under `Acme\`:

```yaml
      - uses: DeGraciaMathieu/what-moved@v4
        with:
          source: src
          namespace: Acme
```

## Dependencies

A class uses another one when its name appears in the code once resolved: a type, a `new`, a static call, an `instanceof`, an attribute, a `::class`. Imports alone and docblocks do not count. Only the classes of `source` are mapped: a dependency to a vendor class is left out.

## Publishing

The image is committed to an orphan branch of the repository, `what-moved-assets` by default, one file per pull request, overwritten at each run. The comment links it through `github.com/<owner>/<repo>/raw/<commit>/...`, so it shows to whoever can read the repository, private or not, and never from a stale cache. This is why the action needs `contents: write`.

The report is kept as an artifact of the run: the comment links it, and it opens once downloaded.
