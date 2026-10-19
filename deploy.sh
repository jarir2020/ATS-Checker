#!/usr/bin/env bash
# Deploy the ATS Checker files over FTP using credentials from .env.
# The configured FTP_DIRECTORY is intentionally ignored. In this hosting setup,
# ../ still lands in the public /ftp-tools/ folder, so ../../ targets its parent.

set -Eeuo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
ENV_FILE="$SCRIPT_DIR/.env"
STATE_FILE="$SCRIPT_DIR/.deploy-state"
REMOTE_TARGET="../../"
CHECK_ONLY=false
FORCE_FULL=false
RUN_MIGRATIONS=true
BACKUP_CONFIRMED=false
PUSH_GIT=true
COMMIT_MESSAGE=''
DEPLOY_MIGRATION_URL=''
DEPLOY_MIGRATION_TOKEN=''
DEPLOY_SITE_URL=''
SITE_URL=''
MIGRATION_URL=''
MIGRATION_TMP_DIR=''
SMOKE_TMP_DIR=''
LFTP_SCRIPT=''
MIGRATION_FILES=()
GIT_BRANCH=''

usage() {
    cat <<'USAGE'
Usage: ./deploy.sh [--check | --full] [--no-migrations] [--confirm-db-backup] [--no-git] [--commit-message MESSAGE]

  --check  Compare the local project with the FTP target without uploading.
  --full   Upload the full project tree without deleting remote files.
  default  Upload changed files since the last successful deploy.
  --no-migrations       Upload code without uploading or applying SQL migrations.
  --confirm-db-backup   Confirm a verified offline DB backup if migrations are pending.
  --no-git              Skip the Git commit and push after live checks.
  --commit-message MSG  Set the commit message used when local changes need committing.

Remote target is fixed to ../../ relative to the FTP login directory, to reach
the parent of the observed /ftp-tools/ destination.
Successful deployments run live response checks, then commit non-ignored local
changes and push the current branch to origin unless --no-git is supplied.
USAGE
}

