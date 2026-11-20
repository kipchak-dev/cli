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
| `kipchak make:resource <Name> --fields=...` | Writes a resource in the starter's conventions: DTO, model, controller, routes and test; `--crud` adds create, update and delete. |
| `kipchak make:dto`, `make:controller` | Write one DTO, with its OpenAPI schema, or one empty controller. |
| `kipchak make:endpoint <METHOD> <path> <Controller>::<method>` | Adds an operation, with its OpenAPI attribute, to an existing controller, and its route. |
| `kipchak make:middleware <Name>` | Writes a PSR-15 middleware; `--global` adds it for every request. |
| `kipchak routes` | Lists the routes the application registers, with the app of each when the project declares more than one. |
| `kipchak openapi:validate` | Checks the OpenAPI document the API generates against the specification and against the routes of every app the project runs. |

A project that runs more than one app, such as a separate worker for uploads, declares the others in its own
`composer.json`, each as the public static method that boots it and returns a `Slim\App`:

```json
"extra": {
    "kipchak": {
        "apps": ["Api\\Receive\\ReceiveApp::boot"]
    }
}
```

`routes` and `openapi:validate` then boot each app in a separate process, stopped after 300 seconds.

Every command accepts `--format=json`, and commands that write files accept `--dry-run`. Exit codes: 0 success,
1 a problem was found (nothing was written), 2 the command could not run.

The documentation is at [kipchak.dev/docs/cli](https://kipchak.dev/docs/cli).

`openapi:validate` checks documents against the OpenAPI Initiative's JSON Schemas, bundled in `resources/openapi/`
under the Apache License 2.0.

## Licence

MIT. See [LICENSE](LICENSE).
