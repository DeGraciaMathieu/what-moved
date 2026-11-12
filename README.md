# What moved

Relire une PR écrite par l'IA, c'est d'abord comprendre ce qu'elle a bougé. Cette action dessine, en commentaire de la PR, la carte des contextes du projet : chaque classe y est un point, rouge quand la PR l'a modifiée, vert quand elle l'a ajoutée, avec seulement les dépendances que la PR touche. On sait où regarder avant d'ouvrir le diff.

Le commentaire donne les chiffres, l'image, et un lien vers un rapport où explorer chaque classe et ses dépendances.

## Utilisation

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
          s3-bucket: mon-bucket
          s3-endpoint: https://fra1.digitaloceanspaces.com
          public-url: https://mon-bucket.fra1.digitaloceanspaces.com
        env:
          AWS_ACCESS_KEY_ID: ${{ secrets.S3_KEY }}
          AWS_SECRET_ACCESS_KEY: ${{ secrets.S3_SECRET }}
```

Les entrées et leurs valeurs par défaut sont décrites dans `action.yml`.

## Les contextes et les couches

Le contexte d'une classe est le segment qui suit le namespace racine : `App\Carnet\Domain\Fondue` est dans `Carnet`. Le segment suivant donne la couche, `Domain`, `Application` ou `Infrastructure`, et `application` par défaut. Les contextes listés dans `web` tombent tous dans la couche web.

Une classe en utilise une autre quand son nom apparaît dans le code une fois résolu : un type, un `new`, un appel statique, un `instanceof`, un attribut, un `::class`. Les imports seuls et les docblocks ne comptent pas.

## La carte

Le fichier `positions` place chaque contexte, pour que la carte reste la même d'une PR à l'autre. Un contexte absent se pose à côté de celui avec lequel il partage le plus de dépendances.

```json
{
    "Http": [150, 125],
    "Carnet": [370, 290]
}
```

## La publication

Un repo privé ne peut pas afficher une image qu'il contient : l'image et le rapport partent dans un bucket S3, sous un chemin impossible à deviner, lisible par qui a le lien. C'est provisoire.
