# Interactive installer for ianseo Custom modules, from GitHub (Windows).
# Repository: https://github.com/Steph-Krs/IanseoModules
#
# Interactive use (recommended) - asks what to install and where:
#   irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
#
# Unattended use (set the variables before the pipe):
#   $IanseoModule='GUIDE'; irm https://raw.githubusercontent.com/Steph-Krs/IanseoModules/main/install.ps1 | iex
#   $IanseoModule='all'; $IanseoDest='C:\xampp\htdocs\Modules\Custom'; irm .../install.ps1 | iex
#   $IanseoLang='fr'; irm .../install.ps1 | iex          # French messages
#
# LANGUAGE. English by default, because the repository is offered to ianseo users
# worldwide. Interactively the first question offers French; unattended, set
# $IanseoLang='fr'.
#
# DELIBERATELY PLAIN ASCII, in both languages. The script is piped straight into
# the interpreter over the network (irm | iex), and PowerShell 5.1 does not always
# decode a downloaded stream as UTF-8: an accented character can arrive mangled
# and there is no way to fix it once the text is running. Unaccented French is
# less pretty than a broken installer.

function Invoke-IanseoInstall {
    $Repo   = 'Steph-Krs/IanseoModules'
    $Branch = 'main'

    function Info($m)   { Write-Host $m -ForegroundColor Cyan }
    function Ok($m)     { Write-Host $m -ForegroundColor Green }
    function ErrMsg($m) { Write-Host $m -ForegroundColor Red }

    # TLS 1.2 (needed on older Windows installations)
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch {}

    # --- Optional arguments, passed as pre-defined global variables ------
    $argModule = ''
    if (Get-Variable -Name IanseoModule -Scope Global -ErrorAction SilentlyContinue) { $argModule = "$($global:IanseoModule)" }
    $argDest = ''
    if (Get-Variable -Name IanseoDest -Scope Global -ErrorAction SilentlyContinue) { $argDest = "$($global:IanseoDest)" }
    $argLang = ''
    if (Get-Variable -Name IanseoLang -Scope Global -ErrorAction SilentlyContinue) { $argLang = "$($global:IanseoLang)".ToLower() }
    $interactive = [Environment]::UserInteractive

    # --- Messages --------------------------------------------------------
    $M = @{
        en = @{
            Title       = '=== ianseo Custom module installer (Windows) ==='
            AskLang     = 'Language / Langue - [E]nglish, [F]rancais ? [E]'
            Downloading = 'Downloading the catalogue ({0}, branch {1})...'
            DlFailed    = 'Download failed: {0}'
            BadArchive  = 'Invalid archive.'
            NoModules   = 'No module found in the repository.'
            Available   = 'Available modules:'
            AllModules  = '  a) All modules'
            AskModule   = "What do you want to install? (numbers separated by commas, or 'a' for all)"
            NoChoice    = 'Nothing chosen. Aborting.'
            OutOfList   = 'Number out of range, ignored: {0}'
            Ignored     = 'Choice ignored: {0}'
            NoneValid   = 'No valid module selected. Aborting.'
            Unknown     = "Unknown module '{0}'. Available: {1}"
            NotInter    = 'Non-interactive session and no module given.'
            Example     = "Example: `$IanseoModule='GUIDE'; irm .../install.ps1 | iex"
            AvailAre    = 'Available modules: {0}'
            Detected    = 'ianseo folder detected: {0}'
            UseThis     = 'Use this folder? [Y/n] (or type another path)'
            AskPath     = 'Full path of the Modules\Custom folder'
            NoPath      = 'No path given. Aborting.'
            NotFound    = 'Folder not found: {0}'
            NoCustom    = 'Modules\Custom folder not found. Set $IanseoDest.'
            InstallTo   = 'Installing into: {0}'
            Modules     = 'Module(s): {0}'
            Shared      = 'Shared library _shared/...'
            Module      = 'Module {0}...'
            KeptJson    = '  existing module.json kept (GitHub config and token preserved).'
            Done        = 'Done. Installed: {0}'
            NextSteps   = 'Next steps:'
            Next1       = '  1. Open ianseo: the module entry appears in the menu.'
            Next2       = '  2. Later updates: the module admin page (admin/update.php).'
            YesPattern  = '^(y|yes)$'
            NoPattern   = '^(n|no)$'
            AllPattern  = '^(a|all)$'
        }
        fr = @{
            Title       = '=== Installateur de modules Custom ianseo (Windows) ==='
            AskLang     = 'Language / Langue - [E]nglish, [F]rancais ? [E]'
            Downloading = 'Telechargement du catalogue ({0}, branche {1})...'
            DlFailed    = 'Echec du telechargement : {0}'
            BadArchive  = 'Archive invalide.'
            NoModules   = 'Aucun module trouve dans le depot.'
            Available   = 'Modules disponibles :'
            AllModules  = '  a) Tous les modules'
            AskModule   = "Que voulez-vous installer ? (numeros separes par des virgules, ou 'a' pour tous)"
            NoChoice    = 'Aucun choix. Abandon.'
            OutOfList   = 'Numero hors liste ignore : {0}'
            Ignored     = 'Choix ignore : {0}'
            NoneValid   = 'Aucun module valide selectionne. Abandon.'
            Unknown     = "Module '{0}' inconnu. Disponibles : {1}"
            NotInter    = 'Session non interactive et aucun module precise.'
            Example     = "Exemple : `$IanseoModule='GUIDE'; irm .../install.ps1 | iex"
            AvailAre    = 'Modules disponibles : {0}'
            Detected    = 'Dossier ianseo detecte : {0}'
            UseThis     = 'Utiliser ce dossier ? [O/n] (ou tapez un autre chemin)'
            AskPath     = 'Chemin complet du dossier Modules\Custom'
            NoPath      = 'Aucun chemin fourni. Abandon.'
            NotFound    = 'Dossier introuvable : {0}'
            NoCustom    = 'Dossier Modules\Custom introuvable. Definissez $IanseoDest.'
            InstallTo   = 'Installation vers : {0}'
            Modules     = 'Module(s) : {0}'
            Shared      = 'Bibliotheque partagee _shared/...'
            Module      = 'Module {0}...'
            KeptJson    = '  module.json existant preserve (config et token GitHub conserves).'
            Done        = 'Termine. Installe(s) : {0}'
            NextSteps   = 'Etapes suivantes :'
            Next1       = "  1. Ouvrez ianseo : l'entree du/des module(s) apparait dans le menu."
            Next2       = '  2. Mises a jour suivantes : page admin du module (admin/update.php).'
            YesPattern  = '^(o|oui|y|yes)$'
            NoPattern   = '^(n|non|no)$'
            AllPattern  = '^(a|all|tous)$'
        }
    }

    # English unless asked otherwise: set explicitly, or chosen at the prompt.
    $lang = 'en'
    if ($argLang -match '^(fr|francais|french)') { $lang = 'fr' }

    Write-Host ''
    if (-not $argLang -and $interactive) {
        $a = (Read-Host $M['en'].AskLang).Trim().ToLower()
        if ($a -match '^(f|fr|francais|french)$') { $lang = 'fr' }
    }
    $T = $M[$lang]

    Info $T.Title

    $tmp = Join-Path ([System.IO.Path]::GetTempPath()) ("ianseo_" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmp | Out-Null

    try {
        # --- Download and extract the repository (ZIP) -------------------
        $zip = Join-Path $tmp 'repo.zip'
        $url = "https://github.com/$Repo/archive/refs/heads/$Branch.zip"
        Info ($T.Downloading -f $Repo, $Branch)
        try {
            Invoke-WebRequest -Uri $url -OutFile $zip -UseBasicParsing
        } catch {
            ErrMsg ($T.DlFailed -f $url); ErrMsg $_.Exception.Message; return
        }
        Expand-Archive -Path $zip -DestinationPath $tmp -Force
        $src = Get-ChildItem -Path $tmp -Directory | Where-Object { $_.Name -like 'IanseoModules-*' } | Select-Object -First 1
        if (-not $src) { ErrMsg $T.BadArchive; return }

        # --- Available modules (folders other than _* and .*) ------------
        $available = @(Get-ChildItem -Path $src.FullName -Directory |
            Where-Object { $_.Name -notlike '_*' -and $_.Name -notlike '.*' } |
            Select-Object -ExpandProperty Name | Sort-Object)
        if ($available.Count -eq 0) { ErrMsg $T.NoModules; return }

        # --- Which module(s) ---------------------------------------------
        $selected = @()
        if ($argModule) {
            if ($argModule -match $T.AllPattern -or $argModule -eq 'tous') {
                $selected = $available
            } elseif ($available -contains $argModule) {
                $selected = @($argModule)
            } else {
                ErrMsg ($T.Unknown -f $argModule, ($available -join ', ')); return
            }
        } elseif ($interactive) {
            Write-Host ''
            Info $T.Available
            for ($i = 0; $i -lt $available.Count; $i++) {
                Write-Host ("  {0}) {1}" -f ($i + 1), $available[$i])
            }
            Write-Host $T.AllModules
            Write-Host ''
            $ans = (Read-Host $T.AskModule).Trim().ToLower()
            if (-not $ans) { ErrMsg $T.NoChoice; return }
            if ($ans -match $T.AllPattern) {
                $selected = $available
            } else {
                foreach ($p in ($ans -split ',')) {
                    $p = $p.Trim()
                    if ($p -match '^\d+$') {
                        $idx = [int]$p - 1
                        if ($idx -ge 0 -and $idx -lt $available.Count) { $selected += $available[$idx] }
                        else { ErrMsg ($T.OutOfList -f $p) }
                    } elseif ($available -contains $p) {
                        $selected += $p
                    } else {
                        ErrMsg ($T.Ignored -f $p)
                    }
                }
            }
            $selected = @($selected | Select-Object -Unique)
            if ($selected.Count -eq 0) { ErrMsg $T.NoneValid; return }
        } else {
            ErrMsg $T.NotInter
            ErrMsg $T.Example
            ErrMsg ($T.AvailAre -f ($available -join ', ')); return
        }

        # --- Locate the Modules/Custom folder ----------------------------
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
                    Info ($T.Detected -f $cand)
                    $a = (Read-Host $T.UseThis).Trim()
                    if ($a -eq '' -or $a -match $T.YesPattern) { $dest = $cand }
                    elseif ($a -match $T.NoPattern)            { $dest = '' }
                    else                                       { $dest = $a }
                }
                while (-not $dest -or -not (Test-Path $dest -PathType Container)) {
                    if ($dest -and -not (Test-Path $dest -PathType Container)) { ErrMsg ($T.NotFound -f $dest) }
                    $dest = (Read-Host $T.AskPath).Trim()
                    if (-not $dest) { ErrMsg $T.NoPath; return }
                }
            } else {
                $dest = $cand
                if (-not $dest) { ErrMsg $T.NoCustom; return }
            }
        }
        if (-not (Test-Path $dest -PathType Container)) { ErrMsg ($T.NotFound -f $dest); return }
        Write-Host ''
        Info ($T.InstallTo -f $dest)
        Info ($T.Modules -f ($selected -join ', '))

        # --- Copy (a local module.json is preserved) ---------------------
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
                Info $T.KeptJson
            }
        }

        if (Test-Path (Join-Path $src.FullName '_shared')) {
            Info $T.Shared
            Copy-One '_shared'
        }
        foreach ($m in $selected) {
            Info ($T.Module -f $m)
            Copy-One $m
        }

        Write-Host ''
        Ok ($T.Done -f ($selected -join ', '))
        Write-Host ''
        Write-Host $T.NextSteps
        Write-Host $T.Next1
        Write-Host $T.Next2
    }
    finally {
        if (Test-Path $tmp) { Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue }
    }
}

Invoke-IanseoInstall
