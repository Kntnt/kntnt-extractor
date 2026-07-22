# Runs the MySQL-backed integration check for GET /tables' engine statistics —
# the standard's DDEV fallback (agents.d/coding-standard/wordpress.md) for the
# MySQL-specific `SHOW TABLE STATUS` columns that the default WordPress Playground
# suite cannot exercise, because Playground runs on SQLite and stubs `Rows`,
# `Data_length`, and `Index_length` to zero.
#
# It provisions a throwaway DDEV WordPress project on a real MySQL/InnoDB server
# in a temporary directory, activates the plugin under test, seeds a little data,
# runs tables-size-test.php through `wp eval-file`, and tears the whole project
# down again — leaving the machine state-neutral whether it passes or fails.
#
# Not part of `composer gate`: MySQL-backed tests are the exception, kept out of
# the fast PR-time suite. Requires Docker and DDEV. Invoke as `bash run.sh`.
#
# Internal script (not under bin/): no shebang; invoke it as `bash run.sh`.

set -euo pipefail

# A fixed project name so a leftover project from an aborted run can be cleared
# before this one starts.
readonly PROJECT_NAME="kntnt-extractor-ddev-test"

# Resolve the plugin root (two levels up) and a throwaway project directory that
# holds the disposable WordPress install and its DDEV configuration.
script_dir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
plugin_root="$( cd "${script_dir}/../../.." && pwd )"
project_dir="$( mktemp -d )"

# Tear the project and its containers down and remove the temp directory on any
# exit, so nothing survives the run — the DB is disposable and the estimates are
# read-only, so there is nothing to preserve.
cleanup() {
	ddev delete -Oy "${PROJECT_NAME}" >/dev/null 2>&1 || true
	rm -rf "${project_dir}"
}
trap cleanup EXIT

# Clear any project of the same name left by an aborted earlier run before
# claiming the name again.
ddev delete -Oy "${PROJECT_NAME}" >/dev/null 2>&1 || true

# Configure and start a WordPress project on DDEV's bundled MySQL-family service,
# with the temp directory itself as the docroot. PHP 8.5 matches the plugin's
# `Requires PHP` floor, without which activation refuses to run.
cd "${project_dir}"
ddev config --project-type=wordpress --docroot=. --project-name="${PROJECT_NAME}" --php-version=8.5 >/dev/null
ddev start >/dev/null

# Install WordPress against DDEV's database (host/user/pass/name all `db`), then
# make the plugin available and active.
ddev wp core download >/dev/null
ddev wp config create --dbname=db --dbuser=db --dbpass=db --dbhost=db --force >/dev/null
ddev wp core install \
	--url="http://${PROJECT_NAME}.ddev.site" \
	--title="Kntnt Extractor DDEV Test" \
	--admin_user=admin \
	--admin_password=admin \
	--admin_email=admin@example.com \
	--skip-email >/dev/null

# Copy the plugin's runtime files (never the test harness) into the install and
# activate it, so its REST routes and Operate grant are live.
plugin_dest="${project_dir}/wp-content/plugins/kntnt-extractor"
mkdir -p "${plugin_dest}"
cp "${plugin_root}/kntnt-extractor.php" "${plugin_dest}/"
cp "${plugin_root}/autoloader.php" "${plugin_dest}/"
cp -R "${plugin_root}/classes" "${plugin_dest}/"
cp -R "${plugin_root}/lib" "${plugin_dest}/"
cp -R "${plugin_root}/languages" "${plugin_dest}/" 2>/dev/null || true
ddev wp plugin activate kntnt-extractor >/dev/null

# Seed enough content that the posts table is non-empty, then refresh InnoDB's
# statistics so SHOW TABLE STATUS reports deterministic, non-zero estimates
# rather than stale zeros right after a fresh install.
ddev wp post generate --count=25 >/dev/null
ddev wp db query "ANALYZE TABLE wp_options, wp_posts, wp_users;" >/dev/null

# Run every MySQL-backed check through a booted WordPress and propagate their
# pass/fail: each *-test.php in this directory is copied in and run through
# `wp eval-file`, and the first non-zero exit fails the whole run.
status=0
for test_file in "${script_dir}"/*-test.php; do
	cp "${test_file}" "${project_dir}/$( basename "${test_file}" )"
	set +e
	ddev wp eval-file "$( basename "${test_file}" )"
	rc=$?
	set -e
	if [[ "${rc}" -ne 0 ]]; then
		status="${rc}"
	fi
done

# Report the outcome and propagate it to the caller.
if [[ "${status}" -eq 0 ]]; then
	echo "DDEV integration check: PASS"
else
	echo "DDEV integration check: FAIL"
fi
exit "${status}"
