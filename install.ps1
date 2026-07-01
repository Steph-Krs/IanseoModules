# Installateur interactif de modules Custom ianseo depuis GitHub (Windows).
# Depot : https://github.com/Steph-Krs/IanseoModules
#
# Usage interactif (recommande) — demande quoi installer et ou :
#   irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
#
# Usage non interactif (definir les variables avant le pipe) :
#   $IanseoModule='GUIDE'; irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
#   $IanseoModule='all'; $IanseoDest='C:\xampp\htdocs\Modules\Custom'; irm .../install.ps1 | iex

function Invoke-IanseoInstall {
    $Repo   = 'Steph-Krs/IanseoModules'
    $Branch = 'main'

    function Info($m)   { Write-Host $m -ForegroundColor Cyan }
    function Ok($m)     { Write-Host $m -ForegroundColor Green }
    function ErrMsg($m) { Write-Host $m -ForegroundColor Red }

    Write-Host ''
    Info '=== Installateur de modules Custom ianseo (Windows) ==='

    # TLS 1.2 (necessaire sur d'anciennes installations Windows)
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch {}

    # Arguments optionnels via variables globales pre-definies
    $argModule = ''
    if (Get-Variable -Name IanseoModule -Scope Global -ErrorAction SilentlyContinue) { $argModule = "$($global:IanseoModule)" }
    $argDest = ''
    if (Get-Variable -Name IanseoDest -Scope Global -ErrorAction SilentlyContinue) { $argDest = "$($global:IanseoDest)" }
    $interactive = [Environment]::UserInteractive

    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("ianseo_" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp | Out-Null

    try {
        # --- Telechargement + extraction du depot (ZIP) ------------------
        $zip = Join-Path $tmp 'repo.zip'
        $url = "https://github.com/$Repo/archive/refs/heads/$Branch.zip"
        Info "Telechargement du catalogue ($Repo, branche $Branch)..."
        try {
            Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
        } catch {
            ErrMsg "Echec du telechargement : $url"; ErrMsg $_.Exception.Message; return
        }
        Expand-Archive -Path $zip -DestinationPath $tmp -Force
        $src = Get-ChildItem -Path $tmp -Directory | Where-Object { $_.Name -like 'IanseoModules-*' } | Select-Object -First 1
        if (-not $src) { ErrMsg 'Archive invalide.'; return }

        # --- Modules disponibles (dossiers hors _* et .*) ----------------
        $available = @(Get-ChildItem -Path $src.FullName -Directory |
            Where-Object { $_.Name -notlike '_*' -and $_.Name -notlike '.*' } |
            Select-Object -ExpandProperty Name | Sort-Object)
        if ($available.Count -eq 0) { ErrMsg 'Aucun module trouve dans le depot.'; return }

        # --- Choix du/des module(s) --------------------------------------
        $selected = @()
        if ($argModule) {
            if ($argModule -eq 'all' -or $argModule -eq 'tous') {
                $selected = $available
            } elseif ($available -contains $argModule) {
                $selected = @($argModule)
            } else {
                ErrMsg "Module '$argModule' inconnu. Disponibles : $($available -join ', ')"; return
            }
        } elseif ($interactive) {
            Write-Host ''
            Info 'Modules disponibles :'
            for ($i = 0; $i -lt $available.Count; $i++) {
                Write-Host ("  {0}) {1}" -f ($i + 1), $available[$i])
            }
            Write-Host '  a) Tous les modules'
            Write-Host ''
            $ans = (Read-Host "Que voulez-vous installer ? (numeros separes par des virgules, ou 'a' pour tous)").Trim().ToLower()
            if (-not $ans) { ErrMsg 'Aucun choix. Abandon.'; return }
            if ($ans -eq 'a' -or $ans -eq 'all' -or $ans -eq 'tous') {
                $selected = $available
            } else {
                foreach ($p in ($ans -split ',')) {
                    $p = $p.Trim()
                    if ($p -match '^\d+$') {
                        $idx = [int]$p - 1
                        if ($idx -ge 0 -and $idx -lt $available.Count) { $selected += $available[$idx] }
                        else { ErrMsg "Numero hors liste ignore : $p" }
                    } elseif ($available -contains $p) {
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
            ErrMsg "Exemple : `$IanseoModule='GUIDE'; irm .../install.ps1 | iex"
            ErrMsg "Modules disponibles : $($available -join ', ')"; return
        }

        # --- Localisation du dossier Modules/Custom ----------------------
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
                    Write-Host ''
                    Info "Dossier ianseo detecte : $cand"
                    $a = (Read-Host "Utiliser ce dossier ? [O/n] (ou tapez un autre chemin)").Trim()
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
        Write-Host ''
        Info "Installation vers : $dest"
        Info "Module(s) : $($selected -join ', ')"

        # --- Copie (module.json local preserve) --------------------------
        function Copy-One($name) {
            $srcDir  = Join-Path $src.FullName $name
            $destDir = Join-Path $dest $name
            $mj      = Join-Path $destDir 'module.json'
            $keep    = $null
            if (Test-Path $mj) {
                $keep = Join-Path $tmp ($name + '.module.json.keep')
                Copy-Item -LiteralPath $mj -Destination $keep -Force
            }
            if (-not (Test-Path $destDir)) { New-Item -ItemType Directory -Path $destDir | Out-Null }
            Copy-Item -Path (Join-Path $srcDir '*') -Destination $destDir -Recurse -Force
            if ($keep) {
                Copy-Item -LiteralPath $keep -Destination $mj -Force
                Info '  module.json existant preserve (config/token GitHub conserves).'
            }
        }

        if (Test-Path (Join-Path $src.FullName '_shared')) {
            Info 'Bibliotheque partagee _shared/...'
            Copy-One '_shared'
        }
        foreach ($m in $selected) {
            Info "Module $m..."
            Copy-One $m
        }

        Write-Host ''
        Ok "Termine. Installe(s) : $($selected -join ', ')"
        Write-Host ''
        Write-Host 'Etapes suivantes :'
        Write-Host "  1. Ouvrez ianseo : l'entree du/des module(s) apparait dans le menu."
        Write-Host '  2. Mises a jour suivantes : page admin du module (admin/update.php).'
    }
    finally {
        if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

Invoke-IanseoInstall
