<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/accesso.php';
accesso_richiedi();
if (!accesso_admin()) {
    http_response_code(403);
    exit('Pagina riservata all\'amministratore.');
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

require_once __DIR__ . '/lib/curatela.php';
require_once __DIR__ . '/lib/gazzetta_parser.php';

$fissi = accesso_config()['utenti'];
$dataDir = __DIR__ . '/data';

/** Atti da rivedere, MASAF e Gazzetta, nella forma che serve al modulo. */
function atti_da_rivedere(string $dataDir): array
{
    $catalog = json_decode((string) @file_get_contents("$dataDir/catalog.json"), true) ?? ['sections' => []];
    $known   = json_decode((string) @file_get_contents("$dataDir/known.json"), true) ?? ['items' => []];
    $gu      = json_decode((string) @file_get_contents("$dataDir/gazzetta.json"), true) ?? ['items' => []];
    $inCatalogo = [];
    foreach ($catalog['sections'] as $s) {
        foreach ($s['items'] ?? [] as $it) {
            if (!empty($it['masaf_id'])) {
                $inCatalogo[(int) $it['masaf_id']] = true;
            }
        }
    }
    $atti = [];
    foreach ($known['items'] as $p) {
        if (($p['status'] ?? '') !== 'pending_review' || isset($inCatalogo[(int) $p['id']])) {
            continue;
        }
        $atti[] = ['tipo' => 'masaf', 'id' => (string) $p['id'], 'data' => (string) $p['first_seen'],
                   'url' => (string) $p['url'], 'ref' => (string) ($p['ref'] ?? ''), 'title' => (string) $p['title'],
                   'desc' => (string) ($p['titolo_completo'] ?? $p['title']), 'fonte' => 'MASAF'];
    }
    foreach (gazzetta_da_rivedere($gu['items']) as $g) {
        $data = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $g['data_gu']);
        $atti[] = ['tipo' => 'gu', 'id' => (string) $g['id'], 'data' => (string) $g['data_gu'], 'url' => (string) $g['url'],
                   'ref' => 'G.U. n. ' . (int) $g['numero_gu'] . ' · ' . ($data ? $data->format('d/m/Y') : $g['data_gu']),
                   'title' => (string) $g['oggetto'], 'desc' => trim($g['titolo'] . ' — ' . $g['oggetto']),
                   'fonte' => 'Gazzetta Ufficiale · ' . $g['tipo_atto']];
    }
    usort($atti, static fn(array $a, array $b): int => strcmp($b['data'], $a['data']));
    return [$atti, array_column($catalog['sections'], 'title', 'id')];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($_POST['azione'] ?? '', ['cataloga', 'escludi'], true)) {
    $tipo = (string) ($_POST['tipo'] ?? '');
    $id   = (string) ($_POST['id'] ?? '');
    try {
        if (!accesso_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Sessione scaduta: ricarica la pagina e riprova.');
        }
        if (!in_array($tipo, ['masaf', 'gu'], true) || $id === '') {
            throw new RuntimeException('Atto non riconosciuto.');
        }
        $testo = '';
        curatela_con_lock($dataDir, static function () use ($dataDir, $tipo, $id, &$testo): void {
            $file  = $tipo === 'masaf' ? "$dataDir/known.json" : "$dataDir/gazzetta.json";
            $arch  = curatela_leggi($file);
            if (($arch['items'][$id]['status'] ?? '') !== 'pending_review') {
                throw new RuntimeException('Questo atto non è più fra quelli da rivedere: forse l\'ha già sistemato qualcun altro.');
            }
            $atto = $arch['items'][$id];
            if ($_POST['azione'] === 'escludi') {
                $arch['items'][$id]['status'] = 'excluded';
                curatela_scrivi($file, $arch);
                if ($tipo === 'masaf') {
                    $escl = curatela_leggi("$dataDir/excluded.json");
                    $escl['ids'] = array_values(array_unique(array_merge($escl['ids'] ?? [], [(int) $id])));
                    curatela_scrivi("$dataDir/excluded.json", $escl);
                }
                $testo = 'Atto escluso: lo trovi in fondo alla pagina dei decreti, fra gli "Esclusi".';
                return;
            }
            $catalog = curatela_leggi("$dataDir/catalog.json");
            $voce = curatela_voce($_POST, array_column($catalog['sections'], 'id'),
                $tipo === 'masaf' ? (int) $id : null, $tipo === 'gu' ? (string) $atto['url'] : null);
            if (is_string($voce)) {
                throw new RuntimeException($voce);
            }
            $catalog = catalogo_inserisci($catalog, (string) $_POST['sezione'], $voce);
            curatela_scrivi("$dataDir/catalog.json", $catalog);
            $arch['items'][$id]['status'] = 'curated';
            curatela_scrivi($file, $arch);
            $testo = 'Atto inserito nel registro dei decreti.';
        });
        $_SESSION['admin_esito'] = ['ok' => true, 'testo' => $testo];
    } catch (RuntimeException $e) {
        $_SESSION['admin_esito'] = ['ok' => false, 'testo' => $e->getMessage()];
    }
    header('Location: admin.php#da-categorizzare');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $esito = ['ok' => false, 'testo' => ''];
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $gestiti = accesso_utenti_gestiti();

    try {
        if (!accesso_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
            $esito['testo'] = 'Sessione scaduta: ricarica la pagina e riprova.';
        } elseif (isset($fissi[$email])) {
            $esito['testo'] = 'Questo account è fisso e si modifica solo dai file del sito.';
        } else {
            switch ((string) ($_POST['azione'] ?? '')) {
                case 'aggiungi':
                    $errore = accesso_controlla_credenziali($email, $password);
                    if ($errore !== null) {
                        $esito['testo'] = $errore;
                    } elseif (isset($gestiti[$email])) {
                        $esito['testo'] = "$email esiste già: per cambiarle la password usa il modulo qui sotto.";
                    } else {
                        $gestiti[$email] = password_hash($password, PASSWORD_DEFAULT);
                        accesso_utenti_salva($gestiti);
                        $esito = ['ok' => true, 'testo' => "Utente $email aggiunto."];
                    }
                    break;
                case 'password':
                    $errore = accesso_controlla_credenziali($email, $password);
                    if ($errore !== null) {
                        $esito['testo'] = $errore;
                    } elseif (!isset($gestiti[$email])) {
                        $esito['testo'] = "$email non è fra gli utenti.";
                    } else {
                        $gestiti[$email] = password_hash($password, PASSWORD_DEFAULT);
                        accesso_utenti_salva($gestiti);
                        $esito = ['ok' => true, 'testo' => "Password di $email cambiata."];
                    }
                    break;
                case 'elimina':
                    if (!isset($gestiti[$email])) {
                        $esito['testo'] = "$email non è fra gli utenti.";
                    } else {
                        unset($gestiti[$email]);
                        accesso_utenti_salva($gestiti);
                        $esito = ['ok' => true, 'testo' => "Utente $email eliminato: non può più entrare."];
                    }
                    break;
                default:
                    $esito['testo'] = 'Operazione sconosciuta.';
            }
        }
    } catch (RuntimeException $e) {
        $esito = ['ok' => false, 'testo' => $e->getMessage()];
    }
    $_SESSION['admin_esito'] = $esito;
    header('Location: admin.php');
    exit;
}

