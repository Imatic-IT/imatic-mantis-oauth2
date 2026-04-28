# ImaticOAuth2 — Local Testing (Docker)

Assumes the Docker stack from `docker-compose.yml` in the project root is running.

| Service  | URL                    |
|----------|------------------------|
| Mantis   | http://localhost:8001  |
| Keycloak | http://localhost:8080  |
| pgAdmin  | http://localhost:5050  |

---

## Step 1 — Install the plugin

1. Open http://localhost:8001/manage_plugin_page.php
2. Find `ImaticOAuth2` → click **Install**

Verify the table was created:

```bash
docker exec mantis-postgres psql -U postgres -d bugtracker \
  -c "\d mantis_imatic_oauth2_identities_table"
```

---

## Step 2 — Configure Keycloak

Open http://localhost:8080 → sign in: `admin / admin`

### 2a. Create a realm

- Click the "Keycloak" dropdown (top left) → **Create realm**
- Name: `imatic` → **Create**

### 2b. Create a client

- **Clients** → **Create client**
- Client ID: `mantis`, Client type: `OpenID Connect` → **Next**
- Client authentication: **ON**, Standard flow: **ON** → **Next**
- Valid redirect URIs: `http://localhost:8001/plugin.php*`
- Valid post-logout redirect URIs: `http://localhost:8001/*`
- Web origins: `http://localhost:8001` → **Save**
- Open the **Credentials** tab → copy `Client secret`

### 2c. Create a test user

- **Users** → **Create user**
- Username: `testuser`, Email: `testuser@imatic.cz` → **Create**
- **Credentials** tab → **Set password**: `Test1234`, Temporary: **OFF** → **Save**

---

## Step 3 — Configure Mantis

Add to `config/config_inc.php`:

```php
$g_imatic_oauth2_providers = [
    'keycloak' => [
        // PHP container reaches Keycloak via Docker service name, not localhost
        'discovery_url' => 'http://keycloak:8080/realms/imatic/.well-known/openid-configuration',
        'client_id'     => 'mantis',
        'client_secret' => 'PASTE_SECRET_FROM_STEP_2b',
        'label'         => 'Sign in with Keycloak',
        'enabled'       => true,
        'public_url'    => 'http://localhost:8080',
    ],
];
```

Verify that the discovery URL is reachable from inside the container:

```bash
docker exec mantis-web curl -s http://keycloak:8080/realms/imatic/.well-known/openid-configuration \
  | python3 -m json.tool | head -10
```

---

## Step 4 — Test the login button

Open http://localhost:8001/login_page.php

The **Sign in with Keycloak** button should appear below the standard login form.

If the button is missing, check for PHP errors:

```bash
docker logs mantis-web --tail=30
```

---

## Step 5 — Test the full OAuth2 flow

1. Ensure a Mantis user with email `testuser@imatic.cz` exists:

```bash
docker exec mantis-postgres psql -U postgres -d bugtracker \
  -c "SELECT id, username, email FROM mantis_user_table WHERE email='testuser@imatic.cz';"
```

   If not → create at http://localhost:8001/manage_user_create_page.php

2. Click **Sign in with Keycloak** on the login page
3. Sign in with Keycloak: `testuser / Test1234`
4. You should be redirected back to Mantis as the linked user

Verify the identity record in the DB:

```bash
docker exec mantis-postgres psql -U postgres -d bugtracker \
  -c "SELECT * FROM mantis_imatic_oauth2_identities_table;"
```

---

## Step 6 — Test logout

Click logout in Mantis. You should be redirected to Keycloak's end_session endpoint and then back to the Mantis login page. Verify that the Keycloak SSO session is also cleared by opening http://localhost:8080 — you should not be signed in.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Button not shown | Check `docker logs mantis-web` for PHP errors |
| `state mismatch` | PHP session issue — check `session_status()` in callback.php |
| `No Mantis user found` | Email in Keycloak does not match any Mantis user email |
| Discovery URL timeout | PHP container cannot reach `keycloak:8080` — check Docker network |
| 500 Internal Server Error | `docker exec mantis-web tail -50 /var/log/apache2/mantis_error.log` |
| Keycloak "Invalid redirect_uri" | Add `http://localhost:8001/plugin.php*` to Valid redirect URIs in Keycloak |
| Keycloak "Invalid post-logout redirect_uri" | Add `http://localhost:8001/*` to Valid post-logout redirect URIs |

---

## Cleanup

```bash
# Stop the stack
docker compose -f /Users/matejbrodziansky/dev/imatic/mantis/docker-compose.yml down

# Reset Keycloak data (start fresh)
docker volume rm mantis_postgres_data
```
