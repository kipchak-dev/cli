<img src="https://kipchak.dev/kipchak-icons/kipchak-icon-128x128.png" width="96" alt="Kipchak" />

# Kipchak CLI

The command-line tool for [Kipchak](https://kipchak.dev). It writes plain code into files your project owns, installs
Kipchak drivers and middleware, and validates the OpenAPI document your API generates. A project never depends on it
at run time: remove it and nothing changes.

```bash
composer require --dev kipchak/cli
vendor/bin/kipchak project
```

Starter 4.2 and later require it already.

## Commands

| Command | What it does |
|---|---|
| `kipchak new <directory>` | Creates a project from the starter, with `composer create-project kipchak/starter`. |
| `kipchak add <package>` | Installs a Kipchak driver or middleware, copies its configuration into `config/` and initialises it in the right place in `drivers/drivers.php` or `middlewares/middlewares.php`. |
| `kipchak project` | Shows what the tool knows about the project: its root, its kipchak/core version and its PSR-4 namespaces. |

Every command accepts `--format=json`, and commands that write files accept `--dry-run`. Exit codes: 0 success,
1 a problem was found (nothing was written), 2 the command could not run.

The documentation is at [kipchak.dev/docs/cli](https://kipchak.dev/docs/cli).

## Coming in 1.1

`make:dto`, `make:controller`, `make:resource`, `make:endpoint` and `make:middleware`, which write plain code in the
starter's conventions; `routes`, which lists the API's routes; and `openapi:validate`, which checks the OpenAPI
document the API generates.

## Licence

MIT. See [LICENSE](LICENSE).
