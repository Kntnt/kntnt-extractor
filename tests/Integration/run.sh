# Runs the WordPress-integration suite inside a WordPress Playground (WASM PHP
# 8.5 + SQLite), the default integration harness per
# agents.d/coding-standard/wordpress.md – it needs no MySQL server. Every
# tests/Integration/*-test.php runs against a real WordPress REST stack, and the
# process exits non-zero if any assertion fails.
#
# Internal script (not under bin/): no shebang; invoke it as `bash run.sh`.

set -euo pipefail

# Pin the Playground CLI so the harness is reproducible across machines and CI.
readonly PLAYGROUND_CLI="@wp-playground/cli@3.1.46"

# Resolve the plugin root (two levels up from this script) and a throwaway
# directory the in-Playground run writes its TAP report into.
script_dir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
plugin_root="$( cd "${script_dir}/../.." && pwd )"
results_dir="$( mktemp -d )"
trap 'rm -rf "${results_dir}"' EXIT

# Boot WordPress with the plugin mounted, run the suite, and capture the exit
# code without letting `set -e` abort before the report is printed.
set +e
npx --yes "${PLAYGROUND_CLI}" run-blueprint \
	--php=8.5 \
	--mount "${plugin_root}:/wordpress/wp-content/plugins/kntnt-extractor" \
	--mount "${results_dir}:/results" \
	--blueprint "${script_dir}/blueprint.json" \
	--verbosity=quiet
status=$?
set -e

# Surface the TAP report: a passing step's stdout is swallowed by Playground, so
# the mounted file is the only visible evidence of a green run.
if [[ -f "${results_dir}/tap.txt" ]]; then
	cat "${results_dir}/tap.txt"
fi

# Report the outcome and propagate the pass/fail up to the caller (the gate).
if [[ "${status}" -eq 0 ]]; then
	echo "Integration suite: PASS"
else
	echo "Integration suite: FAIL"
fi
exit "${status}"
