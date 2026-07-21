# Build-tooling test: build-release-zip.sh produces the distributable archive
# (issue #12, AC3) and the archive's name matches the asset the update checker
# selects by name (AC2), so a published release self-updates in place with no
# manual file replacement (AC4).
#
# Runs on the host, not inside WordPress Playground: it exercises the bash build
# tool with the real zip/unzip toolchain, which the WASM PHP harness cannot. It
# emits a TAP report and exits non-zero on any failed check, so `composer gate`
# turns red.
#
# Internal script (not under bin/): no shebang; invoke it as
# `bash tests/Build/build-release-zip-test.sh`.

set -uo pipefail

# Resolve the repo root (two levels up from this script) and a throwaway output
# directory the build writes its zip into.
script_dir="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
repo_root="$( cd "${script_dir}/../.." && pwd )"
out_dir="$( mktemp -d )"
extract_dir="$( mktemp -d )"
trap 'rm -rf "${out_dir}" "${extract_dir}"' EXIT

# TAP bookkeeping: every check is one line, and any failure fails the process.
tests_run=0
tests_failed=0
report() {
	tests_run=$(( tests_run + 1 ))
	if [[ "$1" == "1" ]]; then
		echo "ok ${tests_run} - $2"
	else
		echo "not ok ${tests_run} - $2"
		tests_failed=$(( tests_failed + 1 ))
	fi
}

# The single filename both sides of the self-update contract must agree on: the
# ASSET_NAME the update checker matches by name, read from its own source so a
# drift between the two is caught here rather than in production.
updater_src="${repo_root}/classes/Update_Checker.php"
expected_name=""
if [[ -f "${updater_src}" ]]; then
	expected_name="$( sed -nE "s/.*ASSET_NAME[[:space:]]*=[[:space:]]*'([^']+)'.*/\1/p" "${updater_src}" | head -n1 )"
fi

# AC3: the build script exists.
build_script="${repo_root}/build-release-zip.sh"
[[ -f "${build_script}" ]] && report 1 "build-release-zip.sh exists" || report 0 "build-release-zip.sh exists"

# AC2/AC3: the update checker names the asset build-release-zip.sh must produce.
[[ "${expected_name}" == "kntnt-extractor.zip" ]] && report 1 "Update_Checker::ASSET_NAME is kntnt-extractor.zip" || report 0 "Update_Checker::ASSET_NAME is kntnt-extractor.zip"

# Build the release zip into the throwaway output directory. Every later check
# depends on this producing the named archive, so failures cascade into clean
# red rather than a fatal.
zip_path=""
if [[ -f "${build_script}" ]]; then
	bash "${build_script}" --output "${out_dir}" >/dev/null 2>&1
	if [[ -n "${expected_name}" && -f "${out_dir}/${expected_name}" ]]; then
		zip_path="${out_dir}/${expected_name}"
	fi
fi

# AC3: the build produced the distributable archive under the expected name.
[[ -n "${zip_path}" ]] && report 1 "build-release-zip.sh produces the named distributable archive" || report 0 "build-release-zip.sh produces the named distributable archive"

# List the archive entries once for the structural checks below.
entries=""
[[ -n "${zip_path}" ]] && entries="$( unzip -Z1 "${zip_path}" 2>/dev/null )"

# Returns success when the archive contains the given path.
contains() {
	printf '%s\n' "${entries}" | grep -qxF "$1"
}

# Returns success when no archive entry starts with the given prefix.
lacks_prefix() {
	! printf '%s\n' "${entries}" | grep -qE "^$1"
}

# AC4: WordPress unpacks the archive over the existing plugin directory, so it
# must contain exactly one top-level directory named for the plugin slug.
top_level="$( printf '%s\n' "${entries}" | sed -E 's#/.*##' | sort -u | grep -v '^$' )"
[[ "${top_level}" == "kntnt-extractor" ]] && report 1 "the archive holds a single top-level kntnt-extractor/ directory" || report 0 "the archive holds a single top-level kntnt-extractor/ directory"

# AC1/AC3: the runtime files the plugin needs to load and self-update are all
# present — the bootstrap, the autoloader, the wiring class, and the bundled
# update-checker library's entry point.
runtime_ok=1
for path in \
	kntnt-extractor/kntnt-extractor.php \
	kntnt-extractor/autoloader.php \
	kntnt-extractor/classes/Plugin.php \
	kntnt-extractor/classes/Update_Checker.php \
	kntnt-extractor/lib/plugin-update-checker/plugin-update-checker.php \
	kntnt-extractor/README.md \
	kntnt-extractor/LICENSE; do
	contains "${path}" || runtime_ok=0
done
[[ -n "${entries}" && "${runtime_ok}" == "1" ]] && report 1 "the archive bundles every runtime file, including the update-checker library" || report 0 "the archive bundles every runtime file, including the update-checker library"

# AC3: development-only artifacts stay out of the distributable — tests, agent
# docs, the coding standard, project docs, VCS metadata, Composer manifests,
# installed dependencies, tool configs, and the build script itself.
dev_ok=1
for prefix in \
	'kntnt-extractor/tests/' \
	'kntnt-extractor/agents\.d/' \
	'kntnt-extractor/docs/' \
	'kntnt-extractor/\.git' \
	'kntnt-extractor/\.claude' \
	'kntnt-extractor/vendor/' \
	'kntnt-extractor/composer\.(json|lock)' \
	'kntnt-extractor/phpcs\.xml\.dist' \
	'kntnt-extractor/phpstan\.neon\.dist' \
	'kntnt-extractor/build-release-zip\.sh'; do
	lacks_prefix "${prefix}" || dev_ok=0
done
[[ -n "${entries}" && "${dev_ok}" == "1" ]] && report 1 "the archive excludes all development-only artifacts" || report 0 "the archive excludes all development-only artifacts"

# Emit the TAP plan and fail the process — and the gate — on any failed check.
echo "1..${tests_run}"
if [[ "${tests_failed}" -gt 0 ]]; then
	echo "Build-tooling test: FAIL"
	exit 1
fi
echo "Build-tooling test: PASS"
