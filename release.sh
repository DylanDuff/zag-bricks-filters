#!/bin/bash
set -e

PLUGIN_SLUG="zag-filters-for-bricks"
MAIN_FILE="plugin.php"
GITHUB_REPO="DylanDuff/zag-bricks-filters"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Get the directory where this script lives
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SCRIPT_DIR"

# ── Helpers ──────────────────────────────────────────────────────────────────

get_current_version() {
    sed -n 's/.*Version: *\([0-9][0-9.]*\).*/\1/p' "$MAIN_FILE"
}

bump_version() {
    local current="$1"
    local part="$2"

    IFS='.' read -r major minor patch <<< "$current"
    patch="${patch:-0}"

    case "$part" in
        major) echo "$((major + 1)).0.0" ;;
        minor) echo "${major}.$((minor + 1)).0" ;;
        patch) echo "${major}.${minor}.$((patch + 1))" ;;
    esac
}

# ── Preflight checks ─────────────────────────────────────────────────────────

echo -e "${CYAN}── Zag Filters Release Script ──${NC}"
echo -e "${CYAN}Usage: $0 [patch|minor|major|x.y.z] [changelog entry...] ${NC}\n"

for cmd in gh zip; do
    if ! command -v "$cmd" &> /dev/null; then
        echo -e "${RED}Error: '$cmd' is required but not installed.${NC}"
        exit 1
    fi
done

if ! gh auth status &> /dev/null; then
    echo -e "${RED}Error: Not authenticated with GitHub CLI. Run 'gh auth login' first.${NC}"
    exit 1
fi

if ! git diff --quiet HEAD -- ':!release.sh' ':!.DS_Store' 2>/dev/null; then
    echo -e "${RED}Error: You have uncommitted changes. Commit or stash them first.${NC}"
    git status --short
    exit 1
fi

# The update checker only follows main, so a release cut elsewhere would tag a
# commit the plugin never sees as current.
if [[ "$(git rev-parse --abbrev-ref HEAD)" != "main" ]]; then
    echo -e "${RED}Error: Releases are cut from main.${NC}"
    exit 1
fi

# The bundle is committed and shipped as-is, so a stale build would ship old JS
# under a new version number. Rebuild and refuse if that changes anything.
if command -v pnpm &> /dev/null && [[ -d node_modules ]]; then
    pnpm build > /dev/null
    if ! git diff --quiet -- assets/js; then
        echo -e "${RED}Error: assets/js is stale. Commit the rebuilt bundle first.${NC}"
        git status --short assets/js
        exit 1
    fi
    php tests/impersonation-test.php > /dev/null || {
        echo -e "${RED}Error: tests/impersonation-test.php failed.${NC}"
        exit 1
    }
else
    echo -e "${YELLOW}Warning: pnpm or node_modules missing, skipping the bundle freshness check.${NC}"
fi

# ── Argument parsing ─────────────────────────────────────────────────────────

CURRENT_VERSION=$(get_current_version)
echo -e "Current version: ${YELLOW}${CURRENT_VERSION}${NC}\n"

