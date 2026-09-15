Environnement de développement (fork Huttopia)
==============================================

Ce fichier est propre au fork Huttopia. Il est volontairement séparé du
`README.md`, qui reste le document amont (badges, liens de documentation
upstream) et qui est resynchronisé lors des merges depuis
`suncat2000/MobileDetectBundle` : isoler l'outillage du fork évite les conflits
de merge à répétition.

Prérequis
---------

Docker et le plugin Docker Compose v2+.

Les fichiers écrits dans le projet depuis le conteneur (`vendor/`, caches)
appartiennent à l'utilisateur hôte : l'UID/GID sont mappés. Par défaut
`1000:1000` ; si votre compte n'est pas en 1000, créez un fichier `.env` à côté
de `docker-compose.yaml` (Compose le lit automatiquement) :

```bash
printf 'UID=%s\nGID=%s\n' "$(id -u)" "$(id -g)" > .env
```

`UID` étant en lecture seule dans bash comme dans zsh, `export UID=…` échoue :
le fichier `.env` est la façon portable de surcharger ces valeurs.

Commandes
---------

```bash
# Construction de l'image (php:8.5-cli + Composer 2)
docker compose build

# Installation des dépendances
# `update` et non `install` : ce dépôt est une bibliothèque, son `composer.lock`
# n'est pas versionné (cf. .gitignore).
docker compose run --rm php-cli composer update

# Suite de tests
docker compose run --rm php-cli vendor/bin/phpunit

# Shell interactif dans le conteneur
docker compose run --rm php-cli bash
```

Le cache Composer est conservé dans le volume nommé `composer-cache`, ce qui
accélère les réinstallations.

Lire le rapport de dépréciations
--------------------------------

Le pont `symfony/phpunit-bridge` est enregistré via son `bootstrap.php`
(autoloadé par Composer) et via le listener `SymfonyTestsListener` déclaré dans
`phpunit.xml.dist`. À la fin du run, PHPUnit affiche un bloc de ce type :

```
Remaining self deprecation notices (1)

  1x: Method "..." might add "void" as a native return type declaration ...
    1x in MobileDetectExtensionTest::setUp from SunCat\MobileDetectBundle\Tests\DependencyInjection
```

Les catégories utilisées par le pont :

| Catégorie            | Signification                                                          |
|----------------------|------------------------------------------------------------------------|
| `Self deprecations`  | Dépréciation déclenchée par du code du bundle lui-même. **À corriger.** |
| `Direct deprecations`| Le bundle appelle directement une API dépréciée d'une dépendance.       |
| `Indirect deprecations` | Dépréciation interne à une dépendance, hors de notre contrôle.      |
| `Legacy deprecations`| Déclenchée par un test marqué `@group legacy`.                          |

Le comportement est piloté par la variable `SYMFONY_DEPRECATIONS_HELPER`,
positionnée dans `docker-compose.yaml` à `max[total]=999999&verbose=1` : les
dépréciations sont **comptées et affichées en détail sans faire échouer le
run**, ce qui est le but recherché ici. Pour faire échouer le run dès qu'une
dépréciation vient du bundle lui-même — c'est l'invocation à utiliser en CI :

```bash
docker compose run --rm -e SYMFONY_DEPRECATIONS_HELPER='max[self]=0' php-cli vendor/bin/phpunit
```

Note : sous `--process-isolation`, chaque test s'exécute dans un processus fils
et le rapport agrégé de dépréciations n'est pas affiché. Cette option sert
uniquement à obtenir un décompte complet quand une erreur fatale interrompt le
run global.

Garde-fou automatisé
--------------------

`Tests/PhpDeprecationTest.php` analyse statiquement les sources du bundle
(vendor exclu) et échoue si un des motifs dépréciés réapparaît : propriété
dynamique, type nullable implicite, paramètre optionnel avant un paramètre
requis, `null` passé au préfixe numérique de `http_build_query()`.
