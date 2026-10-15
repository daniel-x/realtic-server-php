# realtic-server-php

Signaling server for [realtic](https://github.com/daniel-x/realtic) on plain PHP hosting:
`signal.php` with SQLite and repeated polling (no long-lived requests), and `log.php`, which
receives the logs that realtic's `forwardLog` sends, one file per group. The wire format is in
realtic's [docs/protocol.md](https://github.com/daniel-x/realtic/blob/main/docs/protocol.md).

```js
new RalPeerGroup({ signaling: { url: "https://example.com/realtic/signal.php", group: "demo" } });
```

## Development Server

`router.php` runs the endpoints and the realtic client files (library and demos) on PHP's
built-in server. It takes the client files from `client_dir` in `config.php` or from
`REALTIC_CLIENT_DIR`, by default from a realtic checkout next to this directory:

```sh
git clone https://github.com/daniel-x/realtic.git
git clone https://github.com/daniel-x/realtic-server-php.git
cd realtic-server-php && php -S localhost:8080 router.php
# http://localhost:8080/examples/call/?group=test   (open in several tabs or devices)
# http://localhost:8080/examples/chat/?group=test
```

The client files can also come from npm: `npm install realtic`, then
`REALTIC_CLIENT_DIR=node_modules/realtic php -S localhost:8080 router.php`. The router is not
meant for production; there, the web server serves the files.

## Deployment


Requirements: PHP 8.0 or newer with `pdo_sqlite` (SQLite 3.24 or newer), which most shared hosts have.

1. Copy `signal.php`, optionally `log.php`, and `data/` (with its `.htaccess`) to the web space.
2. Optional: copy `config.example.php` to `config.php` next to `signal.php` and adjust it.
3. Make `data/` writable for PHP. Keep the database out of reach of HTTP: the `.htaccess` covers
   Apache; on nginx deny the directory (`location ~ /data/ { deny all; }`), or set `db` to a path
   outside the document root.

Each client sends one POST per poll: every `poll_fast_ms` (250) for 5 s after a message, every
`poll_idle_ms` (3000) otherwise. The server sends both values to the clients. After the mesh is
up most signaling runs over the peers, so a connected group costs one request per client every
3 s. Every request is short, so the server needs no long-lived connections. Other settings:

| Key | Default | Meaning |
| --- | ------- | ------- |
| `db` | `data/realtic.sqlite` | SQLite file |
| `journal_mode` | `WAL` | use `DELETE` on network file systems, where WAL does not work |
| `member_timeout_s` | 30 | a client that stops polling leaves the group after this |
| `message_ttl_s` | 30 | undelivered messages expire |
| `max_group_members` | 64 | |
| `max_body_bytes`, `max_messages`, `max_message_bytes` | 262144, 64, 65536 | request limits |
| `allowed_origins` | `[]` | CORS origins for use from another site, or `['*']` |
| `log_file` | none | request log: one UTC line per request that carried messages or changed membership |
| `log_polls` | false | log idle polls too |
| `log_max_bytes`, `log_files` | 1048576, 5 | log rotation by size |
| `client_log_dir` | none | directory of `log.php` (forwarded logs, one file per group); disabled when unset |
| `client_log_max_bytes`, `client_log_files` | 16777216, 5 | rotation of each group file |
| `client_dir` | `../realtic` | `router.php` only: the realtic client files |

Messages are delivered at most once: a request returns its client's pending messages and deletes
exactly those rows in the same `BEGIN IMMEDIATE` transaction.



The realtic integration tests run against this server (`npm run test:php` in realtic, with this
repository next to it).

## License

Licensed under either of

- Apache License, Version 2.0 ([LICENSE-APACHE](LICENSE-APACHE) or
  <http://www.apache.org/licenses/LICENSE-2.0>)
- MIT license ([LICENSE-MIT](LICENSE-MIT) or <http://opensource.org/licenses/MIT>)

at your option.

Unless you explicitly state otherwise, any contribution intentionally submitted for inclusion in
the work by you, as defined in the Apache-2.0 license, shall be dual licensed as above, without any
additional terms or conditions.

## Authorship

This project, including its purpose and overall architecture, was conceived and designed by its
human authors. The implementation was generated in full with AI assistance (Claude Code), under
the human authors' direction.
