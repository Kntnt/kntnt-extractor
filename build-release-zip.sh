# Builds the distributable kntnt-extractor.zip: the plugin's runtime files, and
# nothing else, under a single top-level directory named for the plugin slug so
# WordPress unpacks the update in place over the existing installation.
#
# The archive name carries no version segment, so the GitHub Releases
# "latest/download" URL stays stable across versions. The self-hosted update
# checker (classes/Update_Checker.php) selects this asset BY NAME, so the
# ZIP_NAME below and Update_Checker::ASSET_NAME are the two ends of one contract
# (ADR-0005): if they drift, self-update breaks. The build stages the source,
# keeps only the runtime allow-list (which bundles the Plugin Update Checker
# under lib/), and zips the result.
#
# With no arguments, the zip is written to dist/ in the repo root (created if
# missing); pass --output/--update/--create to choose a different destination.
#
# Internal script (not under bin/): no shebang; invoke it as
# `bash build-release-zip.sh`.
#
# Requirements: zip.
#   With --tag: git.
#   With --update/--create: gh (GitHub CLI).
#
# Exit codes:
#   0  success
#   1  usage error: unknown, missing, or contradictory arguments
#   2  a required tool is not on PATH
#   3  build or release failure: the tag or the release is not in the expected
#      state

set -euo pipefail

readonly EXIT_USAGE=1
readonly EXIT_MISSING_TOOL=2
readonly EXIT_FAILURE=3

REPO="Kntnt/kntnt-extractor"
PLUGIN_DIR="kntnt-extractor"
ZIP_NAME="${PLUGIN_DIR}.zip"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Runtime files and directories to keep in the release zip. Everything else
# (dev configs, Composer manifests, tests, agent docs, this script, dotfiles) is
# dropped. lib/ carries the bundled Plugin Update Checker.
KEEP=(
	autoloader.php
	classes
	kntnt-extractor.php
	languages
	lib
	LICENSE
	README.md
)

TAG=""
OUTPUT_PATH=""
OUTPUT_FILE=""
RELEASE_ACTION=""
STAGE_ROOT=""

# Print usage and exit with the given code (default 0).
usage() {
	cat <<'HELP'
Usage:
  build-release-zip.sh [--output <path>]
  build-release-zip.sh --tag <tag> [--output <path>]
  build-release-zip.sh --tag <tag> --update
  build-release-zip.sh --tag <tag> --create
  build-release-zip.sh --help

Source:
  Without --tag, builds from the local working copy.
  With --tag <tag>, builds from the files at the given git tag.

Destination (defaults to dist/ in the repo root when none is given):
  --output <path>      Save the zip to <path>. A directory (or trailing /) saves
                       kntnt-extractor.zip inside it; otherwise the last path
                       component is the filename. The parent must exist. Omit to
                       write ./dist/kntnt-extractor.zip.
  --update             Upload the zip to an existing GitHub release for <tag>,
                       replacing any existing zip asset. Requires --tag.
  --create             Create a new GitHub release for <tag> and upload the zip.
                       The tag must already exist. Requires --tag.

Examples:
  build-release-zip.sh
  build-release-zip.sh --output ~/Desktop/custom-name.zip
  build-release-zip.sh --tag v0.2.0 --output /tmp
  build-release-zip.sh --tag v0.2.0 --create
  build-release-zip.sh --tag v0.2.0 --update
HELP
	exit "${1:-0}"
}

# Abort with a message on stderr, using the given exit code (default: build
# failure).
die() {
	echo "Error: $1" >&2
	exit "${2:-$EXIT_FAILURE}"
}

# Report whether a GitHub release already exists for TAG.
release_exists() {
	gh release view "$TAG" --repo "$REPO" &>/dev/null
}