$esito = $_SESSION['admin_esito'] ?? null;
unset($_SESSION['admin_esito']);
$csrf = accesso_csrf_token();
$gestiti = accesso_utenti_gestiti();
ksort($gestiti);
[$atti, $sezioni] = atti_da_rivedere($dataDir);
?>
<!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Utenti — amministrazione</title>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/temi/mare.css">
<style>
  .admin-box { background: rgb(var(--paper-raised)); border: 1px solid rgb(var(--line)); border-radius: 12px;
    padding: 1.1rem 1.2rem; margin-bottom: 1.4rem; }
  .admin-box h2 { margin: 0 0 0.8rem; font-size: 1.2rem; }
  .admin-form { display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: end; }
  .admin-form label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.8rem; color: rgb(var(--muted)); }
  .admin-form input { padding: 0.5rem 0.6rem; border: 1px solid rgb(var(--line)); border-radius: 8px; font-size: 0.95rem; min-width: 15rem; }
  .admin-btn { padding: 0.55rem 1rem; border-radius: 8px; border: 1px solid rgb(var(--brass)); background: rgb(var(--brass));
    color: rgb(var(--paper)); font-weight: 600; cursor: pointer; }
  .admin-btn.secondario { background: transparent; color: rgb(var(--brass-deep)); }
  .admin-btn.pericolo { background: transparent; border-color: rgb(192 44 44); color: rgb(170 30 30); }
  .admin-tab { width: 100%; border-collapse: collapse; font-size: 0.92rem; }
  .admin-tab th { text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.06em;
    color: rgb(var(--muted)); padding: 0.4rem 0.6rem 0.4rem 0; border-bottom: 1px solid rgb(var(--line)); }
  .admin-tab td { padding: 0.7rem 0.6rem 0.7rem 0; border-bottom: 1px solid rgb(var(--line)); vertical-align: middle; }
  .admin-tab .azioni { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
  .admin-tab .azioni input { min-width: 11rem; padding: 0.4rem 0.5rem; border: 1px solid rgb(var(--line)); border-radius: 8px; }
  .esito { padding: 0.8rem 1rem; border-radius: 8px; margin-bottom: 1.2rem; }
  .esito.ok { background: rgb(62 115 104 / 0.14); color: rgb(30 90 75); }
  .esito.ko { background: rgb(192 44 44 / 0.12); color: rgb(160 30 30); }
  .fisso { font-size: 0.75rem; color: rgb(var(--muted)); }
  .nota-admin { font-size: 0.88rem; color: rgb(var(--muted)); margin: 0 0 0.9rem; }
  .atto { border-top: 1px solid rgb(var(--line)); padding: 0.6rem 0; }
  .atto > summary { cursor: pointer; display: flex; flex-wrap: wrap; gap: 0.3rem 0.7rem; align-items: baseline; list-style: none; }
  .atto > summary::-webkit-details-marker { display: none; }
  .atto > summary::before { content: "▸"; color: rgb(var(--muted)); transition: transform 0.2s ease; }
  .atto[open] > summary::before { transform: rotate(90deg); }
  .atto-data, .atto-ref { font-family: ui-monospace, monospace; font-size: 0.74rem; color: rgb(var(--muted)); }
  .atto-ref { font-weight: 700; color: rgb(var(--ink)); }
  .atto-fonte { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 0.05rem 0.45rem;
    border-radius: 999px; background: rgb(var(--brass) / 0.14); color: rgb(var(--brass-deep)); }
  .atto-tit { flex-basis: 100%; font-weight: 600; font-size: 0.92rem; padding-left: 1.1rem; }
  .atto-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 0.7rem; margin: 0.4rem 0 0.6rem 1.1rem; }
  .atto-form label { display: flex; flex-direction: column; gap: 0.25rem; font-size: 0.78rem; color: rgb(var(--muted)); }
  .atto-form .largo { grid-column: 1 / -1; }
  .atto-form input, .atto-form select, .atto-form textarea { padding: 0.5rem 0.6rem; border: 1px solid rgb(var(--line));
    border-radius: 8px; font: inherit; font-size: 0.9rem; color: rgb(var(--ink)); background: rgb(var(--paper)); }
  .atto-azioni { grid-column: 1 / -1; display: flex; gap: 0.6rem; flex-wrap: wrap; }
