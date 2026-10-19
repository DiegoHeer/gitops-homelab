# Mounted over linkding's bookmarks/settings/custom.py placeholder (imported last by prod.py).
# Traefik terminates TLS, so without this Django sees plain HTTP and builds an http:// OIDC
# redirect_uri that Authelia rejects.
SECURE_PROXY_SSL_HEADER = ("HTTP_X_FORWARDED_PROTO", "https")