while (($#)); do
    case "$1" in
        --check)
            [[ "$FORCE_FULL" == false ]] || { usage >&2; exit 2; }
            CHECK_ONLY=true
            shift
            ;;
        --full)
            [[ "$CHECK_ONLY" == false ]] || { usage >&2; exit 2; }
            FORCE_FULL=true
            shift
            ;;
        --no-migrations)
            RUN_MIGRATIONS=false
            shift
            ;;
        --confirm-db-backup)
            BACKUP_CONFIRMED=true
            shift
            ;;
        --no-git)
            PUSH_GIT=false
            shift
            ;;
        --commit-message)
            (($# >= 2)) || { echo '--commit-message requires a value.' >&2; exit 2; }
            COMMIT_MESSAGE="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            printf 'Unknown option: %s\n' "$1" >&2
            usage >&2
            exit 2
            ;;
    esac
done

load_env_value() {
    local wanted="$1"
    local line key value

    # Environment variables supplied by the caller take precedence over .env.
    [[ -n "${!wanted:-}" ]] && return 0
    [[ -r "$ENV_FILE" ]] || return 0

    while IFS= read -r line || [[ -n "$line" ]]; do
        line="${line%$'\r'}"
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ "$line" == *=* ]] || continue

        key="${line%%=*}"
        key="${key//[[:space:]]/}"
        [[ "$key" == "$wanted" ]] || continue

        value="${line#*=}"
        value="${value#"${value%%[![:space:]]*}"}"
        value="${value%"${value##*[![:space:]]}"}"
        if [[ ${#value} -ge 2 && ( ( "${value:0:1}" == '"' && "${value: -1}" == '"' ) || ( "${value:0:1}" == "'" && "${value: -1}" == "'" ) ) ]]; then
            value="${value:1:${#value}-2}"
        fi

        printf -v "$wanted" '%s' "$value"
        return 0
    done < "$ENV_FILE"
}

for variable in FTP_HOST FTP_PORT FTP_USERNAME FTP_PASSWORD FTP_TLS_CHECK_HOSTNAME DEPLOY_MIGRATION_URL DEPLOY_MIGRATION_TOKEN DEPLOY_SITE_URL; do
    load_env_value "$variable"
done

FTP_HOST="${FTP_HOST:-}"
FTP_PORT="${FTP_PORT:-21}"
FTP_USERNAME="${FTP_USERNAME:-}"
FTP_PASSWORD="${FTP_PASSWORD:-}"
FTP_TLS_CHECK_HOSTNAME="${FTP_TLS_CHECK_HOSTNAME:-yes}"
FTP_TLS_CHECK_HOSTNAME="${FTP_TLS_CHECK_HOSTNAME,,}"
DEPLOY_MIGRATION_URL="${DEPLOY_MIGRATION_URL:-}"
DEPLOY_MIGRATION_TOKEN="${DEPLOY_MIGRATION_TOKEN:-}"
DEPLOY_SITE_URL="${DEPLOY_SITE_URL:-${DEPLOY_MIGRATION_URL:-}}"
DEPLOY_MIGRATION_URL="${DEPLOY_MIGRATION_URL:-${DEPLOY_SITE_URL:-}}"

case "$FTP_HOST" in
    ftp://*) FTP_HOST="${FTP_HOST#ftp://}" ;;
    ftps://*) FTP_HOST="${FTP_HOST#ftps://}" ;;
    *://*) echo 'FTP_HOST must be a hostname or an FTP/FTPS URL.' >&2; exit 1 ;;
esac
FTP_HOST="${FTP_HOST%%/*}"

if [[ -z "$FTP_HOST" || -z "$FTP_USERNAME" || -z "$FTP_PASSWORD" ]]; then
    echo 'FTP configuration is incomplete. Set FTP_HOST, FTP_USERNAME, and FTP_PASSWORD in .env.' >&2
    exit 1
fi
if [[ ! "$FTP_PORT" =~ ^[0-9]{1,5}$ ]] || (( FTP_PORT < 1 || FTP_PORT > 65535 )); then
    echo 'FTP_PORT must be a valid port number.' >&2
    exit 1
fi
if [[ "$FTP_TLS_CHECK_HOSTNAME" != yes && "$FTP_TLS_CHECK_HOSTNAME" != no ]]; then
    echo 'FTP_TLS_CHECK_HOSTNAME must be yes or no.' >&2
    exit 1
fi

if [[ "$CHECK_ONLY" == false ]]; then
    if [[ "$DEPLOY_SITE_URL" != https://* || "$DEPLOY_SITE_URL" == 'https://' || "$DEPLOY_SITE_URL" == *[[:space:]]* || "$DEPLOY_SITE_URL" == *'"'* || "$DEPLOY_SITE_URL" == *\\* || "$DEPLOY_SITE_URL" == *\?* || "$DEPLOY_SITE_URL" == *\#* ]]; then
        echo 'Set DEPLOY_SITE_URL to the public HTTPS application base URL without query or fragment.' >&2
        echo 'DEPLOY_MIGRATION_URL is used as a fallback when it is already configured.' >&2
        exit 1
    fi
    SITE_URL="${DEPLOY_SITE_URL%/}"
    if ! command -v curl >/dev/null 2>&1; then
        echo 'curl is required for post-deployment live response checks.' >&2
        exit 1
    fi
fi
for value in "$FTP_HOST" "$FTP_USERNAME" "$FTP_PASSWORD"; do
    if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
        echo 'FTP settings must not contain newline characters.' >&2
        exit 1
    fi
done

if [[ "$RUN_MIGRATIONS" == true && "$CHECK_ONLY" == false ]]; then
    for migration_path in "$SCRIPT_DIR"/migrations/*.sql; do
        [[ -f "$migration_path" ]] || continue
        migration_name="${migration_path##*/}"
        if [[ -L "$migration_path" || ! "$migration_name" =~ ^[0-9]{8}_[a-z0-9][a-z0-9_-]*\.sql$ ]]; then
            echo "Unsafe migration filename or symlink: $migration_name" >&2
            exit 1
        fi
        MIGRATION_FILES+=("migrations/$migration_name")
    done

    if ((${#MIGRATION_FILES[@]})); then
        if [[ "$DEPLOY_MIGRATION_URL" != https://* || "$DEPLOY_MIGRATION_URL" == 'https://' || "$DEPLOY_MIGRATION_URL" == *[[:space:]]* || "$DEPLOY_MIGRATION_URL" == *'"'* || "$DEPLOY_MIGRATION_URL" == *\\* || "$DEPLOY_MIGRATION_URL" == *\?* || "$DEPLOY_MIGRATION_URL" == *\#* ]]; then
            echo 'Set DEPLOY_MIGRATION_URL to the public HTTPS application base URL without query or fragment.' >&2
            exit 1
        fi
        if [[ ${#DEPLOY_MIGRATION_TOKEN} -lt 32 || ! "$DEPLOY_MIGRATION_TOKEN" =~ ^[A-Za-z0-9._~-]+$ ]]; then
            echo 'Set a 32-character-or-longer DEPLOY_MIGRATION_TOKEN in local .env and the matching server .env.' >&2
            exit 1
        fi
        if ! command -v curl >/dev/null 2>&1 || ! command -v php >/dev/null 2>&1; then
            echo 'curl and PHP CLI are required to check and apply deployed migrations.' >&2
            exit 1
        fi
        DEPLOY_MIGRATION_URL="${DEPLOY_MIGRATION_URL%/}"
        MIGRATION_URL="${DEPLOY_MIGRATION_URL}/index.php"
    fi
fi

if ! command -v lftp >/dev/null 2>&1; then
    echo 'lftp is required. Install it before using deploy.sh.' >&2
    exit 1
fi
if ! git -C "$SCRIPT_DIR" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
    echo 'Run deploy.sh from a Git checkout.' >&2
    exit 1
fi

if [[ "$CHECK_ONLY" == false && "$PUSH_GIT" == true ]]; then
    if ! git -C "$SCRIPT_DIR" remote get-url origin >/dev/null 2>&1; then
        echo 'Git remote "origin" is required for the post-deployment push. Use --no-git to skip Git operations.' >&2
        exit 1
    fi
    GIT_BRANCH="$(git -C "$SCRIPT_DIR" symbolic-ref --quiet --short HEAD || true)"
    if [[ -z "$GIT_BRANCH" ]]; then
        echo 'The current Git checkout is detached; check out a branch or use --no-git.' >&2
        exit 1
    fi
    is_versioned_migration_path() {
        [[ "$1" =~ ^migrations/[0-9]{8}_[a-z0-9][a-z0-9_-]*\.sql$ ]]
    }
    while IFS= read -r -d '' tracked_path; do
        case "${tracked_path##*/}" in
            .env|.env.*|keys.txt|*.dump|*.sqlite*|*.db)
                [[ "$tracked_path" == '.env.example' ]] && continue
                printf 'Refusing Git push because a local secret or database file is tracked: %s\n' "$tracked_path" >&2
                exit 1
                ;;
            *.sql)
                if is_versioned_migration_path "$tracked_path"; then
                    continue
                fi
                printf 'Refusing Git push because SQL outside a versioned migration is tracked: %s\n' "$tracked_path" >&2
                exit 1
                ;;
        esac
    done < <(git -C "$SCRIPT_DIR" ls-files -z)
    while IFS= read -r -d '' local_path; do
        if [[ "${local_path##*/}" == *.sql ]] && ! is_versioned_migration_path "$local_path"; then
            printf 'Refusing Git push because SQL outside a versioned migration is untracked: %s\n' "$local_path" >&2
            exit 1
        fi
    done < <(git -C "$SCRIPT_DIR" ls-files --others --exclude-standard -z)
fi

CURRENT_COMMIT="$(git -C "$SCRIPT_DIR" rev-parse HEAD)"
FULL_SYNC=false
FULL_REASON=''
CHANGED_FILES=()
declare -A SEEN_FILES=()

is_excluded() {
    local path="$1"
    case "/$path/" in
        */.git/*|*/.codex/*|*/.agents/*|*/.vscode/*|*/.idea/*|*/.well-known/*|*/db_sql/*|*/legacy/*|*/node_modules/*|*/storage/*|*/uploads/*|*/tmp/*)
            return 0
            ;;
    esac
    case "${path##*/}" in
        .env|.env.*|.deploy-state|.deploy-state.*|keys.txt|*.log|*.sql|*.dump|*.sqlite*|*.db|.DS_Store)
            return 0
            ;;
    esac
    return 1
}

queue_file() {
    local path="$1"
    [[ -n "$path" ]] || return 0
    [[ -z "${SEEN_FILES[$path]+present}" ]] || return 0
    SEEN_FILES[$path]=1
    is_excluded "$path" && return 0
    [[ -f "$SCRIPT_DIR/$path" && ! -L "$SCRIPT_DIR/$path" ]] || return 0
    CHANGED_FILES+=("$path")
}

if [[ "$CHECK_ONLY" == true ]]; then
    FULL_SYNC=true
    FULL_REASON='read-only remote comparison requested'
elif [[ "$FORCE_FULL" == true ]]; then
    FULL_SYNC=true
    FULL_REASON='full upload requested'
elif [[ ! -s "$STATE_FILE" ]]; then
    FULL_SYNC=true
    FULL_REASON='no successful deployment baseline exists'
else
    LAST_DEPLOYED_COMMIT="$(head -n 1 "$STATE_FILE")"
    if [[ ! "$LAST_DEPLOYED_COMMIT" =~ ^[0-9a-fA-F]{40,64}$ ]] \
        || ! git -C "$SCRIPT_DIR" cat-file -e "${LAST_DEPLOYED_COMMIT}^{commit}" 2>/dev/null \
        || ! git -C "$SCRIPT_DIR" merge-base --is-ancestor "$LAST_DEPLOYED_COMMIT" "$CURRENT_COMMIT"; then
        FULL_SYNC=true
        FULL_REASON='saved baseline is missing from or diverges from current Git history'
    else
        while IFS= read -r -d '' path; do
            queue_file "$path"
        done < <(git -C "$SCRIPT_DIR" diff --name-only --no-renames --diff-filter=ACMRT -z "$LAST_DEPLOYED_COMMIT" --)

        while IFS= read -r -d '' path; do
            queue_file "$path"
        done < <(git -C "$SCRIPT_DIR" ls-files --others --exclude-standard -z)

        if [[ " ${CHANGED_FILES[*]} " == *' composer.json '* || " ${CHANGED_FILES[*]} " == *' composer.lock '* ]]; then
            FULL_SYNC=true
            FULL_REASON='Composer dependencies changed; syncing the full tree'
        fi
    fi
fi

lftp_quote() {
    local value="$1"
    [[ "$value" != *$'\n'* && "$value" != *$'\r'* ]] || return 1
    value="${value//\\/\\\\}"
    value="${value//\"/\\\"}"
    value="${value//\$/\\\$}"
    printf '"%s"' "$value"
}

cleanup_temp_files() {
    [[ -z "$LFTP_SCRIPT" ]] || rm -f -- "$LFTP_SCRIPT"
    if [[ -n "$MIGRATION_TMP_DIR" && -d "$MIGRATION_TMP_DIR" ]]; then
        rm -rf -- "$MIGRATION_TMP_DIR"
    fi
    if [[ -n "$SMOKE_TMP_DIR" && -d "$SMOKE_TMP_DIR" ]]; then
        rm -rf -- "$SMOKE_TMP_DIR"
    fi
}

call_migration_api() {
    local mode="$1"
    local backup_confirmed="$2"
    local config_file="$MIGRATION_TMP_DIR/$mode.curl"
    local response_file="$MIGRATION_TMP_DIR/$mode.json"
    local status_file="$MIGRATION_TMP_DIR/$mode.http"
    local items_file="$MIGRATION_TMP_DIR/$mode.items"
    local http_status

    cat > "$config_file" <<CURL_CONFIG
url = "$MIGRATION_URL"
request = "POST"
header = "X-ATS-Deploy-Token: $DEPLOY_MIGRATION_TOKEN"
data = "action=deploy_migrations&mode=$mode&backup_confirmed=$backup_confirmed"
CURL_CONFIG
    chmod 600 "$config_file"

    if ! curl --config "$config_file" --proto '=https' --tlsv1.2 --connect-timeout 20 --max-time 300 --silent --show-error --output "$response_file" --write-out '%{http_code}' > "$status_file"; then
        echo 'Could not reach the HTTPS migration endpoint.' >&2
        return 1
    fi
    http_status="$(<"$status_file")"
    if [[ "$http_status" != 200 ]]; then
        php -r '$d=json_decode(file_get_contents($argv[1]),true); fwrite(STDERR, is_array($d) ? (string)($d["error"] ?? "Remote migration request failed.") : "Remote migration request failed.");' "$response_file" >&2
        printf ' (HTTP %s)\n' "$http_status" >&2
        return 1
    fi

    if ! php -r '$d=json_decode(file_get_contents($argv[1]),true); if (!is_array($d) || empty($d["ok"])) { fwrite(STDERR, is_array($d) ? (string)($d["error"] ?? "Invalid migration response.") : "Invalid migration response."); exit(1); } $key=$argv[2] === "status" ? "pending" : "applied"; foreach (($d[$key] ?? []) as $name) if (is_string($name)) echo $name, PHP_EOL;' "$response_file" "$mode" > "$items_file"; then
        echo 'The migration endpoint returned an invalid response.' >&2
        return 1
    fi
    MIGRATION_ITEMS_FILE="$items_file"
}

run_remote_migrations() {
    local answer
    local migration
    local -a pending=()
    MIGRATION_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/ats-migrations.XXXXXX")"
    chmod 700 "$MIGRATION_TMP_DIR"

    call_migration_api status no
    mapfile -t pending < "$MIGRATION_ITEMS_FILE"
    if ((${#pending[@]} == 0)); then
        echo 'Database migrations: none pending.'
        return 0
    fi

    echo 'Pending database migrations:'
    for migration in "${pending[@]}"; do
        printf '  %s\n' "$migration"
    done
    if [[ "$BACKUP_CONFIRMED" != true ]]; then
        if [[ ! -t 0 ]]; then
            echo 'A verified offline database backup is required. Rerun with --confirm-db-backup only after verifying that backup.' >&2
            return 1
        fi
        read -r -p 'After verifying the offline DB backup, type BACKUP VERIFIED to apply these migrations: ' answer
        if [[ "$answer" != 'BACKUP VERIFIED' ]]; then
            echo 'Migration stopped; no SQL was applied.' >&2
            return 1
        fi
    else
        echo 'Offline database backup confirmation supplied with --confirm-db-backup.'
    fi

    call_migration_api apply yes
    mapfile -t pending < "$MIGRATION_ITEMS_FILE"
    if ((${#pending[@]})); then
        echo 'Applied database migrations:'
        for migration in "${pending[@]}"; do
            printf '  %s\n' "$migration"
        done
    else
        echo 'Database migration endpoint reported no SQL changes.'
    fi

    call_migration_api status no
    mapfile -t pending < "$MIGRATION_ITEMS_FILE"
    if ((${#pending[@]})); then
        echo 'Database migrations are still pending after the apply request:' >&2
        for migration in "${pending[@]}"; do
            printf '  %s\n' "$migration" >&2
        done
        return 1
    fi
    echo 'Database migration status verified: no pending migrations.'
}

check_live_response() {
    local path="$1"
    local expected_status="$2"
    local expected_content_type="$3"
    local expected_body="$4"
    local response_file="$SMOKE_TMP_DIR/response.$((++SMOKE_RESPONSE_INDEX))"
    local response status content_type

    if ! response="$(curl --proto '=https' --proto-redir '=https' --tlsv1.2 --location --max-redirs 5 --silent --show-error \
        --retry 2 --retry-delay 2 --connect-timeout 20 --max-time 30 \
        --output "$response_file" --write-out '%{http_code}|%{content_type}' \
        "$SITE_URL$path")"; then
        echo "Live response check could not reach $path." >&2
        return 1
    fi
    status="${response%%|*}"
    content_type="${response#*|}"
    if [[ "$status" != "$expected_status" || "$content_type" != *"$expected_content_type"* ]]; then
        printf 'Live response check failed for %s: received HTTP %s (%s), expected HTTP %s (%s).\n' \
            "$path" "$status" "$content_type" "$expected_status" "$expected_content_type" >&2
        return 1
    fi
    if [[ -n "$expected_body" ]] && ! grep -Fq -- "$expected_body" "$response_file"; then
        echo "Live response check failed for $path: expected response content was missing." >&2
        return 1
    fi
    printf 'Live check passed: %s -> HTTP %s (%s)\n' "$path" "$status" "$content_type"
}

run_live_smoke_checks() {
    SMOKE_TMP_DIR="$(mktemp -d "${TMPDIR:-/tmp}/ats-smoke.XXXXXX")"
    chmod 700 "$SMOKE_TMP_DIR"
    SMOKE_RESPONSE_INDEX=0

    echo 'Running post-deployment live response checks:'
    check_live_response '/?page=login' 200 'text/html' 'PRIVATE CV WORKSPACE'
    check_live_response '/?action=vault_meta' 401 'application/json' 'Sign in to continue.'
    check_live_response '/assets/css/ats.css' 200 'text/css' ''
    check_live_response '/assets/js/vault.js' 200 'javascript' ''
    check_live_response '/assets/js/cv-renderer.js' 200 'javascript' ''
    check_live_response '/assets/js/generator.js' 200 'javascript' ''
    check_live_response '/assets/js/checker.js' 200 'javascript' ''
    check_live_response '/assets/js/converter.js' 200 'javascript' ''
}

push_git_changes() {
    local message
    if [[ "$PUSH_GIT" != true ]]; then
        echo 'Git commit and push skipped with --no-git.'
        return 0
    fi

    echo 'Committing local changes and pushing to origin:'
    if [[ -n "$(git -C "$SCRIPT_DIR" status --porcelain)" ]]; then
        git -C "$SCRIPT_DIR" add -A -- .
        if ! git -C "$SCRIPT_DIR" diff --cached --quiet; then
            message="$COMMIT_MESSAGE"
            if [[ -z "$message" ]]; then
                message="Deploy: $(date '+%Y-%m-%d %H:%M:%S %Z')"
            fi
            git -C "$SCRIPT_DIR" commit -m "$message"
        fi
    else
        echo 'No local changes need committing.'
    fi

    git -C "$SCRIPT_DIR" push origin "$GIT_BRANCH"
    echo 'Git commit and push completed.'
}

umask 077
LFTP_SCRIPT="$(mktemp "${TMPDIR:-/tmp}/ats-deploy.XXXXXX.lftp")"
trap cleanup_temp_files EXIT

{
    cat <<'LFTP_HEAD'
set cmd:fail-exit yes
set net:timeout 30
set net:max-retries 2
set ftp:passive-mode yes
set ftp:ssl-allow yes
set ftp:ssl-force yes
set ssl:verify-certificate yes
LFTP_HEAD
    printf 'set ssl:check-hostname %s\n' "$FTP_TLS_CHECK_HOSTNAME"
    printf 'open -p %s %s\n' "$FTP_PORT" "$(lftp_quote "ftp://$FTP_HOST")"
    printf 'user %s %s\n' "$(lftp_quote "$FTP_USERNAME")" "$(lftp_quote "$FTP_PASSWORD")"
    printf 'cd %s\n' "$(lftp_quote "$REMOTE_TARGET")"

    if [[ "$FULL_SYNC" == true ]]; then
        dry_run_flag=''
        [[ "$CHECK_ONLY" == true ]] && dry_run_flag='--dry-run'
        printf 'mirror --reverse --verbose --no-perms %s \\\n' "$dry_run_flag"
        printf '  --exclude-glob %s --exclude-glob %s --exclude-glob %s \\\n' \
            "$(lftp_quote '.env*')" "$(lftp_quote '.git*')" "$(lftp_quote '.deploy-state*')"
        printf '  --exclude-glob %s --exclude-glob %s --exclude-glob %s \\\n' \
            "$(lftp_quote 'keys.txt')" "$(lftp_quote '*.log')" "$(lftp_quote '*.sql')"
        printf '  --exclude-glob %s --exclude-glob %s --exclude-glob %s \\\n' \
            "$(lftp_quote '*.dump')" "$(lftp_quote '*.sqlite*')" "$(lftp_quote 'node_modules')"
        printf '  --exclude-glob %s \\\n' "$(lftp_quote '*.db')"
        printf '  --exclude-glob %s --exclude-glob %s --exclude-glob %s \\\n' \
            "$(lftp_quote '.git*/')" "$(lftp_quote '.codex*/')" "$(lftp_quote '.agents*/')"
        printf '  --exclude-glob %s --exclude-glob %s --exclude-glob %s \\\n' \
            "$(lftp_quote '.vscode*/')" "$(lftp_quote '.idea*/')" "$(lftp_quote '.well-known*/')"
        printf '  --exclude-glob %s --exclude-glob %s --exclude-glob %s \\\n' \
            "$(lftp_quote 'db_sql*/')" "$(lftp_quote 'legacy*/')" "$(lftp_quote 'node_modules*/')"
        printf '  --exclude-glob %s --exclude-glob %s --exclude-glob %s \\\n' \
            "$(lftp_quote 'storage*/')" "$(lftp_quote 'uploads*/')" "$(lftp_quote 'tmp*/')"
        printf '  %s %s\n' "$(lftp_quote "$SCRIPT_DIR/")" "$(lftp_quote '.')"
    elif [[ "${#CHANGED_FILES[@]}" -eq 0 ]]; then
        echo 'echo "No changed files to upload"'
    else
        declare -A CREATED_DIRS=()
        for path in "${CHANGED_FILES[@]}"; do
            remote_dir='.'
            if [[ "$path" == */* ]]; then
                remote_dir="${path%/*}"
            fi
            if [[ -z "${CREATED_DIRS[$remote_dir]+present}" ]]; then
                printf 'mkdir -p %s\n' "$(lftp_quote "$remote_dir")"
                CREATED_DIRS[$remote_dir]=1
            fi
            printf 'put -O %s %s\n' \
                "$(lftp_quote "$remote_dir")" \
                "$(lftp_quote "$SCRIPT_DIR/$path")"
        done
    fi
    if [[ "$CHECK_ONLY" == false && "$RUN_MIGRATIONS" == true ]] && ((${#MIGRATION_FILES[@]})); then
        for path in "${MIGRATION_FILES[@]}"; do
            printf 'put -O %s %s\n' \
                "$(lftp_quote 'migrations')" \
                "$(lftp_quote "$SCRIPT_DIR/$path")"
        done
    fi
    echo 'bye'
} > "$LFTP_SCRIPT"

chmod 600 "$LFTP_SCRIPT"

echo "Local project: $SCRIPT_DIR"
echo "FTP target: ../../ (parent of the observed /ftp-tools/ destination)"
echo 'FTP_DIRECTORY is ignored because it is known to point at the wrong directory.'
if [[ "$FTP_TLS_CHECK_HOSTNAME" == no ]]; then
    echo 'Warning: TLS hostname matching is disabled by FTP_TLS_CHECK_HOSTNAME=no; certificate authority validation remains enabled.' >&2
fi
if [[ "$CHECK_ONLY" == true ]]; then
    echo 'Mode: read-only comparison; no files or deployment state will be changed.'
elif [[ "$FULL_SYNC" == true ]]; then
    echo "Mode: full upload ($FULL_REASON); remote-only files will be preserved."
else
    echo "Mode: incremental upload of ${#CHANGED_FILES[@]} changed file(s)."
fi
if [[ "$CHECK_ONLY" == false && "$PUSH_GIT" == true ]]; then
    echo "Git handoff: commit non-ignored local changes and push origin/$GIT_BRANCH after live checks."
elif [[ "$CHECK_ONLY" == false ]]; then
    echo 'Git handoff: disabled with --no-git.'
fi
if [[ "$CHECK_ONLY" == false && "$RUN_MIGRATIONS" == true ]] && ((${#MIGRATION_FILES[@]})); then
    echo 'Migrations: versioned SQL files will be uploaded and checked after the code upload.'
elif [[ "$CHECK_ONLY" == false && "$RUN_MIGRATIONS" == false ]]; then
    echo 'Migrations: disabled with --no-migrations.'
fi

if ! lftp -f "$LFTP_SCRIPT"; then
    echo 'FTP operation failed. The upload may be partial; review the specific lftp error above before retrying.' >&2
    echo 'The database migration endpoint was not called because the FTP phase did not finish.' >&2
    exit 1
fi

if [[ "$CHECK_ONLY" == true ]]; then
    echo 'Read-only FTP comparison finished.'
    exit 0
fi

if [[ "$RUN_MIGRATIONS" == true ]] && ((${#MIGRATION_FILES[@]})); then
    run_remote_migrations
fi

run_live_smoke_checks
push_git_changes

STATE_TEMP="${STATE_FILE}.tmp.$$"
DEPLOYED_COMMIT="$(git -C "$SCRIPT_DIR" rev-parse HEAD)"
printf '%s\n' "$DEPLOYED_COMMIT" > "$STATE_TEMP"
mv -f "$STATE_TEMP" "$STATE_FILE"
echo 'FTP upload, live response checks, and Git handoff finished; deployment baseline saved locally.'
