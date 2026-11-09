#!/bin/sh
# Print the name whose certificate Caddy hands out when a TLS handshake names
# none, given the site addresses in SERVER_NAME ($1). The Caddyfile uses it for
# `default_sni` and `fallback_sni`; the entrypoint exports it as TLS_DEFAULT_SNI.
#
# A browser opening https://192.168.2.45 sends no server name at all: SNI is
# for host names, and RFC 6066 forbids an IP address in it. So a handshake to
# an IP only succeeds if the default certificate is one Caddy holds. That used
# to be `localhost`, always, which is right for the stock SERVER_NAME
# (`localhost, :443`) and for any list that includes localhost. It was wrong
# for an operator who wrote their machine's address, `SERVER_NAME=192.168.2.45`:
# Caddy issued a certificate for that address, then went looking for one named
# localhost, found nothing, and ended every handshake with an internal-error
# alert, which a browser cannot click through (#485). Caddy's own fallback,
# matching on the connection's local address, would not have rescued it
# either: inside a container that is the bridge address, 172.x, never the
# LAN one.
#
# The rule, in order:
#   - localhost is listed: localhost, exactly as before;
#   - otherwise the first IP address listed, so the certificate Caddy issued
#     for it is the one handed out;
#   - otherwise localhost. A real domain never needs this (browsers send its
#     name), and `:80` does no TLS, so there the value is inert.
#
# Entries are separated by commas and/or spaces, as Caddy accepts them. A
# scheme and a port are stripped; `http://` entries and `:80` serve no TLS and
# are skipped. IPv6 addresses are written in brackets, `[fd00::1]:443`.
set -e

first_ip=''

for entry in $(printf '%s' "${1:-}" | tr ',' ' '); do
	case "$entry" in
		http://*) continue ;;
		https://*) entry="${entry#https://}" ;;
	esac

	case "$entry" in
		\[*\]*)
			host="${entry#\[}"
			port="${host#*\]}"
			host="${host%%\]*}"
			;;
		*:*)
			host="${entry%%:*}"
			port=":${entry#*:}"
			;;
		*)
			host="$entry"
			port=''
			;;
	esac

	[ "$port" = ':80' ] && continue
	[ -n "$host" ] || continue

	host="$(printf '%s' "$host" | tr '[:upper:]' '[:lower:]')"
	if [ "$host" = 'localhost' ]; then
		echo 'localhost'
		exit 0
	fi

	if [ -z "$first_ip" ]; then
		case "$host" in
			*:*) first_ip="$host" ;;
			*[!0-9.]*) ;;
			*.*.*.*) first_ip="$host" ;;
		esac
	fi
done

echo "${first_ip:-localhost}"