# Parse the command line into TAG, OUTPUT_PATH and RELEASE_ACTION.
parse_args() {

	while [[ $# -gt 0 ]]; do
		case "$1" in
			--help | -h)
				usage 0
				;;
			--tag)
				[[ $# -lt 2 ]] && die "--tag requires a value." "$EXIT_USAGE"
				TAG="$2"
				shift 2
				;;
			--output)
				[[ $# -lt 2 ]] && die "--output requires a value." "$EXIT_USAGE"
				OUTPUT_PATH="$2"
				shift 2
				;;
			--update | --create)
				[[ -n "$RELEASE_ACTION" ]] && die "--update and --create are mutually exclusive." "$EXIT_USAGE"
				RELEASE_ACTION="${1#--}"
				shift
				;;
			*)
				echo "Error: Unknown option: $1" >&2
				echo >&2
				usage "$EXIT_USAGE"
				;;
		esac
	done

}

# Reject contradictory flag combinations and fill in the default destination.
validate_args() {

	# With no destination given, default to building into dist/ in the repo root.
	if [[ -z "$OUTPUT_PATH" && -z "$RELEASE_ACTION" ]]; then
		OUTPUT_PATH="$SCRIPT_DIR/dist"
		mkdir -p "$OUTPUT_PATH"
	fi

	[[ -n "$OUTPUT_PATH" && -n "$RELEASE_ACTION" ]] && die "--output and --${RELEASE_ACTION} cannot be combined." "$EXIT_USAGE"
	[[ -n "$RELEASE_ACTION" && -z "$TAG" ]] && die "--${RELEASE_ACTION} requires --tag." "$EXIT_USAGE"

	return 0

}

# Resolve OUTPUT_PATH into the absolute OUTPUT_FILE the zip is copied to. A
# directory gets the default filename; a file path's parent must already exist.
resolve_output_file() {

	[[ -z "$OUTPUT_PATH" ]] && return 0

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

# Verify that every tool this invocation needs is on PATH.
require_tools() {

	local missing=()
	local cmd

	command -v zip &>/dev/null || missing+=("zip")
	[[ -n "$TAG" ]] && { command -v git &>/dev/null || missing+=("git"); }
	[[ -n "$RELEASE_ACTION" ]] && { command -v gh &>/dev/null || missing+=("gh"); }

	if [[ ${#missing[@]} -gt 0 ]]; then
		for cmd in "${missing[@]}"; do
			echo "Missing required tool: $cmd" >&2
		done
		exit "$EXIT_MISSING_TOOL"
	fi

}

# Verify the tag exists and that the target release state matches the action.
check_release_state() {

	[[ -z "$TAG" ]] && return 0

	if [[ -z "$(git -C "$SCRIPT_DIR" tag -l "$TAG")" ]]; then
		echo "Error: Tag '$TAG' does not exist." >&2
		echo "Create it first:  git tag $TAG && git push origin $TAG" >&2
		exit "$EXIT_FAILURE"
	fi

	if [[ "$RELEASE_ACTION" == "update" ]] && ! release_exists; then
		die "Release '$TAG' does not exist. Use --create instead."
	fi

	if [[ "$RELEASE_ACTION" == "create" ]] && release_exists; then
		die "Release '$TAG' already exists. Use --update instead."
	fi

	return 0

}

# Copy the source into the staging tree: either the tagged tree or the local
# working copy, minus the directories no release ever contains.
stage_source() {

	if [[ -n "$TAG" ]]; then
		echo "Source: git tag $TAG"
		git -C "$SCRIPT_DIR" archive --prefix="${PLUGIN_DIR}/" "$TAG" | tar -xf - -C "$STAGE_ROOT"
	else
		echo "Source: local working copy"
		rsync -a \
			--exclude='.git' \
			--exclude='.claude' \
			--exclude='vendor' \
			--exclude='dist' \
			--exclude="$ZIP_NAME" \
			"$SCRIPT_DIR/" "$STAGE_ROOT/$PLUGIN_DIR/"
	fi

}

# Delete everything in the staging tree that is not on the keep list.
prune_to_runtime_files() {

	local entry allowed keep

	cd "$STAGE_ROOT/$PLUGIN_DIR"
	shopt -s dotglob

	for entry in *; do
		keep=false
		for allowed in "${KEEP[@]}"; do
			[[ "$entry" == "$allowed" ]] && keep=true && break
		done
		if [[ "$keep" == false ]]; then
			rm -rf "$entry"
			echo "  Removed: $entry"
		fi
	done

	shopt -u dotglob
	cd "$STAGE_ROOT"

}

# Extract the CHANGELOG section for a version into a release-notes file. Prints
# the file path; the section may legitimately be empty.
write_release_notes() {

	local version="$1"
	local notes_file="$STAGE_ROOT/release-notes.md"

	awk -v ver="$version" '
		index($0, "## [" ver "]") == 1 { capture = 1; next }
		capture && /^## \[/ { exit }
		capture && /^\[[^][]+\]:[[:space:]]/ { exit }
		capture { print }
	' "$SCRIPT_DIR/CHANGELOG.md" > "$notes_file"

	echo "$notes_file"

}

# Create the GitHub release, sourcing its body from the matching CHANGELOG
# section rather than GitHub's auto-generated commit digest.
create_release() {

	# The tag is v-prefixed; the changelog heading carries the bare version.
	local version="${TAG#v}"
	local notes_file
	notes_file="$(write_release_notes "$version")"

	# Use the changelog notes when the section had real content; otherwise fall
	# back to auto-generated notes so a release is never published note-less.
	if grep -q '[^[:space:]]' "$notes_file"; then
		printf '\n**Full changelog:** https://github.com/%s/blob/%s/CHANGELOG.md\n' "$REPO" "$TAG" >> "$notes_file"
		gh release create "$TAG" --title "$TAG" --notes-file "$notes_file" --repo "$REPO"
	else
		echo "Warning: no CHANGELOG section for ${version}; using auto-generated notes." >&2
		gh release create "$TAG" --title "$TAG" --generate-notes --repo "$REPO"
	fi

	echo "Created release: $TAG"

}

# Upload the zip, replacing an existing asset of the same name.
upload_asset() {

	if gh release view "$TAG" --repo "$REPO" --json assets --jq '.assets[].name' | grep -qx "$ZIP_NAME"; then
		echo "Replacing existing $ZIP_NAME in release ${TAG}…"
		gh release delete-asset "$TAG" "$ZIP_NAME" --repo "$REPO" --yes
	fi

	gh release upload "$TAG" "$ZIP_NAME" --repo "$REPO"
	echo "Uploaded $ZIP_NAME to release $TAG"

}

main() {

	parse_args "$@"
	validate_args
	resolve_output_file
	require_tools
	check_release_state

	# Work in a staging directory that is removed on any exit. Deliberately not
	# named TMPDIR: that variable steers mktemp and other tools, and reassigning
	# it would redirect every child process's temp files into the staging tree.
	STAGE_ROOT="$(mktemp -d)"
	trap 'rm -rf "$STAGE_ROOT"' EXIT

	stage_source
	prune_to_runtime_files

	# Create the zip with a single top-level plugin directory.
	cd "$STAGE_ROOT"
	zip -qr "$ZIP_NAME" "$PLUGIN_DIR"
	echo "Created: $ZIP_NAME ($(du -h "$ZIP_NAME" | cut -f1))"

	# Deliver the zip to the requested destination.
	if [[ -n "$OUTPUT_FILE" ]]; then
		cp "$ZIP_NAME" "$OUTPUT_FILE"
		echo "Saved: $OUTPUT_FILE"
	fi

	[[ "$RELEASE_ACTION" == "create" ]] && create_release
	[[ -n "$RELEASE_ACTION" ]] && upload_asset

	return 0

}

main "$@"
