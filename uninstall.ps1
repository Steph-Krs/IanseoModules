# Desinstallateur interactif de modules Custom ianseo (Windows).
# Depot : https://github.com/Steph-Krs/IanseoModules
#
# Usage interactif (recommande) :
#   irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/uninstall.ps1 | iex
#
# Usage non interactif (definir les variables avant le pipe) :
#   $IanseoModule='GUIDE'; $IanseoAssumeYes=$true; irm .../uninstall.ps1 | iex
#
# Supprime les FICHIERS du module. Une sauvegarde .zip est creee avant suppression.
# Les tables de base de donnees ne sont PAS supprimees (voir message final).

function Invoke-IanseoUninstall {
    function Info($m)   { Write-Host $m -ForegroundColor Cyan }
    function Ok($m)     { Write-Host $m -ForegroundColor Green }
    function ErrMsg($m) { Write-Host $m -ForegroundColor Red }
    function Warn($m)   { Write-Host $m -ForegroundColor Yellow }

    Write-Host ''
    Info '=== Desinstallateur de modules Custom ianseo (Windows) ==='

    $argModule = ''
    if (Get-Variable -Name IanseoModule -Scope Global -ErrorAction SilentlyContinue) { $argModule = "$($global:IanseoModule)" }
    $argDest = ''
    if (Get-Variable -Name IanseoDest -Scope Global -ErrorAction SilentlyContinue) { $argDest = "$($global:IanseoDest)" }
    $assumeYes = $false
    if (Get-Variable -Name IanseoAssumeYes -Scope Global -ErrorAction SilentlyContinue) { $assumeYes = [bool]$global:IanseoAssumeYes }
    $interactive = [Environment]::UserInteractive

    # --- Localisation du dossier Modules\Custom --------------------------
    function Find-Candidate {
        $cwd = (Get-Location).Path
        if ((Test-Path (Join-Path $cwd 'menu-dist.php')) -or
            (Test-Path (Join-Path $cwd '_shared')) -or
            ((Split-Path $cwd -Leaf) -eq 'Custom')) { return $cwd }
        $cands = @(
            'C:\xampp\htdocs\Modules\Custom',
            'C:\ianseo\htdocs\Modules\Custom',
            (Join-Path $env:SystemDrive 'xampp\htdocs\Modules\Custom')
        )
        foreach ($c in $cands) { if (Test-Path $c -PathType Container) { return $c } }
        return $null
    }

    $dest = ''
    if ($argDest) {
        $dest = $argDest
    } else {
        $cand = Find-Candidate
        if ($interactive) {
            if ($cand) {
                Info "Dossier ianseo detecte : $cand"
                $a = (Read-Host 'Utiliser ce dossier ? [O/n] (ou tapez un autre chemin)').Trim()
                switch -Regex ($a) {
                    '^$|^(o|oui|y|yes)$' { $dest = $cand }
                    '^(n|non|no)$'       { $dest = '' }
                    default              { $dest = $a }
                }
            }
            while (-not $dest -or -not (Test-Path $dest -PathType Container)) {
                if ($dest -and -not (Test-Path $dest -PathType Container)) { ErrMsg "Dossier introuvable : $dest" }
                $dest = (Read-Host 'Chemin complet du dossier Modules\Custom').Trim()
                if (-not $dest) { ErrMsg 'Aucun chemin fourni. Abandon.'; return }
            }
        } else {
            $dest = $cand
            if (-not $dest) { ErrMsg 'Dossier Modules\Custom introuvable. Definissez $IanseoDest.'; return }
        }
    }
    if (-not (Test-Path $dest -PathType Container)) { ErrMsg "Dossier introuvable : $dest"; return }

    # --- Modules installes = dossiers contenant module.json --------------
    $installed = @(Get-ChildItem -Path $dest -Directory |
        Where-Object { $_.Name -notlike '_*' -and $_.Name -notlike '.*' -and (Test-Path (Join-Path $_.FullName 'module.json')) } |
        Select-Object -ExpandProperty Name | Sort-Object)

    if ($installed.Count -eq 0) {
        Warn "Aucun module gere par ce systeme n'est installe dans : $dest"
        Warn '(seuls les dossiers contenant un module.json sont concernes)'
        return
    }

    # --- Choix du/des module(s) ------------------------------------------
    $selected = @()
    if ($argModule) {
        if ($argModule -eq 'all' -or $argModule -eq 'tous') {
            $selected = $installed
        } elseif ($installed -contains $argModule) {
            $selected = @($argModule)
        } else {
            ErrMsg "Module '$argModule' non installe. Installes : $($installed -join ', ')"; return
        }
    } elseif ($interactive) {
        Write-Host ''
        Info "Modules installes dans $dest :"
        for ($i = 0; $i -lt $installed.Count; $i++) { Write-Host ("  {0}) {1}" -f ($i + 1), $installed[$i]) }
        Write-Host '  a) Tous les modules'
        Write-Host ''
        $ans = (Read-Host "Que voulez-vous DESINSTALLER ? (numeros separes par des virgules, ou 'a' pour tous)").Trim().ToLower()
        if (-not $ans) { ErrMsg 'Aucun choix. Abandon.'; return }
        if ($ans -eq 'a' -or $ans -eq 'all' -or $ans -eq 'tous') {
            $selected = $installed
        } else {
            foreach ($p in ($ans -split ',')) {
                $p = $p.Trim()
                if ($p -match '^\d+$') {
                    $idx = [int]$p - 1
                    if ($idx -ge 0 -and $idx -lt $installed.Count) { $selected += $installed[$idx] }
                    else { ErrMsg "Numero hors liste ignore : $p" }
                } elseif ($installed -contains $p) {
                    $selected += $p
                } else {
                    ErrMsg "Choix ignore : $p"
                }
            }
        }
        $selected = @($selected | Select-Object -Unique)
        if ($selected.Count -eq 0) { ErrMsg 'Aucun module valide selectionne. Abandon.'; return }
    } else {
        ErrMsg 'Session non interactive et aucun module precise.'
        ErrMsg "Exemple : `$IanseoModule='GUIDE'; `$IanseoAssumeYes=`$true; irm .../uninstall.ps1 | iex"
        ErrMsg "Modules installes : $($installed -join ', ')"; return
    }

    # --- _shared devient-il orphelin ? -----------------------------------
    $removeShared = $false
    $remaining = @($installed | Where-Object { $selected -notcontains $_ })
    if ($remaining.Count -eq 0 -and (Test-Path (Join-Path $dest '_shared'))) {
        if ($assumeYes) {
            $removeShared = $true
        } elseif ($interactive) {
            Write-Host ''
            Info 'Plus aucun module ne restera : _shared\ (bibliotheque commune) devient inutile.'
            $a = (Read-Host 'Supprimer aussi _shared\ ? [o/N]').Trim().ToLower()
            if ($a -eq 'o' -or $a -eq 'oui' -or $a -eq 'y' -or $a -eq 'yes') { $removeShared = $true }
        }
    }

    $targets = @($selected)
    if ($removeShared) { $targets += '_shared' }

    # --- Recapitulatif + confirmation ------------------------------------
    Write-Host ''
    Warn 'Les dossiers suivants vont etre SUPPRIMES :'
    foreach ($t in $targets) { Write-Host "  - $(Join-Path $dest $t)" }
    Write-Host ''

    if (-not $assumeYes) {
        if (-not $interactive) { ErrMsg 'Confirmation impossible (session non interactive). Definissez $IanseoAssumeYes=$true.'; return }
        $c = (Read-Host "Confirmer la suppression ? Tapez 'oui' en toutes lettres").Trim().ToLower()
        if ($c -ne 'oui') { Info "Abandon, rien n'a ete supprime."; return }
    }

    # --- Sauvegarde avant suppression ------------------------------------
    $backupDir = $env:USERPROFILE
    if (-not $backupDir -or -not (Test-Path $backupDir)) { $backupDir = [System.IO.Path]::GetTempPath() }
    $backup = Join-Path $backupDir ("ianseo-modules-backup-" + (Get-Date -Format 'yyyyMMdd-HHmmss') + ".zip")
    $backupOk = $false
    try {
        $paths = @($targets | ForEach-Object { Join-Path $dest $_ })
        Compress-Archive -Path $paths -DestinationPath $backup -Force -ErrorAction Stop
        Ok "Sauvegarde creee : $backup"
        $backupOk = $true
    } catch {
        Warn "La sauvegarde a echoue : $($_.Exception.Message)"
    }
    if (-not $backupOk) {
        if ($assumeYes) { ErrMsg 'Abandon (mode non interactif, pas de suppression sans sauvegarde).'; return }
        $a = (Read-Host 'Continuer SANS sauvegarde ? [o/N]').Trim().ToLower()
        if ($a -ne 'o' -and $a -ne 'oui' -and $a -ne 'y' -and $a -ne 'yes') { Info "Abandon, rien n'a ete supprime."; return }
    }

    # --- Suppression ------------------------------------------------------
    foreach ($t in $targets) {
        $target = Join-Path $dest $t
        if (-not (Test-Path -LiteralPath $target -PathType Container)) { Warn "Deja absent : $target"; continue }
        Remove-Item -LiteralPath $target -Recurse -Force
        Info "Supprime : $target"
    }

    Write-Host ''
    Ok "Desinstallation terminee : $($selected -join ', ')"
    Write-Host ''
    Warn "Les tables de base de donnees N'ONT PAS ete supprimees."
    Write-Host '  Les donnees des modules (ex. tables TNM_*, GUIDE_*) sont conservees : une'
    Write-Host '  reinstallation les retrouvera. Pour les supprimer definitivement, passez par'
    Write-Host '  phpMyAdmin (DROP TABLE) apres avoir sauvegarde votre base.'
    if ($backupOk) {
        Write-Host ''
        Write-Host 'Restauration eventuelle depuis la sauvegarde :'
        Write-Host "  Expand-Archive -Path `"$backup`" -DestinationPath `"$dest`" -Force"
    }
}

Invoke-IanseoUninstall
