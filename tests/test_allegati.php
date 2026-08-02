<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/allegati.php';

$html = (string) file_get_contents(__DIR__ . '/fixtures/masaf_pagina_allegati.html');
$trovati = allegati_parse($html);

// --- il parser prende gli allegati e solo quelli
t_eq(count($trovati), 2, 'allegati_parse: la pagina campione ha due allegati');

$urlDecreto  = 'https://www.masaf.gov.it/flex/cm/pages/ServeAttachment.php/L/IT/D/1%252F7%252F1%252FD.8bcbda9cd06a5f4c823a/P/BLOB%3AID%3D24472/E/pdf?mode=download';
$urlAllegato = 'https://www.masaf.gov.it/flex/cm/pages/ServeAttachment.php/L/IT/D/1%252F2%252Ff%252FD.72ccdecd040dec4fa68f/P/BLOB%3AID%3D24472/E/pdf?mode=download';

t_true(isset($trovati[$urlDecreto]), 'allegati_parse: manca il decreto principale');
t_true(isset($trovati[$urlAllegato]), 'allegati_parse: manca l\'allegato 1');

// Il nome non deve inglobare il peso, che nel markup vive dentro il link.
t_eq($trovati[$urlDecreto]['titolo'], 'D.D. n. 166619 del 09/04/2026', 'allegati_parse: titolo del decreto');
t_eq($trovati[$urlDecreto]['peso'], '314.67 KB', 'allegati_parse: peso del decreto');
t_eq($trovati[$urlAllegato]['titolo'], 'Allegato 1', 'allegati_parse: titolo dell\'allegato');
t_eq($trovati[$urlAllegato]['peso'], '184.13 KB', 'allegati_parse: peso dell\'allegato');

// I link di navigazione verso altre IDPagina e i link esterni non sono allegati.
foreach (array_keys($trovati) as $u) {
    t_true(str_contains($u, 'ServeAttachment'), "allegati_parse: raccolto un link che non e' un allegato: $u");
}

// --- una pagina senza allegati non deve far esplodere nulla
t_eq(allegati_parse('<html><body><p>nessun documento</p></body></html>'), [], 'allegati_parse: pagina senza allegati');
t_eq(allegati_parse(''), [], 'allegati_parse: input vuoto');

// --- conversione del peso alla forma del catalogo
t_eq(allegati_peso_it('314.67 KB'), '314,67 KB', 'allegati_peso_it: separatore decimale italiano');
t_eq(allegati_peso_it('2.71 MB'), '2,71 MB', 'allegati_peso_it: megabyte');
t_eq(allegati_peso_it('9.67 KB'), '9,67 KB', 'allegati_peso_it: valori sotto la decina');
t_eq(allegati_peso_it('832,88 KB'), '832,88 KB', 'allegati_peso_it: un peso gia\' italiano resta invariato');

// --- confronto con quanto e' gia' registrato
$soloDecreto = allegati_confronta($trovati, [$urlDecreto]);
t_eq(array_keys($soloDecreto['mancanti']), [$urlAllegato], 'allegati_confronta: deve segnalare l\'allegato non registrato');
t_eq($soloDecreto['ignoti'], [], 'allegati_confronta: nessun link orfano quando il decreto e\' quello giusto');

$completo = allegati_confronta($trovati, [$urlDecreto, $urlAllegato]);
t_eq($completo['mancanti'], [], 'allegati_confronta: pagina completamente registrata');
t_eq($completo['ignoti'], [], 'allegati_confronta: pagina completamente registrata, nessun orfano');

$sparito = allegati_confronta($trovati, [$urlDecreto, 'https://www.masaf.gov.it/ServeAttachment.php/vecchio']);
t_eq($sparito['ignoti'], ['https://www.masaf.gov.it/ServeAttachment.php/vecchio'], 'allegati_confronta: link non piu\' in pagina');

// --- raggruppamento del catalogo per pagina MASAF
$finto = ['sections' => [[
    'title' => 'Tonno rosso e alalunga',
    'items' => [
        ['masaf_id' => 24797, 'pdf' => 'https://x/a.pdf'],
        ['masaf_id' => 24797, 'pdf' => 'https://x/b.pdf'],
        ['masaf_id' => 24459, 'pdf' => 'https://x/c.pdf',
         'allegati' => [['pdf' => 'https://x/c1.pdf'], ['pdf' => 'https://x/c2.pdf']]],
        ['external_url' => 'https://altro.it/pagina'], // senza masaf_id: fuori dal controllo
    ],
]]];
$pagine = allegati_pagine_catalogo($finto);
t_eq(array_keys($pagine), [24459, 24797], 'allegati_pagine_catalogo: raggruppa e ordina per IDPagina');
t_eq($pagine[24797]['pdf'], ['https://x/a.pdf', 'https://x/b.pdf'], 'allegati_pagine_catalogo: due voci sulla stessa pagina');
t_eq(
    $pagine[24459]['pdf'],
    ['https://x/c.pdf', 'https://x/c1.pdf', 'https://x/c2.pdf'],
    'allegati_pagine_catalogo: gli allegati agganciati contano come registrati'
);
t_eq(count($pagine[24797]['voci']), 2, 'allegati_pagine_catalogo: conserva le voci della pagina');

// --- il catalogo vero: nessun PDF registrato due volte sotto voci diverse
$reale = json_decode((string) file_get_contents(__DIR__ . '/../data/catalog.json'), true);
$tutti = [];
foreach ($reale['sections'] ?? [] as $s) {
    foreach ($s['items'] ?? [] as $i) {
        foreach (array_merge(
            empty($i['pdf']) ? [] : [$i['pdf']],
            array_column($i['allegati'] ?? [], 'pdf')
        ) as $u) {
            $tutti[] = $u;
        }
    }
}
t_eq(count($tutti), count(array_unique($tutti)), 'catalog.json: lo stesso PDF risulta registrato su piu\' voci');
