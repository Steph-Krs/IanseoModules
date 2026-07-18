#!/usr/bin/env bash
#
# Desinstallateur interactif de modules Custom ianseo.
# Depot : https://github.com/Steph-Krs/IanseoModules
#
# Usage interactif (recommande) :
#   curl -fsSL https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/uninstall.sh | bash
#
# Usage non interactif :
#   IANSEO_ASSUME_YES=1 ./uninstall.sh GUIDE [CHEMIN_CUSTOM]
#   IANSEO_ASSUME_YES=1 ./uninstall.sh all
#
# Supprime les FICHIERS du module. Une sauvegarde .tar.gz est creee avant suppression.
# Les tables de base de donnees ne sont PAS supprimees (voir message final).

set -euo pipefail

ARG_MODULE="${1:-}"
ARG_DEST="${2:-}"
ASSUME_YES="${IANSEO_ASSUME_YES:-0}"

err()  { printf '\033[31m%s\033[0m\n' "$*" >&2; }
info() { printf '\033[36m%s\033[0m\n' "$*"; }
ok()   { printf '\033[32m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m%s\033[0m\n' "$*"; }
ask()  { printf '\033[33m%s\033[0m' "$*"; }

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
info "=== Desinstallateur de modules Custom ianseo ==="

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
    [ -n "$DEST" ] || { err "Dossier Modules/Custom introuvable. Precisez-le en 2e argument."; exit 1; }
  fi
fi
[ -d "$DEST" ] || { err "Dossier introuvable : $DEST"; exit 1; }

# --- Modules installes = dossiers contenant module.json ------------------
INSTALLED=()
while IFS= read -r d; do
  n="$(basename "$d")"
  [ -f "$DEST/$n/module.json" ] && INSTALLED+=("$n")
done < <(find "$DEST" -maxdepth 1 -mindepth 1 -type d ! -name '_*' ! -name '.*' | sort)

if [ "${#INSTALLED[@]}" -eq 0 ]; then
  warn "Aucun module gere par ce systeme n'est installe dans : $DEST"
  warn "(seuls les dossiers contenant un module.json sont concernes)"
  exit 0
fi

module_installed() { local m; for m in "${INSTALLED[@]}"; do [ "$m" = "$1" ] && return 0; done; return 1; }

# --- Choix du/des module(s) a desinstaller -------------------------------
SELECTED=()
if [ -n "$ARG_MODULE" ]; then
  if [ "$ARG_MODULE" = "all" ] || [ "$ARG_MODULE" = "tous" ]; then
    SELECTED=("${INSTALLED[@]}")
  elif module_installed "$ARG_MODULE"; then
    SELECTED=("$ARG_MODULE")
  else
    err "Module '$ARG_MODULE' non installe. Installes : ${INSTALLED[*]}"; exit 1
  fi
elif [ "$TTY_OK" -eq 1 ]; then
  echo
  info "Modules installes dans $DEST :"
  i=1
  for m in "${INSTALLED[@]}"; do printf '  %d) %s\n' "$i" "$m"; i=$((i+1)); done
  printf '  a) Tous les modules\n\n'
  prompt "Que voulez-vous DESINSTALLER ? (numeros separes par des virgules, ou 'a' pour tous) : "
  choice="$(printf '%s' "$ANSWER" | tr 'A-Z' 'a-z' | tr -d ' ')"
  [ -n "$choice" ] || { err "Aucun choix. Abandon."; exit 1; }
  if [ "$choice" = "a" ] || [ "$choice" = "all" ] || [ "$choice" = "tous" ]; then
    SELECTED=("${INSTALLED[@]}")
  else
    IFS=',' read -ra picks <<< "$choice"
    for p in "${picks[@]}"; do
      if printf '%s' "$p" | grep -Eq '^[0-9]+$'; then
        idx=$((p-1))
        if [ "$idx" -ge 0 ] && [ "$idx" -lt "${#INSTALLED[@]}" ]; then
          SELECTED+=("${INSTALLED[$idx]}")
        else
          err "Numero hors liste ignore : $p"
        fi
      elif module_installed "$p"; then
        SELECTED+=("$p")
      else
        err "Choix ignore : $p"
      fi
    done
  fi
  [ "${#SELECTED[@]}" -gt 0 ] || { err "Aucun module valide selectionne. Abandon."; exit 1; }
