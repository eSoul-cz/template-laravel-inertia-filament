<!-- esoul-guidelines:begin architecture -->
# Project guidelines - Architecture

The application is a Laravel monolith: business operations are commands and queries (`esoul-cz/laravel-cqrs`) and there is one database. The user interface is the project's variant: Inertia with Vue pages, a Filament admin panel, or both. The versions are named only in [tools.md](tools.md#versions). The project's ADRs record every decision that deviates from this file.

| Part | Technology | Where | Details |
|---|---|---|---|
| User interface | The project's variant: Inertia and Vue pages and/or a Filament admin panel | `App\Infrastructure\Http`, plus the variant's own directories | [frontend.md](frontend.md) |
| Business operations | Commands and queries via `esoul-cz/laravel-cqrs` | `App\Domain\CQRS` | [cqrs.md](cqrs.md) |
| Business rules and calculations | Framework-free value objects, enums, domain services | `App\Domain\<Module>` | this file |
| Database | The engine the project's ADR chooses in production, SQLite `:memory:` in tests | `database/` | [testing.md](testing.md#database-in-tests) |
| Runtime | Docker images built by Jenkins, deployed by Komodo | `docker/`, `Jenkinsfile` | [deployment.md](deployment.md) |

## Layers

### `App\Domain\<Module>` - the framework-free core

- Contains value objects (money, quantities, rates), enums, domain services and calculators, and the typed domain exceptions (`App\Domain\Exception`) that report a broken business rule.
- MUST NOT reference `Illuminate\Database`, an Eloquent model, a facade, the container, the request or the session. It takes plain values and returns plain values, so every rule and formula is unit-tested without a database.
- Value objects used by several modules live in `App\Domain\ValueObjects`; everything else lives in the module that owns it.
- The one exception is `App\Domain\Providers` (next section). A package layer MAY additionally allow the types of the domain model its package provides, and says so in its own architecture file.

### `App\Domain\Providers` - service providers

