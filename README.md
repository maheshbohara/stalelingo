# Translation Drift

Outdated translation tracker for Polylang and WPML. The WordPress.org readme is [`readme.txt`](readme.txt).

## Development

Requires Docker (Compose v2) and `make`. Everything else runs in containers.

```sh
cp .env.example .env     # optional
make up && make setup    # http://localhost:8080/wp-admin (admin / password), Mailpit on :8025
make help                # all targets
```

`make setup PROVIDER=wpml` uses WPML when its zips are in `./private/wpml/` (git-ignored).

| Check | Command |
|---|---|
| Coding standards, ESLint, TypeScript, Stylelint | `make lint` |
| Static analysis / PHP compatibility | `make phpstan`, `make phpcompat` |
| Tests | `make test-unit`, `make test-integration`, `make test-js`, `make test-e2e`, `make test` |
| Coverage gate | `make coverage` |
| WordPress.org package | `make zip`, `make plugin-check`, `make readme-validate` |