if [[ $# -ge 1 ]]; then
    VERSION_ARG="$1"
    shift
    CHANGELOG_ARGS=("$@")
    NON_INTERACTIVE=true
else
    NON_INTERACTIVE=false
fi

# ── Version selection ─────────────────────────────────────────────────────────

if [[ "$NON_INTERACTIVE" == true ]]; then
    case "$VERSION_ARG" in
        patch|minor|major)
            NEW_VERSION=$(bump_version "$CURRENT_VERSION" "$VERSION_ARG")
            ;;
        [0-9]*.[0-9]*)
            if [[ ! "$VERSION_ARG" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
                echo -e "${RED}Invalid version format: ${VERSION_ARG}${NC}"
                exit 1
            fi
            NEW_VERSION="$VERSION_ARG"
            ;;
        *)
            echo -e "${RED}Invalid version argument: ${VERSION_ARG}${NC}"
            echo -e "Expected: patch | minor | major | x.y.z"
            exit 1
            ;;
    esac
else
    echo "How would you like to bump the version?"
    echo -e "  ${GREEN}1)${NC} patch  → $(bump_version "$CURRENT_VERSION" patch)"
    echo -e "  ${GREEN}2)${NC} minor  → $(bump_version "$CURRENT_VERSION" minor)"
    echo -e "  ${GREEN}3)${NC} major  → $(bump_version "$CURRENT_VERSION" major)"
    echo -e "  ${GREEN}4)${NC} custom"
    echo ""
    read -rp "Select [1-4]: " choice

    case "$choice" in
        1) NEW_VERSION=$(bump_version "$CURRENT_VERSION" patch) ;;
        2) NEW_VERSION=$(bump_version "$CURRENT_VERSION" minor) ;;
        3) NEW_VERSION=$(bump_version "$CURRENT_VERSION" major) ;;
        4)
            read -rp "Enter version number (e.g. 2.1.0): " NEW_VERSION
            if [[ ! "$NEW_VERSION" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
                echo -e "${RED}Invalid version format.${NC}"
                exit 1
            fi
            ;;
        *)
            echo -e "${RED}Invalid selection.${NC}"
            exit 1
            ;;
    esac
fi

echo ""
echo -e "Releasing: ${YELLOW}${CURRENT_VERSION}${NC} → ${GREEN}${NEW_VERSION}${NC}"

# ── Changelog entry ──────────────────────────────────────────────────────────

if [[ "$NON_INTERACTIVE" == true ]]; then
    CHANGELOG_LINES=("${CHANGELOG_ARGS[@]}")
    if [[ ${#CHANGELOG_LINES[@]} -eq 0 ]]; then
        echo -e "${RED}At least one changelog entry is required.${NC}"
        exit 1
    fi
else
    echo ""
    echo -e "${CYAN}Enter changelog notes for v${NEW_VERSION} (one per line, empty line to finish):${NC}"
    CHANGELOG_LINES=()
    while true; do
        read -rp "  * " line
        [[ -z "$line" ]] && break
        CHANGELOG_LINES+=("$line")
    done

    if [[ ${#CHANGELOG_LINES[@]} -eq 0 ]]; then
        echo -e "${RED}At least one changelog entry is required.${NC}"
        exit 1
    fi
fi

echo ""
echo -e "Version ${GREEN}${NEW_VERSION}${NC} changelog:"
for line in "${CHANGELOG_LINES[@]}"; do
    echo -e "  ${GREEN}*${NC} $line"
done

if [[ "$NON_INTERACTIVE" != true ]]; then
    echo ""
    read -rp "Continue? [y/N]: " confirm
    if [[ "$confirm" != [yY] ]]; then
        echo "Aborted."
        exit 0
    fi
fi

# ── Bump version in plugin.php ────────────────────────────────────────────────

echo -e "\n${CYAN}[1/5] Bumping version number...${NC}"
# perl rather than sed -i: the in-place flag differs between BSD and GNU sed.
perl -pi -e "s/Version:[[:space:]]*\Q${CURRENT_VERSION}\E/Version: ${NEW_VERSION}/" "$MAIN_FILE"
echo -e "  ${GREEN}✓${NC} $MAIN_FILE → $NEW_VERSION"

# ── Create release zip ────────────────────────────────────────────────────────

echo -e "\n${CYAN}[2/5] Packaging release zip...${NC}"

ZIP_NAME="${PLUGIN_SLUG}-${NEW_VERSION}.zip"
rm -f "$ZIP_NAME"

TEMP_DIR=$(mktemp -d)
PACKAGE_DIR="${TEMP_DIR}/${PLUGIN_SLUG}"
mkdir -p "$PACKAGE_DIR"

cp "$MAIN_FILE" "$PACKAGE_DIR/"
cp -r assets "$PACKAGE_DIR/"
cp -r inc "$PACKAGE_DIR/"
cp -r plugin-update-checker "$PACKAGE_DIR/"
# ref/, src/, docs/, tests/ and the build tooling are development-only.

(cd "$TEMP_DIR" && zip -r -q "${SCRIPT_DIR}/${ZIP_NAME}" "$PLUGIN_SLUG")
rm -rf "$TEMP_DIR"

ZIP_SIZE=$(du -h "$ZIP_NAME" | cut -f1 | xargs)
echo -e "  ${GREEN}✓${NC} ${ZIP_NAME} (${ZIP_SIZE})"

# ── Git commit and tag ────────────────────────────────────────────────────────

echo -e "\n${CYAN}[3/5] Committing and pushing...${NC}"
git add "$MAIN_FILE"
git commit -m "Release v${NEW_VERSION}"
git push
echo -e "  ${GREEN}✓${NC} Committed and pushed"

# ── Create GitHub release ─────────────────────────────────────────────────────

echo -e "\n${CYAN}[4/5] Creating GitHub release...${NC}"

RELEASE_NOTES="## What's Changed"
for line in "${CHANGELOG_LINES[@]}"; do
    RELEASE_NOTES+=$'\n'"- ${line}"
done
RELEASE_NOTES+=$'\n\n'"**Full Changelog**: https://github.com/${GITHUB_REPO}/compare/v${CURRENT_VERSION}...v${NEW_VERSION}"

gh release create "v${NEW_VERSION}" \
    "$ZIP_NAME" \
    --title "v${NEW_VERSION}" \
    --notes "$RELEASE_NOTES"

echo -e "  ${GREEN}✓${NC} GitHub release created with asset"

# ── Cleanup ───────────────────────────────────────────────────────────────────

echo -e "\n${CYAN}[5/5] Cleaning up...${NC}"
rm -f "$ZIP_NAME"
echo -e "  ${GREEN}✓${NC} Zip removed"

echo -e "\n${GREEN}── Release v${NEW_VERSION} complete! ──${NC}"
echo -e "View at: https://github.com/${GITHUB_REPO}/releases/tag/v${NEW_VERSION}"
