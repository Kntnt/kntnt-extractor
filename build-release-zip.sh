# Builds the distributable kntnt-extractor.zip: the plugin's runtime files, and
# nothing else, under a single top-level directory named for the plugin slug so
# WordPress unpacks the update in place over the existing installation.
#
# The archive is produced from the tracked files at HEAD with `git archive`, so
# only committed content ships and no untracked or ignored file — at any depth,
# including one nested inside a kept runtime directory — can leak into a release.
#
# The archive name carries no version segment, so the GitHub Releases
# "latest/download" URL stays stable across versions. The self-hosted update
# checker (classes/Update_Checker.php) selects this asset BY NAME, so the
# ZIP_NAME below and Update_Checker::ASSET_NAME are the two ends of one contract
# (ADR-0005): if they drift, self-update breaks.
#
# With no arguments the zip is written to dist/ in the repo root (created if
# missing); pass --output to choose a different destination. Publishing the
# archive to a GitHub release is the release workflow's job, not this script's.
#
# Internal script (not under bin/): no shebang; invoke it as
# `bash build-release-zip.sh`.
#
# Requirements: git.
#
# Exit codes:
#   0  success
#   1  usage error: unknown, missing, or malformed arguments
#   2  a required tool is not on PATH
#   3  build failure: git archive could not produce the archive

set -euo pipefail

readonly EXIT_USAGE=1
readonly EXIT_MISSING_TOOL=2
readonly EXIT_FAILURE=3

PLUGIN_DIR="kntnt-extractor"
ZIP_NAME="${PLUGIN_DIR}.zip"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Runtime files and directories to keep in the release zip, expressed as the git
# pathspec the archive is limited to. Everything else the repository tracks (dev
# configs, Composer manifests, tests, agent docs, this script, dotfiles) is left
# out simply by not appearing here. lib/ carries the bundled Plugin Update
# Checker.
KEEP=(
	autoloader.php
	classes
	kntnt-extractor.php
	languages
	lib
	LICENSE
	README.md
	uninstall.php
)

OUTPUT_PATH=""
OUTPUT_FILE=""

# Print usage and exit with the given code (default 0).
usage() {
	cat <<'HELP'
Usage:
  build-release-zip.sh [--output <path>]
  build-release-zip.sh --help

Destination (defaults to dist/ in the repo root when none is given):
  --output <path>      Save the zip to <path>. A directory (or trailing /) saves
                       kntnt-extractor.zip inside it; otherwise the last path
                       component is the filename. The parent must exist. Omit to
                       write ./dist/kntnt-extractor.zip.

Examples:
  build-release-zip.sh
  build-release-zip.sh --output ~/Desktop/custom-name.zip
  build-release-zip.sh --output /tmp
HELP
	exit "${1:-0}"
}

# Abort with a message on stderr, using the given exit code (default: build
# failure).
die() {
	echo "Error: $1" >&2
	exit "${2:-$EXIT_FAILURE}"
}

# Parse the command line into OUTPUT_PATH.
parse_args() {

	while [[ $# -gt 0 ]]; do
		case "$1" in
			--help | -h)
				usage 0
				;;
			--output)
				[[ $# -lt 2 ]] && die "--output requires a value." "$EXIT_USAGE"
				OUTPUT_PATH="$2"
				shift 2
				;;
			*)
				echo "Error: Unknown option: $1" >&2
				echo >&2
				usage "$EXIT_USAGE"
				;;
		esac
	done

}

# With no destination given, default to building into dist/ in the repo root.
default_destination() {

	if [[ -z "$OUTPUT_PATH" ]]; then
		OUTPUT_PATH="$SCRIPT_DIR/dist"
		mkdir -p "$OUTPUT_PATH"
	fi

}

# Resolve OUTPUT_PATH into the absolute OUTPUT_FILE the archive is written to. A
# directory gets the default filename; a file path's parent must already exist.
resolve_output_file() {

	if [[ -d "$OUTPUT_PATH" ]]; then
		OUTPUT_FILE="$(cd "$OUTPUT_PATH" && pwd)/$ZIP_NAME"
	elif [[ "$OUTPUT_PATH" == */ ]]; then
		die "Directory '${OUTPUT_PATH}' does not exist." "$EXIT_USAGE"
	else
		local parent_dir="${OUTPUT_PATH%/*}"
		[[ "$parent_dir" == "$OUTPUT_PATH" ]] && parent_dir="."
		[[ ! -d "$parent_dir" ]] && die "Directory '${parent_dir}' does not exist." "$EXIT_USAGE"
		OUTPUT_FILE="$(cd "$parent_dir" && pwd)/${OUTPUT_PATH##*/}"
	fi

}

# Verify git — the only tool this build needs — is on PATH.
require_tools() {

	if ! command -v git &>/dev/null; then
		echo "Missing required tool: git" >&2
		exit "$EXIT_MISSING_TOOL"
	fi

}

main() {

	parse_args "$@"
	default_destination
	resolve_output_file
	require_tools

	# Archive the tracked runtime files at HEAD straight into the zip: git limits
	# the output to the KEEP pathspec and to committed content, so the allow-list
	# is enforced at every depth and nothing untracked can slip in. The single
	# --prefix directory lets WordPress unpack the update in place.
	git -C "$SCRIPT_DIR" archive --prefix="${PLUGIN_DIR}/" --format=zip -o "$OUTPUT_FILE" HEAD -- "${KEEP[@]}" \
		|| die "git archive failed to build ${ZIP_NAME} from HEAD."

	echo "Created: $OUTPUT_FILE ($(du -h "$OUTPUT_FILE" | cut -f1))"

	return 0

}

main "$@"