- Service providers live in `App\Domain\Providers` (`AppServiceProvider` and the provider of an admin panel, when the project has one) and are listed in `bootstrap/providers.php`.
- A provider binds contracts to implementations, so it uses the container, facades and `App\Infrastructure`. **It is the one place in `App\Domain` that may**, and the layer architecture test exempts exactly this namespace ([testing.md](testing.md#architecture-tests)). A provider holds wiring only, never a business rule.

### `App\Domain\CQRS` - commands, queries and their handlers

- Commands live in `App\Domain\CQRS\Command\<Module>`, queries in `App\Domain\CQRS\Query\<Module>`, each next to its handler ([cqrs.md](cqrs.md)).
- Handlers MAY read and write the Eloquent models in `App\Infrastructure\Models`. **They are the single place a business operation touches persistence**, and they own the transaction and the locks.
- Handlers delegate every calculation and business rule to the framework-free core ([cqrs.md](cqrs.md#writing-commands)).

### `App\Application`

- `App\Application\Dto\<Module>`: the DTOs queries return; what pages and exporters receive.
- `App\Application\Event\<Module>`: events a module raises for others, readonly, carrying ids.
- `App\Application\Contract`: interfaces the infrastructure implements (external clients, storage, exporters, the change history recorder). The container binds the implementation.
- Contains no business rule and no persistence, and never references a vendor SDK class.

### `App\Infrastructure`

- Eloquent models (`App\Infrastructure\Models`, including `User`), controllers (`App\Infrastructure\Http\Controllers`), middleware (`App\Infrastructure\Http\Middleware`), form requests (`App\Infrastructure\Http\Requests`), policies, casts, external clients (`App\Infrastructure\Integration\<System>`), exporters, console commands, jobs and listeners.
- Contains no business rule. Controllers, console commands, listeners and jobs dispatch commands and execute queries.
- **Controllers stay thin**: authorize through the policy, validate in a form request ([requests-and-validation.md](requests-and-validation.md)), dispatch one command or execute the queries the page needs, return the response. Nothing else.

### Dependency direction

- `App\Domain` MUST NOT depend on `App\Application` or `App\Infrastructure`, except `App\Domain\Providers` (above) and the CQRS handlers below. The framework-free core depends on nothing else in the application.
- CQRS handlers depend on the core, on `App\Application` (DTOs, events, contracts) and on the models in `App\Infrastructure\Models`, nothing else in `App\Infrastructure`. They never depend on controllers, requests or the session. Handlers talk to external systems through the contracts in `App\Application\Contract`; the container binds the implementation.
- `App\Application` MUST NOT depend on `App\Infrastructure`.
- `App\Infrastructure` depends on the commands, queries and DTOs. Models MAY use domain enums and value objects through casts.
- A repository interface MUST NOT be introduced unless a second implementation actually exists. A handler uses the Eloquent model directly.

The boundaries inside `App\Domain` (the core, `App\Domain\CQRS` which touches Eloquent, and `App\Domain\Providers`) run through the middle of a layer, so they are enforced by an architecture test that is a named exception in [testing.md](testing.md#architecture-tests).

## Modules

Module boundaries are namespaces inside the layers, not separate packages. Each module owns its data and exposes behaviour through its commands and queries; other modules ask, they do not reach in. The project lists its modules and what each owns in its architecture ADR (or its plan), not in `CONTEXT.md`. Each external system is one namespace, `Integration\<System>`.

- A module MUST NOT read another module's Eloquent model. Cross-module reads execute the owning module's query. Reaching into another module's model is `Major` in review even when it works.
- A module MUST NOT write another module's data. It dispatches that module's command.
- Code shared by several modules (`App\Domain\ValueObjects`, contracts) depends on no module.

## Persistence

- Migrations are forward-only. A deployed migration is never edited; a mistake is corrected by a new migration. A migration MUST be safe to run while the previous release still serves traffic: add first, remove in a later release.
- Every table scoped to a tenant, customer, sales channel or legal entity carries that scope explicitly. A query that can return another scope's data is a bug, not a filter to add later.
- A yes/no fact SHOULD be stored as a nullable timestamp of when it became true (`archived_at`, not `is_archived`). A plain boolean remains fine where the moment carries no meaning.
- A column with more than two values SHOULD be a native `ENUM` where the engine has one (MariaDB, MySQL), mapped to a PHP backed enum through a cast. PostgreSQL and the SQLite test database have no such type here: Laravel's `enum()` creates a `varchar` with a `CHECK` constraint there, which is accepted as the implementation, so "database-native" is not literally true on them. Values the client manages as data (types, tags, categories) are tables, not enums.
- Generated index and foreign key names MUST stay within 64 characters; give long ones an explicit short name. The suite checks this ([testing.md](testing.md#database-in-tests)).
- Money columns hold integer minor units (`BIGINT`); exact decimals are `DECIMAL` with the scale the source material uses. `FLOAT` and `DOUBLE` are never used for money or quantities.
- Translatable content is stored in JSON columns keyed by locale, one row per entity, never a second row per language.

## Money

The principles are in [domain.md](domain.md#money). On this stack:

- An amount is a project value object (`App\Domain\ValueObjects\Money`: integer minor units and a currency, arithmetic only within one currency), mapped to a `BIGINT` column by a cast.
- Exact decimals (rates, coefficients, percentages) are `BcMath\Number` or decimal strings computed with bcmath.
- **One rounding mode for the whole application, half away from zero by default** (`RoundingMode::HalfAwayFromZero`). A project that needs another mode (for example half-even for a tax rule) records it in its money ADR; one mode per application either way. It is applied at one place per calculation chain, inside the framework-free core, and is never chosen at a call site. PHP `round()` is never used on money.
- **A float never carries money, not even for display.** `float()` is never called on money input, and the formatter does not hand an amount to ICU as a float (`NumberFormatter::formatCurrency()` takes one): it builds the string from the integer or decimal-string parts.
- Numbers are formatted on the server by one formatter per kind (money, quantity, date, percentage); the frontend renders the string it receives.

## Admin panels

<!-- from: package/filament -->
An admin panel built on Filament is a separate user interface: the frontend rules of the Inertia pages do not apply to it.

- Its panel provider is `App\Domain\Providers\Filament\AdminPanelProvider`, listed in `bootstrap/providers.php`. Like every provider it holds wiring only, never a business rule.
- Filament resources MAY be used for plain CRUD: maintaining data that carries no business rule beyond validation.
- A custom action that performs a business operation dispatches the corresponding command ([cqrs.md](cqrs.md)) instead of reimplementing it.

> Local additions go below this managed block. Content outside the block is preserved when the guidelines are synced.
<!-- esoul-guidelines:end architecture -->
