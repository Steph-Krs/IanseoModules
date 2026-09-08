#!/usr/bin/env bash
#
# Interactive installer for ianseo Custom modules, from GitHub.
# Repository: https://github.com/Steph-Krs/IanseoModules
#
# Interactive use (recommended) - asks what to install and where:
#   curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh | bash
#
# Direct use (unattended):
#   ./install.sh [MODULE|all] [CUSTOM_PATH]
#   curl -fsSL .../install.sh | bash -s -- GUIDE /var/www/html/Modules/Custom
#
# LANGUAGE. English by default, because the repository is offered to ianseo users
# worldwide. Interactively the first question offers French; unattended, export
# IANSEO_LANG=fr before running.
#
# DELIBERATELY PLAIN ASCII, in both languages. The script is piped straight into
# the shell over the network, where the terminal encoding is whatever the user
# happens to have; unaccented French is less pretty than a mangled installer.
#
set -euo pipefail

REPO="Steph-Krs/IanseoModules"
BRANCH="main"

ARG_MODULE="${1:-}"
ARG_DEST="${2:-}"

# --- Output --------------------------------------------------------------
err()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m%s\033[0m\n' "$*"; }
ask()  { printf '\033[33m%s\033[0m' "$*"; }   # yellow, no newline

# --- Keyboard input (works even through "curl | bash") -------------------
TTY_OK=0
[ -r /dev/tty ] && TTY_OK=1
prompt() {  # $1 = message; answer in $ANSWER
  ANSWER=""
  if [ "$TTY_OK" -eq 1 ]; then
    ask "$1"
    IFS= read -r ANSWER < /dev/tty || ANSWER=""
  fi
}

# --- Language ------------------------------------------------------------
# English unless asked otherwise: IANSEO_LANG, or the first question.
LANG_SEL="en"
case "$(printf '%s' "${IANSEO_LANG:-}" | tr 'A-Z' 'a-z')" in
  fr|francais|french) LANG_SEL="fr" ;;
esac

echo
if [ -z "${IANSEO_LANG:-}" ] && [ "$TTY_OK" -eq 1 ]; then
  prompt "Language / Langue - [E]nglish, [F]rancais ? [E] : "
  case "$(printf '%s' "$ANSWER" | tr 'A-Z' 'a-z' | tr -d ' \r')" in
    f|fr|francais|french) LANG_SEL="fr" ;;
  esac
fi