</style>
</head>
<body>
<div class="wrap">

  <div class="topbar"><a href="index.php">← Registro decreti</a><a href="news.php">News</a><a href="bandi.php">Bandi</a><a href="logout.php">Esci</a></div>

  <div class="masthead">
    <p class="eyebrow">Amministrazione</p>
    <h1>Utenti del sito</h1>
    <p class="lede">Chi è in questo elenco può entrare nel sito con la sua email e password. Le modifiche valgono subito:
      un utente eliminato viene fatto uscire al suo prossimo clic.</p>
  </div>

  <?php if ($esito !== null): ?>
  <div class="esito <?= $esito['ok'] ? 'ok' : 'ko' ?>"><?= h($esito['testo']) ?></div>
  <?php endif; ?>

  <section class="admin-box" id="da-categorizzare">
    <h2>Da categorizzare (<?= count($atti) ?>)</h2>
    <p class="nota-admin">Atti trovati automaticamente e non ancora verificati. Aprine uno, leggi l'originale, poi
      inseriscilo nella sezione giusta del registro oppure escludilo. Le modifiche compaiono subito nella pagina dei decreti.</p>
    <?php if ($atti === []): ?>
    <p class="nota-admin"><strong>Nessun atto da categorizzare.</strong></p>
    <?php endif; ?>
    <?php foreach ($atti as $a): ?>
    <details class="atto">
      <summary>
        <span class="atto-data"><?= h($a['data']) ?></span>
        <span class="atto-fonte"><?= h($a['fonte']) ?></span>
        <?php if ($a['ref'] !== '' && $a['tipo'] === 'masaf'): ?><span class="atto-ref"><?= h($a['ref']) ?></span><?php endif; ?>
        <span class="atto-tit"><?= h($a['title']) ?></span>
      </summary>
      <p><a href="<?= h($a['url']) ?>" target="_blank" rel="noopener">Apri l'atto originale ↗</a></p>
      <form method="post" action="admin.php" class="atto-form">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="tipo" value="<?= h($a['tipo']) ?>">
        <input type="hidden" name="id" value="<?= h($a['id']) ?>">
        <label>Sezione del registro
          <select name="sezione">
            <option value="">— scegli —</option>
            <?php foreach ($sezioni as $sid => $stit): ?><option value="<?= h($sid) ?>"><?= h($stit) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Riferimento (numero e data dell'atto)
          <input type="text" name="ref" value="<?= h($a['ref']) ?>" placeholder="D.D. n. 123456 · 01/01/2026">
        </label>
        <label class="largo">Titolo breve, come apparirà nella scheda
          <input type="text" name="title" value="<?= h($a['title']) ?>">
        </label>
        <label class="largo">Descrizione: cosa dispone, per chi, da quando
          <textarea name="desc" rows="4"><?= h($a['desc']) ?></textarea>
        </label>
        <label class="largo">Link al PDF (facoltativo, deve cominciare con https://)
          <input type="url" name="pdf" placeholder="https://www.masaf.gov.it/...">
        </label>
        <div class="atto-azioni">
          <button class="admin-btn" type="submit" name="azione" value="cataloga">Inserisci nel registro</button>
          <button class="admin-btn pericolo" type="submit" name="azione" value="escludi" formnovalidate
                  onclick="return confirm('Escludere questo atto dal registro?');">Escludi</button>
        </div>
      </form>
    </details>
    <?php endforeach; ?>
  </section>

  <section class="admin-box">
    <h2>Aggiungi un utente</h2>
    <form class="admin-form" method="post" action="admin.php" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
      <input type="hidden" name="azione" value="aggiungi">
      <label>Email <input type="email" name="email" required></label>
      <label>Password (almeno 10 caratteri) <input type="text" name="password" required minlength="10" autocomplete="new-password"></label>
      <button class="admin-btn" type="submit">Aggiungi</button>
    </form>
  </section>

  <section class="admin-box">
    <h2>Utenti (<?= count($gestiti) + count($fissi) ?>)</h2>
    <div class="tabella-scroll" style="overflow-x:auto">
    <table class="admin-tab">
      <thead><tr><th scope="col">Email</th><th scope="col">Azioni</th></tr></thead>
      <tbody>
      <?php foreach ($fissi as $email => $_): ?>
      <tr><td><?= h($email) ?></td><td><span class="fisso">amministratore · account fisso</span></td></tr>
      <?php endforeach; ?>
      <?php foreach ($gestiti as $email => $_): ?>
      <tr>
        <td><?= h($email) ?></td>
        <td class="azioni">
          <form class="azioni" method="post" action="admin.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="azione" value="password">
            <input type="hidden" name="email" value="<?= h($email) ?>">
            <input type="text" name="password" placeholder="nuova password" required minlength="10" autocomplete="new-password" aria-label="Nuova password per <?= h($email) ?>">
            <button class="admin-btn secondario" type="submit">Cambia password</button>
          </form>
          <form method="post" action="admin.php" onsubmit="return confirm('Eliminare questo utente? Non potrà più entrare nel sito.');">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <input type="hidden" name="azione" value="elimina">
            <input type="hidden" name="email" value="<?= h($email) ?>">
            <button class="admin-btn pericolo" type="submit">Elimina</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </section>

</div>
</body>
</html>
