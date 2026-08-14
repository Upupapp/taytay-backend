# Runbooks

Operational documentation. Architecture lives in [`../architecture/`](../architecture/),
decisions in [`../adr/`](../adr/README.md); this is the "how do I actually run it" layer.

| Runbook | Use it when |
| --- | --- |
| [Local development](local-development.md) | Setting up a machine, booting the stack, diagnosing a local failure. |
| [Environments and secrets](environments-and-secrets.md) | Configuring an environment, handling or rotating a secret, checking what staging must not share with production, reviewing infrastructure gaps. |

## Two commands worth remembering

```bash
docker compose up -d --wait && php artisan lguids:readiness   # is my stack up?
composer check                                                # lint + full test suite
```

`lguids:readiness` writes and reads back through the database, cache, Redis, queue and
object storage rather than pinging a port, and exits non-zero when anything is unreachable.
The public `GET /api/v1/health` endpoint deliberately reports none of that — dependency
status is for operators with a shell, not for the internet.
