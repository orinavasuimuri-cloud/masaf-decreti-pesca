<#
.SYNOPSIS
    Registra (o riallinea) i job di raccolta nell'Utilita' di pianificazione di Windows.

.DESCRIPTION
    Su Windows non c'e' un crontab da versionare, e la pianificazione finisce per
    esistere solo dentro il sistema: chi riprende il progetto mesi dopo non ha modo
    di sapere a che ora gira cosa, ne' di rimetterlo com'era. Questo script e' il
    corrispettivo del crontab di esempio in docs/DEPLOY.md, in una forma che si puo'
    rileggere e rieseguire.

    E' idempotente: rilanciarlo riallinea i job esistenti senza duplicarli.

    Gli orari sono sfasati come nel crontab documentato. Lo sfasamento non e' piu'
    l'unica difesa - dalla revisione del lock i fetcher aspettano il proprio turno
    invece di rinunciare - ma resta il modo piu' semplice per non far partire cinque
    processi insieme quando la macchina si riaccende dopo essere stata spenta.

.EXAMPLE
    pwsh -File tools\pianifica_windows.ps1
    pwsh -File tools\pianifica_windows.ps1 -WhatIf   # mostra cosa farebbe, senza toccare nulla
#>
[CmdletBinding(SupportsShouldProcess)]
param(
    # Cartella del progetto. Il valore predefinito e' quella che contiene questo script.
    [string] $Progetto = (Split-Path -Parent $PSScriptRoot),

    # Interprete PHP. Se non passato si cerca nel PATH.
    [string] $Php
)

$ErrorActionPreference = 'Stop'

if (-not $Php) {
    $trovato = Get-Command php.exe -ErrorAction SilentlyContinue
    if (-not $trovato) {
        throw "php.exe non e' nel PATH: rilancia lo script passando -Php <percorso di php.exe>"
    }
    $Php = $trovato.Source
}

foreach ($f in 'scraper.php', 'gazzetta_fetcher.php', 'news_fetcher.php', 'bandi_fetcher.php', 'check_allegati.php') {
    if (-not (Test-Path (Join-Path $Progetto $f))) {
        throw "manca $f in $Progetto : e' la cartella giusta del progetto?"
    }
}

# Un giro non arriva al minuto (il piu' lento, i bandi, sta sotto i due minuti):
# un'ora e' un tetto larghissimo che pero' impedisce a un processo impantanato di
# restare in vita per giorni tenendosi il lock. Il valore che l'Utilita' di
# pianificazione mette da sola sarebbe 72 ore.
$limite = New-TimeSpan -Hours 1

$job = @(
    @{ Nome = 'MASAF Decreti Pesca Scraper'; Script = 'scraper.php';         Ora = '06:00'; OgniOre = 6; Descrizione = 'Indice dei decreti MASAF' }
    @{ Nome = 'MASAF Gazzetta Pesca';        Script = 'gazzetta_fetcher.php'; Ora = '06:20'; OgniOre = 0; Descrizione = 'Sommari della Gazzetta Ufficiale, con recupero dei fascicoli saltati' }
    @{ Nome = 'MASAF News Pesca';            Script = 'news_fetcher.php';     Ora = '06:40'; OgniOre = 6; Descrizione = 'Notizie da stampa e istituzioni' }
    @{ Nome = 'MASAF Bandi Pesca';           Script = 'bandi_fetcher.php';    Ora = '07:00'; OgniOre = 12; Descrizione = 'Bandi regionali e GAL (42 fonti, il piu lento)' }
    @{ Nome = 'MASAF Controllo Allegati';    Script = 'check_allegati.php';   Ora = '05:00'; Settimanale = 'Sunday'; Descrizione = 'Controllo di integrita del catalogo' }
)

foreach ($j in $job) {
    $azione = New-ScheduledTaskAction -Execute $Php -Argument ('"{0}"' -f (Join-Path $Progetto $j.Script)) -WorkingDirectory $Progetto

    if ($j.Settimanale) {
        $trigger = New-ScheduledTaskTrigger -Weekly -DaysOfWeek $j.Settimanale -At $j.Ora
    }
    else {
        $trigger = New-ScheduledTaskTrigger -Daily -At $j.Ora
        if ($j.OgniOre -gt 0) {
            # La ripetizione infragiornaliera non si imposta su un trigger giornaliero
            # con i parametri di New-ScheduledTaskTrigger: si costruisce un trigger
            # -Once che la sa esprimere e se ne prende il solo blocco Repetition.
            $conRipetizione = New-ScheduledTaskTrigger -Once -At $j.Ora `
                -RepetitionInterval (New-TimeSpan -Hours $j.OgniOre) `
                -RepetitionDuration (New-TimeSpan -Days 1)
            $trigger.Repetition = $conRipetizione.Repetition
        }
    }

    # StartWhenAvailable recupera le esecuzioni perse mentre la macchina era spenta.
    # E' quello che serve su un portatile, ed e' anche quello che il 9 agosto 2026 ha
    # fatto partire tre job nello stesso secondo: da solo non basta, va insieme
    # all'attesa sul lock in lib/lock.php.
    $impostazioni = New-ScheduledTaskSettingsSet `
        -StartWhenAvailable `
        -DontStopOnIdleEnd `
        -MultipleInstances IgnoreNew `
        -ExecutionTimeLimit $limite

    $esistente = Get-ScheduledTask -TaskName $j.Nome -ErrorAction SilentlyContinue
    if ($PSCmdlet.ShouldProcess($j.Nome, $(if ($esistente) { 'riallinea' } else { 'crea' }))) {
        Register-ScheduledTask -TaskName $j.Nome -Action $azione -Trigger $trigger `
            -Settings $impostazioni -Description $j.Descrizione -Force | Out-Null

        $quando = if ($j.Settimanale) { "$($j.Settimanale) $($j.Ora)" }
                  elseif ($j.OgniOre -gt 0) { "$($j.Ora), poi ogni $($j.OgniOre)h" }
                  else { $j.Ora }
        Write-Output ("{0,-30} {1,-22} {2}" -f $j.Nome, $quando, $j.Script)
    }
}
