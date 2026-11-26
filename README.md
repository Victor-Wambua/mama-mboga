# Mama Mboga

**Daily-price ordering for neighbourhood greengrocers, built in framework-free PHP 8.**
The owner sets the day's prices, customers order from their phones, WhatsApp orders are read into drafts, and the credit book ("deni") never loses a shilling.

[![CI](https://github.com/Victor-Wambua/mama-mboga/actions/workflows/ci.yml/badge.svg)](https://github.com/Victor-Wambua/mama-mboga/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
![PHP 8.3 | 8.4](https://img.shields.io/badge/PHP-8.3%20%7C%208.4-777bb3)
![SQLite](https://img.shields.io/badge/db-SQLite-003b57)
![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)

![Mama Mboga: owner console and customer menu](docs/screenshots/hero.png)

> **Status: portfolio build, runs locally with sample data.** "Mama Njeri Greens" is a made-up shop; every customer, phone number and order is synthetic. No payment provider and no WhatsApp API is connected (see [limits](#status-and-roadmap)). There is no hosted demo because the app needs PHP.

## The problem

A neighbourhood greengrocer's trade runs on three things: WhatsApp messages ("sukuma 2 bunch, tomato 1kg, deliver 6pm"), a notebook of who owes what (*deni*), and prices that change every morning with the market. Orders get misread. The owner re-types everyone's order into one shopping list before dawn. Credit balances get lost, argued about, or quietly written off.

## What it does

- **Daily price board.** Each morning the owner sets today's items: English and Swahili names, unit (kg, bunch, piece, heap/*rundo*), price in KES, stock, and an optional "special". One tap copies yesterday's list.
- **Mobile-first menu, no login.** Customers see today's prices, build a basket with unit-aware steppers (kg in half-kilo steps, bunches whole), pick a capacity-limited delivery slot or pickup, and order.
- **WhatsApp bridge.** After ordering, the customer gets a pre-filled `wa.me` link with their order and reference, URL-encoded correctly (Swahili text, bullets, line breaks). The order is saved either way.
- **Paste-a-message parser.** The owner pastes a customer's WhatsApp message; it is matched against today's board (English and Swahili names, aliases, typos, "kilo moja na nusu", "kumi na mbili ndizi", grams, fractions) into a draft. Anything uncertain is flagged for a human, never guessed silently.
- **Owner console** (PIN or password, sessions, CSRF): orders board with a status flow (received, packing, out, delivered, cancelled), **market shopping list** with "bought" check-offs and a print view, **delivery run sheet** by slot, **daily takings**.
- **Deni ledger.** Credit limits per customer, an append-only ledger of charges, payments and credit notes in integer KES, M-Pesa codes or numbered cash receipts, aging buckets, a statement the customer opens through a signed link, and polite reminder messages (Swahili or English) as pre-filled WhatsApp links.
- **Built for cheap phones.** The menu page is 16.8 KB of HTML plus 14.1 KB CSS and about 3 KB of vanilla JS (3.0 + 3.7 KB gzipped). Installable PWA, offline menu, dark mode, 48 px touch targets, works at 360 px.

| Customer menu (360 px) | Checkout | Paste a WhatsApp order |
| --- | --- | --- |
| ![menu](docs/screenshots/menu-phone.png) | ![checkout](docs/screenshots/checkout-phone.png) | ![paste review](docs/screenshots/paste-review-phone.png) |

| Market shopping list | Dark mode | Deni ledger |
| --- | --- | --- |
| ![market list](docs/screenshots/market-list-phone.png) | ![dark](docs/screenshots/owner-orders-dark-phone.png) | ![ledger](docs/screenshots/deni-ledger-desktop.png) |

## Quick start

Needs PHP 8.3+ with `pdo_sqlite` and `mbstring`, and Composer.

```bash
git clone https://github.com/Victor-Wambua/mama-mboga.git
cd mama-mboga
composer install
composer seed     # creates .env and an APP_KEY, migrates, loads the demo shop
composer serve    # http://127.0.0.1:8080
```

- Shop front: <http://127.0.0.1:8080>
- Owner console: <http://127.0.0.1:8080/owner>, demo PIN `246810` (sample data only; set your own with `php bin/console owner:pin <6+ characters>`).

`composer seed` resets the database. To start empty instead, run `composer setup` and then `php bin/console owner:pin ...`.

## How it works

```mermaid
flowchart LR
  subgraph Infrastructure
    FC[public/index.php] --> K[Kernel + middleware]
    K --> R[Router] --> C[Controllers]
    C --> V[PHP templates]
    SQL[(SQLite repositories)]
  end
  subgraph Application
    S[Services: orders, price board, ledger, messaging]
    P{{Ports: repositories, clock, transaction}}
  end
  subgraph Domain
    D[Money, Quantity, StockPrice, Order, Ledger, ShoppingList, DeliverySlot, WhatsApp parser]
  end
  C --> S --> D
  S --> P
  SQL -. implements .-> P
```

The **domain** layer is plain PHP with no I/O: money, orders, the ledger and the message parser can be tested with no database. The **application** layer coordinates them through small ports (repository interfaces, a clock, a transaction). **Infrastructure** holds everything with a side effect: the hand-written HTTP core (request/response, router with `{id:\d+}` parameters, middleware pipeline, an autowiring container, sessions in SQLite), PDO repositories, migrations and the template engine.

Decisions worth knowing (details in [docs/CASE-STUDY.md](docs/CASE-STUDY.md)):

- **Money is an integer number of shillings.** Quantities are integer thousandths, so 1.5 kg x KSh 85 is `1500 * 85 / 1000` rounded half-up. No float touches money.
- **The ledger is append-only in two places.** The domain `Ledger` returns a new ledger on every append and enforces its invariants; SQLite triggers also refuse `UPDATE` and `DELETE` on the table.
- **Stock and slot capacity are checked inside one `BEGIN IMMEDIATE` transaction**, so two customers cannot take the last bunch or the last delivery place.
- **Output is escaped by convention and checked by a test.** Templates echo only through `$e()` and friends; a test scans every template. The CSP forbids inline script and style, so there are none.
- **The parser flags instead of guessing.** Partial matches, ambiguity (`viazi` is Irish or sweet), unit mismatches (`sukuma 2 kg` when it is sold by the bunch) and odd amounts all go to the owner for confirmation.

## Testing

```bash
composer test       # PHPUnit
composer stan       # PHPStan level 8
composer cs         # PHP-CS-Fixer, PSR-12 (composer cs:fix to apply)
composer accuracy   # score the WhatsApp parser on its case tables
composer check      # all of the above except accuracy
```

Real numbers from the last run: **441 tests, 864 assertions, all passing** on PHP 8.4 and PHP 8.3; PHPStan level 8 reports no errors; the code style check is clean. CI runs the same on 8.3 and 8.4, then seeds the demo and requests the home page. Tests run offline against in-memory SQLite and a frozen clock. Coverage is not measured.

| Area | Tests | What is covered |
| --- | ---: | --- |
| WhatsApp parser | 197 | 178 table-driven cases plus matcher, edit distance, normaliser, address/phone/time extraction |
| Money, quantities, phones | 42 | half-up rounding table, decimal parsing, Kenyan number formats |
| Ledger, slots, takings, shopping list, orders | 52 | overpay, credit limit, duplicate receipts, one charge per order, FIFO aging, capacity, cut-off |
| Application services | 51 | stock reservation, last-item race, slot capacity, deni limit, cancel restores stock, WhatsApp links |
| Router, container, middleware, sessions, DB | 51 | route params, 404/405, circular DI, CSRF, rate limit, session fixation, migration rollback, append-only triggers |
| HTTP feature tests (in-process) | 48 | order flow, auth on every owner route, XSS, signed links, expiry, 100 KB budget |

### Parser accuracy (read this before trusting the number)

`composer accuracy` scores two hand-written tables of realistic messages:

| Batch | Cases | Exact |
| --- | ---: | ---: |
| A: written while building and tuning | 121 | 121 (100%) |
| B: written afterwards, run once before any fix | 57 | 54 (94.7%) |
| B after fixing its 3 misses | 57 | 57 (100%) |

"Exact" means every line matched the right item, quantity and unit, and every line that should be flagged was flagged. These are my own cases, not messages from real shoppers, so 100% is a regression guarantee, not a forecast. The honest number is batch B's first run: **94.7% on phrasing the parser had not been tuned on.** The 3 misses were an address read as an item, a bare "Ok", and a price ("20 bob").

## Project layout

```
src/
  Domain/          Money, Quantity, Unit, StockPrice, Order, Ledger, ShoppingList, DeliverySlot, Parsing/
  Application/     Services, ports (interfaces), WhatsApp/reminder messages, demo seeder
  Infrastructure/  Http (router, kernel, middleware), Container, Persistence (PDO, migrations), View, Security
  Web/             Controllers and signed-link helper
templates/         Plain PHP templates (public/, owner/, partials/, layouts/)
public/            Front controller, CSS, three small JS files, manifest, service worker
migrations/        Numbered .sql files
bin/console        setup, migrate, seed, owner:pin, parser:accuracy
tests/             Domain, Application, Infrastructure, Feature, Support, Fixtures (parser case tables)
docs/              CASE-STUDY.md, screenshots/
```

## Configuration

Set in `.env` (created from `.env.example`) or the environment.

| Variable | Default | Meaning |
| --- | --- | --- |
| `APP_ENV` | `local` | `production` refuses to start with a placeholder `APP_KEY` |
| `APP_KEY` | generated by `composer seed` | HMAC secret for signed statement and order links |
| `APP_URL` | empty | Public base URL used in links sent by WhatsApp; empty uses the request host |
| `APP_TIMEZONE` | `Africa/Nairobi` | Day boundaries and slot cut-offs |
| `DB_PATH` | `var/mama-mboga.sqlite` | SQLite file |
| `APP_DEBUG` | `0` | Show exception class and message on the error page |

Shop name, WhatsApp number, slot size and the owner PIN live in the database and are edited under **Settings**.

## Status and roadmap

Working: everything listed above, on a single shop with sample data. Not done, on purpose:

- **No payments integration.** M-Pesa payments are recorded by hand with their confirmation code. A Daraja STK push and callback would replace the manual step.
- **WhatsApp is a link, not an API.** The app never sends anything; the customer or owner presses send. Automatic status messages would need the Business API.
- **One shop, one owner login.** No multi-tenant model, no staff roles.
- **Rate limiting trusts `REMOTE_ADDR`.** Behind a proxy, set it up to pass the real client address.
- **The parser is rule-based and tuned on my own examples.** It has not seen real customers' messages.

Next steps: a Daraja callback that settles deni automatically, a customer "reorder last basket" button, stock-in from the market list ("bought" adds to stock), per-customer price lists, and a Playwright smoke test in CI.

---

Built by Victor Wambua. [Portfolio](https://victor-wambua.github.io/victor-portfolio/) · [Case studies](https://github.com/Victor-Wambua/case-studies). Licensed under the [MIT License](LICENSE).
