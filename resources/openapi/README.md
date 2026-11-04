# OpenAPI schemas

The JSON Schemas `kipchak openapi:validate` checks documents against (check OAS-001), as the OpenAPI Initiative
publishes them:

| File | Version | Source |
|---|---|---|
| `oas-3.0.json` | OpenAPI 3.0.x | https://spec.openapis.org/oas/3.0/schema/2024-10-18 |
| `oas-3.1.json` | OpenAPI 3.1.x | https://spec.openapis.org/oas/3.1/schema/2022-10-07 |

The files are unchanged. `src/Scaffold/Validate/SchemaValidator.php` adapts them for opis/json-schema when it loads
them, and its comment says how.

The OpenAPI Initiative publishes them under the Apache License 2.0: see [LICENSE](LICENSE).