else
  err "Terminal non interactif et aucun module precise."
  err "Exemple : IANSEO_ASSUME_YES=1 ./uninstall.sh GUIDE"
  err "Modules installes : ${INSTALLED[*]}"; exit 1
fi

# --- _shared devient-il orphelin ? ---------------------------------------
REMOVE_SHARED=0
remaining=0
for m in "${INSTALLED[@]}"; do
  keep=1
  for s in "${SELECTED[@]}"; do [ "$m" = "$s" ] && keep=0; done
  [ "$keep" -eq 1 ] && remaining=$((remaining+1))
done
if [ "$remaining" -eq 0 ] && [ -d "$DEST/_shared" ]; then
  if [ "$ASSUME_YES" = "1" ]; then
    REMOVE_SHARED=1
  elif [ "$TTY_OK" -eq 1 ]; then
    echo
    info "Plus aucun module ne restera : _shared/ (bibliotheque commune) devient inutile."
    prompt "Supprimer aussi _shared/ ? [o/N] : "
    case "$(printf '%s' "$ANSWER" | tr 'A-Z' 'a-z' | tr -d ' \r')" in
      o|oui|y|yes) REMOVE_SHARED=1 ;;
    esac
  fi
fi

# --- Recapitulatif + confirmation ----------------------------------------
TARGETS=("${SELECTED[@]}")
[ "$REMOVE_SHARED" -eq 1 ] && TARGETS+=("_shared")

echo
warn "Les dossiers suivants vont etre SUPPRIMES :"
for t in "${TARGETS[@]}"; do echo "  - $DEST/$t"; done
echo

if [ "$ASSUME_YES" != "1" ]; then
  if [ "$TTY_OK" -ne 1 ]; then
    err "Confirmation impossible (pas de terminal). Utilisez IANSEO_ASSUME_YES=1."; exit 1
  fi
  prompt "Confirmer la suppression ? Tapez 'oui' en toutes lettres : "
  [ "$(printf '%s' "$ANSWER" | tr 'A-Z' 'a-z' | tr -d ' \r')" = "oui" ] || { info "Abandon, rien n'a ete supprime."; exit 0; }
fi

# --- Sauvegarde avant suppression ----------------------------------------
BACKUP_DIR="${HOME:-/tmp}"
[ -d "$BACKUP_DIR" ] && [ -w "$BACKUP_DIR" ] || BACKUP_DIR=/tmp
BACKUP="$BACKUP_DIR/ianseo-modules-backup-$(date +%Y%m%d-%H%M%S).tar.gz"

if tar -czf "$BACKUP" -C "$DEST" "${TARGETS[@]}" 2>/dev/null; then
  ok "Sauvegarde creee : $BACKUP"
else
  warn "La sauvegarde a echoue."
  if [ "$ASSUME_YES" = "1" ]; then
    err "Abandon (mode non interactif, pas de suppression sans sauvegarde)."; exit 1
  fi
  prompt "Continuer SANS sauvegarde ? [o/N] : "
  case "$(printf '%s' "$ANSWER" | tr 'A-Z' 'a-z' | tr -d ' \r')" in
    o|oui|y|yes) ;;
    *) info "Abandon, rien n'a ete supprime."; exit 0 ;;
  esac
fi

# --- Suppression ----------------------------------------------------------
del_dir() {  # $1 = nom du dossier, relatif a $DEST
  local name="$1" target="$DEST/$1"
  [ -n "$name" ] && [ -n "$DEST" ] || { err "Cible invalide, ignoree."; return 1; }
  [ -d "$target" ] || { warn "Deja absent : $target"; return 0; }
  rm -rf -- "$target"
  info "Supprime : $target"
}

for t in "${TARGETS[@]}"; do del_dir "$t"; done

echo
ok "Desinstallation terminee : ${SELECTED[*]}"
echo
warn "Les tables de base de donnees N'ONT PAS ete supprimees."
echo "  Les donnees des modules (ex. tables TNM_*, GUIDE_*) sont conservees : une"
echo "  reinstallation les retrouvera. Pour les supprimer definitivement, passez par"
echo "  phpMyAdmin (DROP TABLE) apres avoir sauvegarde votre base."
echo
echo "Restauration eventuelle depuis la sauvegarde :"
echo "  tar -xzf \"$BACKUP\" -C \"$DEST\""
