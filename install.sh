#!/usr/bin/env bash
#
# Installateur interactif de modules Custom ianseo depuis GitHub.
# Depot : https://github.com/Steph-Krs/IanseoModules
#
# Usage interactif (recommande) — le script demande quoi installer et ou :
#   curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.sh | bash
#
# Usage direct (non interactif) :
#   ./install.sh [MODULE|all] [CHEMIN_CUSTOM]
#   curl -fsSL .../install.sh | bash -s -- GUIDE /var/www/html/Modules/Custom
#
set -euo pipefail

REPO="Steph-Krs/IanseoModules"
BRANCH="main"

ARG_MODULE="${1:-}"
ARG_DEST="${2:-}"

# --- Sorties -------------------------------------------------------------
err()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m%s\033[0m\n' "$*"; }
ask()  { printf '\033[33m%s\033[0m' "$*"; }   # jaune, sans saut de ligne

# --- Lecture clavier (fonctionne meme via "curl | bash") -----------------
TTY_OK=0
[ -r /dev/tty ] && TTY_OK=1
prompt() {  # $1 = message ; reponse dans $ANSWER
  ANSWER=""
  if [ "$TTY_OK" -eq 1 ]; then
    ask "$1"
    IFS= read -r ANSWER < /dev/tty || ANSWER=""
  fi
}

echo
info "=== Installateur de modules Custom ianseo ==="

# --- Prerequis -----------------------------------------------------------
for bin in curl tar; do
  command -v "$bin" >/dev/null 2>&1 || { err "Commande requise manquante : $bin"; exit 1; }
done

# --- Telechargement + extraction du depot --------------------------------
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
URL="https://github.com/$REPO/archive/refs/heads/$BRANCH.tar.gz"
info "Telechargement du catalogue ($REPO, branche $BRANCH)..."
curl -fsSL "$URL" -o "$TMP/repo.tar.gz" || { err "Echec du telechargement : $URL"; exit 1; }
tar -xzf "$TMP/repo.tar.gz" -C "$TMP"
SRC="$(find "$TMP" -maxdepth 1 -type d -name 'IanseoModules-*' | head -n 1)"
[ -n "$SRC" ] && [ -d "$SRC" ] || { err "Archive invalide."; exit 1; }

# --- Modules disponibles (dossiers hors _* et .*) ------------------------
AVAILABLE=()
while IFS= read -r d; do
  AVAILABLE+=("$(basename "$d")")
done < <(find "$SRC" -maxdepth 1 -mindepth 1 -type d ! -name '_*' ! -name '.*' | sort)
[ "${#AVAILABLE[@]}" -gt 0 ] || { err "Aucun module trouve dans le depot."; exit 1; }

module_exists() {  # $1 = nom ; 0 si present
  local m
  for m in "${AVAILABLE[@]}"; do [ "$m" = "$1" ] && return 0; done
  return 1
}

# --- Choix du/des module(s) ----------------------------------------------
SELECTED=()
if [ -n "$ARG_MODULE" ]; then
  if [ "$ARG_MODULE" = "all" ] || [ "$ARG_MODULE" = "tous" ]; then
    SELECTED=("${AVAILABLE[@]}")
  elif module_exists "$ARG_MODULE"; then
    SELECTED=("$ARG_MODULE")
  else
    err "Module '$ARG_MODULE' inconnu. Disponibles : ${AVAILABLE[*]}"; exit 1
  fi
elif [ "$TTY_OK" -eq 1 ]; then
  echo
  info "Modules disponibles :"
  i=1
  for m in "${AVAILABLE[@]}"; do printf '  %d) %s\n' "$i" "$m"; i=$((i+1)); done
  printf '  a) Tous les modules\n\n'
  prompt "Que voulez-vous installer ? (numeros separes par des virgules, ou 'a' pour tous) : "
  choice="$(printf '%s' "$ANSWER" | tr 'A-Z' 'a-z' | tr -d ' ')"
  [ -n "$choice" ] || { err "Aucun choix. Abandon."; exit 1; }
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
          err "Numero hors liste ignore : $p"
        fi
      elif module_exists "$p"; then
        SELECTED+=("$p")
      else
        err "Choix ignore : $p"
      fi
    done
  fi
  [ "${#SELECTED[@]}" -gt 0 ] || { err "Aucun module valide selectionne. Abandon."; exit 1; }
else
  err "Terminal non interactif et aucun module precise."
  err "Exemple : curl -fsSL .../install.sh | bash -s -- GUIDE"
  err "Modules disponibles : ${AVAILABLE[*]}"; exit 1
fi

# --- Localisation du dossier Modules/Custom ------------------------------
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
      info "Dossier ianseo detecte : $cand"
      prompt "Utiliser ce dossier ? [O/n] (ou tapez un autre chemin) : "
      a="$(printf '%s' "$ANSWER" | tr -d ' \r')"
      case "$a" in
        ""|o|O|oui|y|Y|yes) DEST="$cand" ;;
        n|N|non|no)         DEST="" ;;
        *)                  DEST="$a" ;;
      esac
    fi
    while [ -z "$DEST" ] || [ ! -d "$DEST" ]; do
      [ -n "$DEST" ] && [ ! -d "$DEST" ] && err "Dossier introuvable : $DEST"
      prompt "Chemin complet du dossier Modules/Custom : "
      DEST="$(printf '%s' "$ANSWER" | tr -d '\r')"
      [ -z "$DEST" ] && { err "Aucun chemin fourni. Abandon."; exit 1; }
    done
  else
    DEST="$cand"
    [ -n "$DEST" ] || { err "Dossier Modules/Custom introuvable. Precisez-le : ... | bash -s -- <MODULE> /chemin/Custom"; exit 1; }
  fi
fi
[ -d "$DEST" ] || { err "Dossier introuvable : $DEST"; exit 1; }
echo
info "Installation vers : $DEST"
info "Module(s) : ${SELECTED[*]}"

# --- Copie (module.json local preserve) ----------------------------------
copy_dir() {  # $1 = nom du dossier a copier
  local name="$1" keep=""
  if [ -f "$DEST/$name/module.json" ]; then
    keep="$TMP/$name.module.json.keep"
    cp "$DEST/$name/module.json" "$keep"
  fi
  mkdir -p "$DEST/$name"
  cp -rf "$SRC/$name/." "$DEST/$name/"
  if [ -n "$keep" ]; then
    cp "$keep" "$DEST/$name/module.json"
    info "  module.json existant preserve (config/token GitHub conserves)."
  fi
  chmod -R u+rwX,go+rX "$DEST/$name" 2>/dev/null || true
}

if [ -d "$SRC/_shared" ]; then
  info "Bibliotheque partagee _shared/..."
  copy_dir "_shared"
fi
for m in "${SELECTED[@]}"; do
  info "Module $m..."
  copy_dir "$m"
done

echo
ok "Termine. Installe(s) : ${SELECTED[*]}"
echo
echo "Etapes suivantes :"
echo "  1. (Linux) au besoin, ajustez le proprietaire pour le serveur web :"
for m in "${SELECTED[@]}"; do echo "       chown -R www-data:www-data \"$DEST/$m\""; done
echo "       chown -R www-data:www-data \"$DEST/_shared\""
echo "  2. Ouvrez ianseo : l'entree du/des module(s) apparait dans le menu."
echo "  3. Mises a jour suivantes : page admin du module (admin/update.php)."