# One function per message rather than an associative array: bash 3.2, which is
# what macOS still ships, has no associative arrays.
t() {
  if [ "$LANG_SEL" = "fr" ]; then
    case "$1" in
      title)       printf '%s' "=== Installateur de modules Custom ianseo ===" ;;
      missing)     printf '%s' "Commande requise manquante :" ;;
      downloading) printf '%s' "Telechargement du catalogue" ;;
      branch)      printf '%s' "branche" ;;
      dlfailed)    printf '%s' "Echec du telechargement :" ;;
      badarchive)  printf '%s' "Archive invalide." ;;
      nomodules)   printf '%s' "Aucun module trouve dans le depot." ;;
      available)   printf '%s' "Modules disponibles :" ;;
      allmodules)  printf '%s' "  a) Tous les modules" ;;
      askmodule)   printf '%s' "Que voulez-vous installer ? (numeros separes par des virgules, ou 'a' pour tous) : " ;;
      nochoice)    printf '%s' "Aucun choix. Abandon." ;;
      outoflist)   printf '%s' "Numero hors liste ignore :" ;;
      ignored)     printf '%s' "Choix ignore :" ;;
      nonevalid)   printf '%s' "Aucun module valide selectionne. Abandon." ;;
      unknown)     printf '%s' "Module inconnu :" ;;
      availare)    printf '%s' "Disponibles :" ;;
      notinter)    printf '%s' "Terminal non interactif et aucun module precise." ;;
      example)     printf '%s' "Exemple : curl -fsSL .../install.sh | bash -s -- GUIDE" ;;
      detected)    printf '%s' "Dossier ianseo detecte :" ;;
      usethis)     printf '%s' "Utiliser ce dossier ? [O/n] (ou tapez un autre chemin) : " ;;
      askpath)     printf '%s' "Chemin complet du dossier Modules/Custom : " ;;
      nopath)      printf '%s' "Aucun chemin fourni. Abandon." ;;
      notfound)    printf '%s' "Dossier introuvable :" ;;
      nocustom)    printf '%s' "Dossier Modules/Custom introuvable. Precisez-le : ... | bash -s -- <MODULE> /chemin/Custom" ;;
      installto)   printf '%s' "Installation vers :" ;;
      modules)     printf '%s' "Module(s) :" ;;
      shared)      printf '%s' "Bibliotheque partagee _shared/..." ;;
      module)      printf '%s' "Module" ;;
      keptjson)    printf '%s' "  module.json existant preserve (config et token GitHub conserves)." ;;
      done)        printf '%s' "Termine. Installe(s) :" ;;
      nextsteps)   printf '%s' "Etapes suivantes :" ;;
      next1)       printf '%s' "  1. (Linux) au besoin, ajustez le proprietaire pour le serveur web :" ;;
      next2)       printf '%s' "  2. Ouvrez ianseo : l'entree du/des module(s) apparait dans le menu." ;;
      next3)       printf '%s' "  3. Mises a jour suivantes : page admin du module (admin/update.php)." ;;
    esac
  else
    case "$1" in
      title)       printf '%s' "=== ianseo Custom module installer ===" ;;
      missing)     printf '%s' "Required command missing:" ;;
      downloading) printf '%s' "Downloading the catalogue" ;;
      branch)      printf '%s' "branch" ;;
      dlfailed)    printf '%s' "Download failed:" ;;
      badarchive)  printf '%s' "Invalid archive." ;;
      nomodules)   printf '%s' "No module found in the repository." ;;
      available)   printf '%s' "Available modules:" ;;
      allmodules)  printf '%s' "  a) All modules" ;;
      askmodule)   printf '%s' "What do you want to install? (numbers separated by commas, or 'a' for all): " ;;
      nochoice)    printf '%s' "Nothing chosen. Aborting." ;;
      outoflist)   printf '%s' "Number out of range, ignored:" ;;
      ignored)     printf '%s' "Choice ignored:" ;;
      nonevalid)   printf '%s' "No valid module selected. Aborting." ;;
      unknown)     printf '%s' "Unknown module:" ;;
      availare)    printf '%s' "Available:" ;;
      notinter)    printf '%s' "Non-interactive terminal and no module given." ;;
      example)     printf '%s' "Example: curl -fsSL .../install.sh | bash -s -- GUIDE" ;;
      detected)    printf '%s' "ianseo folder detected:" ;;
      usethis)     printf '%s' "Use this folder? [Y/n] (or type another path): " ;;
      askpath)     printf '%s' "Full path of the Modules/Custom folder: " ;;
      nopath)      printf '%s' "No path given. Aborting." ;;
      notfound)    printf '%s' "Folder not found:" ;;
      nocustom)    printf '%s' "Modules/Custom folder not found. Give it: ... | bash -s -- <MODULE> /path/Custom" ;;
      installto)   printf '%s' "Installing into:" ;;
      modules)     printf '%s' "Module(s):" ;;
      shared)      printf '%s' "Shared library _shared/..." ;;
      module)      printf '%s' "Module" ;;
      keptjson)    printf '%s' "  existing module.json kept (GitHub config and token preserved)." ;;
      done)        printf '%s' "Done. Installed:" ;;
      nextsteps)   printf '%s' "Next steps:" ;;
      next1)       printf '%s' "  1. (Linux) if needed, set the owner for the web server:" ;;
      next2)       printf '%s' "  2. Open ianseo: the module entry appears in the menu." ;;
      next3)       printf '%s' "  3. Later updates: the module admin page (admin/update.php)." ;;
    esac
  fi
}

info "$(t title)"

# --- Prerequisites -------------------------------------------------------
for bin in curl tar; do
  command -v "$bin" >/dev/null 2>&1 || { err "$(t missing) $bin"; exit 1; }
done

# --- Download and extract the repository ---------------------------------
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
URL="https://github.com/$REPO/archive/refs/heads/$BRANCH.tar.gz"
info "$(t downloading) ($REPO, $(t branch) $BRANCH)..."
curl -fsSL "$URL" -o "$TMP/repo.tar.gz" || { err "$(t dlfailed) $URL"; exit 1; }
tar -xzf "$TMP/repo.tar.gz" -C "$TMP"
SRC="$(find "$TMP" -maxdepth 1 -type d -name 'IanseoModules-*' | head -n 1)"
[ -n "$SRC" ] && [ -d "$SRC" ] || { err "$(t badarchive)"; exit 1; }

# --- Available modules (folders other than _* and .*) --------------------
AVAILABLE=()
while IFS= read -r d; do
  AVAILABLE+=("$(basename "$d")")
done < <(find "$SRC" -maxdepth 1 -mindepth 1 -type d ! -name '_*' ! -name '.*' | sort)
[ "${#AVAILABLE[@]}" -gt 0 ] || { err "$(t nomodules)"; exit 1; }

module_exists() {  # $1 = name; 0 when present
  local m
  for m in "${AVAILABLE[@]}"; do [ "$m" = "$1" ] && return 0; done
  return 1
}

# --- Which module(s) -----------------------------------------------------
SELECTED=()
if [ -n "$ARG_MODULE" ]; then
  if [ "$ARG_MODULE" = "all" ] || [ "$ARG_MODULE" = "tous" ]; then
    SELECTED=("${AVAILABLE[@]}")
  elif module_exists "$ARG_MODULE"; then
    SELECTED=("$ARG_MODULE")
  else
    err "$(t unknown) '$ARG_MODULE'. $(t availare) ${AVAILABLE[*]}"; exit 1
  fi
