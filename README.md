<img src="https://kipchak.dev/kipchak-icons/kipchak-icon-128x128.png" width="96" alt="Kipchak" />

# Kipchak CLI

The command-line tool for [Kipchak](https://kipchak.dev). It writes plain code into files your project owns, installs
Kipchak drivers and middleware, and validates the OpenAPI document your API generates. A project never depends on it
at run time: remove it and nothing changes.

> In development. The commands are specified in SR-2026-012 and released with kipchak/cli 1.0.

```bash
composer require --dev kipchak/cli
vendor/bin/kipchak project
```

Every command accepts `--format=json`, and commands that write files accept `--dry-run`. Exit codes: 0 success,
1 a problem was found (nothing was written), 2 the command could not run.

## Licence

MIT. See [LICENSE](LICENSE).
