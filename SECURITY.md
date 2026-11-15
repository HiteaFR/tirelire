# Security policy

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through GitHub: on the repository page, open the **Security** tab, then
**Report a vulnerability** (GitHub private vulnerability reporting). Include the version (or
commit), the steps to reproduce, and the impact you see. Never include real bank data or secrets.

What to expect:

- an acknowledgement within 7 days;
- an assessment and, if the report is confirmed, a fix or a mitigation plan as soon as possible;
- credit in the release notes if you wish.

Tirelire is maintained in spare time: these delays are a goal, not a guarantee.

## Supported versions

Only the latest release receives security fixes.

## Security model

Tirelire is a **single-household** application. Keep these points in mind when deploying it or
assessing a finding:

- **No multi-user isolation.** Every account that can log in sees and edits all the data. Create
  accounts (`php artisan finance:user`) only for people who share the same finances. There is no
  public sign-up.
- **Login throttling.** After 5 failed attempts for the same email address *and* IP address, login
  is blocked for one minute. There is no global limit per email address: an attack spread over many
  IP addresses is not slowed down. Passwords must be at least 12 characters long.
- **Client IP and reverse proxies.** The IP used by the throttling is the one resolved through
  `TRUSTED_PROXIES`. Set it to the exact address of your reverse proxy. With `TRUSTED_PROXIES=*`,
  any client can forge `X-Forwarded-For` and bypass the per-IP limit.
- **Sessions.** Resetting a password with `finance:user` logs out every open session and revokes
  "remember me" cookies. Behind HTTPS, set `SESSION_SECURE_COOKIE=true`.
- **Private mode is cosmetic.** It only hides amounts on screen (for use in public places); the data
  is still sent to the browser that is logged in.
- **CSV import.** Only CSV or text files up to 10 MB are accepted; the type is checked from the
  content. The uploaded file is deleted from the temporary upload folder right after the import,
  whether it succeeds, fails or is refused.
- **Data at rest.** The SQLite database is not encrypted. Protect the host or the Docker volume, and
  its backups, accordingly.

Findings that require one of these documented limits to be ignored are still welcome, but will be
weighed accordingly.