elif [ "$TTY_OK" -eq 1 ]; then
  echo
  info "$(t available)"
  i=1
  for m in "${AVAILABLE[@]}"; do printf '  %d) %s\n' "$i" "$m"; i=$((i+1)); done
  printf '%s\n\n' "$(t allmodules)"
  prompt "$(t askmodule)"
  choice="$(printf '%s' "$ANSWER" | tr 'A-Z' 'a-z' | tr -d ' ')"
  [ -n "$choice" ] || { err "$(t nochoice)"; exit 1; }
  if [ "$choice" = "a" ] || [ "$choice" = "all" ] || [ "$choice" = "tous" ]; then
    SELECTED=("${AVAILABLE[@]}")
  else
    IFS=',' read -ra picks <<< "$choice"
    for p in "${picks[@]}"; do
      if printf '%s' "$p" | grep -Eq '^[0-9]+$'; then
        idx=$((p-1))
        if [ "$idx" -ge 0 ] && [ "$idx" -lt "${#AVAILABLE[@]}" ]; then
          SELECTED+=("${AVAILABLE[$idx]}")
        else
          err "$(t outoflist) $p"
        fi
      elif module_exists "$p"; then
        SELECTED+=("$p")
      else
        err "$(t ignored) $p"
      fi
    done
  fi
  [ "${#SELECTED[@]}" -gt 0 ] || { err "$(t nonevalid)"; exit 1; }
else
  err "$(t notinter)"
  err "$(t example)"
  err "$(t availare) ${AVAILABLE[*]}"; exit 1
fi

# --- Locate the Modules/Custom folder ------------------------------------
find_candidate() {
  if [ -f "./menu-dist.php" ] || [ -d "./_shared" ] || [ "$(basename "$PWD")" = "Custom" ]; then
    printf '%s' "$PWD"; return 0
  fi
  local c
  for c in \
    /var/www/html/Modules/Custom \
    /var/www/ianseo/Modules/Custom \
    /var/www/ianseo/htdocs/Modules/Custom \
    /opt/ianseo/Modules/Custom \
    /opt/lampp/htdocs/Modules/Custom \
    /Applications/XAMPP/htdocs/Modules/Custom \
    /Applications/MAMP/htdocs/Modules/Custom; do
    [ -d "$c" ] && { printf '%s' "$c"; return 0; }
  done
  local hit
  hit="$(find /var/www /opt /Applications -maxdepth 6 -type d -path '*/Modules/Custom' 2>/dev/null | head -n 1 || true)"
  [ -n "$hit" ] && { printf '%s' "$hit"; return 0; }
  return 1
}

DEST=""
if [ -n "$ARG_DEST" ]; then
  DEST="$ARG_DEST"
else
  cand="$(find_candidate || true)"
  if [ "$TTY_OK" -eq 1 ]; then
    if [ -n "$cand" ]; then
      echo
      info "$(t detected) $cand"
      prompt "$(t usethis)"
      a="$(printf '%s' "$ANSWER" | tr -d ' \r')"
      # Both languages accepted whichever was chosen: a French speaker running
      # the English messages still types "o" for oui.
      case "$a" in
        ""|o|O|oui|y|Y|yes) DEST="$cand" ;;
        n|N|non|no)         DEST="" ;;
        *)                  DEST="$a" ;;
      esac
    fi
    while [ -z "$DEST" ] || [ ! -d "$DEST" ]; do
      [ -n "$DEST" ] && [ ! -d "$DEST" ] && err "$(t notfound) $DEST"
      prompt "$(t askpath)"
      DEST="$(printf '%s' "$ANSWER" | tr -d '\r')"
      [ -z "$DEST" ] && { err "$(t nopath)"; exit 1; }
    done
  else
    DEST="$cand"
    [ -n "$DEST" ] || { err "$(t nocustom)"; exit 1; }
  fi
fi
[ -d "$DEST" ] || { err "$(t notfound) $DEST"; exit 1; }
echo
info "$(t installto) $DEST"
info "$(t modules) ${SELECTED[*]}"

# --- Copy (a local module.json is preserved) -----------------------------
copy_dir() {  # $1 = folder name to copy
  local name="$1" keep=""
  if [ -f "$DEST/$name/module.json" ]; then
    keep="$TMP/$name.module.json.keep"
    cp "$DEST/$name/module.json" "$keep"
  fi
  mkdir -p "$DEST/$name"
  cp -rf "$SRC/$name/." "$DEST/$name/"
  if [ -n "$keep" ]; then
    cp "$keep" "$DEST/$name/module.json"
    info "$(t keptjson)"
  fi
  chmod -R u+rwX,go+rX "$DEST/$name" 2>/dev/null || true
}

if [ -d "$SRC/_shared" ]; then
  info "$(t shared)"
  copy_dir "_shared"
fi
for m in "${SELECTED[@]}"; do
  info "$(t module) $m..."
  copy_dir "$m"
done

echo
ok "$(t done) ${SELECTED[*]}"
echo
echo "$(t nextsteps)"
echo "$(t next1)"
for m in "${SELECTED[@]}"; do echo "       chown -R www-data:www-data \"$DEST/$m\""; done
echo "       chown -R www-data:www-data \"$DEST/_shared\""
echo "$(t next2)"
echo "$(t next3)"
