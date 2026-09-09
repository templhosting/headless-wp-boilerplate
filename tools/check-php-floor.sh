#!/bin/sh
# Lints every PHP file in the repo on the interpreter named in `Requires PHP`.
#
# Refuses to run on any other version, so bumping the floor in the plugin
# headers without bumping the php-floor image in compose.yaml fails loudly
# instead of quietly testing the wrong version.
set -eu

FLOOR=8.3

ACTUAL=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
if [ "$ACTUAL" != "$FLOOR" ]; then
	echo "Expected PHP $FLOOR, got $ACTUAL. Bump the php-floor image in compose.yaml or FLOOR here." >&2
	exit 1
fi

echo "Linting on PHP $ACTUAL..."

STATUS=0
for dir in wp-content tools tests; do
	[ -d "$dir" ] || continue
	for file in $(find "$dir" -name '*.php' -type f | sort); do
		if ! php -l "$file" >/dev/null; then
			echo "Parse error: $file" >&2
			STATUS=1
		fi
	done
done

if [ "$STATUS" -eq 0 ]; then
	echo "All files parse on PHP $FLOOR."
fi

exit "$STATUS"
